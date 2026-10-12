import { beforeEach, describe, expect, it, vi } from "vitest";
import { FakeRedis } from "../../../tests/helpers/fake-redis";
import { getRedis } from "@/lib/redis/client";
import { logger } from "@/lib/observability/logger";
import {
  releaseIdempotencyKey,
  reserveIdempotencyKey,
  storeIdempotentResult,
} from "./idempotency";

// ============================================================================
//  Idempotencia del checkout: evita pedidos duplicados por doble clic o por
//  reintentos de red.
//
//  La propiedad crítica es que un duplicado YA DETECTADO nunca vuelva a
//  responder `proceed`. Si `SET NX` confirma que la clave existe y la lectura
//  posterior falla (timeout, valor ilegible), lo correcto es `conflict`
//  (reintentable): `proceed` crearía el segundo pedido. Solo el fallo del
//  `SET NX` inicial, o no tener Redis, abre la puerta a `proceed`, porque ahí no
//  sabemos si hay duplicado y bloquear todo el checkout sería peor.
// ============================================================================

vi.mock("@/lib/redis/client", () => ({ getRedis: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const KEY = "checkout-key-1";
const REDIS_KEY = `idem:${KEY}`;

let redis: FakeRedis;
beforeEach(() => {
  vi.clearAllMocks();
  redis = new FakeRedis();
  vi.mocked(getRedis).mockReturnValue(redis as never);
});

describe("ciclo normal", () => {
  it("la primera petición procede y reserva la clave con TTL", async () => {
    const result = await reserveIdempotencyKey(KEY, 3600);

    expect(result.status).toBe("proceed");
    expect(JSON.parse(redis.store.get(REDIS_KEY)!)).toEqual({ state: "processing" });
    expect(redis.ttls.get(REDIS_KEY)).toBe(3600);
  });

  it("un duplicado mientras la primera sigue en curso es conflict", async () => {
    await reserveIdempotencyKey(KEY);

    expect((await reserveIdempotencyKey(KEY)).status).toBe("conflict");
  });

  it("un duplicado tras completarse recibe la respuesta guardada (replay)", async () => {
    await reserveIdempotencyKey(KEY);
    await storeIdempotentResult(KEY, { orderId: 9 }, 201);

    const result = await reserveIdempotencyKey(KEY);

    expect(result).toEqual({ status: "replay", body: { orderId: 9 }, statusCode: 201 });
  });

  it("liberar la reserva permite reintentar tras un fallo", async () => {
    await reserveIdempotencyKey(KEY);
    await releaseIdempotencyKey(KEY);

    expect((await reserveIdempotencyKey(KEY)).status).toBe("proceed");
  });
});

describe("un duplicado detectado nunca vuelve a proceder", () => {
  beforeEach(async () => {
    // La primera petición ya reservó la clave: el SET NX de la segunda detecta el duplicado.
    await reserveIdempotencyKey(KEY);
  });

  it("si la lectura posterior falla (timeout), responde conflict y no proceed", async () => {
    vi.spyOn(redis, "get").mockRejectedValue(new Error("timeout"));

    expect((await reserveIdempotencyKey(KEY)).status).toBe("conflict");
  });

  it("deja rastro estructurado del fallo de lectura", async () => {
    vi.spyOn(redis, "get").mockRejectedValue(new Error("timeout"));

    await reserveIdempotencyKey(KEY);

    expect(logger.error).toHaveBeenCalledWith(
      expect.objectContaining({
        event: "idempotency.redis_error",
        op: "read_duplicate",
      }),
      expect.any(String),
    );
  });

  it("si el valor guardado es ilegible (JSON corrupto), responde conflict y no proceed", async () => {
    redis.store.set(REDIS_KEY, "{no es json");

    expect((await reserveIdempotencyKey(KEY)).status).toBe("conflict");
  });

  it("si la clave caduca entre el SET NX y el GET, responde conflict (reintentable)", async () => {
    vi.spyOn(redis, "get").mockResolvedValue(null);

    expect((await reserveIdempotencyKey(KEY)).status).toBe("conflict");
  });
});

describe("sin garantía de idempotencia (decisión documentada: no bloquear el checkout)", () => {
  it("sin Redis, procede", async () => {
    vi.mocked(getRedis).mockReturnValue(null);

    expect((await reserveIdempotencyKey(KEY)).status).toBe("proceed");
  });

  it("si el propio SET NX falla (Redis caído), procede y deja rastro", async () => {
    redis.down();

    expect((await reserveIdempotencyKey(KEY)).status).toBe("proceed");
    expect(logger.error).toHaveBeenCalledWith(
      expect.objectContaining({ event: "idempotency.redis_error", op: "reserve" }),
      expect.any(String),
    );
  });
});

describe("el mejor esfuerzo no es silencioso", () => {
  it("guardar el resultado con Redis caído no rompe y deja rastro", async () => {
    redis.down();

    await expect(
      storeIdempotentResult(KEY, { ok: true }, 200),
    ).resolves.toBeUndefined();
    expect(logger.error).toHaveBeenCalledWith(
      expect.objectContaining({ event: "idempotency.redis_error", op: "store" }),
      expect.any(String),
    );
  });

  it("liberar con Redis caído no rompe y deja rastro", async () => {
    redis.down();

    await expect(releaseIdempotencyKey(KEY)).resolves.toBeUndefined();
    expect(logger.error).toHaveBeenCalledWith(
      expect.objectContaining({ event: "idempotency.redis_error", op: "release" }),
      expect.any(String),
    );
  });
});
