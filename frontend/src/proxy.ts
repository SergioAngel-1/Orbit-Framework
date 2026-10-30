import createMiddleware from "next-intl/middleware";
import { NextRequest, NextResponse } from "next/server";
import { decodeJwt } from "jose";
import { routing } from "@/i18n/routing";
import {
  AUTH_COOKIE,
  REFRESH_COOKIE,
  TOKEN_EXPIRY_SKEW_SECONDS,
  DEFAULT_AUTH_TOKEN_MAX_AGE,
} from "@/lib/auth/constants";
import { REFRESH_TOKEN_MUTATION } from "@/lib/auth/mutations";
import {
  getOrCreateRequestId,
  REQUEST_ID_HEADER,
} from "@/lib/observability/request-id";
import { isSignedRoute } from "@/lib/security/signed-routes";
import { internalSecretHeaders } from "@/lib/security/internal-secret";
import { toInternalPath, toPublicPath } from "@/lib/instance/public-paths";
import { compileRedirects, matchRedirect } from "@/lib/instance/redirects";
import { ENV_NOINDEX_HEADER, indexingBlockedByEnv } from "@/lib/seo/indexing";
import { normalizeReferralCode } from "@/lib/loyalty/referral-code";
import { REF_COOKIE, refCookieOptions } from "@/lib/loyalty/ref-cookie";

// ============================================================================
//  Proxy compuesto (antes "middleware"; Next 16 renombró el convenio a `proxy`,
//  mismo comportamiento y runtime edge):
//   1. Barrera de Origin para escrituras en /api  (edge-compatible).
//   2. Redirecciones 301 de la instancia (`InstanceRegistry.redirects`).
//   3. next-intl: detección/redirect/rewrite de locale (solo páginas).
//   4. Refresh transparente del JWT: si caducó, lo renueva y lo propaga al
//      navegador y al request en curso (para que el SSR vea el token fresco).
//   5. Captura de `?ref=<código>` en páginas: deja la cookie httpOnly `hwe_ref`
//      sin consultar WordPress ni la config (edge). La URL no cambia.
//
//  next-intl NO debe tocar /api ni archivos (sitemap.xml, *.png…): el matcher
//  excluye rutas con punto, y /api se gestiona en su propia rama.
// ============================================================================

const intlMiddleware = createMiddleware(routing);
/** Reglas de `InstanceRegistry.redirects`, compiladas una vez por arranque. */
const INSTANCE_REDIRECTS = compileRedirects();
const UNSAFE_METHODS = new Set(["POST", "PUT", "PATCH", "DELETE"]);

function isExpired(token: string): boolean {
  try {
    const { exp } = decodeJwt(token);
    if (typeof exp !== "number") return true;
    return exp - TOKEN_EXPIRY_SKEW_SECONDS <= Math.floor(Date.now() / 1000);
  } catch {
    return true;
  }
}

function maxAgeFromToken(token: string): number {
  try {
    const { exp } = decodeJwt(token);
    if (typeof exp === "number") {
      const seconds = exp - Math.floor(Date.now() / 1000);
      if (seconds > 0) return seconds;
    }
  } catch {
    /* noop */
  }
  return DEFAULT_AUTH_TOKEN_MAX_AGE;
}

/**
 * Timeout del refresh, en ms.
 *
 * Sin él esta petición no tenía techo, y está en el camino de TODA petición de
 * página: el JWT de acceso dura ~5 min (`DEFAULT_AUTH_TOKEN_MAX_AGE`), así que
 * cualquier visitante con sesión pasa por aquí varias veces por sesión. Un
 * WordPress que acepta la conexión y no responde —saturado, con una query
 * bloqueada en MySQL— dejaba colgada la navegación entera, en el edge, antes de
 * que ningún Route Handler pudiera degradar nada.
 *
 * Más corto que el timeout del cliente GraphQL a propósito: esto no es una
 * consulta de datos que alguien esté esperando ver, es un trámite de sesión con
 * degradado limpio. Si no responde a tiempo se sigue sin token renovado —el
 * visitante se ve como anónimo en esa respuesta y la siguiente lo reintenta,
 * porque la cookie de refresco sobrevive—, que es infinitamente preferible a
 * una página que no carga.
 */
