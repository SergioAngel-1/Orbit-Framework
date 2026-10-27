import { beforeEach, describe, expect, it, vi } from "vitest";
import { SignJWT } from "jose";
import { AUTH_COOKIE } from "./constants";
import { getSession, requireSession } from "./session";

// ============================================================================
//  El BFF solo puede aceptar ACCESS tokens como sesión.
//
//  WPGraphQL JWT Authentication firma los dos credenciales con el mismo secreto
//  HS256; el refresh token se distingue por `data.user.user_secret`. Antes de
//  este arreglo `verifyAuthToken` no lo miraba, así que un `hwe_rt` robado
//  presentado en la cookie `hwe_at` montaba una sesión en TODAS las rutas del
//  BFF (que usan `getSession`/`requireSession`), incluidas las que hablan con
//  WooCommerce con las claves de administrador del BFF: ni logout-all ni el
//  cambio de contraseña lo revocaban.
// ============================================================================

const SECRET = "test-jwt-secret-muy-largo-para-pruebas-1234567890";

/** Token presentado en la cookie de acceso; cada test lo sustituye. */
let presentedToken: string | undefined;

vi.mock("next/headers", () => ({
  cookies: async () => ({
    get: (name: string) =>
      name === AUTH_COOKIE && presentedToken ? { value: presentedToken } : undefined,
  }),
}));

// La blocklist no es el control bajo prueba: un refresh token no está en ella
// (logout-all solo revoca el access token que llama), y así se demuestra que el
// rechazo viene del binding de tipo de credencial.
vi.mock("./revocation", () => ({ isTokenRevoked: vi.fn(async () => false) }));

async function signToken(payload: Record<string, unknown>, expSeconds: number) {
  return new SignJWT(payload)
    .setProtectedHeader({ alg: "HS256" })
    .setIssuedAt()
    .setExpirationTime(Math.floor(Date.now() / 1000) + expSeconds)
    .sign(new TextEncoder().encode(SECRET));
}

describe("sesión del BFF y refresh tokens", () => {
  beforeEach(() => {
    vi.stubEnv("GRAPHQL_JWT_AUTH_SECRET_KEY", SECRET);
    presentedToken = undefined;
  });

  it("acepta un access token como sesión", async () => {
    presentedToken = await signToken(
      { iss: "https://wp.example", data: { user: { id: "7" } } },
      300,
    );

    const session = await getSession();
    expect(session?.userId).toBe("7");
  });

  it("no acepta un refresh token en la cookie de acceso", async () => {
    presentedToken = await signToken(
      {
        iss: "https://wp.example",
        data: { user: { id: "7", user_secret: "secreto-vigente-del-usuario" } },
      },
      365 * 24 * 60 * 60,
    );

    await expect(getSession()).resolves.toBeNull();
    await expect(requireSession()).rejects.toThrow("UNAUTHENTICATED");
  });

  it("no acepta un refresh token con el secreto ya rotado (tras logout-all)", async () => {
    presentedToken = await signToken(
      {
        iss: "https://wp.example",
        data: { user: { id: "7", user_secret: "secreto-anterior" } },
      },
      365 * 24 * 60 * 60,
    );

    await expect(getSession()).resolves.toBeNull();
  });
});
