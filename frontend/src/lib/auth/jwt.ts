import "server-only";
import { jwtVerify, decodeJwt } from "jose";
import { DEFAULT_AUTH_TOKEN_MAX_AGE } from "./constants";

// ============================================================================
//  Verificación y lectura de JWT emitidos por WPGraphQL JWT Authentication.
//
//  El plugin firma los tokens con HS256 usando GRAPHQL_JWT_AUTH_SECRET_KEY.
//  Verificamos la firma **localmente** (sin llamar a WordPress en cada request)
//  -> rápido y resistente a manipulación.
// ============================================================================

function getSecretKey(): Uint8Array {
  const secret = process.env.GRAPHQL_JWT_AUTH_SECRET_KEY;
  if (!secret) {
    throw new Error(
      "GRAPHQL_JWT_AUTH_SECRET_KEY no está definido en el servidor del frontend. " +
        "Debe coincidir con el secreto configurado en WordPress.",
    );
  }
  return new TextEncoder().encode(secret);
}

export interface AuthTokenPayload {
  userId: string;
}

/**
 * Verifica la firma y vigencia del JWT de acceso y extrae el id de usuario.
 * Devuelve `null` si el token es inválido, está expirado, no contiene el id o
 * NO es un token de acceso (ver más abajo).
 *
 * WPGraphQL JWT Authentication firma el access token y el refresh token con el
 * MISMO secreto y el MISMO algoritmo (HS256): la firma no distingue una
 * credencial de la otra. Lo único que las separa es que el refresh token lleva
 * `data.user.user_secret` y vive mucho más (365 días). Por eso, además de la
 * firma, aquí se exige la forma de access token: un refresh token presentado en
 * la cookie de acceso se rechaza. Si no se hiciera, un `hwe_rt` filtrado montaría
 * una sesión en todo el BFF —también en las rutas que consultan WooCommerce con
 * las claves de administrador del BFF, que nunca pasan por WordPress— y ni
 * `logout-all` ni el cambio de contraseña lo revocarían: ambos solo rotan el
 * secreto por usuario, que WordPress compara en `enforceRefreshTokenSecret`
 * únicamente cuando valida un bearer. El refresh token sigue teniendo un único
 * uso legítimo: `/api/auth/refresh` (y el refresco transparente de `proxy.ts`),
 * que lo canjean en WordPress y no pasan por esta función.
 */
export async function verifyAuthToken(token: string): Promise<AuthTokenPayload | null> {
  try {
    const { payload } = await jwtVerify(token, getSecretKey(), {
      algorithms: ["HS256"],
    });
    const data = payload as {
      data?: { user?: { id?: string | number; user_secret?: unknown } };
    };
    const user = data.data?.user;
    // Marcador de refresh token: no es una sesión. Falla cerrado si el claim
    // aparece, aunque venga vacío.
    if (user && "user_secret" in user) {
      return null;
    }
    const id = user?.id;
    return id != null ? { userId: String(id) } : null;
  } catch {
    return null;
  }
}

/**
 * Calcula los segundos restantes hasta la expiración del token (claim `exp`),
 * para alinear el `maxAge` de la cookie con la vida real del JWT.
 * No verifica la firma (solo se usa para fijar la cookie tras emitir el token).
 */
export function getTokenMaxAgeSeconds(token: string): number {
  try {
    const { exp } = decodeJwt(token);
    if (typeof exp === "number") {
      const seconds = exp - Math.floor(Date.now() / 1000);
      if (seconds > 0) return seconds;
    }
  } catch {
    /* token no decodificable: usamos el valor por defecto */
  }
  return DEFAULT_AUTH_TOKEN_MAX_AGE;
}
