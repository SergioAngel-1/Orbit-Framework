import "server-only";

// ============================================================================
//  Extracción de la IP del cliente a partir de X-Forwarded-For.
//
//  SEGURIDAD: el primer valor de XFF lo controla el CLIENTE (es falsificable).
//  Cada proxy de confianza AÑADE a la derecha la IP de su par inmediato. Por
//  tanto, con N proxies de confianza, la IP real del cliente es el valor que
//  está N posiciones desde el final.
//
//  `TRUSTED_PROXY_COUNT` (por defecto 1: solo Caddy delante) define N. Tomar el
//  primer valor a ciegas permitiría a un atacante falsear su IP y evadir el
//  rate-limit.
// ============================================================================

function trustedProxyCount(): number {
  const n = Number(process.env.TRUSTED_PROXY_COUNT);
  if (Number.isInteger(n) && n >= 0) return n;
  return 1; // Caddy/Nginx delante por defecto.
}

export function getClientIp(request: Request): string {
  const xff = request.headers.get("x-forwarded-for");
  const count = trustedProxyCount();

  if (xff) {
    const parts = xff.split(",").map((p) => p.trim()).filter(Boolean);
    if (parts.length > 0) {
      if (count === 0) {
        // Sin proxies de confianza: XFF no es fiable; preferimos x-real-ip.
        const realIp0 = request.headers.get("x-real-ip");
        if (realIp0) return realIp0.trim();
      }
      // IP añadida por el proxy de confianza más cercano (a `count` del final).
      const idx = Math.max(0, parts.length - count);
      const ip = parts[idx];
      if (ip) return ip;
    }
  }

  const realIp = request.headers.get("x-real-ip");
  if (realIp) return realIp.trim();

  return "unknown";
}
