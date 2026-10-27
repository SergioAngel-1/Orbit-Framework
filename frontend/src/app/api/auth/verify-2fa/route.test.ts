import { beforeEach, describe, expect, it, vi } from "vitest";
import { SignJWT } from "jose";
import { FakeRedis } from "../../../../../tests/helpers/fake-redis";
import { getRedis } from "@/lib/redis/client";
import {
  PENDING_LOGIN_MAX_ATTEMPTS,
  createPendingLogin,
  getPendingLogin,
} from "@/lib/auth/pending-login";
import { syncAuthenticatedCart } from "@/lib/woocommerce/cart-session";
import { claimGuestOrder } from "@/lib/checkout/guest-order-claim";
import { linkConsentReceipt } from "@/lib/analytics/consent-ledger";
import { POST } from "./route";

// ============================================================================
//  POST /api/auth/verify-2fa: segundo paso del login con 2FA.
//
//  El `ephemeralToken` es un id opaco de un solo uso que apunta a credenciales
//  guardadas en Redis. Se rechaza un id inventado, caducado o ya usado, y un
//  JWT firmado con el formato antiguo ya NO vale como credencial.
// ============================================================================

const AUTH = "AUTH-TOKEN-REAL.firma.xyz";
const REFRESH = "REFRESH-TOKEN-REAL";
const GOOD_TOTP = "123456";
// Formato real: 32 hex en grupos de 4 (128 bits).
const GOOD_RECOVERY = "0123-4567-89ab-cdef-0123-4567-89ab-cdef";

const cookieSet = vi.fn();
vi.mock("next/headers", () => ({ cookies: async () => ({ set: cookieSet }) }));
vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/woocommerce/cart-session", () => ({
  syncAuthenticatedCart: vi.fn(async () => undefined),
}));
vi.mock("@/lib/checkout/guest-order-claim", () => ({
  claimGuestOrder: vi.fn(async () => "no_credential"),
}));
vi.mock("@/lib/analytics/consent-ledger", () => ({
  linkConsentReceipt: vi.fn(async () => undefined),
}));
vi.mock("@/lib/redis/client", () => ({ getRedis: vi.fn() }));
vi.mock("@/lib/auth/jwt", () => ({ getTokenMaxAgeSeconds: () => 300 }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));
vi.mock("otplib", () => ({
  verify: vi.fn(async ({ token }: { token: string }) => ({
    valid: token === "123456",
  })),
}));

let redis: FakeRedis;

function verifyRequest(body: unknown): Request {
  return new Request("https://tienda.example/api/auth/verify-2fa", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify(body),
  });
}

/** WordPress: estado 2FA, secreto TOTP y verificación de códigos de recuperación. */
function stubWordPress() {
  vi.stubGlobal(
    "fetch",
    vi.fn(async (url: string, init?: RequestInit) => {
      if (url.includes("/2fa-status/")) return Response.json({ enabled: true });
      if (url.includes("/2fa-secret"))
        return Response.json({ secret: "SECRETO", enabled: true });
      if (url.includes("/2fa-recovery/verify")) {
        const { code } = JSON.parse(String(init?.body)) as { code: string };
        return new Response("{}", { status: code === GOOD_RECOVERY ? 200 : 401 });
      }
      return new Response("not found", { status: 404 });
    }) as unknown as typeof fetch,
  );
}

async function newPendingLogin(): Promise<string> {
  const id = await createPendingLogin({
    authToken: AUTH,
    refreshToken: REFRESH,
    userId: "7",
  });
  if (!id) throw new Error("no se pudo crear el login pendiente del test");
  return id;
}

beforeEach(() => {
  vi.unstubAllGlobals();
  vi.mocked(syncAuthenticatedCart).mockClear();
  vi.mocked(claimGuestOrder).mockClear();
  vi.mocked(linkConsentReceipt).mockClear();
  cookieSet.mockClear();
  redis = new FakeRedis();
  vi.mocked(getRedis).mockReturnValue(redis as never);
  stubWordPress();
});

