import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { SignJWT } from "jose";
import { NextRequest } from "next/server";
import {
  AUTH_COOKIE,
  REFRESH_BACKOFF_COOKIE,
  REFRESH_COOKIE,
} from "@/lib/auth/constants";

// ============================================================================
//  Un refresh token que WordPress RECHAZA no se vuelve a probar en cada petición.
//
//  Antes el proxy solo retiraba la cookie de acceso y CADA petición posterior
//  (documento, RSC, prefetch, /api/*) volvía a pedir `refreshJwtAuthToken` a
//  WordPress con el mismo token.
//
//  Pero un «rechazo» con forma de rechazo (cuerpo GraphQL con
//  `data.refreshJwtAuthToken` a null y errores; 200/401/403) no prueba que el
//  token esté muerto: lo mismo responde WordPress si le falta
//  `GRAPHQL_JWT_AUTH_SECRET_KEY` (un `.env` mal restaurado) o si la lectura del
//  secreto del usuario falla en la BD. Borrar el refresh token ahí cerraría la
//  sesión de todo el mundo para siempre. Así que el rechazo deja una cookie corta
//  de espera, ligada a ESE refresh token, durante la que el proxy no pregunta; el
//  refresh token se queda. Solo se borra cuando él mismo dice que caducó (`exp`).
//
//  Un timeout, un fallo de red, un 5xx, un 429, un cuerpo que no es GraphQL (p. ej.
//  el 404 de `hwe-bff-only` sin secreto interno) o el «Internal server error» de
//  un resolver no dejan ni siquiera la espera: el siguiente request reintenta.
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

/** Refresh token con forma de JWT y `exp` (en segundos desde ahora). */
function refreshJwt(expInSeconds: number): string {
  const b64 = (o: object) => Buffer.from(JSON.stringify(o)).toString("base64url");
  const exp = Math.floor(Date.now() / 1000) + expInSeconds;
  return `${b64({ alg: "HS256", typ: "JWT" })}.${b64({ exp, data: { user: { id: "7", user_secret: "s" } } })}.firma`;
}

/** Cuerpo de WordPress cuando le falta `GRAPHQL_JWT_AUTH_SECRET_KEY` (403, mismo mensaje). */
const missingSecretKey = () => rejection(403);
/** Cuerpo cuando `enforceRefreshTokenSecret` no puede leer el secreto del usuario (BD). */
const userSecretUnreadable = () => rejection(403);

/** La respuesta deja la cookie de espera (con valor y vida corta). */
function setsBackoff(res: Response): boolean {
  return res.headers
    .getSetCookie()
    .some(
      (line) =>
        line.startsWith(`${REFRESH_BACKOFF_COOKIE}=`) &&
        !line.startsWith(`${REFRESH_BACKOFF_COOKIE}=;`) &&
        /max-age=60\b/i.test(line) &&
        /httponly/i.test(line),
    );
}

/** Valor de la cookie de espera que dejó la respuesta. */
function backoffValue(res: Response): string {
  const line = res.headers
    .getSetCookie()
    .find((l) => l.startsWith(`${REFRESH_BACKOFF_COOKIE}=`))!;
  return line.slice(REFRESH_BACKOFF_COOKIE.length + 1).split(";")[0]!;
}

const touchesRefresh = (res: Response) =>
  res.headers.getSetCookie().some((l) => l.startsWith(`${REFRESH_COOKIE}=`));

