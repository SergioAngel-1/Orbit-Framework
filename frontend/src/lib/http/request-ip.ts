import "server-only";

// ============================================================================
//  Extracción de la IP del cliente a partir de las cabeceras del proxy.
//  En producción detrás de un reverse proxy/CDN, confía en X-Forwarded-For.
// ============================================================================

export function getClientIp(request: Request): string {
  const xff = request.headers.get("x-forwarded-for");
  if (xff) {
    // El primer valor es la IP original del cliente.
    const first = xff.split(",")[0]?.trim();
    if (first) return first;
  }
  const realIp = request.headers.get("x-real-ip");
  if (realIp) return realIp.trim();

  return "unknown";
}
