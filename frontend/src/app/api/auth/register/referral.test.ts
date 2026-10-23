import { beforeEach, describe, expect, it, vi } from "vitest";
import { fetchGraphQL } from "@/lib/graphql-client";
import { getSiteConfig } from "@/lib/config";
import { attributeReferral } from "@/lib/loyalty/server";
import { logger } from "@/lib/observability/logger";
import { POST } from "./route";

// ============================================================================
//  POST /api/auth/register con código de referido (spec §9.3).
//
//  El registro acepta `referral_code` en el cuerpo o, si no viene, la cookie
//  `hwe_ref` que dejó el proxy. La atribución es BEST-EFFORT y ocurre después
//  de crear la cuenta, con el id real de WordPress: un fallo se registra (sin
//  PII) y no rompe el alta. La cookie se retira siempre.
//
//  Con el módulo `loyalty` o «Referidos» apagados no se lee ni el cuerpo ni la
//  cookie: la decisión es del registro, no del proxy.
// ============================================================================

const cookieGet = vi.fn();
const cookieSet = vi.fn();

vi.mock("next/headers", () => ({
  cookies: async () => ({ get: cookieGet, set: cookieSet }),
}));
vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/graphql-client", () => ({ fetchGraphQL: vi.fn() }));
vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn() }));
vi.mock("@/lib/loyalty/server", () => ({ attributeReferral: vi.fn() }));
vi.mock("@/lib/auth/jwt", () => ({ getTokenMaxAgeSeconds: () => 300 }));
vi.mock("@/lib/woocommerce/cart-session", () => ({
  syncAuthenticatedCart: vi.fn(async () => undefined),
}));
vi.mock("@/lib/checkout/guest-order-claim", () => ({
  claimGuestOrder: vi.fn(async () => "no_credential"),
}));
vi.mock("@/lib/analytics/consent-ledger", () => ({
  linkConsentReceipt: vi.fn(async () => undefined),
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const USER_ID = 7;

const register = (extra: Record<string, unknown> = {}) =>
  POST(
    new Request("https://tienda.example/api/auth/register", {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({
        username: "ana",
        email: "ana@example.com",
        password: "una-clave-larga-1",
        ...extra,
      }),
    }),
  );

/** Configura el módulo `loyalty` y sus interruptores. */
function loyaltyConfig({
  module = true,
  points = true,
  referrals = true,
}: {
  module?: boolean;
  points?: boolean;
  referrals?: boolean;
} = {}) {
  vi.mocked(getSiteConfig).mockResolvedValue({
    auth: { phone_login_enabled: false, phone_default_dial_code: "" },
    modules: { loyalty: module },
    loyalty: { points_enabled: points, referrals_enabled: referrals },
  } as never);
}

/** Simula la cookie `hwe_ref` que dejó el proxy. */
function refCookie(value?: string) {
  cookieGet.mockImplementation((name: string) =>
    name === "hwe_ref" && value ? { name, value } : undefined,
  );
}

/** `set` de la cookie de referido, si la hubo. */
const refCookieWrites = () =>
  cookieSet.mock.calls.filter(([name]) => name === "hwe_ref");

beforeEach(() => {
  vi.clearAllMocks();
  vi.unstubAllGlobals();
  vi.stubGlobal(
    "fetch",
    vi.fn(async () => Response.json({ ok: true })),
  );
  cookieGet.mockReturnValue(undefined);
  vi.mocked(fetchGraphQL).mockImplementation((async (query: string) =>
    query.includes("login")
      ? {
          login: {
            authToken: "AUTH",
            refreshToken: "REFRESH",
            user: { id: "u1", databaseId: USER_ID, email: "ana@example.com" },
          },
        }
      : {
          registerUser: { user: { id: "u1", databaseId: USER_ID } },
        }) as never);
  vi.mocked(attributeReferral).mockResolvedValue({ ok: true });
  loyaltyConfig();
});

describe("atribución del referido en el registro", () => {
  it("usa el código del cuerpo y atribuye después de crear la cuenta", async () => {
    const res = await register({ referral_code: " abcd2345 " });

    expect(res.status).toBe(201);
    expect(attributeReferral).toHaveBeenCalledWith(USER_ID, "ABCD2345");

    // La cuenta se crea ANTES de atribuir: el id ya existe en WordPress.
    const registerCall = vi
      .mocked(fetchGraphQL)
      .mock.calls.findIndex(([query]) => String(query).includes("registerUser"));
    expect(registerCall).toBeGreaterThanOrEqual(0);
    expect(vi.mocked(attributeReferral).mock.invocationCallOrder[0]!).toBeGreaterThan(
      vi.mocked(fetchGraphQL).mock.invocationCallOrder[registerCall]!,
    );
  });

  it("sin código en el cuerpo usa la cookie que dejó el proxy", async () => {
    refCookie("qrst3456");

    const res = await register();

    expect(res.status).toBe(201);
    expect(attributeReferral).toHaveBeenCalledWith(USER_ID, "QRST3456");
  });

  it("el código del cuerpo gana a la cookie", async () => {
    refCookie("ZZZZ2222");

    await register({ referral_code: "ABCD2345" });

    expect(attributeReferral).toHaveBeenCalledTimes(1);
    expect(attributeReferral).toHaveBeenCalledWith(USER_ID, "ABCD2345");
  });

  it("un formato inválido en el cuerpo se ignora y no cae a la cookie", async () => {
    refCookie("ABCD2345");

    const res = await register({ referral_code: "no-es-un-código" });

    expect(res.status).toBe(201);
    expect(attributeReferral).not.toHaveBeenCalled();
  });

  it("un null en el cuerpo se trata como ausente y usa la cookie", async () => {
    refCookie("QRST3456");

    await register({ referral_code: null });

    expect(attributeReferral).toHaveBeenCalledWith(USER_ID, "QRST3456");
  });

  it("borra la cookie hwe_ref cuando la atribución sale bien", async () => {
    refCookie("ABCD2345");

    await register();

    expect(refCookieWrites()).toEqual([
      ["hwe_ref", "", expect.objectContaining({ maxAge: 0, path: "/" })],
    ]);
  });

  it("un fallo de la atribución no rompe el registro, se registra sin PII y borra la cookie", async () => {
    vi.mocked(attributeReferral).mockResolvedValue({
      ok: false,
      reason: "unavailable",
    });

    const res = await register({ referral_code: "ABCD2345" });

    expect(res.status).toBe(201);
    expect((await res.json()).user.databaseId).toBe(USER_ID);

    const events = [
      ...vi.mocked(logger.warn).mock.calls,
      ...vi.mocked(logger.error).mock.calls,
    ]
      .map((call) => call[0] as Record<string, unknown>)
      .filter((fields) => fields.event === "auth.register.referral_not_attributed");
    expect(events).toEqual([
      expect.objectContaining({ reason: "unavailable", userId: USER_ID }),
    ]);
    // Sin PII: ni el email ni el código completo.
    const logged = JSON.stringify(events);
    expect(logged).not.toContain("ana@example.com");
    expect(logged).not.toContain("ABCD2345");

    expect(refCookieWrites()).toHaveLength(1);
  });

  it("con el módulo apagado ignora el cuerpo y la cookie", async () => {
    loyaltyConfig({ module: false });
    refCookie("ABCD2345");

    const res = await register({ referral_code: "ABCD2345" });

    expect(res.status).toBe(201);
    expect(attributeReferral).not.toHaveBeenCalled();
    expect(refCookieWrites()).toHaveLength(0);
  });

  it("con «Referidos» apagado ignora el cuerpo y la cookie", async () => {
    loyaltyConfig({ points: true, referrals: false });
    refCookie("ABCD2345");

    const res = await register({ referral_code: "ABCD2345" });

    expect(res.status).toBe(201);
    expect(attributeReferral).not.toHaveBeenCalled();
    expect(refCookieWrites()).toHaveLength(0);
  });

  it("sin código ni cookie no atribuye nada y responde igual que hoy", async () => {
    const res = await register();

    expect(res.status).toBe(201);
    expect(attributeReferral).not.toHaveBeenCalled();
    expect(refCookieWrites()).toHaveLength(0);
  });
});
