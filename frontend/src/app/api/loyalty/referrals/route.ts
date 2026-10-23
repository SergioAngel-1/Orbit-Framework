import { NextResponse } from "next/server";
import { z } from "zod";
import { getSession } from "@/lib/auth/session";
import { requireModule } from "@/lib/api/modules";
import { getSiteConfig } from "@/lib/config";
import { requireLoyalty } from "@/lib/loyalty/guard";
import { getLoyaltyReferrals } from "@/lib/loyalty/server";
import { LoyaltyReadError } from "@/lib/loyalty/types";
import { logger } from "@/lib/observability/logger";

export const dynamic = "force-dynamic";

// ============================================================================
//  GET /api/loyalty/referrals?level&page
//
//  Red N1/N2 del usuario de la sesión, paginada. `level` fuera de 1|2 es 400:
//  no se adivina un nivel (el lector de WordPress también lo rechazaría).
// ============================================================================

const NO_STORE = { "Cache-Control": "no-store" };
const PER_PAGE_DEFAULT = 20;
const PER_PAGE_MAX = 100;

const querySchema = z.object({
  level: z
    .enum(["1", "2"])
    .nullish()
    .transform((value) => (value ? Number(value) : 1)),
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
        "No se pudo consultar la red de referidos",
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

  const featureOff = requireLoyalty(config, "referrals");
  if (featureOff) return featureOff;

  const session = await getSession();
  if (!session) {
    return NextResponse.json(
      { error: "No autenticado." },
      { status: 401, headers: NO_STORE },
    );
  }

  const params = new URL(request.url).searchParams;
  const parsed = querySchema.safeParse({
    level: params.get("level") ?? undefined,
    page: params.get("page") ?? undefined,
    per_page: params.get("per_page") ?? undefined,
  });

  if (!parsed.success) {
    logger.warn(
      { event: "loyalty.referrals.invalid_query" },
      "Nivel de referidos inválido",
    );
    return NextResponse.json(
      { error: "Datos inválidos.", details: parsed.error.flatten().fieldErrors },
      { status: 400, headers: NO_STORE },
    );
  }

  try {
    const page = await getLoyaltyReferrals(Number(session.userId), {
      level: parsed.data.level === 2 ? 2 : 1,
      page: parsed.data.page,
      perPage: parsed.data.per_page,
    });

    return NextResponse.json(page, { headers: NO_STORE });
  } catch (error) {
    return failure(error, "referrals");
  }
}
