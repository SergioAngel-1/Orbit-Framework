import { NextResponse } from "next/server";
import { z } from "zod";
import { getSession } from "@/lib/auth/session";
import { requireModule } from "@/lib/api/modules";
import { getSiteConfig } from "@/lib/config";
import { requestLocale } from "@/lib/i18n/request-locale";
import { describeEntry } from "@/lib/loyalty/describe";
import { requireLoyalty } from "@/lib/loyalty/guard";
import { getLoyaltyTransactions } from "@/lib/loyalty/server";
import { LoyaltyReadError, type LoyaltyTransaction } from "@/lib/loyalty/types";
import { logger } from "@/lib/observability/logger";

export const dynamic = "force-dynamic";

// ============================================================================
//  GET /api/loyalty/transactions?page&per_page
//
//  Movimientos paginados del usuario de la sesión. `per_page` se recorta a
//  1..100 antes de llegar a WordPress y cada movimiento viaja con su texto ya
//  compuesto en el idioma de la petición (`describeEntry`).
// ============================================================================

const NO_STORE = { "Cache-Control": "no-store" };
const PER_PAGE_DEFAULT = 20;
const PER_PAGE_MAX = 100;

const querySchema = z.object({
  page: z.coerce.number().int().min(1).catch(1).optional().default(1),
  per_page: z.coerce
    .number()
    .int()
    .catch(PER_PAGE_DEFAULT)
    .transform((value) => Math.min(PER_PAGE_MAX, Math.max(1, value)))
    .optional()
    .default(PER_PAGE_DEFAULT),
});

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

  const params = new URL(request.url).searchParams;
  const parsed = querySchema.parse({
    page: params.get("page") ?? undefined,
    per_page: params.get("per_page") ?? undefined,
  });

  try {
    const page = await getLoyaltyTransactions(Number(session.userId), {
      page: parsed.page,
      perPage: parsed.per_page,
    });

    const locale = requestLocale(request.headers);
    const items: LoyaltyTransaction[] = page.items.map((item) => ({
      ...item,
      description: describeEntry(item, locale),
    }));

    return NextResponse.json(
      { items, total: page.total, pages: page.pages },
      { headers: NO_STORE },
    );
  } catch (error) {
    return failure(error, "transactions");
  }
}
