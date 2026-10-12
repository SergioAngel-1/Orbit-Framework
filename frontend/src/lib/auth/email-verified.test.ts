import { afterEach, describe, expect, it, vi } from "vitest";
import { isEmailVerified } from "./email-verified";

// ============================================================================
//  ¿El email del usuario de la sesión está verificado en WordPress?
//  Lo exige el alta de 2FA. Falla CERRADO: ante cualquier duda, no verificado.
// ============================================================================

vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const SESSION = { userId: "7", token: "JWT" };

function stubMe(response: Response | Error) {
  const fetchMock = vi.fn(async () => {
    if (response instanceof Error) throw response;
    return response;
  });
  vi.stubGlobal("fetch", fetchMock as unknown as typeof fetch);
  return fetchMock;
}

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("isEmailVerified", () => {
  it("true solo si WordPress dice email_verified === true", async () => {
    stubMe(Response.json({ email_verified: true }));

    expect(await isEmailVerified(SESSION)).toBe(true);
  });

  it.each([
    ["false", { email_verified: false }],
    ["ausente", {}],
    ["un texto 'true'", { email_verified: "true" }],
    ["1", { email_verified: 1 }],
    ["null", { email_verified: null }],
  ])("%s no cuenta como verificado", async (_label, body) => {
    stubMe(Response.json(body));

    expect(await isEmailVerified(SESSION)).toBe(false);
  });

  it("un error HTTP de WordPress falla cerrado y queda registrado", async () => {
    stubMe(new Response("boom", { status: 502 }));
    const { logger } = await import("@/lib/observability/logger");

    expect(await isEmailVerified(SESSION)).toBe(false);
    expect(logger.warn).toHaveBeenCalledWith(
      expect.objectContaining({ event: "auth.email_verified.check_failed" }),
      expect.any(String),
    );
  });

  it("un fallo de red falla cerrado", async () => {
    stubMe(new TypeError("fetch failed"));

    expect(await isEmailVerified(SESSION)).toBe(false);
  });

  it("un cuerpo que no es JSON falla cerrado", async () => {
    stubMe(new Response("<html>", { status: 200 }));

    expect(await isEmailVerified(SESSION)).toBe(false);
  });

  it("consulta a WordPress con el token de la sesión y sin caché", async () => {
    const fetchMock = stubMe(Response.json({ email_verified: true }));

    await isEmailVerified(SESSION);

    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toContain("/wp-json/hwe/v1/auth/me");
    expect((init.headers as Record<string, string>).Authorization).toBe("Bearer JWT");
    expect(init.cache).toBe("no-store");
  });
});
