import { beforeEach, describe, expect, it, vi } from "vitest";
import { FakeRedis } from "../../../tests/helpers/fake-redis";
import { getRedis } from "@/lib/redis/client";
import {
  PENDING_LOGIN_MAX_ATTEMPTS,
  consumePendingLogin,
  createPendingLogin,
  getPendingLogin,
  recordFailedAttempt,
} from "./pending-login";

// ============================================================================
//  Login pendiente de 2FA (lib/auth/pending-login.ts).
//
//  Antes, el paso entre "contraseña correcta" y "2FA verificado" viajaba al
//  navegador como un JWT FIRMADO (no cifrado) con el authToken y el
//  refreshToken reales en su payload: cualquiera lo decodificaba en base64 sin
//  conocer el secreto y obtenía la sesión completa, saltándose el segundo
//  factor. Ahora las credenciales viven en Redis y el cliente solo recibe un id
//  opaco.
// ============================================================================

vi.mock("@/lib/redis/client", () => ({ getRedis: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const DATA = { authToken: "AT-real", refreshToken: "RT-real", userId: "7" };

let redis: FakeRedis;
beforeEach(() => {
  redis = new FakeRedis();
  vi.mocked(getRedis).mockReturnValue(redis as never);
});

describe("createPendingLogin", () => {
  it("devuelve un id opaco que no contiene ni deriva de las credenciales", async () => {
    const id = await createPendingLogin(DATA);

    expect(id).toMatch(/^[0-9a-f-]{36}$/);
    expect(id).not.toContain("AT-real");
    expect(id).not.toContain("RT-real");
    // No es un JWT: nada que decodificar en el navegador.
    expect(id!.split(".")).toHaveLength(1);
  });

  it("guarda las credenciales en Redis con un TTL corto", async () => {
    const id = await createPendingLogin(DATA);

    const key = [...redis.store.keys()].find((k) => k.includes(id!));
    expect(key).toBeDefined();
    expect(JSON.parse(redis.store.get(key!)!)).toEqual(DATA);
    expect(redis.ttls.get(key!)).toBeLessThanOrEqual(300);
    expect(redis.ttls.get(key!)).toBeGreaterThan(0);
  });

  it("cada login pendiente recibe un id distinto", async () => {
    const a = await createPendingLogin(DATA);
    const b = await createPendingLogin(DATA);

    expect(a).not.toBe(b);
  });

  it("sin Redis falla CERRADO: no emite nada", async () => {
    vi.mocked(getRedis).mockReturnValue(null);

    expect(await createPendingLogin(DATA)).toBeNull();
  });

  it("si Redis rechaza el comando falla cerrado", async () => {
    redis.down();

    expect(await createPendingLogin(DATA)).toBeNull();
  });
});

describe("getPendingLogin", () => {
  it("devuelve las credenciales SIN consumirlas: un código mal tecleado se puede reintentar", async () => {
    const id = (await createPendingLogin(DATA))!;

    expect(await getPendingLogin(id)).toEqual(DATA);
    expect(await getPendingLogin(id)).toEqual(DATA);
  });

  it("un id inventado no existe", async () => {
    expect(await getPendingLogin("00000000-0000-0000-0000-000000000000")).toBeNull();
  });

  it("un id caducado no existe", async () => {
    const id = (await createPendingLogin(DATA))!;
    for (const key of redis.store.keys()) redis.expireNow(key);

    expect(await getPendingLogin(id)).toBeNull();
  });

  it("rechaza un valor corrupto en Redis en lugar de propagarlo", async () => {
    const id = (await createPendingLogin(DATA))!;
    const key = [...redis.store.keys()].find((k) => k.includes(id))!;

    redis.store.set(key, "{no es json");
    expect(await getPendingLogin(id)).toBeNull();

    redis.store.set(key, JSON.stringify({ authToken: "x" })); // faltan campos
    expect(await getPendingLogin(id)).toBeNull();
  });

  it("sin Redis o con Redis caído no devuelve nada", async () => {
    const id = (await createPendingLogin(DATA))!;

    redis.down();
    expect(await getPendingLogin(id)).toBeNull();

    vi.mocked(getRedis).mockReturnValue(null);
    expect(await getPendingLogin(id)).toBeNull();
  });
});

describe("consumePendingLogin: un solo uso", () => {
  it("entrega las credenciales una vez y la siguiente llamada no obtiene nada", async () => {
    const id = (await createPendingLogin(DATA))!;

    expect(await consumePendingLogin(id)).toEqual(DATA);
    expect(await consumePendingLogin(id)).toBeNull();
    expect(await getPendingLogin(id)).toBeNull();
  });

  it("de dos reclamaciones simultáneas solo una recibe las credenciales", async () => {
    const id = (await createPendingLogin(DATA))!;

    const [a, b] = await Promise.all([
      consumePendingLogin(id),
      consumePendingLogin(id),
    ]);

    expect([a, b].filter(Boolean)).toHaveLength(1);
  });

  it("un id inexistente no entrega nada", async () => {
    expect(await consumePendingLogin("no-existe")).toBeNull();
  });

  it("con Redis caído no entrega nada", async () => {
    const id = (await createPendingLogin(DATA))!;
    redis.down();

    expect(await consumePendingLogin(id)).toBeNull();
  });
});

describe("recordFailedAttempt: tope de intentos por login pendiente", () => {
  it("permite reintentar hasta el tope y entonces invalida el login pendiente", async () => {
    const id = (await createPendingLogin(DATA))!;

    for (let i = 1; i < PENDING_LOGIN_MAX_ATTEMPTS; i++) {
      expect(await recordFailedAttempt(id), `intento fallido ${i}`).toBe(false);
      expect(await getPendingLogin(id)).toEqual(DATA);
    }

    // El último intento agota el cupo: se bloquea y se borra.
    expect(await recordFailedAttempt(id)).toBe(true);
    expect(await getPendingLogin(id)).toBeNull();
  });

  it("cada login pendiente lleva su propia cuenta", async () => {
    const a = (await createPendingLogin(DATA))!;
    const b = (await createPendingLogin(DATA))!;

    for (let i = 0; i < PENDING_LOGIN_MAX_ATTEMPTS; i++) await recordFailedAttempt(a);

    expect(await getPendingLogin(a)).toBeNull();
    expect(await getPendingLogin(b)).toEqual(DATA);
  });

  it("con Redis caído cuenta como bloqueado (falla cerrado)", async () => {
    const id = (await createPendingLogin(DATA))!;
    redis.down();

    expect(await recordFailedAttempt(id)).toBe(true);
  });
});
