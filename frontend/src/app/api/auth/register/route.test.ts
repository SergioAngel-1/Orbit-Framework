import { beforeEach, describe, expect, it, vi } from "vitest";
import { fetchGraphQL } from "@/lib/graphql-client";
import { logger } from "@/lib/observability/logger";
import { POST } from "./route";

// ============================================================================
//  POST /api/auth/register: el correo de verificación es opcional, pero que
//  falle no pasa en silencio.
//
//  Tras el alta, el BFF pedía el correo de verificación sin mirar la respuesta:
//  solo registraba un fallo de red, y WordPress contestaba 200 aunque `wp_mail()`
//  fallara. Ahora WordPress contesta 502 si el correo no sale; el registro NO se
//  rompe (la verificación es opcional y no bloquea la cuenta), pero queda
//  registrado para que un SMTP roto no pase desapercibido.
// ============================================================================

const cookieSet = vi.fn();
vi.mock("next/headers", () => ({ cookies: async () => ({ set: cookieSet }) }));
vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/graphql-client", () => ({ fetchGraphQL: vi.fn() }));
vi.mock("@/lib/auth/jwt", () => ({ getTokenMaxAgeSeconds: () => 300 }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const request = () =>
  new Request("https://tienda.example/api/auth/register", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify({
      username: "ana",
      email: "ana@example.com",
      password: "una-clave-larga-1",
    }),
  });

function stubSendVerification(response: Response | Error) {
  const fetchMock = vi.fn(async () => {
    if (response instanceof Error) throw response;
    return response;
  });
  vi.stubGlobal("fetch", fetchMock as unknown as typeof fetch);
  return fetchMock;
}

/** Eventos `auth.register.send_verification_failed` registrados. */
const failedEvents = () =>
  vi
    .mocked(logger.warn)
    .mock.calls.map((call) => call[0] as { event?: string; status?: number })
    .filter((fields) => fields.event === "auth.register.send_verification_failed");

beforeEach(() => {
  vi.clearAllMocks();
  vi.unstubAllGlobals();
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

describe("correo de verificación tras el registro", () => {
  it("si sale bien, el registro responde 201 y no deja ningún aviso", async () => {
    stubSendVerification(Response.json({ ok: true }));

    const res = await POST(request());

    expect(res.status).toBe(201);
    expect(failedEvents()).toHaveLength(0);
  });

  it("si WordPress responde 502 (el correo no salió), el registro sigue y el fallo se registra", async () => {
    stubSendVerification(
      Response.json({ error: "No se pudo enviar el email." }, { status: 502 }),
    );

    const res = await POST(request());

    expect(res.status).toBe(201);
    expect(cookieSet).toHaveBeenCalled(); // sesión iniciada igualmente
    expect(failedEvents()).toEqual([
      expect.objectContaining({
        event: "auth.register.send_verification_failed",
        status: 502,
      }),
    ]);
  });

  it("si falla la red hacia WordPress, el registro sigue y el fallo se registra", async () => {
    stubSendVerification(new TypeError("fetch failed"));

    const res = await POST(request());

    expect(res.status).toBe(201);
    expect(failedEvents()).toHaveLength(1);
  });

  it("pide el correo con el token de la sesión recién creada", async () => {
    const fetchMock = stubSendVerification(Response.json({ ok: true }));

    await POST(request());

    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toContain("/wp-json/hwe/v1/auth/send-verification");
    expect((init.headers as Record<string, string>).Authorization).toBe("Bearer AUTH");
  });
});