const REFRESH_TIMEOUT_MS = 3_000;

/**
 * Evento estructurado desde el edge. Aquí no hay pino (es de Node), pero un fallo
 * que se traga debe dejar rastro: una línea JSON por stdout, con la misma forma
 * (`event`, `service`) que el resto de logs.
 */
function logEdgeWarning(event: string, fields: Record<string, unknown>): void {
  console.warn(
    JSON.stringify({ level: "warn", service: "hwe-frontend", event, ...fields }),
  );
}

async function refreshAuthToken(refreshToken: string): Promise<string | null> {
  const endpoint =
    process.env.WORDPRESS_INTERNAL_API_URL ?? "http://localhost:8080/graphql";
  try {
    const res = await fetch(endpoint, {
      method: "POST",
      // `hwe-bff-only` solo deja pasar a GraphQL a quien presente el secreto interno.
      headers: { "Content-Type": "application/json", ...internalSecretHeaders() },
      body: JSON.stringify({
        query: REFRESH_TOKEN_MUTATION,
        variables: { refreshToken },
      }),
      signal: AbortSignal.timeout(REFRESH_TIMEOUT_MS),
    });
    if (!res.ok) {
      logEdgeWarning("proxy.refresh_failed", { reason: `HTTP ${res.status}` });
      return null;
    }
    const json = (await res.json()) as {
      data?: { refreshJwtAuthToken?: { authToken?: string } };
    };
    const token = json.data?.refreshJwtAuthToken?.authToken;
    if (typeof token === "string" && token.length > 0) return token;
    logEdgeWarning("proxy.refresh_failed", { reason: "respuesta sin authToken" });
    return null;
  } catch (error) {
    logEdgeWarning("proxy.refresh_failed", {
      reason: error instanceof Error ? `${error.name}: ${error.message}` : "error",
    });
    return null;
  }
}

function originBlocked(request: NextRequest): boolean {
  const { pathname } = request.nextUrl;
  if (!pathname.startsWith("/api/")) return false;
  if (!UNSAFE_METHODS.has(request.method)) return false;
  // Webhooks y revalidación: los llama otro servidor (no traen Origin) y se
  // autentican por firma. Lista cerrada; ver lib/security/signed-routes.ts.
  if (isSignedRoute(pathname)) return false;

  const allowed = process.env.ALLOWED_ORIGIN;
  if (!allowed) return false;

  const origin = request.headers.get("origin");
  if (origin) return origin !== allowed;

  const referer = request.headers.get("referer");
  if (referer) {
    try {
      return new URL(referer).origin !== allowed;
    } catch {
      return true;
    }
  }
  return true;
}

/** Reconstruye el cookie header del request con el authToken renovado. */
function cookieHeaderWithAuth(request: NextRequest, token: string): string {
  return request.cookies
    .getAll()
    .filter((c) => c.name !== AUTH_COOKIE)
    .map((c) => `${c.name}=${c.value}`)
    .concat(`${AUTH_COOKIE}=${token}`)
    .join("; ");
}

function authCookie(token: string) {
  return {
    name: AUTH_COOKIE,
    value: token,
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "lax" as const,
    path: "/",
    maxAge: maxAgeFromToken(token),
  };
}

/** Parte un pathname en su prefijo de idioma y la ruta sin prefijo. */
function splitLocale(pathname: string): {
  prefix: string;
  rest: string;
  locale: string;
} {
  const parts = pathname.split("/");
  const first = parts[1] ?? "";
  if ((routing.locales as readonly string[]).includes(first)) {
    const rest = parts.length > 2 ? `/${parts.slice(2).join("/")}` : "/";
    return { prefix: `/${first}`, rest, locale: first };
  }
  return {
    prefix: "",
    rest: pathname === "" ? "/" : pathname,
    locale: routing.defaultLocale,
  };
}

function isRedirect(response: NextResponse): boolean {
  return response.status >= 300 && response.status < 400;
}

