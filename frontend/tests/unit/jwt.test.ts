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
