import { describe, it, expect, beforeEach, afterEach } from "vitest";
import { SignJWT } from "jose";
import { verifyAuthToken, getTokenMaxAgeSeconds } from "@/lib/auth/jwt";

const SECRET = "test-jwt-secret-muy-largo-para-pruebas-1234567890";

async function signToken(
  payload: Record<string, unknown>,
  expSeconds: number,
  secret = SECRET,
): Promise<string> {
  return new SignJWT(payload)
    .setProtectedHeader({ alg: "HS256" })
    .setIssuedAt()
    .setExpirationTime(Math.floor(Date.now() / 1000) + expSeconds)
    .sign(new TextEncoder().encode(secret));
}

describe("verifyAuthToken", () => {
  let saved: string | undefined;
  beforeEach(() => {
    saved = process.env.GRAPHQL_JWT_AUTH_SECRET_KEY;
    process.env.GRAPHQL_JWT_AUTH_SECRET_KEY = SECRET;
  });
  afterEach(() => {
    if (saved === undefined) delete process.env.GRAPHQL_JWT_AUTH_SECRET_KEY;
    else process.env.GRAPHQL_JWT_AUTH_SECRET_KEY = saved;
  });

  it("extrae el userId de un token válido (shape WPGraphQL JWT)", async () => {
    const token = await signToken({ data: { user: { id: "42" } } }, 300);
    const res = await verifyAuthToken(token);
    expect(res?.userId).toBe("42");
  });

  it("devuelve null con firma inválida (otro secreto)", async () => {
    const token = await signToken(
      { data: { user: { id: "42" } } },
      300,
      "otro-secreto-distinto",
    );
    expect(await verifyAuthToken(token)).toBeNull();
  });

  it("devuelve null con token expirado", async () => {
    const token = await signToken({ data: { user: { id: "42" } } }, -10);
    expect(await verifyAuthToken(token)).toBeNull();
  });

  it("devuelve null si falta el id", async () => {
    const token = await signToken({ data: { user: {} } }, 300);
    expect(await verifyAuthToken(token)).toBeNull();
  });

  // --------------------------------------------------------------------------
  //  Binding de tipo de credencial. WPGraphQL JWT Authentication firma el access
  //  token y el refresh token con el MISMO secreto y el MISMO algoritmo (HS256).
  //  La única diferencia es que el refresh lleva `data.user.user_secret` (y vive
  //  365 días). Si el BFF no lo mira, un `hwe_rt` robado se puede presentar como
  //  cookie de acceso y sobrevive a logout-all y al cambio de contraseña, que
  //  solo rotan ese secreto por usuario.
  // --------------------------------------------------------------------------

  it("rechaza un refresh token de WPGraphQL presentado como token de acceso", async () => {
    const refreshToken = await signToken(
      {
        iss: "https://wp.example",
        data: { user: { id: "42", user_secret: "secreto-vigente-del-usuario" } },
      },
      365 * 24 * 60 * 60,
    );
    expect(await verifyAuthToken(refreshToken)).toBeNull();
  });

  it("acepta el access token del mismo usuario (no lleva user_secret)", async () => {
    const accessToken = await signToken(
      { iss: "https://wp.example", data: { user: { id: "42" } } },
      300,
    );
    await expect(verifyAuthToken(accessToken)).resolves.toEqual({ userId: "42" });
  });
});

describe("getTokenMaxAgeSeconds", () => {
  it("calcula segundos restantes hasta exp", async () => {
    const token = await signToken({ data: { user: { id: "1" } } }, 300);
    const secs = getTokenMaxAgeSeconds(token);
    expect(secs).toBeGreaterThan(0);
    expect(secs).toBeLessThanOrEqual(300);
  });

  it("usa el valor por defecto con un token no decodificable", () => {
    expect(getTokenMaxAgeSeconds("no-es-un-jwt")).toBeGreaterThan(0);
  });
});
