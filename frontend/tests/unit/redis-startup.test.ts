import { once } from "node:events";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
  closedRedisUrl,
  startRespServer,
  type RespServer,
} from "../helpers/resp-server";

// ============================================================================
//  Primer comando de Redis en un proceso recién arrancado.
//
//  `getRedis()` crea el cliente en el PRIMER uso (no se calienta al arrancar) y
//  lo configura con `enableOfflineQueue: false`. ioredis devuelve el cliente
//  en `connecting`, y hasta que llega `ready` (unos milisegundos) rechaza TODO
//  comando con «Stream isn't writeable and enableOfflineQueue options is
//  false». Quien lo recibía lo tomaba por una caída: la primera petición tras
//  arrancar se saltaba el rate-limit, el anti-replay, la revocación…
//
//  Estos tests usan el ioredis REAL contra un servidor RESP en proceso (sin
//  Redis ni Docker). Los dos últimos bloques fijan lo que NO cambia: con Redis
//  de verdad caído, los comandos siguen fallando rápido y cada consumidor
//  degrada con su política de siempre.
// ============================================================================

const logger = vi.hoisted(() => ({
  info: vi.fn(),
  warn: vi.fn(),
  error: vi.fn(),
  debug: vi.fn(),
}));
vi.mock("@/lib/observability/logger", () => ({
  logger,
  requestLogger: () => logger,
}));

import { getRedis, REDIS_STARTUP_GRACE_MS } from "@/lib/redis/client";
import { resetRedisFailureLog } from "@/lib/redis/log";
import { markEventOnce } from "@/lib/security/replay";
import { rateLimit } from "@/lib/security/rate-limit";

const NOT_WRITEABLE = "Stream isn't writeable and enableOfflineQueue options is false";
const globalForRedis = globalThis as unknown as {
  __hweRedis?: { disconnect(): void } | null;
};

let server: RespServer | undefined;
let savedUrl: string | undefined;

/** Un «proceso nuevo»: sin cliente cacheado y apuntando a `url`. */
function freshProcess(url: string): void {
  process.env.REDIS_URL = url;
  delete globalForRedis.__hweRedis;
}

async function elapsed<T>(run: () => Promise<T>): Promise<[T | Error, number]> {
  const started = Date.now();
  const outcome = await run().catch((error: Error) => error);
  return [outcome, Date.now() - started];
}

beforeEach(() => {
  savedUrl = process.env.REDIS_URL;
  logger.warn.mockReset();
  logger.error.mockReset();
  resetRedisFailureLog();
});

afterEach(async () => {
  globalForRedis.__hweRedis?.disconnect();
  delete globalForRedis.__hweRedis;
  await server?.close();
  server = undefined;
  if (savedUrl !== undefined) process.env.REDIS_URL = savedUrl;
  else delete process.env.REDIS_URL;
});

describe("Redis sano, primer uso tras arrancar", () => {
  beforeEach(async () => {
    server = await startRespServer();
    freshProcess(server.url);
  });

  it("el primer comando espera a la conexión en vez de rechazarse", async () => {
    const redis = getRedis()!;
    expect(redis.status).not.toBe("ready"); // la ventana existe: sin esto no probaría nada

    await expect(redis.incr("primero")).resolves.toBe(1);
    await expect(redis.incr("primero")).resolves.toBe(2);
  });

  it("los comandos que llegan juntos en esa ventana conservan su orden", async () => {
    const redis = getRedis()!;

    const results = await Promise.all([
      redis.incr("orden"),
      redis.incr("orden"),
      redis.incr("orden"),
    ]);

    expect(results).toEqual([1, 2, 3]);
  });

  it("un pipeline lanzado en esa ventana también se ejecuta", async () => {
    const redis = getRedis()!;

    const results = await redis.pipeline().incr("lote").expire("lote", 60).exec();

    expect(results).toEqual([
      [null, 1],
      [null, 1],
    ]);
  });

  it("rateLimit cuenta la primera petición: no la deja pasar sin límite", async () => {
    const result = await rateLimit("arranque", 3, 60);

    expect(result).toMatchObject({ success: true, remaining: 2, limit: 3 });
    expect(server!.commands.some(([name]) => name === "INCR")).toBe(true);
    expect(logger.error).not.toHaveBeenCalled();
  });

  it("el anti-replay registra el primer evento y descarta su reenvío", async () => {
    expect(await markEventOnce("payments:test", '{"id":1}')).toBe(true);
    expect(await markEventOnce("payments:test", '{"id":1}')).toBe(false);
    expect(logger.error).not.toHaveBeenCalled();
  });
});

describe("Redis caído de verdad: la política no cambia", () => {
  it("con la conexión rechazada, cada comando falla rápido (sin esperar el plazo)", async () => {
    freshProcess(await closedRedisUrl());
    const redis = getRedis()!;

    for (let call = 0; call < 3; call++) {
      const [outcome, ms] = await elapsed(() => redis.incr("caido"));
      expect(outcome).toBeInstanceOf(Error);
      expect((outcome as Error).message).toBe(NOT_WRITEABLE);
      expect(ms).toBeLessThan(500);
    }
  });

  it("rateLimit falla abierto y lo registra; en modo estricto usa memoria", async () => {
    freshProcess(await closedRedisUrl());

    const open = await rateLimit("abierto", 1, 60);
    expect(open).toMatchObject({ success: true, remaining: 1 });
    expect(logger.error).toHaveBeenCalledWith(
      expect.objectContaining({ event: "rate_limit.redis_error", op: "count" }),
      expect.any(String),
    );

    expect((await rateLimit("estricto", 1, 60, { strict: true })).success).toBe(true);
    expect((await rateLimit("estricto", 1, 60, { strict: true })).success).toBe(false);
  });

  it("si Redis acepta la conexión pero no responde, la espera tiene tope y es única", async () => {
    server = await startRespServer({ mute: true });
    freshProcess(server.url);
    const redis = getRedis()!;

    const [first, firstMs] = await elapsed(() => redis.incr("mudo"));
    expect((first as Error).message).toBe(NOT_WRITEABLE);
    expect(firstMs).toBeGreaterThanOrEqual(REDIS_STARTUP_GRACE_MS - 200);
    expect(firstMs).toBeLessThan(REDIS_STARTUP_GRACE_MS + 1_500);

    // Pasado el plazo, la ventana de arranque se cierra: ya no se retiene nada.
    const [second, secondMs] = await elapsed(() => redis.incr("mudo"));
    expect((second as Error).message).toBe(NOT_WRITEABLE);
    expect(secondMs).toBeLessThan(300);
  }, 15_000);

  it("una caída tras haber conectado falla rápido, sin esperar", async () => {
    server = await startRespServer();
    freshProcess(server.url);
    const redis = getRedis()!;
    await redis.incr("vivo");

    const closed = once(redis, "close");
    await server.close();
    server = undefined;
    await closed;

    const [outcome, ms] = await elapsed(() => redis.incr("vivo"));
    expect(outcome).toBeInstanceOf(Error);
    expect(ms).toBeLessThan(300);
  });
});
