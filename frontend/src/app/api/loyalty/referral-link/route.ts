import { NextResponse } from "next/server";
import { getSession } from "@/lib/auth/session";
import { requireModule } from "@/lib/api/modules";
import { getSiteConfig } from "@/lib/config";
import { requireLoyalty } from "@/lib/loyalty/guard";
import { getReferralLink } from "@/lib/loyalty/server";
import { LoyaltyReadError } from "@/lib/loyalty/types";
import { logger } from "@/lib/observability/logger";

export const dynamic = "force-dynamic";

// ============================================================================
//  GET /api/loyalty/referral-link
//
//  Código y URL pública de referido del usuario de la sesión. La URL se compone
//  con `config.brand.url` (nunca con `home_url()` de WordPress, que en headless
//  apunta al backend).
// ============================================================================

const NO_STORE = { "Cache-Control": "no-store" };

/** Traduce el error tipado de los lectores a una respuesta HTTP coherente. */
function failure(error: unknown, event: string): NextResponse {
  if (error instanceof LoyaltyReadError) {
    const status =
      error.code === "not_found"
        ? 404
        : error.code === "invalid_request"
          ? 400
          : error.code === "unavailable"
            ? 503
            : 502;

    if (status >= 500) {
      logger.error(
        {
          event: `loyalty.${event}_unavailable`,
          code: error.code,
          status: error.status,
        },
        "No se pudo consultar el enlace de referido",
      );
    }

    return NextResponse.json(
      { error: error.message, code: error.code },
      { status, headers: NO_STORE },
    );
  }

  logger.error(
    {
      event: `loyalty.${event}_failed`,
      err: error instanceof Error ? error.message : String(error),
    },
    "Error inesperado en el BFF de fidelización",
  );
  return NextResponse.json(
    { error: "Error interno." },
    { status: 500, headers: NO_STORE },
  );
}

export async function GET(request: Request) {
  // La query se ignora a propósito: el `user_id` sale de la sesión verificada.
  void request;

  const config = await getSiteConfig();

  const moduleOff = requireModule(config, "loyalty");
  if (moduleOff) return moduleOff;

  const featureOff = requireLoyalty(config, "referrals");
  if (featureOff) return featureOff;

  const session = await getSession();
  if (!session) {
    return NextResponse.json(
      { error: "No autenticado." },
      { status: 401, headers: NO_STORE },
    );
  }

  try {
    const link = await getReferralLink(Number(session.userId), config);
    return NextResponse.json(link, { headers: NO_STORE });
  } catch (error) {
    return failure(error, "referral_link");
  }
}
