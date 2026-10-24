import { NextResponse } from "next/server";
import { getSiteConfig } from "@/lib/config";
import { getClientIp } from "@/lib/http/request-ip";
import { requireMembershipCard } from "@/lib/memberships/guard";
import { membershipFailure, NO_STORE } from "@/lib/memberships/http";
import { getMembershipLevels, verifyMembershipCard } from "@/lib/memberships/server";
import type { MembershipVerification } from "@/lib/memberships/types";
import { requestLocale } from "@/lib/i18n/request-locale";
import { logReadFailure } from "@/lib/observability/read-failure";
import { logger } from "@/lib/observability/logger";
import { rateLimit } from "@/lib/security/rate-limit";

export const dynamic = "force-dynamic";

// ============================================================================
//  GET /api/memberships/verify/[token]
//
//  La única ruta pública del módulo: la abre el QR del carné. Rate-limit por IP,
//  formato del token validado antes de llamar a WordPress (hex de 64) y la MISMA
//  respuesta para cualquier token no válido: no se puede distinguir si no
//  existe, caducó o se revocó. Nunca se cachea (ni la válida ni la inválida).
// ============================================================================

const RATE_LIMIT = 30;
const RATE_WINDOW_SECONDS = 60;

/** Token emitido por `HWE_Memberships_Card`: 32 bytes en hexadecimal. */
const CARD_TOKEN_PATTERN = /^[0-9a-f]{64}$/;

export async function GET(
  request: Request,
  { params }: { params: Promise<{ token: string }> },
) {
  const config = await getSiteConfig();

  const blocked = requireMembershipCard(config);
  if (blocked) return blocked;

  const limit = await rateLimit(
    `memberships-verify:${getClientIp(request)}`,
    RATE_LIMIT,
    RATE_WINDOW_SECONDS,
  );
  if (!limit.success) {
    logger.warn(
      { event: "memberships.verify.rate_limited" },
      "Rate-limit en la verificación pública del carné",
    );
    return NextResponse.json(
      { error: "Demasiadas peticiones. Inténtalo más tarde." },
      {
        status: 429,
        headers: {
          ...NO_STORE,
          "Retry-After": String(limit.retryAfter),
          "X-RateLimit-Limit": String(limit.limit),
          "X-RateLimit-Remaining": String(limit.remaining),
        },
      },
    );
  }

  const { token } = await params;
  const clean = token.trim();
  if (!CARD_TOKEN_PATTERN.test(clean)) {
    // Un token con formato imposible no puede ser válido: no merece una llamada
    // a WordPress y la respuesta es idéntica a la de uno inexistente.
    return NextResponse.json({ valid: false }, { headers: NO_STORE });
  }

  try {
    const verification = await verifyMembershipCard(clean);
    if (!verification.valid) {
      return NextResponse.json({ valid: false }, { headers: NO_STORE });
    }

    const locale = requestLocale(request.headers);
    const translated = await withTranslatedLevelName(verification, locale);

    return NextResponse.json(translated, { headers: NO_STORE });
  } catch (error) {
    return membershipFailure(
      error,
      "verify",
      "Error inesperado al verificar el carné de membresía",
    );
  }
}

/**
 * Traduce el nombre del nivel con los niveles de `/levels` (cacheados por ISR).
 * Si el nivel ya no está o la lectura falla, se conserva el nombre que mandó
 * WordPress: es contenido, y el contenido falla suave.
 */
async function withTranslatedLevelName(
  verification: Extract<MembershipVerification, { valid: true }>,
  locale: string,
): Promise<MembershipVerification> {
  try {
    const levels = await getMembershipLevels(locale);
    const match = levels.find(
      (level) =>
        level.slug === verification.level.slug ||
        Object.values(level.slugs).includes(verification.level.slug),
    );
    if (match) {
      return {
        ...verification,
        level: { name: match.name, slug: match.slug },
      };
    }
  } catch (error) {
    logReadFailure("memberships.verify_levels_failed", error, {
      level: "warn",
      message: "No se pudo traducir el nombre del nivel del carné; se usa el nativo",
    });
  }

  return verification;
}
