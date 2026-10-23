// ============================================================================
//  Formato de un código de referido. Puro y apto para EDGE: `proxy.ts` lo usa
//  para decidir si deja la cookie y el registro del BFF para no mandar a
//  WordPress algo que no puede existir.
//
//  Debe coincidir con `HWE_Loyalty_Referrals::CODE_PATTERN` (PHP): 8 caracteres
//  del alfabeto sin letras ni dígitos que se confundan al dictarlos (0/O, 1/I/L).
//  Que el código exista y pueda atribuirse lo decide WordPress, no esto.
// ============================================================================

/** Alfabeto del código, en el mismo orden que en PHP. */
export const REFERRAL_CODE_ALPHABET = "ABCDEFGHJKMNPQRSTUVWXYZ23456789";
export const REFERRAL_CODE_LENGTH = 8;

const REFERRAL_CODE_PATTERN = new RegExp(
  `^[${REFERRAL_CODE_ALPHABET}]{${REFERRAL_CODE_LENGTH}}$`,
);

/**
 * Normaliza (mayúsculas y sin espacios, igual que WordPress) y valida el
 * formato. Devuelve `null` si el valor no es texto o no encaja: un formato
 * inválido se ignora, nunca rompe el registro ni borra una cookie existente.
 */
export function normalizeReferralCode(value: unknown): string | null {
  if (typeof value !== "string") return null;
  const code = value.replace(/\s+/g, "").toUpperCase();
  return REFERRAL_CODE_PATTERN.test(code) ? code : null;
}
