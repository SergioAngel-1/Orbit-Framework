import { baseCookieOptions, type CookieOptions } from "@/lib/security/cookie-policy";

// ============================================================================
//  Cookie del código de referido (`hwe_ref`).
//
//  La deja `proxy.ts` cuando una página llega con `?ref=<código>` y la consume
//  el registro del BFF. Sin `server-only` a propósito: el proxy corre en edge y
//  necesita estas constantes y opciones. La decisión de usarla (módulo y
//  «Referidos» encendidos) es del registro, no del proxy.
// ============================================================================

/** Nombre de la cookie que deja el proxy al capturar `?ref=`. */
export const REF_COOKIE = "hwe_ref";

/** 30 días: margen de sobra para que el visitante decida crear la cuenta. */
export const REF_COOKIE_MAX_AGE = 30 * 24 * 60 * 60;

/**
 * Opciones de `hwe_ref`: las mismas que el resto de cookies del framework
 * (`httpOnly`, `SameSite=Lax`, `path=/` y `Secure` en producción) con la vida
 * propia de esta cookie.
 */
export function refCookieOptions(): CookieOptions {
  return { ...baseCookieOptions(), maxAge: REF_COOKIE_MAX_AGE };
}
