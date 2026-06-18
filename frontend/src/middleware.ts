import { NextRequest, NextResponse } from "next/server";
import { decodeJwt } from "jose";
import {
  AUTH_COOKIE,
  REFRESH_COOKIE,
  TOKEN_EXPIRY_SKEW_SECONDS,
  DEFAULT_AUTH_TOKEN_MAX_AGE,
} from "@/lib/auth/constants";
import { REFRESH_TOKEN_MUTATION } from "@/lib/auth/mutations";

// ============================================================================
//  Middleware de refresh transparente del JWT de acceso.
//
//  Si el JWT está ausente/expirado pero existe un refresh token, lo renueva
//  contra WordPress y propaga el nuevo token:
//    - al NAVEGADOR (cookie Set-Cookie en la respuesta), y
//    - al REQUEST en curso (reescribiendo la cabecera Cookie) para que los
//      Server Components de esta misma petición ya vean el token fresco.
//
//  Solo decodifica el `exp` (no verifica firma): la verificación real ocurre en
//  el servidor vía `lib/auth/session.ts`. El middleware es UX, no la barrera.
// ============================================================================

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
    });
    if (!res.ok) return null;
    const json = (await res.json()) as {
      data?: { refreshJwtAuthToken?: { authToken?: string } };
    };
    const token = json.data?.refreshJwtAuthToken?.authToken;
    return typeof token === "string" && token.length > 0 ? token : null;
  } catch {
    return null;
  }
}

const UNSAFE_METHODS = new Set(["POST", "PUT", "PATCH", "DELETE"]);

/**
 * Verificación de Origin centralizada (edge-compatible) para escrituras en
 * `/api/*`. Es una primera barrera barata; los handlers repiten la comprobación
 * (defensa en profundidad) y añaden CSRF + rate-limit. El rate-limit/idempotencia
 * con Redis vive en los handlers porque el edge runtime no admite TCP a Redis.
 */
function originBlocked(request: NextRequest): boolean {
  const { pathname } = request.nextUrl;
  if (!pathname.startsWith("/api/")) return false;
  if (!UNSAFE_METHODS.has(request.method)) return false;

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
  // Escritura sin Origin ni Referer -> no fiable.
  return true;
}

export async function middleware(request: NextRequest) {
  // 1) Barrera de origen para escrituras en la API (incluye /api/auth).
  if (originBlocked(request)) {
    return NextResponse.json({ error: "Origen no permitido." }, { status: 403 });
  }

  // 2) Refresh transparente del JWT (no aplica a los endpoints de auth, que
  //    gestionan las cookies por sí mismos).
  if (request.nextUrl.pathname.startsWith("/api/auth/")) {
    return NextResponse.next();
  }

  const authToken = request.cookies.get(AUTH_COOKIE)?.value;
  const refreshToken = request.cookies.get(REFRESH_COOKIE)?.value;

  // Sin refresh token no hay nada que hacer.
  if (!refreshToken) {
    return NextResponse.next();
  }

  // El token de acceso sigue siendo válido: continuar sin tocar nada.
  if (authToken && !isExpired(authToken)) {
    return NextResponse.next();
  }

  const newToken = await refreshAuthToken(refreshToken);

  // Refresh fallido: limpiamos el JWT obsoleto y dejamos pasar como anónimo.
  if (!newToken) {
    const response = NextResponse.next();
    if (authToken) {
      response.cookies.delete(AUTH_COOKIE);
    }
    return response;
  }

  // Propaga el token fresco al request actual (para el SSR de esta petición).
  const requestHeaders = new Headers(request.headers);
  const forwardedCookies = request.cookies
    .getAll()
    .filter((c) => c.name !== AUTH_COOKIE)
    .map((c) => `${c.name}=${c.value}`);
  forwardedCookies.push(`${AUTH_COOKIE}=${newToken}`);
  requestHeaders.set("cookie", forwardedCookies.join("; "));

  const response = NextResponse.next({ request: { headers: requestHeaders } });

  // ...y al navegador (cookie httpOnly).
  response.cookies.set({
    name: AUTH_COOKIE,
    value: newToken,
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "lax",
    path: "/",
    maxAge: maxAgeFromToken(newToken),
  });

  return response;
}

export const config = {
  // Aplica a todo salvo estáticos. Incluye /api/auth para la barrera de Origin;
  // el refresh de JWT se omite internamente para esas rutas.
  matcher: ["/((?!_next/static|_next/image|favicon.ico).*)"],
};
