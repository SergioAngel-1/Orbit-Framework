import createMiddleware from "next-intl/middleware";
import { NextRequest, NextResponse } from "next/server";
import { decodeJwt } from "jose";
import { routing } from "@/i18n/routing";
import {
  AUTH_COOKIE,
  REFRESH_BACKOFF_COOKIE,
  REFRESH_BACKOFF_SECONDS,
  REFRESH_COOKIE,
  TOKEN_EXPIRY_SKEW_SECONDS,
  DEFAULT_AUTH_TOKEN_MAX_AGE,
} from "@/lib/auth/constants";
import { REFRESH_TOKEN_MUTATION } from "@/lib/auth/mutations";
import {
  getOrCreateRequestId,
  REQUEST_ID_HEADER,
} from "@/lib/observability/request-id";
import { isPublicMachineRoute, isSignedRoute } from "@/lib/security/signed-routes";
import { internalSecretHeaders } from "@/lib/security/internal-secret";
import { toInternalPath, toPublicPath } from "@/lib/instance/public-paths";
import { compileRedirects, matchRedirect } from "@/lib/instance/redirects";
import { ENV_NOINDEX_HEADER, indexingBlockedByEnv } from "@/lib/seo/indexing";
import { normalizeReferralCode } from "@/lib/loyalty/referral-code";
import { REF_COOKIE, refCookieOptions } from "@/lib/loyalty/ref-cookie";
import { baseCookieOptions } from "@/lib/security/cookie-policy";

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

/**
 * ¿El refresh token ya caducó por su propio `exp`? Se lee sin verificar la firma:
 * solo decide BORRAR la cookie, y quien falsifique un `exp` pasado solo se cierra
 * su propia sesión. Un token sin `exp` legible no se da por caducado (decide
 * WordPress).
 */
function refreshTokenExpired(token: string): boolean {
  try {
    const { exp } = decodeJwt(token);
    return typeof exp === "number" && exp <= Math.floor(Date.now() / 1000);
  } catch {
    return false;
  }
}

/**
 * Huella del refresh token para la cookie de espera: SHA-256 truncado, en base64url.
 * Liga la espera a ESE token (una sesión nueva trae otro y no espera) sin guardar
 * el token en otra cookie.
 */
async function refreshFingerprint(token: string): Promise<string> {
  const digest = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(token));
  let binary = "";
  for (const byte of new Uint8Array(digest).slice(0, 16)) {
    binary += String.fromCharCode(byte);
  }
  return btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
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
 * una página que no carga. Un rechazo de WordPress (ver `isDefinitiveRejection`)
 * tampoco la borra: deja una espera corta (ver `resolveSession`).
 */
const REFRESH_TIMEOUT_MS = 3_000;

/**
 * Refresh en vuelo, por refresh token. El mapa vive por isolate (el proxy corre
 * en edge): basta para que varias peticiones simultáneas del mismo navegador
 * —varias tarjetas, un prefetch, el RSC y el documento— compartan UNA llamada a
 * WPGraphQL en vez de una cada una. El token es la clave y no se registra nunca:
 * ni en logs ni en eventos; el mapa se borra al resolverse la promesa, también
 * cuando falla o expira.
 */
const refreshInFlight = new Map<string, Promise<RefreshOutcome>>();

/**
 * Resultado del refresh. `token: null` con `rejected: true` es un rechazo de
 * WordPress con forma de rechazo: el proxy deja de preguntar durante un rato
 * (cookie de espera). Con `rejected: false` (timeout, red, 5xx…) no se sabe nada
 * del token y el siguiente request reintenta.
 */
type RefreshOutcome =
  { token: string; rejected: false } | { token: null; rejected: boolean };

function refreshAuthTokenOnce(refreshToken: string): Promise<RefreshOutcome> {
  const pending = refreshInFlight.get(refreshToken);
  if (pending) return pending;

  const tracked = refreshAuthToken(refreshToken).finally(() => {
    // Solo se borra si sigue siendo esta misma promesa: si ya se sustituyó (no
    // debería, pero el borrado nunca debe pisar un refresh más nuevo), se deja.
    if (refreshInFlight.get(refreshToken) === tracked) {
      refreshInFlight.delete(refreshToken);
    }
  });
  refreshInFlight.set(refreshToken, tracked);
  return tracked;
}

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

