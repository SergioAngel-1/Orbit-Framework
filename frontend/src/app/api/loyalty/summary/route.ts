import { NextResponse } from "next/server";
import { getSession } from "@/lib/auth/session";
import { requireModule } from "@/lib/api/modules";
import { getSiteConfig } from "@/lib/config";
import { pointsToMoney } from "@/lib/loyalty/format";
import { requireLoyalty } from "@/lib/loyalty/guard";
import { getLoyaltySummary } from "@/lib/loyalty/server";
import { LoyaltyReadError } from "@/lib/loyalty/types";
import { logger } from "@/lib/observability/logger";

export const dynamic = "force-dynamic";

// ============================================================================
//  GET /api/loyalty/summary
//
//  Resumen del monedero del usuario de la sesión. El `user_id` no se acepta de
//  la query: llega verificado en el JWT. Un fallo de WordPress es un 5xx
//  tipado, nunca un saldo 0 inventado (spec §9.2 «Fallos»).
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
        "No se pudo consultar el monedero de fidelización",
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

  const featureOff = requireLoyalty(config, "points");
  if (featureOff) return featureOff;

  const session = await getSession();
  if (!session) {
    return NextResponse.json(
      { error: "No autenticado." },
      { status: 401, headers: NO_STORE },
    );
  }

  try {
    const summary = await getLoyaltySummary(Number(session.userId));
    return NextResponse.json(
      {
        ...summary,
        // Valor del saldo en la moneda de la tienda (unidades mayores). La
        // conversión es de presentación: el descuento real lo calcula WordPress.
        balance_value: pointsToMoney(summary.balance, config.loyalty),
      },
      { headers: NO_STORE },
    );
  } catch (error) {
    return failure(error, "summary");
  }
}
