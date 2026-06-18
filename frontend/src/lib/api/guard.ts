import "server-only";
import { NextResponse } from "next/server";
import { assertAllowedOrigin } from "@/lib/security/origin";
import { verifyCsrf } from "@/lib/security/csrf";
import { rateLimit } from "@/lib/security/rate-limit";
import { getClientIp } from "@/lib/http/request-ip";

// ============================================================================
//  Guard unificado para endpoints de escritura del BFF.
//  Orden: verificación de Origin → CSRF → rate-limit.
//  Devuelve una `NextResponse` de error si bloquea, o `null` si todo pasa.
// ============================================================================

export interface GuardOptions {
  /** Verificar CSRF (double-submit). Por defecto true. */
  csrf?: boolean;
  /** Configuración de rate-limit. Si se omite, no se limita. */
  rateLimit?: {
    /** Nombre lógico del límite (p. ej. "login"). */
    name: string;
    limit: number;
    windowSeconds: number;
    /** Identificador adicional (p. ej. userId) para limitar también por usuario. */
    extraId?: string;
  };
}

export async function guardMutation(
  request: Request,
  options: GuardOptions = {},
): Promise<NextResponse | null> {
  // 1) Origen permitido.
  if (!assertAllowedOrigin(request)) {
    return NextResponse.json({ error: "Origen no permitido." }, { status: 403 });
  }

  // 2) CSRF.
  if (options.csrf !== false) {
    const ok = await verifyCsrf(request);
    if (!ok) {
      return NextResponse.json({ error: "Token CSRF inválido." }, { status: 403 });
    }
  }

  // 3) Rate-limit (por IP, y opcionalmente por usuario).
  if (options.rateLimit) {
    const { name, limit, windowSeconds, extraId } = options.rateLimit;
    const ip = getClientIp(request);
    const identifier = extraId ? `${name}:${ip}:${extraId}` : `${name}:${ip}`;

    const result = await rateLimit(identifier, limit, windowSeconds);
    if (!result.success) {
      return NextResponse.json(
        { error: "Demasiadas peticiones. Inténtalo más tarde." },
        {
          status: 429,
          headers: {
            "Retry-After": String(result.retryAfter),
            "X-RateLimit-Limit": String(result.limit),
            "X-RateLimit-Remaining": String(result.remaining),
          },
        },
      );
    }
  }

  return null;
}