describe("rechazo de WordPress: espera, no borrado", () => {
  it.each([
    ["sin GRAPHQL_JWT_AUTH_SECRET_KEY en WordPress", missingSecretKey],
    ["con la lectura del secreto del usuario fallando", userSecretUnreadable],
    ["con un 200 de token inválido", () => rejection(200)],
    ["con un 401", () => rejection(401)],
  ])(
    "%s, el refresh token se queda y se deja la cookie de espera",
    async (_l, impl) => {
      vi.stubGlobal("fetch", vi.fn(async () => impl()) as unknown as typeof fetch);

      const res = await request("/tienda", await sessionCookies());

      expect(touchesRefresh(res), "no se debe tocar la cookie del refresh token").toBe(
        false,
      );
      expect(setsBackoff(res)).toBe(true);
      // El acceso caducado sí se retira, como antes.
      expect(expires(res, AUTH_COOKIE)).toBe(true);
    },
  );

  it("en /api/* también", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => rejection(200)) as unknown as typeof fetch,
    );

    const res = await request("/api/store/cart", await sessionCookies());

    expect(touchesRefresh(res)).toBe(false);
    expect(setsBackoff(res)).toBe(true);
  });

  it("con la cookie de espera de ESE refresh token, no se llama a WordPress", async () => {
    const fetchMock = vi.fn(async () => rejection(403));
    vi.stubGlobal("fetch", fetchMock as unknown as typeof fetch);
    const cookies = await sessionCookies();

    const first = await request("/tienda", cookies);
    const wait = backoffValue(first);
    await request("/tienda", `${cookies}; ${REFRESH_BACKOFF_COOKIE}=${wait}`);
    await request("/api/store/cart", `${cookies}; ${REFRESH_BACKOFF_COOKIE}=${wait}`);

    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it("la espera no delata el refresh token (no es su valor)", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => rejection(403)) as unknown as typeof fetch,
    );

    const res = await request("/tienda", await sessionCookies());

    expect(backoffValue(res)).not.toContain("refresco-revocado");
  });

  it("una espera de OTRO refresh token (sesión nueva) no impide refrescar, y se limpia al lograrlo", async () => {
    const fetchMock = vi.fn(
      async () =>
        new Response(
          JSON.stringify({ data: { refreshJwtAuthToken: { authToken: "nuevo" } } }),
          { status: 200 },
        ),
    );
    vi.stubGlobal("fetch", fetchMock as unknown as typeof fetch);

    const res = await request(
      "/tienda",
      `${await sessionCookies()}; ${REFRESH_BACKOFF_COOKIE}=de-otra-sesion`,
    );

    expect(fetchMock).toHaveBeenCalledOnce();
    expect(res.cookies.get(AUTH_COOKIE)?.value).toBe("nuevo");
    expect(expires(res, REFRESH_BACKOFF_COOKIE)).toBe(true);
  });

  it("con COOKIE_DOMAIN, la espera lleva el dominio de la política de cookies", async () => {
    vi.stubEnv("COOKIE_DOMAIN", ".tienda.example");
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => rejection(200)) as unknown as typeof fetch,
    );

    const res = await request("/tienda", await sessionCookies());

    const line = res.headers
      .getSetCookie()
      .find((l) => l.startsWith(`${REFRESH_BACKOFF_COOKIE}=`));
    expect(line).toMatch(/domain=\.?tienda\.example/i);
  });
});

describe("refresh token caducado por su propio exp", () => {
  it("se borra sin preguntar a WordPress (página y /api)", async () => {
    const fetchMock = vi.fn(async () => rejection(200));
    vi.stubGlobal("fetch", fetchMock as unknown as typeof fetch);
    const cookies = `${AUTH_COOKIE}=${await expiredToken()}; ${REFRESH_COOKIE}=${refreshJwt(-60)}`;

    const page = await request("/tienda", cookies);
    const api = await request("/api/store/cart", cookies);

    for (const res of [page, api]) {
      expect(expires(res, REFRESH_COOKIE)).toBe(true);
      expect(expires(res, AUTH_COOKIE)).toBe(true);
    }
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("uno vigente por su exp y rechazado NO se borra", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => rejection(403)) as unknown as typeof fetch,
    );

    const res = await request(
      "/tienda",
      `${AUTH_COOKIE}=${await expiredToken()}; ${REFRESH_COOKIE}=${refreshJwt(3600)}`,
    );

    expect(touchesRefresh(res)).toBe(false);
    expect(setsBackoff(res)).toBe(true);
  });
});

describe("fallos que no dicen nada del token", () => {
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

      expect(touchesRefresh(res)).toBe(false);
      expect(setsBackoff(res)).toBe(false);
    },
  );
});
