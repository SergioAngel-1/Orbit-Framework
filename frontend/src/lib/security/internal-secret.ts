// ============================================================================
//  Secreto interno compartido BFF → WordPress.
//
//  El mu-plugin `hwe-bff-only` solo deja pasar a GraphQL, la Store API y
//  `hwe/v1/auth|consent` a quien presente este secreto en `X-HWE-Internal-Secret`
//  (o a WP-CLI). Es una CREDENCIAL, no una suposición sobre la red: la heurística
//  anterior (sin X-Forwarded-For + IP privada) confiaba en cualquier contenedor,
//  VPN o proxy mal configurado que compartiera red con WordPress.
//
//  El valor es `HWE_REVALIDATION_SECRET`, el mismo que ya firma la revalidación y
//  que comprueba `hwe/v1/auth/2fa-status`. Va en TODAS las llamadas del BFF a
//  WordPress; ver `lib/wordpress/internal-fetch.ts` y la guarda
//  `lib/security/wordpress-calls.test.ts`.
//
//  Sin `server-only` a propósito: lo usa también el proxy (edge), que no puede
//  importar módulos de servidor. Es seguro: la variable no lleva `NEXT_PUBLIC_`,
//  así que en un bundle de navegador valdría `undefined` y no se filtra nada.
//  Aun así NO debe importarse desde un componente de cliente (lo vigila la guarda).
// ============================================================================

import { timingSafeEqual } from "node:crypto";

export const INTERNAL_SECRET_HEADER = "X-HWE-Internal-Secret";

/**
 * Cabecera con el secreto interno, o `{}` si no está configurado (entonces
 * WordPress rechazará la llamada: el guard de arranque ya exige el secreto en
 * producción). Se lee en cada llamada, no al importar.
 */
export function internalSecretHeaders(): Record<string, string> {
  const secret = process.env.HWE_REVALIDATION_SECRET?.trim();
  return secret ? { [INTERNAL_SECRET_HEADER]: secret } : {};
}

/**
 * ¿La petición entrante trae el secreto interno? Es la autenticación de las rutas
 * del BFF que SOLO debe llamar WordPress (p. ej. el informe de
 * `/api/consent-stats`). Comparación en tiempo constante; sin secreto
 * configurado, siempre `false` (falla cerrado).
 */
export function hasValidInternalSecret(request: Request): boolean {
  const expected = process.env.HWE_REVALIDATION_SECRET?.trim();
  const received = request.headers.get(INTERNAL_SECRET_HEADER)?.trim();
  if (!expected || !received) return false;
  const a = Buffer.from(expected);
  const b = Buffer.from(received);
  return a.length === b.length && timingSafeEqual(a, b);
}
