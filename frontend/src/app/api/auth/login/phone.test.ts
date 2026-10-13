import { beforeEach, describe, expect, it, vi } from "vitest";
import { fetchGraphQL } from "@/lib/graphql-client";
import { getSiteConfig } from "@/lib/config";
import { resolvePhoneToLogin } from "@/lib/auth/phone-account";
import { POST } from "./route";

// ============================================================================
//  POST /api/auth/login con el teléfono como usuario (`auth.phone_login_enabled`).
// ============================================================================

vi.mock("next/headers", () => ({ cookies: async () => ({ set: vi.fn() }) }));
vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/graphql-client", () => ({ fetchGraphQL: vi.fn() }));
vi.mock("@/lib/auth/jwt", () => ({ getTokenMaxAgeSeconds: () => 300 }));
vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn() }));
vi.mock("@/lib/auth/phone-account", () => ({ resolvePhoneToLogin: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const login = (username: string) =>
  POST(
    new Request("https://tienda.example/api/auth/login", {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ username, password: "una-clave-larga" }),
    }),
  );

const config = (enabled: boolean, dial = "") =>
  vi.mocked(getSiteConfig).mockResolvedValue({
    auth: { phone_login_enabled: enabled, phone_default_dial_code: dial },
  } as never);

beforeEach(() => {
  vi.clearAllMocks();
  vi.stubGlobal(
    "fetch",
    vi.fn(async () => Response.json({ enabled: false })),
  );
  vi.mocked(fetchGraphQL).mockResolvedValue({
    login: {
      authToken: "a.b.c",
      refreshToken: "r",
      user: { id: "x", databaseId: 7, email: "ana@example.com" },
    },
  });
});

const loginVariables = () => vi.mocked(fetchGraphQL).mock.calls[0][1]?.variables;

describe("login por teléfono", () => {
  it("con el flag encendido, traduce el teléfono al login de la cuenta", async () => {
    config(true, "+34");
    vi.mocked(resolvePhoneToLogin).mockResolvedValue("ana@example.com");

    const res = await login("600 11 22 33");

    expect(res.status).toBe(200);
    expect(resolvePhoneToLogin).toHaveBeenCalledWith("+34600112233");
    expect(loginVariables()).toEqual({
      username: "ana@example.com",
      password: "una-clave-larga",
    });
  });

  it("un teléfono que no es de nadie responde como credenciales inválidas", async () => {
    config(true);
    vi.mocked(resolvePhoneToLogin).mockResolvedValue(null);

    const res = await login("+34600112233");

    expect(res.status).toBe(401);
    expect(fetchGraphQL).not.toHaveBeenCalled();
  });

  it("con el flag apagado no se consulta ningún teléfono", async () => {
    config(false);

    await login("+34600112233");

    expect(resolvePhoneToLogin).not.toHaveBeenCalled();
    expect(loginVariables()).toEqual({
      username: "+34600112233",
      password: "una-clave-larga",
    });
  });
});
