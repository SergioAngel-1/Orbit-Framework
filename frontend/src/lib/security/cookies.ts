import "server-only";
import { baseCookieOptions, type CookieOptions } from "./cookie-policy";

// ============================================================================
//  Opciones centralizadas para las cookies de la aplicación.
//
//  Punto único de verdad para los flags de seguridad de cookies del BFF. La
//  base (`secure`, `sameSite`, `path`, `httpOnly` y `COOKIE_DOMAIN`) vive en
//  `cookie-policy.ts`, que también usa `proxy.ts` en edge; aquí solo se ajusta
//  lo propio de cada cookie. Mantenerlo así evita repetir (y olvidar) flags
//  como `secure` en cada sitio.
// ============================================================================

export type { CookieOptions } from "./cookie-policy";

/** Opciones base del framework (las mismas que usa el proxy en edge). */
function baseOptions(): CookieOptions {
  return baseCookieOptions();
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
 * Cookie del refresh token. `httpOnly` (inaccesible desde JS) y disponible en
 * todo el sitio (`path: "/"`) para que el proxy pueda auto-refrescar el JWT de
 * acceso en cualquier ruta. `SameSite=Lax` evita su envío en peticiones
 * cross-site de terceros.
 *
 * @param maxAgeSeconds Vida de la cookie en segundos.
 */
export function refreshCookieOptions(maxAgeSeconds: number): CookieOptions {
  return { ...baseOptions(), maxAge: maxAgeSeconds };
}

/**
 * Cookie del token CSRF. A diferencia de las de sesión, NO es httpOnly: el
 * cliente necesita leerla para reenviarla en la cabecera `X-CSRF-Token`
 * (patrón double-submit cookie).
 *
 * @param maxAgeSeconds Vida de la cookie en segundos.
 */
export function csrfCookieOptions(maxAgeSeconds: number): CookieOptions {
  return { ...baseOptions(), httpOnly: false, maxAge: maxAgeSeconds };
}

/** Opciones para BORRAR una cookie (maxAge 0). */
export function expiredCookieOptions(path = "/"): CookieOptions {
  return { ...baseOptions(), path, maxAge: 0 };
}

/**
 * Cookie httpOnly con el COMPROBANTE del registro de consentimientos del
 * dispositivo (`hwe_consent`). `SameSite=Lax` basta: solo la lee el BFF, en las
 * peticiones del propio sitio.
 */
export function consentReceiptCookieOptions(maxAgeSeconds: number): CookieOptions {
  return { ...baseOptions(), maxAge: maxAgeSeconds };
}
