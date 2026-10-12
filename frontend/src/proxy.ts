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

// ============================================================================
//  Proxy compuesto (antes "middleware"; Next 16 renombró el convenio a `proxy`,
//  mismo comportamiento y runtime edge):
//   1. Barrera de Origin para escrituras en /api  (edge-compatible).
//   2. next-intl: detección/redirect/rewrite de locale (solo páginas).
//   3. Refresh transparente del JWT: si caducó, lo renueva y lo propaga al
//      navegador y al request en curso (para que el SSR vea el token fresco).
//
//  next-intl NO debe tocar /api ni archivos (sitemap.xml, *.png…): el matcher
//  excluye rutas con punto, y /api se gestiona en su propia rama.
// ============================================================================

const intlMiddleware = createMiddleware(routing);
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
    process.env.WORDPRESS_INTERNAL_API_URL ??
    process.env.NEXT_PUBLIC_WORDPRESS_API_URL ??
    "http://localhost:8080/graphql";
  try {
    const res = await fetch(endpoint, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
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

  // 3) Páginas: refresca (reconstruyendo el request) y deja que next-intl
  //    construya la respuesta de locale sobre el request ya actualizado.
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

  const response = intlMiddleware(workingRequest);
  if (newToken) response.cookies.set(authCookie(newToken));
  if (clearAuth) response.cookies.delete(AUTH_COOKIE);
  response.headers.set(REQUEST_ID_HEADER, requestId);
  return response;
}

export const config = {
  // Excluye internos de Next y cualquier ruta con punto (sitemap.xml, *.png…).
  // /api/* sí entra (para la barrera de Origin y el refresh).
  matcher: ["/((?!_next|.*\\..*).*)"],
};
