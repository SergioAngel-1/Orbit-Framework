import "server-only";

// ============================================================================
//  Opciones centralizadas para las cookies de la aplicación.
//
//  Punto único de verdad para los flags de seguridad de cookies. Lo consumirán
//  los Route Handlers de autenticación (Fase 2) y CSRF (Fase 4). Mantenerlo aquí
//  evita repetir (y olvidar) flags como `secure` o `sameSite` en cada sitio.
// ============================================================================

const isProd = process.env.NODE_ENV === "production";

/** Tipo de las opciones de cookie aceptadas por `next/headers` cookies().set(). */
export interface CookieOptions {
  httpOnly: boolean;
  secure: boolean;
  sameSite: "lax" | "strict" | "none";
  path: string;
  maxAge?: number;
  domain?: string;
}

/** Dominio opcional de cookie (subdominios). Vacío = host actual. */
const COOKIE_DOMAIN = process.env.COOKIE_DOMAIN || undefined;

/**
 * Opciones base. En producción las cookies son `Secure` (solo HTTPS).
 * `SameSite=Lax` es un buen equilibrio para sesión + navegación normal;
 * los endpoints de escritura se protegen además con CSRF (Fase 4).
 */
function baseOptions(): CookieOptions {
  return {
    httpOnly: true,
    secure: isProd,
    sameSite: "lax",
    path: "/",
    ...(COOKIE_DOMAIN ? { domain: COOKIE_DOMAIN } : {}),
  };
}

/**
 * Cookie de sesión httpOnly (p. ej. el JWT de acceso). No accesible desde JS
 * del cliente -> mitiga el robo de token por XSS.
 *
 * @param maxAgeSeconds Vida de la cookie en segundos.
 */
export function sessionCookieOptions(maxAgeSeconds: number): CookieOptions {
  return { ...baseOptions(), maxAge: maxAgeSeconds };
}

/**
 * Cookie del refresh token. Restringida por `path` para que solo se envíe al
 * endpoint de refresco, reduciendo su exposición.
 *
 * @param maxAgeSeconds Vida de la cookie en segundos.
 */
export function refreshCookieOptions(maxAgeSeconds: number): CookieOptions {
  return {
    ...baseOptions(),
    path: "/api/auth/refresh",
    maxAge: maxAgeSeconds,
  };
}

/**
 * Cookie del token CSRF. A diferencia de las de sesión, NO es httpOnly: el
 * cliente necesita leerla para reenviarla en la cabecera `X-CSRF-Token`
 * (patrón double-submit cookie, Fase 4).
 *
 * @param maxAgeSeconds Vida de la cookie en segundos.
 */
export function csrfCookieOptions(maxAgeSeconds: number): CookieOptions {
  return {
    httpOnly: false,
    secure: isProd,
    sameSite: "lax",
    path: "/",
    maxAge: maxAgeSeconds,
    ...(COOKIE_DOMAIN ? { domain: COOKIE_DOMAIN } : {}),
  };
}

/** Opciones para BORRAR una cookie (maxAge 0). */
export function expiredCookieOptions(path = "/"): CookieOptions {
  return { ...baseOptions(), path, maxAge: 0 };
}
