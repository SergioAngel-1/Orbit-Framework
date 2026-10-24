import { NextResponse } from "next/server";
import { getSession } from "@/lib/auth/session";
import { getSiteConfig, isFallbackSiteConfig } from "@/lib/config";
import { buildVerifyUrl } from "@/lib/memberships/format";
import { requireMembershipCard } from "@/lib/memberships/guard";
import {
  membershipFailure,
  membershipUnauthorized,
  NO_STORE,
} from "@/lib/memberships/http";
import { getMembershipCard } from "@/lib/memberships/server";
import { logger } from "@/lib/observability/logger";
import { rateLimit } from "@/lib/security/rate-limit";

export const dynamic = "force-dynamic";

/** Cada petición inserta un token en WordPress: se acota por socio. */
const RATE_LIMIT = 10;
const RATE_WINDOW_SECONDS = 60;

// ============================================================================
//  GET /api/memberships/card
//
//  Emite (o reemite) el carné del socio de la sesión y devuelve el token, su
//  caducidad y la URL absoluta del QR. La URL se compone con `config.brand.url`
//  (el dominio del frontend), nunca con `home_url()` de WordPress. Sin nivel
//  efectivo, WordPress responde 403 tipado `membership_card_unavailable`.
// ============================================================================

export async function GET(request: Request) {
  // La petición no se lee: el socio sale de la sesión verificada.
  void request;

  const config = await getSiteConfig();

  const blocked = requireMembershipCard(config);
  if (blocked) return blocked;

  const session = await getSession();
  if (!session) return membershipUnauthorized();

  const limit = await rateLimit(
    `memberships-card:${session.userId}`,
    RATE_LIMIT,
    RATE_WINDOW_SECONDS,
  );
  if (!limit.success) {
    logger.warn(
      { event: "memberships.card_rate_limited", userId: Number(session.userId) },
      "Rate-limit al emitir el carné de membresía",
    );
    return NextResponse.json(
      { error: "Demasiadas peticiones. Inténtalo más tarde." },
      {
        status: 429,
        headers: { ...NO_STORE, "Retry-After": String(limit.retryAfter) },
      },
    );
  }

  try {
    const issued = await getMembershipCard(Number(session.userId));
    const verifyPath =
      issued.verify_path.trim() || (config.memberships?.card_verify_path ?? "");
    const verifyUrl = isFallbackSiteConfig(config)
      ? null
      : buildVerifyUrl(config.brand.url, verifyPath, issued.token);

    if (!verifyUrl) {
      // Publicar un QR apuntando a un dominio inventado es peor que no emitir
      // el carné: sin `brand.url` válido se falla tipado y se registra.
      logger.error(
        {
          event: "memberships.card_invalid_base_url",
          userId: Number(session.userId),
        },
        "No se pudo componer la URL de verificación del carné",
      );
      return NextResponse.json(
        {
          error: "La dirección del sitio no está configurada.",
          code: "invalid_config",
        },
        { status: 500, headers: NO_STORE },
      );
    }

    return NextResponse.json(
      {
        token: issued.token,
        expires_at: issued.expires_at,
        verify_url: verifyUrl,
      },
      { headers: NO_STORE },
    );
  } catch (error) {
    return membershipFailure(
      error,
      "card",
      "Error inesperado al emitir el carné de membresía",
    );
  }
}