/**
 * Un redirect de next-intl puede llevar en `Location` una ruta INTERNA (si venía de una
 * ruta interna): se publica antes de devolverlo. La consulta se conserva.
 */
function publicizeRedirect(response: NextResponse, baseUrl: string): NextResponse {
  const location = response.headers.get("location");
  if (!location) return response;
  try {
    const url = new URL(location, baseUrl);
    const { prefix, rest, locale } = splitLocale(url.pathname);
    const publicRest = toPublicPath(rest, locale);
    if (publicRest === rest) return response;
    url.pathname = `${prefix}${publicRest}`;
    response.headers.set("location", url.toString());
  } catch {
    /* una Location que no es una URL: se devuelve tal cual */
  }
  return response;
}

export async function proxy(request: NextRequest) {
  const { pathname } = request.nextUrl;

  // Request-id de correlación: lo reutilizamos del entrante (Caddy lo genera y
  // reenvía) o creamos uno. Se reenvía a los handlers (request) y se devuelve en
  // la respuesta para alinear logs de Caddy, Next y navegador.
  const requestId = getOrCreateRequestId(request.headers);
  const fwd = new Headers(request.headers);
  fwd.set(REQUEST_ID_HEADER, requestId);

  // 1) Barrera de Origin para escrituras en la API.
  if (originBlocked(request)) {
    const res = NextResponse.json({ error: "Origen no permitido." }, { status: 403 });
    res.headers.set(REQUEST_ID_HEADER, requestId);
    return res;
  }

  const authToken = request.cookies.get(AUTH_COOKIE)?.value;
  const refreshToken = request.cookies.get(REFRESH_COOKIE)?.value;
  const needsRefresh = !!refreshToken && (!authToken || isExpired(authToken));

  // 2) Rutas /api: sin locale. Refresh JWT salvo en /api/auth/*.
  if (pathname.startsWith("/api")) {
    if (pathname.startsWith("/api/auth/") || !needsRefresh) {
      const res = NextResponse.next({ request: { headers: fwd } });
      res.headers.set(REQUEST_ID_HEADER, requestId);
      return res;
    }
    const newToken = await refreshAuthToken(refreshToken!);
    if (!newToken) {
      const res = NextResponse.next({ request: { headers: fwd } });
      if (authToken) res.cookies.delete(AUTH_COOKIE);
      res.headers.set(REQUEST_ID_HEADER, requestId);
      return res;
    }
    const headers = new Headers(request.headers);
    headers.set("cookie", cookieHeaderWithAuth(request, newToken));
    headers.set(REQUEST_ID_HEADER, requestId);
    const res = NextResponse.next({ request: { headers } });
    res.cookies.set(authCookie(newToken));
    res.headers.set(REQUEST_ID_HEADER, requestId);
    return res;
  }

  // 3) Redirecciones de la instancia (migración): antes que el idioma y que el
  //    refresh, porque la URL antigua no es una página de este sitio.
  const legacy = matchRedirect(pathname, request.nextUrl.search, INSTANCE_REDIRECTS);
  if (legacy) {
    const res = NextResponse.redirect(
      new URL(legacy.location, request.url),
      legacy.status,
    );
    res.headers.set(REQUEST_ID_HEADER, requestId);
    return res;
  }

  // 4) Páginas: refresca (reconstruyendo el request) y deja que next-intl
  //    construya la respuesta de locale sobre el request ya actualizado.
  //
  //    Un `?ref=<código>` con formato válido se guarda en una cookie httpOnly
  //    (`InstanceRegistry.referralCookieDays`, 30 días por defecto). Aquí NO se
  //    consulta WordPress ni la config: el proxy corre en edge y la decisión de usarlo
  //    es del registro, que conoce el módulo y sus interruptores. Un formato inválido no toca la cookie que ya hubiera y la
  //    URL se queda como está (sin redirección para limpiar el parámetro).
  const referralCode = normalizeReferralCode(request.nextUrl.searchParams.get("ref"));

  let workingRequest = new NextRequest(request.url, { headers: fwd });
  let newToken: string | null = null;
  let clearAuth = false;

  if (needsRefresh) {
    newToken = await refreshAuthToken(refreshToken!);
    if (newToken) {
      const headers = new Headers(request.headers);
      headers.set("cookie", cookieHeaderWithAuth(request, newToken));
      headers.set(REQUEST_ID_HEADER, requestId);
      workingRequest = new NextRequest(request.url, { headers });
    } else if (authToken) {
      clearAuth = true;
    }
  }

  // Rutas públicas de la instancia (`InstanceRegistry.publicPaths`): la URL pública sirve
  // la carpeta INTERNA de `app/[locale]/` (rewrite) y la ruta interna antigua redirige 308
  // a la pública, para no dejar dos URLs del mismo contenido. Sin mapa, las dos funciones
  // son la identidad y no se toca nada (comportamiento idéntico al del framework).
  const localePath = splitLocale(request.nextUrl.pathname);
  const internalRest = toInternalPath(localePath.rest, localePath.locale);
  const publicRest = toPublicPath(localePath.rest, localePath.locale);

  let response = intlMiddleware(workingRequest);

  if (internalRest === localePath.rest && publicRest !== localePath.rest) {
    // URL interna → 308 a la pública.
    const url = new URL(request.url);
    url.pathname = `${localePath.prefix}${publicRest}`;
    response = NextResponse.redirect(url, 308);
  } else if (internalRest !== localePath.rest) {
    // URL pública → se renderiza la carpeta interna (rewrite) con el idioma que resolvió
    // next-intl (que ya ha aplicado su detección y sus redirects de prefijo).
    if (!isRedirect(response)) {
      const headers = new Headers(workingRequest.headers);
      const intlLocale = response.headers.get(
        "x-middleware-request-x-next-intl-locale",
      );
      if (intlLocale) headers.set("x-next-intl-locale", intlLocale);
      const url = new URL(workingRequest.url);
      // La carpeta de `app/[locale]/` necesita SIEMPRE el tramo de idioma interno (`/es/...`
      // para el principal), igual que hace next-intl con su propio rewrite.
      url.pathname = `/${localePath.locale}${internalRest}`;
      const rewritten = NextResponse.rewrite(url, { request: { headers } });
      for (const cookie of response.cookies.getAll()) rewritten.cookies.set(cookie);
      const alternate = response.headers.get("link");
      if (alternate) rewritten.headers.set("link", alternate);
      response = rewritten;
    } else {
      response = publicizeRedirect(response, request.url);
    }
  }

  if (newToken) response.cookies.set(authCookie(newToken));
  if (clearAuth) response.cookies.delete(AUTH_COOKIE);
  // El último código que llega reemplaza al anterior; si no hay, la cookie que
  // ya tuviera el navegador se queda intacta.
  if (referralCode) response.cookies.set(REF_COOKIE, referralCode, refCookieOptions());
  // Guarda de staging (`HWE_NOINDEX`): también en la cabecera, que un buscador
  // respeta aunque no llegue a leer el `<meta>` (redirecciones, errores).
  if (indexingBlockedByEnv()) response.headers.set("X-Robots-Tag", ENV_NOINDEX_HEADER);
  response.headers.set(REQUEST_ID_HEADER, requestId);
  return response;
}

export const config = {
  // Excluye internos de Next (`_next`), cualquier ruta con punto (sitemap.xml,
  // *.png…) y las dos rutas de metadatos dinámicas que Next sirve sin
  // extensión (`/icon` y `/apple-icon`): next-intl las trataba como páginas y
  // respondía un 404 en HTML en vez de la imagen. Las formas con barra
  // (`icon/`, `apple-icon/`) cubren además `/icon/<id>` y `/apple-icon/<id>`,
  // que sirve `generateImageMetadata`. La exclusión es por ruta completa: una
  // página llamada `/icon-guide` (o `/en/icon/…`) sigue pasando por el proxy.
  // /api/* sí entra (para la barrera de Origin y el refresh).
  matcher: ["/((?!_next|.*\\..*|icon$|icon/|apple-icon$|apple-icon/).*)"],
};
