import "server-only";

// ============================================================================
//  Verificación de origen para endpoints de escritura (mitigación CSRF base).
//
//  Comprueba que la cabecera `Origin` (o `Referer` como respaldo) coincide con
//  ALLOWED_ORIGIN. Es una primera barrera; la protección CSRF completa con token
//  double-submit llega en la Fase 4.
// ============================================================================

/**
 * @returns true si la petición procede de un origen autorizado.
 *          Si no hay ALLOWED_ORIGIN configurado, no se restringe (dev).
 *          Una petición de escritura sin Origin ni Referer se rechaza.
 */
export function assertAllowedOrigin(request: Request): boolean {
  const allowed = process.env.ALLOWED_ORIGIN;
  if (!allowed) {
    return true;
  }

  const origin = request.headers.get("origin");
  if (origin) {
    return origin === allowed;
  }

  // Algunos navegadores omiten Origin en ciertas peticiones; usamos Referer.
  const referer = request.headers.get("referer");
  if (referer) {
    try {
      return new URL(referer).origin === allowed;
    } catch {
      return false;
    }
  }

  // Petición de escritura sin Origin ni Referer -> no fiable.
  return false;
}
