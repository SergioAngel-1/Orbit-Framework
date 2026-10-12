import { beforeEach, describe, expect, it, vi } from "vitest";
import { FakeRedis } from "../../../tests/helpers/fake-redis";
import { getRedis } from "@/lib/redis/client";
import {
  delPending2faSecret,
  getPending2faSecret,
  setPending2faSecret,
} from "./two-factor-pending";

// ============================================================================
//  Secreto TOTP pendiente de activación (entre POST /2fa/setup y
//  GET /2fa/qr + POST /2fa/activate).
//
//  Antes el secreto viajaba en el query string de GET /2fa/qr y, antes aún, a
//  api.qrserver.com (un tercero) dentro de la URL: quedaba en logs de servidor y
//  de proxy, en el historial del navegador y en la cabecera Referer. Ahora se
//  guarda aquí, en el servidor, con TTL, y el QR lo lee por sesión.
// ============================================================================

vi.mock("@/lib/redis/client", () => ({ getRedis: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

let redis: FakeRedis;
beforeEach(() => {
  redis = new FakeRedis();
  vi.mocked(getRedis).mockReturnValue(redis as never);
});

describe("secreto TOTP pendiente", () => {
  it("se guarda por usuario con un TTL razonable para completar el alta", async () => {
    await setPending2faSecret("7", "SECRETO123");

    expect(redis.store.get("2fa_pending:7")).toBe("SECRETO123");
    expect(redis.ttls.get("2fa_pending:7")).toBe(900);
  });

  it("se lee desde la misma clave", async () => {
    await setPending2faSecret("7", "SECRETO123");

    expect(await getPending2faSecret("7")).toBe("SECRETO123");
  });

  it("cada usuario ve solo el suyo", async () => {
    await setPending2faSecret("7", "DE-SIETE");
    await setPending2faSecret("8", "DE-OCHO");

    expect(await getPending2faSecret("7")).toBe("DE-SIETE");
    expect(await getPending2faSecret("8")).toBe("DE-OCHO");
    expect(await getPending2faSecret("9")).toBeNull();
  });

  it("un nuevo setup sustituye al anterior", async () => {
    await setPending2faSecret("7", "PRIMERO");
    await setPending2faSecret("7", "SEGUNDO");

    expect(await getPending2faSecret("7")).toBe("SEGUNDO");
  });

  it("se borra al invalidarlo, y deja de poder leerse", async () => {
    await setPending2faSecret("7", "SECRETO123");

    await delPending2faSecret("7");

    expect(await getPending2faSecret("7")).toBeNull();
  });

  it("caduca con el TTL", async () => {
    await setPending2faSecret("7", "SECRETO123");
    redis.expireNow("2fa_pending:7");

    expect(await getPending2faSecret("7")).toBeNull();
  });
});

describe("sin Redis o con Redis caído", () => {
  it("guardar no rompe el alta (el QR quedará no disponible) y deja rastro si Redis falla", async () => {
    redis.down();
    const { logger } = await import("@/lib/observability/logger");

    await expect(setPending2faSecret("7", "SECRETO123")).resolves.toBeUndefined();

    expect(logger.error).toHaveBeenCalledWith(
      expect.objectContaining({ event: "auth.2fa_pending.redis_error", op: "set" }),
      expect.any(String),
    );
  });

  it("guardar sin Redis tampoco rompe", async () => {
    vi.mocked(getRedis).mockReturnValue(null);

    await expect(setPending2faSecret("7", "SECRETO123")).resolves.toBeUndefined();
  });

  it("leer devuelve null: falla CERRADO, nunca un secreto inventado", async () => {
    await setPending2faSecret("7", "SECRETO123");

    redis.down();
    expect(await getPending2faSecret("7")).toBeNull();

    vi.mocked(getRedis).mockReturnValue(null);
    expect(await getPending2faSecret("7")).toBeNull();
  });

  it("borrar no lanza si Redis falla", async () => {
    redis.down();

    await expect(delPending2faSecret("7")).resolves.toBeUndefined();
  });

  it("los errores de Redis no registran el secreto", async () => {
    redis.down();
    const { logger } = await import("@/lib/observability/logger");
    vi.mocked(logger.error).mockClear();

    await setPending2faSecret("7", "SECRETO-NO-LOGUEAR");
    await getPending2faSecret("7");

    const logged = JSON.stringify(vi.mocked(logger.error).mock.calls);
    expect(logged).not.toContain("SECRETO-NO-LOGUEAR");
  });
});
