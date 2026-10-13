/**
 * Validación de email para formularios públicos (newsletter, contacto).
 * Más estricta que el type="email" del navegador: exige TLD ≥ 2 letras,
 * un solo @, sin espacios, longitud razonable.
 */

/** Local@dominio con al menos un punto y TLD de ≥2 letras. */
const EMAIL_RE =
  /^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*\.[a-zA-Z]{2,}$/;

export const EMAIL_MAX_LENGTH = 200;

/** Normaliza para comparar/enviar (trim + minúsculas). */
export function normalizeEmail(raw: string): string {
  return (raw || "").trim().toLowerCase();
}

export type EmailValidationResult =
  { ok: true; email: string } | { ok: false; reason: "empty" | "too_long" | "invalid" };

export function validateEmail(raw: string): EmailValidationResult {
  const email = normalizeEmail(raw);
  if (!email) return { ok: false, reason: "empty" };
  if (email.length > EMAIL_MAX_LENGTH) return { ok: false, reason: "too_long" };
  if (!EMAIL_RE.test(email)) return { ok: false, reason: "invalid" };
  // Doble punto / dominio incompleto ya cubiertos por el regex; rechazo extra.
  if (email.includes("..")) return { ok: false, reason: "invalid" };
  return { ok: true, email };
}
