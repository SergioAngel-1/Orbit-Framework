import { instanceRegistry } from "@/instance/registry";
import { baseCookieOptions, type CookieOptions } from "@/lib/security/cookie-policy";

// ============================================================================
//  Cookie del código de referido (`hwe_ref`).
//
//  La deja `proxy.ts` cuando una página llega con `?ref=<código>` y la consume
//  el registro del BFF. Sin `server-only` a propósito: el proxy corre en edge y
//  necesita estas constantes y opciones. La decisión de usarla (módulo y
//  «Referidos» encendidos) es del registro, no del proxy.
//
//  La ventana la declara la instancia en `InstanceRegistry.referralCookieDays` y no en
//  el Control Center: el proxy no consulta WordPress ni la config, y hacerlo solo para
//  esto añadiría una llamada a cada visita con `?ref=`.
// ============================================================================

/** Nombre de la cookie que deja el proxy al capturar `?ref=`. */
export const REF_COOKIE = "hwe_ref";

/** 30 días: margen de sobra para que el visitante decida crear la cuenta. */
export const REF_COOKIE_MAX_AGE = 30 * 24 * 60 * 60;

const SECONDS_PER_DAY = 24 * 60 * 60;
const MAX_DAYS = 365;

/** Se evalúa en cada visita con `?ref=`: el aviso sale una vez por proceso. */
let invalidDaysReported = false;

/** Vida de `hwe_ref` en segundos. Un valor inválido deja un aviso y cae a los 30 días. */
export function refCookieMaxAge(
  days: number | undefined = instanceRegistry.referralCookieDays,
): number {
  if (days === undefined) return REF_COOKIE_MAX_AGE;
  if (!Number.isInteger(days) || days < 1 || days > MAX_DAYS) {
    // Consola y no logger: este módulo también corre en edge (`proxy.ts`).
    if (!invalidDaysReported) {
      invalidDaysReported = true;
      console.warn(JSON.stringify({ event: "instance.referral_cookie_invalid", days }));
    }
    return REF_COOKIE_MAX_AGE;
  }
  return days * SECONDS_PER_DAY;
}

/**
 * Opciones de `hwe_ref`: las mismas que el resto de cookies del framework
 * (`httpOnly`, `SameSite=Lax`, `path=/` y `Secure` en producción) con la vida
 * propia de esta cookie.
 */
export function refCookieOptions(): CookieOptions {
  return { ...baseCookieOptions(), maxAge: refCookieMaxAge() };
}
