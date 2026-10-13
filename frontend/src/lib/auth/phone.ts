// ============================================================================
//  Teléfono como identificador de cuenta (`auth.phone_login_enabled`).
//
//  El teléfono de la cuenta se guarda en E.164 (`+34600111222`). El núcleo no
//  supone ningún país: un número sin prefijo solo se acepta si el Control
//  Center define `auth.phone_default_dial_code`.
// ============================================================================

const E164 = /^\+[1-9]\d{7,14}$/;

/** ¿Es un teléfono E.164 válido? */
export function isE164(value: string): boolean {
  return E164.test(value);
}

/** Une prefijo (`+34`, `34`) y número nacional en E.164, o `null` si no cuadra. */
export function normalizePhoneE164(dialCode: string, national: string): string | null {
  const cc = dialCode.replace(/\D/g, "");
  const number = national.replace(/\D/g, "").replace(/^0+/, "");
  if (!cc || !number) return null;
  const e164 = `+${cc}${number}`;
  return isE164(e164) ? e164 : null;
}

export type LoginIdentifier =
  | { kind: "email"; value: string }
  | { kind: "phone"; value: string }
  | { kind: "username"; value: string };

export interface PhoneLoginOptions {
  /** `auth.phone_login_enabled`: sin él, todo lo que no sea email es usuario. */
  enabled: boolean;
  /** `auth.phone_default_dial_code`: prefijo para números escritos sin él. */
  defaultDialCode?: string;
}

/**
 * Clasifica lo escrito en «usuario» del login: email, teléfono o nombre de
 * usuario de WordPress. Teléfono = empieza por `+` (o por `00`) con 8–15 cifras,
 * o un número nacional si hay prefijo por defecto. Lo demás, nombre de usuario.
 */
export function classifyLoginIdentifier(
  raw: string,
  options: PhoneLoginOptions,
): LoginIdentifier {
  const trimmed = raw.trim();
  if (trimmed.includes("@")) return { kind: "email", value: trimmed.toLowerCase() };
  if (!options.enabled) return { kind: "username", value: trimmed };

  const compact = trimmed.replace(/[\s().-]/g, "");
  if (/^(\+|00)\d{8,15}$/.test(compact)) {
    const e164 = `+${compact.replace(/^(\+|00)/, "")}`;
    if (isE164(e164)) return { kind: "phone", value: e164 };
  }
  if (options.defaultDialCode && /^\d{6,14}$/.test(compact)) {
    const e164 = normalizePhoneE164(options.defaultDialCode, compact);
    if (e164) return { kind: "phone", value: e164 };
  }
  return { kind: "username", value: trimmed };
}
