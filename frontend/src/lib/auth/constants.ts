// ============================================================================
//  Constantes de autenticación (nombres de cookie, tiempos de vida).
//  Sin "server-only": las usa tanto el middleware (edge) como el código de
//  servidor (Route Handlers / RSC). No contiene secretos.
// ============================================================================

/** Cookie httpOnly que guarda el JWT de acceso (corta duración). */
export const AUTH_COOKIE = process.env.AUTH_COOKIE_NAME || "hwe_at";

/** Cookie httpOnly que guarda el refresh token (larga duración). */
export const REFRESH_COOKIE = process.env.JWT_REFRESH_COOKIE_NAME || "hwe_rt";

/**
 * Vida por defecto del JWT de acceso si no se puede leer su claim `exp`
 * (el plugin WPGraphQL JWT usa 5 min por defecto).
 */
export const DEFAULT_AUTH_TOKEN_MAX_AGE = 60 * 5;

/**
 * Vida de la cookie del refresh token (segundos). El refresh token del plugin
 * no caduca por sí mismo; acotamos su validez en el navegador.
 */
export const REFRESH_TOKEN_MAX_AGE =
  Number(process.env.REFRESH_TOKEN_MAX_AGE_DAYS || 30) * 24 * 60 * 60;

/** Margen de seguridad (segundos) al evaluar la expiración del token. */
export const TOKEN_EXPIRY_SKEW_SECONDS = 10;
