import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";

// Almacén de cookies en memoria que imita la API de next/headers cookies().
const jar = new Map<string, string>();
vi.mock("next/headers", () => ({
  cookies: async () => ({
    get: (name: string) => {
      const value = jar.get(name);
      return value === undefined ? undefined : { name, value };
    },
    set: (name: string, value: string) => {
      jar.set(name, value);
    },
  }),
}));

import {
  generateCsrfToken,
  verifyCsrf,
  CSRF_COOKIE,
  CSRF_HEADER,
} from "@/lib/security/csrf";

function reqWith(headerToken?: string): Request {
  const headers: Record<string, string> = {};
  if (headerToken !== undefined) headers[CSRF_HEADER] = headerToken;
  return new Request("https://app.example/api/x", { method: "POST", headers });
}

describe("CSRF signed double-submit", () => {
  let saved: string | undefined;
  beforeEach(() => {
    saved = process.env.CSRF_SECRET;
    process.env.CSRF_SECRET = "csrf-secret-de-prueba-1234567890";
    jar.clear();
  });
  afterEach(() => {
    if (saved === undefined) delete process.env.CSRF_SECRET;
    else process.env.CSRF_SECRET = saved;
  });

  it("genera tokens con formato <random>.<hmac> y únicos", () => {
    const a = generateCsrfToken();
    const b = generateCsrfToken();
    expect(a).toMatch(/^[a-f0-9]+\.[a-f0-9]+$/);
    expect(a).not.toBe(b);
  });

  it("acepta cuando cabecera y cookie coinciden y el token es auténtico", async () => {
    const token = generateCsrfToken();
    jar.set(CSRF_COOKIE, token);
    expect(await verifyCsrf(reqWith(token))).toBe(true);
  });

  it("rechaza si la cabecera no coincide con la cookie", async () => {
    jar.set(CSRF_COOKIE, generateCsrfToken());
    expect(await verifyCsrf(reqWith(generateCsrfToken()))).toBe(false);
  });

  it("rechaza un token con firma manipulada", async () => {
    const [random] = generateCsrfToken().split(".");
    const forged = `${random}.deadbeef`;
    jar.set(CSRF_COOKIE, forged);
    expect(await verifyCsrf(reqWith(forged))).toBe(false);
  });

  it("rechaza si falta la cabecera o la cookie", async () => {
    expect(await verifyCsrf(reqWith(undefined))).toBe(false);
    jar.clear();
    expect(await verifyCsrf(reqWith("algo"))).toBe(false);
  });
});

// ============================================================================
//  CSRF_SECRET es obligatorio: sin él, falla cerrado.
//
//  Antes `getSecret()` degradaba a GRAPHQL_JWT_AUTH_SECRET_KEY y, si tampoco
//  estaba, a la literal pública `insecure-dev-csrf-secret`. Con una clave pública
//  cualquiera puede firmar un token CSRF "auténtico" y la protección desaparece
//  sin que nada falle; y reutilizar el secreto JWT acopla dos dominios de
//  seguridad (rotar uno rompe el otro).
// ============================================================================
describe("CSRF_SECRET es obligatorio", () => {
  const JWT_SECRET = "jwt-secret-que-NO-debe-usarse-1234567890";
  let savedCsrf: string | undefined;
  let savedJwt: string | undefined;

  beforeEach(() => {
    savedCsrf = process.env.CSRF_SECRET;
    savedJwt = process.env.GRAPHQL_JWT_AUTH_SECRET_KEY;
    delete process.env.CSRF_SECRET;
    process.env.GRAPHQL_JWT_AUTH_SECRET_KEY = JWT_SECRET;
    jar.clear();
  });
  afterEach(() => {
    if (savedCsrf === undefined) delete process.env.CSRF_SECRET;
    else process.env.CSRF_SECRET = savedCsrf;
    if (savedJwt === undefined) delete process.env.GRAPHQL_JWT_AUTH_SECRET_KEY;
    else process.env.GRAPHQL_JWT_AUTH_SECRET_KEY = savedJwt;
  });

  it("generar un token lanza, sin degradar al secreto JWT", () => {
    expect(() => generateCsrfToken()).toThrow(/CSRF_SECRET/);
  });

  it("un CSRF_SECRET vacío cuenta como no definido", () => {
    process.env.CSRF_SECRET = "";

    expect(() => generateCsrfToken()).toThrow(/CSRF_SECRET/);
  });

  it("emitir un token lanza y no fija ninguna cookie", async () => {
    const { issueCsrfToken } = await import("@/lib/security/csrf");

    await expect(issueCsrfToken()).rejects.toThrow(/CSRF_SECRET/);
    expect(jar.size).toBe(0);
  });

  it("verificar lanza en lugar de aceptar, aunque el token venga firmado con el secreto JWT", async () => {
    const { createHmac } = await import("node:crypto");
    const random = "a".repeat(64);
    const forged = `${random}.${createHmac("sha256", JWT_SECRET).update(random).digest("hex")}`;
    jar.set(CSRF_COOKIE, forged);

    await expect(verifyCsrf(reqWith(forged))).rejects.toThrow(/CSRF_SECRET/);
  });

  it("con CSRF_SECRET definido, un token firmado con la literal pública antigua NO se acepta", async () => {
    process.env.CSRF_SECRET = "csrf-secret-de-prueba-1234567890";
    const { createHmac } = await import("node:crypto");
    const random = "b".repeat(64);
    const forged = `${random}.${createHmac("sha256", "insecure-dev-csrf-secret").update(random).digest("hex")}`;
    jar.set(CSRF_COOKIE, forged);

    expect(await verifyCsrf(reqWith(forged))).toBe(false);
  });

  it("con CSRF_SECRET definido, un token firmado con el secreto JWT NO se acepta", async () => {
    process.env.CSRF_SECRET = "csrf-secret-de-prueba-1234567890";
    const { createHmac } = await import("node:crypto");
    const random = "c".repeat(64);
    const forged = `${random}.${createHmac("sha256", JWT_SECRET).update(random).digest("hex")}`;
    jar.set(CSRF_COOKIE, forged);

    expect(await verifyCsrf(reqWith(forged))).toBe(false);
  });
});
