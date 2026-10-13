import { beforeEach, describe, expect, it, vi } from "vitest";
import { FakeRedis } from "../helpers/fake-redis";

// ============================================================================
//  Redis degradado: anti-replay, lock, rate-limit y revocación de tokens siguen
//  funcionando con menos garantías cuando Redis no responde, pero NUNCA en
//  silencio (spec §2). Con Redis sano se prueba también su comportamiento normal.
// ============================================================================

let redis: FakeRedis;
vi.mock("@/lib/redis/client", () => ({ getRedis: () => redis }));

const logger = vi.hoisted(() => ({ warn: vi.fn(), error: vi.fn(), info: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({ logger }));

import { resetRedisFailureLog } from "@/lib/redis/log";
import { markEventOnce, releaseEvent } from "@/lib/security/replay";
import { withLock } from "@/lib/security/lock";
import { rateLimit } from "@/lib/security/rate-limit";
import { isTokenRevoked, revokeToken } from "@/lib/auth/revocation";

beforeEach(() => {
  redis = new FakeRedis();
  logger.warn.mockReset();
  logger.error.mockReset();
  resetRedisFailureLog();
});

describe("anti-replay (markEventOnce / releaseEvent)", () => {
  it("el primer evento procede y el reenvío del mismo cuerpo se descarta", async () => {
    expect(await markEventOnce("payments:wompi", '{"id":1}')).toBe(true);
    expect(await markEventOnce("payments:wompi", '{"id":1}')).toBe(false);
  });

  it("soltar la marca deja reevaluar el mismo evento", async () => {
    await markEventOnce("payments:wompi", '{"id":1}');
    await releaseEvent("payments:wompi", '{"id":1}');

    expect(await markEventOnce("payments:wompi", '{"id":1}')).toBe(true);
  });

  it("con Redis caído falla abierto, y lo registra como error", async () => {
    redis.down();

    expect(await markEventOnce("payments:wompi", "{}")).toBe(true);
    expect(logger.error).toHaveBeenCalledWith(
      expect.objectContaining({ event: "replay.redis_error", op: "mark" }),
      expect.stringContaining("anti-replay"),
    );
  });

  it("si no puede soltar la marca lo registra: el reintento se descartaría como duplicado", async () => {
    redis.down();

    await releaseEvent("payments:wompi", "{}");

    expect(logger.error).toHaveBeenCalledWith(
      expect.objectContaining({ event: "replay.redis_error", op: "release" }),
      expect.any(String),
    );
  });
});

describe("withLock", () => {
  it("con Redis sano ejecuta la sección y suelta el lock", async () => {
    const result = await withLock("wishlist:7", async () => {
      expect(redis.store.has("lock:wishlist:7")).toBe(true);
      return "hecho";
    });

    expect(result).toBe("hecho");
    expect(redis.store.has("lock:wishlist:7")).toBe(false);
  });

  it("con Redis caído ejecuta igualmente, sin lock, y lo registra", async () => {
    redis.down();

    expect(await withLock("wishlist:7", async () => 42)).toBe(42);
    expect(logger.warn).toHaveBeenCalledWith(
      expect.objectContaining({ event: "lock.redis_error", op: "acquire" }),
      expect.any(String),
    );
  });

  it("si otro proceso retiene el lock todo el plazo, ejecuta igualmente y lo registra", async () => {
    vi.useFakeTimers();
    try {
      redis.store.set("lock:wishlist:7", "otro");
      const pending = withLock("wishlist:7", async () => "tarde");
      await vi.advanceTimersByTimeAsync(3_500);

      expect(await pending).toBe("tarde");
      expect(logger.warn).toHaveBeenCalledWith(
        expect.objectContaining({ event: "lock.wait_timeout", key: "wishlist:7" }),
        expect.any(String),
      );
      // No era nuestro lock: no se libera el del otro.
      expect(redis.store.get("lock:wishlist:7")).toBe("otro");
    } finally {
      vi.useRealTimers();
    }
  });
});

describe("rateLimit con Redis", () => {
  it("cuenta, y al pasar el límite responde con el tiempo restante", async () => {
    expect((await rateLimit("login:ip", 2, 60)).success).toBe(true);
    expect((await rateLimit("login:ip", 2, 60)).remaining).toBe(0);

    const blocked = await rateLimit("login:ip", 2, 60);

    expect(blocked).toMatchObject({ success: false, remaining: 0, limit: 2 });
    expect(blocked.retryAfter).toBe(60);
  });

  it("con Redis caído, no estricto: falla abierto y lo registra como error", async () => {
    redis.down();

    expect((await rateLimit("search:ip", 1, 60)).success).toBe(true);
    expect((await rateLimit("search:ip", 1, 60)).success).toBe(true);
    expect(logger.error).toHaveBeenCalledWith(
      expect.objectContaining({ event: "rate_limit.redis_error" }),
      expect.any(String),
    );
  });

  it("con Redis caído, estricto: cuenta en memoria y sigue limitando", async () => {
    redis.down();
    const id = `login:${Math.random()}`;

    expect((await rateLimit(id, 1, 60, { strict: true })).success).toBe(true);
    expect((await rateLimit(id, 1, 60, { strict: true })).success).toBe(false);
    expect(logger.warn).toHaveBeenCalledWith(
      expect.objectContaining({ event: "rate_limit.redis_error" }),
      expect.stringContaining("memoria"),
    );
  });
});

describe("revocación de tokens", () => {
  const token = (expInSeconds: number) =>
    [
      "e30",
      Buffer.from(
        JSON.stringify({ exp: Math.floor(Date.now() / 1000) + expInSeconds }),
      ).toString("base64url"),
      "firma",
    ].join(".");

  it("un token revocado lo está hasta que caduque", async () => {
    const jwt = token(600);
    expect(await isTokenRevoked(jwt)).toBe(false);

    await revokeToken(jwt);

    expect(await isTokenRevoked(jwt)).toBe(true);
  });

  it("con Redis caído la comprobación falla abierta, y lo registra como error", async () => {
    redis.down();

    expect(await isTokenRevoked(token(600))).toBe(false);
    expect(logger.error).toHaveBeenCalledWith(
      expect.objectContaining({ event: "revocation.redis_error", op: "check" }),
      expect.stringContaining("revocación"),
    );
  });

  it("si no puede revocar lo registra: el token sigue vivo hasta que caduque", async () => {
    redis.down();

    await revokeToken(token(600));

    expect(logger.error).toHaveBeenCalledWith(
      expect.objectContaining({ event: "revocation.redis_error", op: "revoke" }),
      expect.any(String),
    );
  });
});
