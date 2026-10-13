import { beforeEach, describe, expect, it, vi } from "vitest";
import { fetchGraphQL } from "@/lib/graphql-client";
import { getSiteConfig } from "@/lib/config";
import { getGeoPack } from "@/lib/geo";
import { attachIdentity, isDocumentAvailable } from "@/lib/customer/identity-api";
import { CO_PACK, FALLBACK_ES_PACK } from "../../../../../tests/helpers/geo-pack";
import { POST } from "./route";

// ============================================================================
//  POST /api/auth/register con el módulo `customer-identity`: documento (único
//  entre cuentas, comprobado ANTES de crear la cuenta) y aceptación de términos.
// ============================================================================

vi.mock("@/lib/analytics/consent-ledger", () => ({
  linkConsentReceipt: vi.fn(async () => undefined),
}));
vi.mock("next/headers", () => ({ cookies: async () => ({ set: vi.fn() }) }));
vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/graphql-client", () => ({ fetchGraphQL: vi.fn() }));
vi.mock("@/lib/auth/jwt", () => ({ getTokenMaxAgeSeconds: () => 300 }));
vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn() }));
vi.mock("@/lib/geo", () => ({ getGeoPack: vi.fn() }));
vi.mock("@/lib/customer/identity-api", () => ({
  isDocumentAvailable: vi.fn(),
  attachIdentity: vi.fn(),
}));
vi.mock("@/lib/woocommerce/cart-session", () => ({
  syncAuthenticatedCart: vi.fn(async () => undefined),
}));
vi.mock("@/lib/checkout/guest-order-claim", () => ({
  claimGuestOrder: vi.fn(async () => "no_credential"),
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const DOC = { documentType: "CC", documentNumber: "1.012.345.678" };

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

const created = () =>
  vi
    .mocked(fetchGraphQL)
    .mock.calls.some(([query]) => String(query).includes("registerUser"));

function moduleOn(on: boolean) {
  vi.mocked(getSiteConfig).mockResolvedValue({
    auth: { phone_login_enabled: false, phone_default_dial_code: "" },
    modules: { "customer-identity": on },
  } as never);
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.stubGlobal(
    "fetch",
    vi.fn(async () => Response.json({ ok: true })),
  );
  vi.mocked(fetchGraphQL).mockImplementation((async (query: string) =>
    query.includes("login")
      ? {
          login: {
            authToken: "AUTH",
            refreshToken: "REFRESH",
            user: { id: "u1", databaseId: 21 },
          },
        }
      : { registerUser: { user: { id: "u1" } } }) as never);
  vi.mocked(getGeoPack).mockResolvedValue(CO_PACK);
  vi.mocked(isDocumentAvailable).mockResolvedValue(true);
  vi.mocked(attachIdentity).mockResolvedValue({
    ok: true,
    identity: {
      documentType: "CC",
      documentNumber: "1012345678",
      termsAcceptedAt: "t",
    },
  });
  moduleOn(true);
});

describe("registro con documento y términos", () => {
  it("con el módulo apagado no pide nada y no consulta el paquete de país", async () => {
    moduleOn(false);

    const res = await register();

    expect(res.status).toBe(201);
    expect(getGeoPack).not.toHaveBeenCalled();
    expect(attachIdentity).not.toHaveBeenCalled();
    expect(isDocumentAvailable).not.toHaveBeenCalled();
  });

  it("encendido, exige documento y términos antes de crear la cuenta (422 por campo)", async () => {
    const res = await register();

    expect(res.status).toBe(422);
    expect(Object.keys((await res.json()).details).sort()).toEqual([
      "identity",
      "terms_accepted",
    ]);
    expect(created()).toBe(false);
  });

  it("un documento que no cuadra con el paquete se rechaza", async () => {
    const res = await register({
      identity: { documentType: "CC", documentNumber: "12" },
      terms_accepted: true,
    });

    expect(res.status).toBe(422);
    expect(created()).toBe(false);
  });

  it("un documento de otra cuenta es un 409 y no se crea el usuario", async () => {
    vi.mocked(isDocumentAvailable).mockResolvedValue(false);

    const res = await register({ identity: DOC, terms_accepted: true });

    expect(res.status).toBe(409);
    expect((await res.json()).details.identity).toEqual([
      "Este documento ya está registrado.",
    ]);
    expect(created()).toBe(false);
  });

  it("si no se puede comprobar la unicidad, 503 y tampoco se crea el usuario", async () => {
    vi.mocked(isDocumentAvailable).mockResolvedValue(null);

    expect((await register({ identity: DOC, terms_accepted: true })).status).toBe(503);
    expect(created()).toBe(false);
  });

  it("crea la cuenta y guarda documento (normalizado) y términos en ella", async () => {
    const res = await register({ identity: DOC, terms_accepted: true });

    expect(res.status).toBe(201);
    expect(isDocumentAvailable).toHaveBeenCalledWith("CC", "1012345678");
    expect(attachIdentity).toHaveBeenCalledWith(21, {
      documentType: "CC",
      documentNumber: "1012345678",
      termsAccepted: true,
    });
  });

  it("si WordPress no guarda la identidad, la cuenta sigue siendo válida", async () => {
    vi.mocked(attachIdentity).mockResolvedValue({ ok: false, reason: "unavailable" });

    expect((await register({ identity: DOC, terms_accepted: true })).status).toBe(201);
  });

  it("en un país sin documentos solo pide los términos", async () => {
    vi.mocked(getGeoPack).mockResolvedValue(FALLBACK_ES_PACK);

    expect((await register()).status).toBe(422);
    const res = await register({ terms_accepted: true });

    expect(res.status).toBe(201);
    expect(isDocumentAvailable).not.toHaveBeenCalled();
    expect(attachIdentity).toHaveBeenCalledWith(21, {
      documentType: undefined,
      documentNumber: undefined,
      termsAccepted: true,
    });
  });
});
