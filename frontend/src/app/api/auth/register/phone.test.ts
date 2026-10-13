import { beforeEach, describe, expect, it, vi } from "vitest";
import { fetchGraphQL } from "@/lib/graphql-client";
import { getSiteConfig } from "@/lib/config";
import { attachPhoneToAccount, isPhoneAvailable } from "@/lib/auth/phone-account";
import { POST } from "./route";

// ============================================================================
//  POST /api/auth/register con teléfono (`auth.phone_login_enabled`).
// ============================================================================

vi.mock("@/lib/analytics/consent-ledger", () => ({
  linkConsentReceipt: vi.fn(async () => undefined),
}));
vi.mock("next/headers", () => ({ cookies: async () => ({ set: vi.fn() }) }));
vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/graphql-client", () => ({ fetchGraphQL: vi.fn() }));
vi.mock("@/lib/auth/jwt", () => ({ getTokenMaxAgeSeconds: () => 300 }));
vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn() }));
vi.mock("@/lib/auth/phone-account", () => ({
  isPhoneAvailable: vi.fn(),
  attachPhoneToAccount: vi.fn(async () => true),
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const register = (phone?: string) =>
  POST(
    new Request("https://tienda.example/api/auth/register", {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({
        username: "ana",
        email: "ana@example.com",
        password: "una-clave-larga-1",
        ...(phone !== undefined ? { phone } : {}),
      }),
    }),
  );

const flag = (enabled: boolean) =>
  vi.mocked(getSiteConfig).mockResolvedValue({
    auth: { phone_login_enabled: enabled, phone_default_dial_code: "" },
  } as never);

const created = () =>
  vi
    .mocked(fetchGraphQL)
    .mock.calls.some(([query]) => String(query).includes("registerUser"));

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
            user: { id: "u1", databaseId: 7, email: "ana@example.com" },
          },
        }
      : { registerUser: { user: { id: "u1" } } }) as never);
});

describe("registro con teléfono", () => {
  it("con el flag y el teléfono libre, crea la cuenta y le guarda el teléfono", async () => {
    flag(true);
    vi.mocked(isPhoneAvailable).mockResolvedValue(true);

    const res = await register("+34600112233");

    expect(res.status).toBe(201);
    expect(attachPhoneToAccount).toHaveBeenCalledWith(7, "+34600112233");
  });

  it("un teléfono en uso responde 409 sin crear la cuenta", async () => {
    flag(true);
    vi.mocked(isPhoneAvailable).mockResolvedValue(false);

    const res = await register("+34600112233");

    expect(res.status).toBe(409);
    expect(created()).toBe(false);
  });

  it("si no se puede comprobar el teléfono, no se crea la cuenta a ciegas (503)", async () => {
    flag(true);
    vi.mocked(isPhoneAvailable).mockResolvedValue(null);

    expect((await register("+34600112233")).status).toBe(503);
    expect(created()).toBe(false);
  });

  it("con el flag apagado, un teléfono se rechaza (422)", async () => {
    flag(false);

    expect((await register("+34600112233")).status).toBe(422);
    expect(isPhoneAvailable).not.toHaveBeenCalled();
  });

  it("un teléfono mal formado es un 422 de validación", async () => {
    expect((await register("600112233")).status).toBe(422);
  });

  it("sin teléfono el registro no consulta la config", async () => {
    expect((await register()).status).toBe(201);
    expect(getSiteConfig).not.toHaveBeenCalled();
  });
});
