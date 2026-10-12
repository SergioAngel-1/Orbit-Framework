import { beforeEach, describe, expect, it, vi } from "vitest";
import { logger } from "@/lib/observability/logger";
import { revokeAllSessions } from "./session";

// ============================================================================
//  revokeAllSessions: rota el secreto JWT del usuario en WordPress (invalida TODOS
//  sus refresh tokens). Devuelve si WordPress lo confirmó: tragarse el fallo, como
//  hacía la versión original, dejaba al usuario creyendo que había cerrado sus
//  otras sesiones cuando no era así.
// ============================================================================

vi.mock("next/headers", () => ({ cookies: vi.fn() }));
vi.mock("@/lib/graphql-client", () => ({ fetchGraphQL: vi.fn() }));
vi.mock("./jwt", () => ({ verifyAuthToken: vi.fn() }));
vi.mock("./revocation", () => ({ isTokenRevoked: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

beforeEach(() => {
  vi.clearAllMocks();
  vi.unstubAllGlobals();
});

describe("revokeAllSessions", () => {
  it("llama a logout-all de WordPress con el access token y devuelve true si responde OK", async () => {
    const fetchMock = vi.fn(async () => new Response("{}", { status: 200 }));
    vi.stubGlobal("fetch", fetchMock);

    await expect(revokeAllSessions("jwt-abc")).resolves.toBe(true);

    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toContain("/wp-json/hwe/v1/auth/logout-all");
    expect(init.method).toBe("POST");
    expect((init.headers as Record<string, string>).Authorization).toBe(
      "Bearer jwt-abc",
    );
  });

  it("devuelve false y registra el error si WordPress responde con un fallo", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => new Response("{}", { status: 500 })),
    );

    await expect(revokeAllSessions("jwt-abc")).resolves.toBe(false);
    expect(logger.error).toHaveBeenCalledWith(
      expect.objectContaining({
        event: "auth.revoke_all_sessions.wp_error",
        status: 500,
      }),
      expect.any(String),
    );
  });

  it("devuelve false y registra el error si WordPress es inalcanzable", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => Promise.reject(new Error("ECONNREFUSED"))),
    );

    await expect(revokeAllSessions("jwt-abc")).resolves.toBe(false);
    expect(logger.error).toHaveBeenCalledWith(
      expect.objectContaining({ event: "auth.revoke_all_sessions.error" }),
      expect.any(String),
    );
  });
});
