import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { SignJWT } from "jose";
import { NextRequest } from "next/server";
import { AUTH_COOKIE, REFRESH_COOKIE } from "@/lib/auth/constants";

// ============================================================================
//  Un refresh token que WordPress RECHAZA se borra del navegador.
//
//  Antes el proxy solo retiraba la cookie de acceso: la del refresh token
//  sobrevivía y CADA petición posterior (documento, RSC, prefetch, /api/*)
//  volvía a pedir `refreshJwtAuthToken` a WordPress con un token que ya sabía
//  inválido, hasta que la cookie caducaba (30 días por defecto).
//
//  Solo cuenta como rechazo DEFINITIVO la respuesta en la que el resolver de la
//  mutación corrió y dijo que no: cuerpo GraphQL con `data.refreshJwtAuthToken`
//  a null y errores (200 para un token mal formado o caducado, 401/403 para uno
//  de otro emisor o con el secreto revocado; así responde WPGraphQL JWT Auth). Un
//  timeout, un fallo de red, un 5xx, un 429, un cuerpo que no es GraphQL (p. ej.
//  el 404 de `hwe-bff-only` sin secreto interno) o el «Internal server error» de
//  un resolver que falló NO dicen nada del token: la cookie se queda y el
//  siguiente request reintenta, como hasta ahora.
// ============================================================================

const ORIGIN = "https://tienda.example";

async function expiredToken(): Promise<string> {
  const secret = new TextEncoder().encode("secreto-de-prueba-suficientemente-largo");
  return new SignJWT({ data: { user: { id: "7" } } })
    .setProtectedHeader({ alg: "HS256" })
    .setIssuedAt(Math.floor(Date.now() / 1000) - 3600)
    .setExpirationTime(Math.floor(Date.now() / 1000) - 60)
    .sign(secret);
}

async function request(path: string, cookies: string) {
  const { proxy } = await import("@/proxy");
  return proxy(
    new NextRequest(new URL(path, ORIGIN), {
      method: "GET",
      headers: { cookie: cookies },
    }),
  );
}

const sessionCookies = async () =>
  `${AUTH_COOKIE}=${await expiredToken()}; ${REFRESH_COOKIE}=refresco-revocado`;

/** Cuerpo real de WPGraphQL JWT Auth al rechazar un refresh token. */
const rejection = (status: number) =>
  new Response(
    JSON.stringify({
      errors: [
        {
          message: "The provided refresh token is invalid",
          path: ["refreshJwtAuthToken"],
        },
      ],
      data: { refreshJwtAuthToken: null },
    }),
    { status },
  );

/** `Set-Cookie` que borra la cookie: valor vacío y `Max-Age=0` (o `Expires` pasado). */
function expires(res: Response, name: string): boolean {
  const lines = res.headers
    .getSetCookie()
    .filter((line) => line.startsWith(`${name}=`));
  return lines.some(
    (line) =>
      line.startsWith(`${name}=;`) &&
      (/max-age=0/i.test(line) || /expires=thu, 01 jan 1970/i.test(line)),
  );
}

beforeEach(() => {
  vi.resetModules();
  vi.stubEnv("ALLOWED_ORIGIN", ORIGIN);
  vi.stubEnv("WORDPRESS_INTERNAL_API_URL", "http://wordpress:80/graphql");
  vi.spyOn(console, "warn").mockImplementation(() => {});
});

afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe("refresh token rechazado por WordPress", () => {
  it.each([200, 401, 403])(
    "con rechazo definitivo (HTTP %i) una página borra también la cookie del refresh token",
    async (status) => {
      vi.stubGlobal(
        "fetch",
        vi.fn(async () => rejection(status)) as unknown as typeof fetch,
      );

      const res = await request("/tienda", await sessionCookies());

      expect(
        expires(res, REFRESH_COOKIE),
        "la cookie del refresh token sigue viva",
      ).toBe(true);
      expect(expires(res, AUTH_COOKIE)).toBe(true);
    },
  );

  it("en /api/* también", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => rejection(200)) as unknown as typeof fetch,
    );

    const res = await request("/api/store/cart", await sessionCookies());

    expect(expires(res, REFRESH_COOKIE)).toBe(true);
    expect(expires(res, AUTH_COOKIE)).toBe(true);
  });

  it("sin cookie de acceso (solo el refresh token) también se borra", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => rejection(200)) as unknown as typeof fetch,
    );

    const res = await request("/tienda", `${REFRESH_COOKIE}=refresco-revocado`);

    expect(expires(res, REFRESH_COOKIE)).toBe(true);
  });

  it("con COOKIE_DOMAIN, el borrado lleva el mismo dominio con el que se dejó la cookie", async () => {
    vi.stubEnv("COOKIE_DOMAIN", ".tienda.example");
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => rejection(200)) as unknown as typeof fetch,
    );

    const res = await request("/tienda", await sessionCookies());

    const line = res.headers
      .getSetCookie()
      .find((l) => l.startsWith(`${REFRESH_COOKIE}=`));
    expect(line).toMatch(/domain=\.?tienda\.example/i);
  });

  it.each([
    ["un timeout", () => Promise.reject(new DOMException("aborted", "TimeoutError"))],
    ["un fallo de red", () => Promise.reject(new TypeError("fetch failed"))],
    ["un 500", async () => new Response("boom", { status: 500 })],
    ["un 502 con cuerpo GraphQL", async () => rejection(502)],
    ["un 429", async () => rejection(429)],
    [
      "el 404 de hwe-bff-only (sin secreto interno)",
      async () =>
        new Response(JSON.stringify({ errors: [{ message: "Not Found" }] }), {
          status: 404,
        }),
    ],
    [
      "un resolver que falló por dentro",
      async () =>
        new Response(
          JSON.stringify({
            errors: [
              { message: "Internal server error", path: ["refreshJwtAuthToken"] },
            ],
            data: { refreshJwtAuthToken: null },
          }),
          { status: 200 },
        ),
    ],
    ["un cuerpo que no es JSON", async () => new Response("<html>", { status: 200 })],
  ])(
    "con %s la cookie del refresh token se queda para reintentar",
    async (_label, impl) => {
      vi.stubGlobal("fetch", vi.fn(impl) as unknown as typeof fetch);

      const res = await request("/tienda", await sessionCookies());

      expect(
        res.headers.getSetCookie().some((l) => l.startsWith(`${REFRESH_COOKIE}=`)),
      ).toBe(false);
    },
  );

  it("tras el rechazo, la siguiente petición sin la cookie ya no llama a WordPress", async () => {
    const fetchMock = vi.fn(async () => rejection(200));
    vi.stubGlobal("fetch", fetchMock as unknown as typeof fetch);

    await request("/tienda", await sessionCookies());
    // El navegador aplica los Set-Cookie: sin refresh token ni acceso, es anónimo.
    await request("/tienda", "");

    expect(fetchMock).toHaveBeenCalledTimes(1);
  });
});