/**
 * ¿WordPress rechazó el refresh token?
 *
 * Solo si el resolver de `refreshJwtAuthToken` corrió y dijo que no: cuerpo
 * GraphQL con `data.refreshJwtAuthToken` a null y algún error. Así responde
 * WPGraphQL JWT Auth a un token mal formado o caducado (HTTP 200) y a uno de otro
 * emisor o con el secreto del usuario revocado (401/403). No cuenta un 5xx, un
 * 429, un cuerpo sin `data` (p. ej. el 404 de `hwe-bff-only` cuando falta el
 * secreto interno: es configuración, no el token) ni el «Internal server error»
 * con el que graphql-php enmascara un fallo interno del resolver.
 *
 * OJO: no es una prueba de que el token esté muerto. La MISMA respuesta sale si a
 * WordPress le falta `GRAPHQL_JWT_AUTH_SECRET_KEY` (403) o si no puede leer el
 * secreto del usuario (`hwe-auth/Session.php`, también por un fallo de BD). Por
 * eso un rechazo no borra el refresh token: solo frena los reintentos.
 */
function isDefinitiveRejection(status: number, body: unknown): boolean {
  if (status !== 200 && status !== 401 && status !== 403) return false;
  if (typeof body !== "object" || body === null) return false;
  const { data, errors } = body as { data?: unknown; errors?: unknown };
  if (typeof data !== "object" || data === null) return false;
  if (!("refreshJwtAuthToken" in data)) return false;
  if ((data as { refreshJwtAuthToken: unknown }).refreshJwtAuthToken !== null)
    return false;
  if (!Array.isArray(errors) || errors.length === 0) return false;
  return !errors.some(
    (error) =>
      typeof error === "object" &&
      error !== null &&
      (error as { message?: unknown }).message === "Internal server error",
  );
}

const NOT_REFRESHED: RefreshOutcome = { token: null, rejected: false };

async function refreshAuthToken(refreshToken: string): Promise<RefreshOutcome> {
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
    // El cuerpo se lee también con 401/403: es ahí donde WordPress dice que el
    // token está revocado. Un cuerpo ilegible no prueba nada sobre el token.
    const json = (await res.json().catch(() => null)) as {
      data?: { refreshJwtAuthToken?: { authToken?: string } | null };
    } | null;
    if (res.ok) {
      const token = json?.data?.refreshJwtAuthToken?.authToken;
      if (typeof token === "string" && token.length > 0) {
        return { token, rejected: false };
      }
    }
    if (isDefinitiveRejection(res.status, json)) {
      logEdgeWarning("proxy.refresh_rejected", { status: res.status });
      return { token: null, rejected: true };
    }
    logEdgeWarning("proxy.refresh_failed", {
      reason: res.ok ? "respuesta sin authToken" : `HTTP ${res.status}`,
    });
    return NOT_REFRESHED;
  } catch (error) {
    logEdgeWarning("proxy.refresh_failed", {
      reason: error instanceof Error ? `${error.name}: ${error.message}` : "error",
    });
    return NOT_REFRESHED;
  }
}

/** Lo que la respuesta tiene que hacer con las cookies de sesión. */
interface SessionCookieOps {
  /** Token de acceso nuevo. */
  setAuth?: string;
  /** Retirar el acceso: `delete` (como siempre) o `expire` (con la política del BFF). */
  clearAuth?: "delete" | "expire";
  /** Borrar el refresh token (solo si caducó por su propio `exp`). */
  expireRefresh?: boolean;
  /** Dejar la espera con esta huella. */
  setBackoff?: string;
  /** Retirar una espera que ya no aplica. */
  expireBackoff?: boolean;
}

/**
 * Decide el refresh de esta petición. Sin refresh token o con el acceso vigente no
 * hace nada. Si no:
 *  - refresh token caducado por su `exp`: se borran las dos cookies, sin WordPress;
 *  - espera vigente de ESE refresh token: no se pregunta (visitante anónimo);
 *  - si no, se pide a WordPress. Bien: acceso nuevo y fuera la espera. Rechazo:
 *    espera de `REFRESH_BACKOFF_SECONDS` y el refresh token SE QUEDA (el rechazo
 *    puede ser de configuración o de BD, ver `isDefinitiveRejection`). Fallo
 *    transitorio: el siguiente request reintenta, como siempre.
 */
