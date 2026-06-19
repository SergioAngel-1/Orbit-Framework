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
