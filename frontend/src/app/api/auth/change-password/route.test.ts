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
//  TODOS sus refresh tokens (`enforceRefreshTokenSecret`), también el de ESTE
//  dispositivo. Para que la sesión actual se conserve hay que reemitir sus tokens
//  iniciando sesión con la contraseña nueva, y revocar el access token anterior.
//
//  El cambio lo hace `POST hwe/v1/auth/change-password`: WordPress verifica la
//  contraseña actual. La ruta `wp/v2/users/<id>` que se usaba antes la retira
//  `security.php` a los clientes, y los tests que simulaban su 200 no lo veían.
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
let restBody: Record<string, unknown> | null;
let restRequest: { url: string; auth: string | null } | null;

/** Respuesta de WordPress a `POST hwe/v1/auth/change-password`. */
function stubChangePassword(status = 200) {
  vi.stubGlobal(
    "fetch",
    vi.fn(async (url: string, init?: RequestInit) => {
      calls.push("wp:change-password");
      restRequest = {
        url: String(url),
        auth: new Headers(init?.headers).get("authorization"),
      };
      restBody = JSON.parse(String(init?.body));
      return new Response("{}", { status });
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
  restRequest = null;
  vi.mocked(requireSession).mockResolvedValue({ userId: "7", token: OLD_TOKEN });
  vi.mocked(getCustomer).mockResolvedValue({ email: EMAIL } as never);
  vi.mocked(revokeAllSessions).mockImplementation(async () => {
    calls.push("wp:logout-all");
    return true;
  });
  vi.mocked(revokeToken).mockImplementation(async () => {
    calls.push("blocklist:old-token");
  });
  // El único login que hace el BFF es el de reemitir esta sesión con la contraseña nueva.
  vi.mocked(fetchGraphQL).mockImplementation((async () => {
    calls.push("gql:login-new");
    return login("token-nuevo", "refresh-nuevo");
  }) as never);
  stubChangePassword();
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

describe("WordPress verifica la contraseña actual y fija la nueva", () => {
  it("usa el endpoint propio con la sesión del usuario, no `wp/v2/users` (que security.php retira)", async () => {
    await POST(request(BODY));

    expect(restRequest?.url).toContain("/wp-json/hwe/v1/auth/change-password");
    expect(restRequest?.url).not.toContain("wp/v2/users");
    expect(restRequest?.auth).toBe(`Bearer ${OLD_TOKEN}`);
    expect(restBody).toEqual({
      current_password: BODY.currentPassword,
      new_password: BODY.newPassword,
    });
  });

  it("una contraseña actual incorrecta es 403 y no revoca nada", async () => {
    stubChangePassword(403);

    const res = await POST(request(BODY));

    expect(res.status).toBe(403);
    expect((await res.json()).error).toMatch(/no es correcta/i);
    expect(revokeAllSessions).not.toHaveBeenCalled();
    expect(cookieStore.set).not.toHaveBeenCalled();
  });

  it("demasiados intentos fallidos (el bloqueo de WordPress) es 429", async () => {
    stubChangePassword(429);

    const res = await POST(request(BODY));

    expect(res.status).toBe(429);
    expect(revokeAllSessions).not.toHaveBeenCalled();
  });

  it("una caída de WordPress no es «contraseña incorrecta»: 502 y no revoca nada", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => {
        throw new Error("sin red");
      }) as unknown as typeof fetch,
    );

    const res = await POST(request(BODY));

    expect(res.status).toBe(502);
    expect(revokeAllSessions).not.toHaveBeenCalled();
  });

  it.each([
    ["no se puede leer el perfil", async () => Promise.reject(new Error("woo caído"))],
    ["la cuenta no tiene email", async () => ({ email: "" })],
  ])("si %s: 502 y la contraseña no se toca", async (_caso, impl) => {
    vi.mocked(getCustomer).mockImplementation(impl as never);

    const res = await POST(request(BODY));

    expect(res.status).toBe(502);
    expect(calls).not.toContain("wp:change-password");
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
    // Primero el cambio; luego se rota el secreto; luego se reemite ESTA sesión.
    expect(calls).toEqual([
      "wp:change-password",
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

    const [, loginOpts] = vi.mocked(fetchGraphQL).mock.calls[0]!;
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
    stubChangePassword(500);

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
    vi.mocked(fetchGraphQL).mockRejectedValue(
      new GraphQLClientError("network", "sin red"),
    );

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
