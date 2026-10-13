import { beforeEach, describe, expect, it, vi } from "vitest";
import { decodeJwt } from "jose";
import { FakeRedis } from "../../../../../tests/helpers/fake-redis";
import { getRedis } from "@/lib/redis/client";
import { fetchGraphQL } from "@/lib/graphql-client";
import { getPendingLogin } from "@/lib/auth/pending-login";
import { syncAuthenticatedCart } from "@/lib/woocommerce/cart-session";
import { claimGuestOrder } from "@/lib/checkout/guest-order-claim";
import { POST } from "./route";

// ============================================================================
//  POST /api/auth/login con 2FA activo.
//
//  Regresión crítica: el `ephemeralToken` que recibía el navegador era un JWT
//  FIRMADO (no cifrado) con el authToken y el refreshToken reales en su payload.
//  Bastaba decodificarlo en base64 —sin conocer ningún secreto— para obtener la
//  sesión completa sin pasar por el segundo factor. Ahora es un id opaco y las
//  credenciales se quedan en Redis.
// ============================================================================

const AUTH = "AUTH-TOKEN-REAL.firma.xyz";
const REFRESH = "REFRESH-TOKEN-REAL";

const cookieSet = vi.fn();
vi.mock("next/headers", () => ({ cookies: async () => ({ set: cookieSet }) }));
vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/woocommerce/cart-session", () => ({
  syncAuthenticatedCart: vi.fn(async () => undefined),
}));
vi.mock("@/lib/checkout/guest-order-claim", () => ({
  claimGuestOrder: vi.fn(async () => "no_credential"),
}));
vi.mock("@/lib/graphql-client", () => ({ fetchGraphQL: vi.fn() }));
vi.mock("@/lib/redis/client", () => ({ getRedis: vi.fn() }));
vi.mock("@/lib/auth/jwt", () => ({ getTokenMaxAgeSeconds: () => 300 }));
// Config del sitio con el login por teléfono apagado (el caso de estos tests).
vi.mock("@/lib/config", () => ({
  getSiteConfig: async () => ({
    auth: { phone_login_enabled: false, phone_default_dial_code: "" },
  }),
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

let redis: FakeRedis;

function loginRequest(): Request {
  return new Request("https://tienda.example/api/auth/login", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify({ username: "ana", password: "una-clave-larga" }),
  });
}

/** Responde al estado 2FA (el único fetch que hace la ruta). */
function stubTwoFactorStatus(enabled: boolean | "error") {
  vi.stubGlobal(
    "fetch",
    vi.fn(async () =>
      enabled === "error"
        ? new Response("boom", { status: 500 })
        : new Response(JSON.stringify({ enabled }), { status: 200 }),
    ) as unknown as typeof fetch,
  );
}

beforeEach(() => {
  vi.unstubAllGlobals();
  vi.mocked(syncAuthenticatedCart).mockClear();
  vi.mocked(claimGuestOrder).mockClear();
  cookieSet.mockClear();
  redis = new FakeRedis();
  vi.mocked(getRedis).mockReturnValue(redis as never);
  vi.mocked(fetchGraphQL).mockResolvedValue({
    login: {
      authToken: AUTH,
      refreshToken: REFRESH,
      user: { id: "dXNlcjo3", databaseId: 7, email: "ana@example.com" },
    },
  });
});

describe("login con 2FA activo", () => {
  beforeEach(() => stubTwoFactorStatus(true));

  it("pide el segundo factor y entrega un id opaco, no un JWT", async () => {
    const res = await POST(loginRequest());
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.requires_2fa).toBe(true);
    expect(body.ephemeralToken).toMatch(/^[0-9a-f-]{36}$/);
    // No hay nada que decodificar: no es un JWT.
    expect(() => decodeJwt(body.ephemeralToken)).toThrow();
  });

  it("la respuesta no contiene NINGÚN token real, ni en claro ni en base64", async () => {
    const res = await POST(loginRequest());
    const text = await res.text();

    for (const secret of [AUTH, REFRESH]) {
      expect(text).not.toContain(secret);
      expect(text).not.toContain(Buffer.from(secret).toString("base64"));
      expect(text).not.toContain(Buffer.from(secret).toString("base64url"));
    }
    expect(text).not.toMatch(/authToken|refreshToken/);
  });

  it("no abre sesión: no se escribe ninguna cookie antes del segundo factor", async () => {
    await POST(loginRequest());

    expect(cookieSet).not.toHaveBeenCalled();
  });

  it("las credenciales quedan en el servidor, reclamables con ese id", async () => {
    const body = await (await POST(loginRequest())).json();

    expect(await getPendingLogin(body.ephemeralToken)).toEqual({
      authToken: AUTH,
      refreshToken: REFRESH,
      userId: "7",
    });
  });

  it("sin Redis falla CERRADO: 503 y ningún token en la respuesta", async () => {
    vi.mocked(getRedis).mockReturnValue(null);

    const res = await POST(loginRequest());
    const text = await res.text();

    expect(res.status).toBe(503);
    expect(text).not.toContain(AUTH);
    expect(text).not.toContain(REFRESH);
    expect(text).not.toContain("ephemeralToken");
    expect(cookieSet).not.toHaveBeenCalled();
  });

  it("con Redis caído falla CERRADO: 503 y ningún token en la respuesta", async () => {
    redis.down();

    const res = await POST(loginRequest());
    const text = await res.text();

    expect(res.status).toBe(503);
    expect(text).not.toContain(AUTH);
    expect(text).not.toContain(REFRESH);
    expect(cookieSet).not.toHaveBeenCalled();
  });
});

describe("login sin 2FA", () => {
  it("abre la sesión con cookies y no crea ningún login pendiente", async () => {
    stubTwoFactorStatus(false);

    const res = await POST(loginRequest());

    expect(res.status).toBe(200);
    expect((await res.json()).user.databaseId).toBe(7);
    const names = cookieSet.mock.calls.map((c) => c[0]);
    expect(names).toHaveLength(2);
    expect(cookieSet.mock.calls.map((c) => c[1])).toEqual([AUTH, REFRESH]);
    expect(redis.store.size).toBe(0);
  });

  it("al abrir sesión carga el carrito de la cuenta y liga el pedido de invitado", async () => {
    stubTwoFactorStatus(false);

    await POST(loginRequest());

    expect(syncAuthenticatedCart).toHaveBeenCalledWith(AUTH);
    expect(claimGuestOrder).toHaveBeenCalledWith(7);
  });

  it("con 2FA pendiente aún no hay sesión: ni carrito de la cuenta ni pedido de invitado", async () => {
    stubTwoFactorStatus(true);

    await POST(loginRequest());

    expect(syncAuthenticatedCart).not.toHaveBeenCalled();
    expect(claimGuestOrder).not.toHaveBeenCalled();
  });

  it("si no se puede saber si hay 2FA, falla cerrado (503) y no abre sesión", async () => {
    stubTwoFactorStatus("error");

    const res = await POST(loginRequest());

    expect(res.status).toBe(503);
    expect(cookieSet).not.toHaveBeenCalled();
  });
});
