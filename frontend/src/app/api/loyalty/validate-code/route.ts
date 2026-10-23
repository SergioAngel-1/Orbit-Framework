import { NextResponse } from "next/server";
import { z } from "zod";
import { requireModule } from "@/lib/api/modules";
import { getSiteConfig } from "@/lib/config";
import { getClientIp } from "@/lib/http/request-ip";
import { requireLoyalty } from "@/lib/loyalty/guard";
import { validateReferralCode } from "@/lib/loyalty/server";
import { LoyaltyReadError } from "@/lib/loyalty/types";
import { logger } from "@/lib/observability/logger";
import { rateLimit } from "@/lib/security/rate-limit";

export const dynamic = "force-dynamic";

// ============================================================================
//  GET /api/loyalty/validate-code?code=…
//
//  La única ruta pública del módulo (la usa el registro). Rate-limit por IP y
//  respuesta `{ valid }` y nada más: ni nombre, ni id, ni datos de la cuenta
//  dueña del código (spec §9.2).
// ============================================================================

const NO_STORE = { "Cache-Control": "no-store" };
const RATE_LIMIT = 30;
const RATE_WINDOW_SECONDS = 60;

/** Los códigos tienen una longitud fija corta; nada más largo puede ser válido. */
const codeSchema = z.string().trim().max(64);

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
        "No se pudo validar el código de referido",
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

  // Ruta pública y barata de enumerar: se limita por IP antes de tocar
  // WordPress. Fail-open si Redis cae, como el resto de límites no estrictos.
  const limit = await rateLimit(
    `loyalty-validate-code:${getClientIp(request)}`,
    RATE_LIMIT,
    RATE_WINDOW_SECONDS,
  );
  if (!limit.success) {
    logger.warn(
      { event: "loyalty.validate_code.rate_limited" },
      "Rate-limit en la validación de códigos de referido",
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

  const raw = new URL(request.url).searchParams.get("code") ?? "";
  const parsed = codeSchema.safeParse(raw);
  if (!parsed.success || parsed.data === "") {
    // Un código vacío o imposible no es un error del servidor: se responde que
    // no vale, sin consultar a WordPress ni revelar nada.
    return NextResponse.json({ valid: false }, { headers: NO_STORE });
  }

  try {
    const { valid } = await validateReferralCode(parsed.data);
    return NextResponse.json({ valid }, { headers: NO_STORE });
  } catch (error) {
    return failure(error, "validate_code");
  }
}
