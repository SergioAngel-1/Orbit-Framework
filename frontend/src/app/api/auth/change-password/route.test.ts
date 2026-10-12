import { beforeEach, describe, expect, it, vi } from "vitest";
import { getCustomer } from "@/lib/account/data";
import { fetchGraphQL, GraphQLClientError } from "@/lib/graphql-client";
import { requireSession, revokeAllSessions } from "@/lib/auth/session";
import { revokeToken } from "@/lib/auth/revocation";
import { AUTH_COOKIE, REFRESH_COOKIE } from "@/lib/auth/constants";
import { logger } from "@/lib/observability/logger";
import { POST } from "./route";

// ============================================================================
//  POST /api/auth/change-password.
//
//  Cambiar la contraseña tiene que cerrar el resto de sesiones: quien cambia la
//  contraseña suele hacerlo porque sospecha que la cuenta está comprometida, y un
//  refresh token robado seguiría renovándose.
//
//  Pero `logout-all` rota el secreto JWT del usuario en WordPress, lo que invalida
//  TODOS sus refresh tokens, también el de ESTE dispositivo. Para que la sesión
//  actual se conserve hay que reemitir sus tokens iniciando sesión con la
//  contraseña nueva, y revocar el access token anterior.
// ============================================================================

const cookieStore = { set: vi.fn() };

vi.mock("next/headers", () => ({ cookies: vi.fn(async () => cookieStore) }));
vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/auth/session", () => ({
  requireSession: vi.fn(),
  revokeAllSessions: vi.fn(),
}));
vi.mock("@/lib/auth/revocation", () => ({ revokeToken: vi.fn() }));
vi.mock("@/lib/auth/jwt", () => ({ getTokenMaxAgeSeconds: () => 300 }));
vi.mock("@/lib/account/data", () => ({ getCustomer: vi.fn() }));
vi.mock("@/lib/graphql-client", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/graphql-client")>()),
  fetchGraphQL: vi.fn(),
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const OLD_TOKEN = "jwt-actual";
const BODY = {
  currentPassword: "contraseña-vieja",
  newPassword: "contraseña-nueva-123",
};
const EMAIL = "ana@example.com";

const login = (authToken: string, refreshToken: string) => ({
  login: {
    authToken,
    refreshToken,
    user: { id: "u", databaseId: 7, name: "Ana", email: EMAIL },
  },
});

/** El orden de las llamadas con efectos: lo que importa es QUÉ pasa antes de qué. */
let calls: string[];
let restBody: unknown;

function stubRestUpdate(ok = true) {
  vi.stubGlobal(
    "fetch",
    vi.fn(async (_url: string, init?: RequestInit) => {
      calls.push("rest:update");
      restBody = JSON.parse(String(init?.body));
      return new Response("{}", { status: ok ? 200 : 500 });
    }) as unknown as typeof fetch,
  );
}

const request = (body: unknown) =>
  new Request("https://tienda.example/api/auth/change-password", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify(body),
  });

beforeEach(() => {
  vi.clearAllMocks();
  vi.unstubAllGlobals();
  calls = [];
  restBody = null;
  vi.mocked(requireSession).mockResolvedValue({ userId: "7", token: OLD_TOKEN });
  vi.mocked(getCustomer).mockResolvedValue({ email: EMAIL } as never);
  vi.mocked(revokeAllSessions).mockImplementation(async () => {
    calls.push("wp:logout-all");
    return true;
  });
  vi.mocked(revokeToken).mockImplementation(async () => {
    calls.push("blocklist:old-token");
  });
  // 1.ª llamada: verificar la contraseña actual. 2.ª: reemitir con la nueva.
  vi.mocked(fetchGraphQL).mockImplementation((async (
    _q: string,
    opts: { variables?: { password?: string } },
  ) => {
    if (opts.variables?.password === BODY.currentPassword) {
      calls.push("gql:verify-current");
      return login("token-de-verificacion", "refresh-de-verificacion");
    }
    calls.push("gql:login-new");
    return login("token-nuevo", "refresh-nuevo");
  }) as never);
  stubRestUpdate();
});

describe("validación y acceso", () => {
  it.each([
    ["contraseña actual vacía", { ...BODY, currentPassword: "" }],
    ["contraseña nueva demasiado corta", { ...BODY, newPassword: "corta" }],
    ["cuerpo vacío", {}],
  ])("%s: 422 y no toca nada", async (_caso, body) => {
    const res = await POST(request(body));

    expect(res.status).toBe(422);
    expect(fetchGraphQL).not.toHaveBeenCalled();
    expect(revokeAllSessions).not.toHaveBeenCalled();
  });

  it("sin sesión responde 401", async () => {
    vi.mocked(requireSession).mockRejectedValue(new Error("UNAUTHENTICATED"));

    const res = await POST(request(BODY));

    expect(res.status).toBe(401);
    expect(revokeAllSessions).not.toHaveBeenCalled();
  });
});

