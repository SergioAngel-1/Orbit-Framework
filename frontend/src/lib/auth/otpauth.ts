// ============================================================================
//  URI `otpauth://` del alta de 2FA ("Key URI Format" de Google Authenticator).
//
//  La arma el servidor (GET /api/auth/2fa/qr) con el secreto pendiente: el
//  cliente ya no construye nada con el secreto. Función pura, sin efectos.
// ============================================================================

export interface OtpauthParams {
  /** Nombre del sitio: lo que ve el usuario en su app de autenticación. */
  issuer: string;
  /** Identificador de la cuenta (el email). */
  account: string;
  /** Secreto TOTP en base32. */
  secret: string;
}

/**
 * Construye `otpauth://totp/<emisor>:<cuenta>?secret=…&issuer=…`.
 *
 * Emisor y cuenta se codifican (un `:` o un `&` en el nombre del sitio no puede
 * romper la etiqueta ni inyectar parámetros). El secreto va tal cual: es base32,
 * sin caracteres que codificar.
 */
export function buildOtpauthUri({ issuer, account, secret }: OtpauthParams): string {
  const label = `${encodeURIComponent(issuer)}:${encodeURIComponent(account)}`;
  return `otpauth://totp/${label}?secret=${secret}&issuer=${encodeURIComponent(issuer)}`;
}