describe("verify-2fa: el id opaco", () => {
  it("con un código TOTP válido abre la sesión con las credenciales guardadas", async () => {
    const id = await newPendingLogin();

    const res = await POST(verifyRequest({ ephemeralToken: id, code: GOOD_TOTP }));

    expect(res.status).toBe(200);
    expect(cookieSet.mock.calls.map((c) => c[1])).toEqual([AUTH, REFRESH]);
  });

  it("al abrir la sesión carga el carrito de la cuenta y liga el pedido de invitado", async () => {
    const id = await newPendingLogin();

    await POST(verifyRequest({ ephemeralToken: id, code: GOOD_TOTP }));

    expect(syncAuthenticatedCart).toHaveBeenCalledWith(AUTH, "7");
    expect(claimGuestOrder).toHaveBeenCalledWith("7");
    expect(linkConsentReceipt).toHaveBeenCalledWith(expect.any(Request), "7");
  });

  it("un código inválido no abre sesión: ni carrito ni pedido de invitado", async () => {
    const id = await newPendingLogin();

    await POST(verifyRequest({ ephemeralToken: id, code: "000000" }));

    expect(syncAuthenticatedCart).not.toHaveBeenCalled();
    expect(claimGuestOrder).not.toHaveBeenCalled();
    expect(linkConsentReceipt).not.toHaveBeenCalled();
  });

  it("es de UN SOLO USO: tras verificar, el mismo id ya no vale", async () => {
    const id = await newPendingLogin();
    await POST(verifyRequest({ ephemeralToken: id, code: GOOD_TOTP }));
    cookieSet.mockClear();

    const again = await POST(verifyRequest({ ephemeralToken: id, code: GOOD_TOTP }));

    expect(again.status).toBe(401);
    expect(cookieSet).not.toHaveBeenCalled();
    expect(await getPendingLogin(id)).toBeNull();
  });

  it("dos verificaciones simultáneas con un código válido abren UNA sola sesión", async () => {
    const id = await newPendingLogin();

    const [a, b] = await Promise.all([
      POST(verifyRequest({ ephemeralToken: id, code: GOOD_TOTP })),
      POST(verifyRequest({ ephemeralToken: id, code: GOOD_TOTP })),
    ]);

    expect([a.status, b.status].sort()).toEqual([200, 401]);
    expect(cookieSet).toHaveBeenCalledTimes(2); // authToken + refreshToken, una vez
  });

  it("un id inventado se rechaza", async () => {
    const res = await POST(
      verifyRequest({
        ephemeralToken: "00000000-0000-0000-0000-000000000000",
        code: GOOD_TOTP,
      }),
    );

    expect(res.status).toBe(401);
    expect(cookieSet).not.toHaveBeenCalled();
  });

  it("un id caducado se rechaza", async () => {
    const id = await newPendingLogin();
    for (const key of redis.store.keys()) redis.expireNow(key);

    const res = await POST(verifyRequest({ ephemeralToken: id, code: GOOD_TOTP }));

    expect(res.status).toBe(401);
  });

  it("un JWT firmado con el formato antiguo ya NO vale como credencial", async () => {
    // Es exactamente lo que el login emitía antes: tokens reales dentro de un JWT.
    const legacy = await new SignJWT({
      authToken: AUTH,
      refreshToken: REFRESH,
      userId: "7",
    })
      .setProtectedHeader({ alg: "HS256" })
      .setExpirationTime("5m")
      .sign(new TextEncoder().encode("fallback-dev-secret-change-in-prod"));

    const res = await POST(verifyRequest({ ephemeralToken: legacy, code: GOOD_TOTP }));

    expect(res.status).toBe(401);
    expect(cookieSet).not.toHaveBeenCalled();
  });

  it("sin Redis falla cerrado: no abre sesión", async () => {
    const id = await newPendingLogin();
    vi.mocked(getRedis).mockReturnValue(null);

    const res = await POST(verifyRequest({ ephemeralToken: id, code: GOOD_TOTP }));

    expect(res.status).toBe(401);
    expect(cookieSet).not.toHaveBeenCalled();
  });
});

describe("verify-2fa: código incorrecto", () => {
  it("rechaza (401) y deja reintentar con el mismo id", async () => {
    const id = await newPendingLogin();

    const bad = await POST(verifyRequest({ ephemeralToken: id, code: "000000" }));
    expect(bad.status).toBe(401);
    expect(cookieSet).not.toHaveBeenCalled();

    const retry = await POST(verifyRequest({ ephemeralToken: id, code: GOOD_TOTP }));
    expect(retry.status).toBe(200);
  });

  it("tras agotar los intentos invalida el login: ni el código correcto sirve ya", async () => {
    const id = await newPendingLogin();

    let last = 0;
    for (let i = 0; i < PENDING_LOGIN_MAX_ATTEMPTS; i++) {
      last = (await POST(verifyRequest({ ephemeralToken: id, code: "000000" }))).status;
    }
    expect(last).toBe(429);

    const good = await POST(verifyRequest({ ephemeralToken: id, code: GOOD_TOTP }));
    expect(good.status).toBe(401);
    expect(cookieSet).not.toHaveBeenCalled();
  });

  it("un código de formato inválido se rechaza sin gastar un intento", async () => {
    const id = await newPendingLogin();

    const res = await POST(verifyRequest({ ephemeralToken: id, code: "12ab" }));

    expect(res.status).toBe(422);
    expect(await getPendingLogin(id)).not.toBeNull();
    expect(redis.store.has(`pending_login_attempts:${id}`)).toBe(false);
  });
});

describe("verify-2fa: código de recuperación", () => {
  it("uno válido abre la sesión y consume el login pendiente", async () => {
    const id = await newPendingLogin();

    const res = await POST(
      verifyRequest({ ephemeralToken: id, recoveryCode: GOOD_RECOVERY }),
    );

    expect(res.status).toBe(200);
    expect(cookieSet.mock.calls.map((c) => c[1])).toEqual([AUTH, REFRESH]);
    expect(await getPendingLogin(id)).toBeNull();
  });

  it("uno inválido se rechaza, cuenta como intento y no abre sesión", async () => {
    const id = await newPendingLogin();

    const res = await POST(verifyRequest({ ephemeralToken: id, recoveryCode: "NOPE" }));

    expect(res.status).toBe(401);
    expect(cookieSet).not.toHaveBeenCalled();
    expect(redis.store.get(`pending_login_attempts:${id}`)).toBe("1");
  });
});