describe("verificación de la contraseña actual", () => {
  it("se hace con el EMAIL de la cuenta, no con el id numérico de la sesión", async () => {
    await POST(request(BODY));

    const [, opts] = vi.mocked(fetchGraphQL).mock.calls[0]!;
    expect(opts?.variables).toEqual({
      username: EMAIL,
      password: BODY.currentPassword,
    });
  });

  it("una contraseña incorrecta es 403 y no cambia ni revoca nada", async () => {
    vi.mocked(fetchGraphQL).mockRejectedValue(
      new GraphQLClientError("graphql", "Error de GraphQL: incorrect_password"),
    );

    const res = await POST(request(BODY));

    expect(res.status).toBe(403);
    expect((await res.json()).error).toMatch(/no es correcta/i);
    expect(calls).not.toContain("rest:update");
    expect(revokeAllSessions).not.toHaveBeenCalled();
  });

  it.each([
    ["WordPress inalcanzable", new GraphQLClientError("network", "sin red")],
    [
      "WordPress responde 5xx",
      new GraphQLClientError("http", "HTTP 503", { status: 503 }),
    ],
  ])("%s no es «contraseña incorrecta»: 502 y no cambia nada", async (_caso, error) => {
    vi.mocked(fetchGraphQL).mockRejectedValue(error);

    const res = await POST(request(BODY));

    expect(res.status).toBe(502);
    expect(calls).not.toContain("rest:update");
  });

  it.each([
    ["no se puede leer el perfil", async () => Promise.reject(new Error("woo caído"))],
    ["la cuenta no tiene email", async () => ({ email: "" })],
  ])("si %s: 502 y no cambia nada", async (_caso, impl) => {
    vi.mocked(getCustomer).mockImplementation(impl as never);

    const res = await POST(request(BODY));

    expect(res.status).toBe(502);
    expect(fetchGraphQL).not.toHaveBeenCalled();
    expect(calls).not.toContain("rest:update");
  });
});

describe("cambio de contraseña y revocación de sesiones", () => {
  it("camino feliz: cambia la contraseña, cierra las demás sesiones y conserva la actual", async () => {
    const res = await POST(request(BODY));

    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({
      ok: true,
      otherSessionsRevoked: true,
      sessionKept: true,
    });
    expect(restBody).toEqual({ password: BODY.newPassword });
    // Primero el cambio; luego se rota el secreto; luego se reemite ESTA sesión.
    expect(calls).toEqual([
      "gql:verify-current",
      "rest:update",
      "wp:logout-all",
      "gql:login-new",
      "blocklist:old-token",
    ]);
  });

  it("revoca con el token de la sesión actual", async () => {
    await POST(request(BODY));

    expect(revokeAllSessions).toHaveBeenCalledWith(OLD_TOKEN);
  });

  it("la sesión actual se reemite con la contraseña NUEVA y se guardan las cookies", async () => {
    await POST(request(BODY));

    const [, loginOpts] = vi.mocked(fetchGraphQL).mock.calls[1]!;
    expect(loginOpts?.variables).toEqual({
      username: EMAIL,
      password: BODY.newPassword,
    });
    expect(cookieStore.set).toHaveBeenCalledWith(
      AUTH_COOKIE,
      "token-nuevo",
      expect.any(Object),
    );
    expect(cookieStore.set).toHaveBeenCalledWith(
      REFRESH_COOKIE,
      "refresh-nuevo",
      expect.any(Object),
    );
    // El access token anterior ya no vale aunque aún no haya caducado.
    expect(revokeToken).toHaveBeenCalledWith(OLD_TOKEN);
  });

  it("si WordPress no cambia la contraseña: 502 y no revoca nada", async () => {
    stubRestUpdate(false);

    const res = await POST(request(BODY));

    expect(res.status).toBe(502);
    expect(revokeAllSessions).not.toHaveBeenCalled();
    expect(cookieStore.set).not.toHaveBeenCalled();
  });

  it("si no se pueden cerrar las otras sesiones: la contraseña ya cambió, se avisa y la sesión actual no se toca", async () => {
    vi.mocked(revokeAllSessions).mockResolvedValue(false);

    const res = await POST(request(BODY));

    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({
      ok: true,
      otherSessionsRevoked: false,
      sessionKept: true,
    });
    // Sin rotación no hace falta reemitir: la sesión actual sigue siendo válida.
    expect(cookieStore.set).not.toHaveBeenCalled();
    expect(revokeToken).not.toHaveBeenCalled();
    expect(logger.error).toHaveBeenCalledWith(
      expect.objectContaining({ event: "auth.change_password.sessions_not_revoked" }),
      expect.any(String),
    );
  });

  it("si se rotó el secreto pero no se puede reemitir esta sesión: se avisa de que hay que volver a entrar", async () => {
    vi.mocked(fetchGraphQL).mockImplementation((async (
      _q: string,
      opts: { variables?: { password?: string } },
    ) => {
      if (opts.variables?.password === BODY.currentPassword) {
        return login("token-de-verificacion", "refresh-de-verificacion");
      }
      throw new GraphQLClientError("network", "sin red");
    }) as never);

    const res = await POST(request(BODY));

    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({
      ok: true,
      otherSessionsRevoked: true,
      sessionKept: false,
    });
    expect(cookieStore.set).not.toHaveBeenCalled();
    expect(logger.error).toHaveBeenCalledWith(
      expect.objectContaining({ event: "auth.change_password.reissue_failed" }),
      expect.any(String),
    );
  });

  it("las contraseñas nunca llegan al log", async () => {
    await POST(request(BODY));

    const logged = JSON.stringify([
      ...vi.mocked(logger.info).mock.calls,
      ...vi.mocked(logger.warn).mock.calls,
      ...vi.mocked(logger.error).mock.calls,
    ]);
    expect(logged).not.toContain(BODY.currentPassword);
    expect(logged).not.toContain(BODY.newPassword);
  });
});