async function resolveSession(
  request: NextRequest,
): Promise<{ newToken: string | null; ops: SessionCookieOps }> {
  const authToken = request.cookies.get(AUTH_COOKIE)?.value;
  const refreshToken = request.cookies.get(REFRESH_COOKIE)?.value;
  if (!refreshToken || (authToken && !isExpired(authToken))) {
    return { newToken: null, ops: {} };
  }
  const backoff = request.cookies.get(REFRESH_BACKOFF_COOKIE)?.value;

  if (refreshTokenExpired(refreshToken)) {
    logEdgeWarning("proxy.refresh_expired", {});
    return {
      newToken: null,
      ops: { clearAuth: "expire", expireRefresh: true, expireBackoff: !!backoff },
    };
  }

  // La huella solo se calcula si hay espera: sin ella, el camino hasta el refresh
  // compartido (`refreshAuthTokenOnce`) sigue siendo síncrono y las peticiones
  // simultáneas se deduplican.
  if (backoff && backoff === (await refreshFingerprint(refreshToken))) {
    return { newToken: null, ops: {} };
  }

  const outcome = await refreshAuthTokenOnce(refreshToken);
  if (outcome.token) {
    return {
      newToken: outcome.token,
      ops: { setAuth: outcome.token, expireBackoff: !!backoff },
    };
  }
  if (outcome.rejected) {
    return {
      newToken: null,
      ops: {
        clearAuth: authToken ? "expire" : undefined,
        setBackoff: await refreshFingerprint(refreshToken),
      },
    };
  }
  return { newToken: null, ops: { clearAuth: authToken ? "delete" : undefined } };
}

function applySessionCookies(response: NextResponse, ops: SessionCookieOps): void {
  if (ops.setAuth) response.cookies.set(authCookie(ops.setAuth));
  if (ops.clearAuth === "delete") response.cookies.delete(AUTH_COOKIE);
  if (ops.clearAuth === "expire") expireSessionCookie(response, AUTH_COOKIE);
  if (ops.expireRefresh) expireSessionCookie(response, REFRESH_COOKIE);
  if (ops.setBackoff) {
    response.cookies.set(REFRESH_BACKOFF_COOKIE, ops.setBackoff, {
      ...baseCookieOptions(),
      maxAge: REFRESH_BACKOFF_SECONDS,
    });
  } else if (ops.expireBackoff) {
    expireSessionCookie(response, REFRESH_BACKOFF_COOKIE);
  }
}

/**
 * Borra una cookie de sesión con la MISMA política con la que la deja el BFF
 * (`lib/security/cookies.ts`): con `COOKIE_DOMAIN` el borrado necesita ese
 * dominio, o el navegador conserva la cookie original.
 */
function expireSessionCookie(response: NextResponse, name: string): void {
  response.cookies.set(name, "", { ...baseCookieOptions(), maxAge: 0 });
}

function originBlocked(request: NextRequest): boolean {
  const { pathname } = request.nextUrl;
  if (!pathname.startsWith("/api/")) return false;
  if (!UNSAFE_METHODS.has(request.method)) return false;
  // Webhooks y revalidación: los llama otro servidor (no traen Origin) y se
  // autentican por firma. Lista cerrada; ver lib/security/signed-routes.ts.
  if (isSignedRoute(pathname)) return false;
  // Rutas públicas de máquina de la instancia: sin sesión por contrato (test).
  if (isPublicMachineRoute(pathname)) return false;

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

  // 2) Rutas /api: sin locale. Refresh JWT salvo en /api/auth/*.
  if (pathname.startsWith("/api")) {
    if (pathname.startsWith("/api/auth/")) {
      const res = NextResponse.next({ request: { headers: fwd } });
      res.headers.set(REQUEST_ID_HEADER, requestId);
      return res;
    }
    const { newToken, ops } = await resolveSession(request);
    let res: NextResponse;
    if (newToken) {
      const headers = new Headers(request.headers);
      headers.set("cookie", cookieHeaderWithAuth(request, newToken));
      headers.set(REQUEST_ID_HEADER, requestId);
      res = NextResponse.next({ request: { headers } });
    } else {
      res = NextResponse.next({ request: { headers: fwd } });
    }
    applySessionCookies(res, ops);
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
  const session = await resolveSession(request);
  if (session.newToken) {
    const headers = new Headers(request.headers);
    headers.set("cookie", cookieHeaderWithAuth(request, session.newToken));
    headers.set(REQUEST_ID_HEADER, requestId);
    workingRequest = new NextRequest(request.url, { headers });
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

  applySessionCookies(response, session.ops);
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
