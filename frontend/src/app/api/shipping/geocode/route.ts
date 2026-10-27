import { NextResponse } from "next/server";
import { BodyTooLarge, bodyTooLarge, readJsonBody } from "@/lib/api/body";
import { z } from "zod";
import { getSiteConfig } from "@/lib/config";
import { getSession } from "@/lib/auth/session";
import { guardMutation } from "@/lib/api/guard";
import { handleApiError } from "@/lib/api/errors";
import { requireModule } from "@/lib/api/modules";
import { requestLocale } from "@/lib/i18n/request-locale";
import { logger } from "@/lib/observability/logger";
import { GEOCODE_QUERY_MAX_LENGTH, geocodeAddress } from "@/lib/shipping/geocode";
import { geocodeErrorKey, shippingErrorText } from "@/lib/shipping/errors";

export const dynamic = "force-dynamic";

// ============================================================================
//  POST /api/shipping/geocode — `{ query }` → `{ results: [{ lat, lng, label }] }`.
//
//  Buscador del mapa del checkout (módulo `shipping`; con el módulo apagado, 404). Se llama
//  AL ENVIAR la búsqueda, no por tecla: la política de uso del proveedor lo prohíbe y con
//  Google cuesta. El límite es 10 por minuto: por usuario con sesión, por IP sin ella. Sin
//  sesión solo se atiende si la tienda admite compra de invitado (`checkout_login_required`
//  apagado); si no, 401. La clave del proveedor nunca sale de WordPress.
// ============================================================================

const RATE_LIMIT = { name: "shipping_geocode", limit: 10, windowSeconds: 60 } as const;

const searchSchema = z.object({
  query: z.string().trim().min(1).max(GEOCODE_QUERY_MAX_LENGTH),
});

export async function POST(request: Request) {
  const config = await getSiteConfig();
  const off = requireModule(config, "shipping");
  if (off) return off;

  // La sesión se lee antes del guard solo para el límite por usuario: no decide nada más.
  const session = await getSession();
  const blocked = await guardMutation(request, {
    rateLimit: {
      ...RATE_LIMIT,
      extraId: session ? String(session.userId) : undefined,
    },
  });
  if (blocked) return blocked;

  if (!session && config.ecommerce.checkout_login_required) {
    return handleApiError(new Error("UNAUTHENTICATED"));
  }

  let body: unknown;
  try {
    body = await readJsonBody(request);
  } catch (error) {
    if (error instanceof BodyTooLarge) return bodyTooLarge();
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const parsed = searchSchema.safeParse(body);
  if (!parsed.success) {
    return NextResponse.json(
      { error: "Datos inválidos.", details: parsed.error.flatten().fieldErrors },
      { status: 422 },
    );
  }

  const outcome = await geocodeAddress(parsed.data.query);
  if (!outcome.ok) {
    logger.warn(
      { event: "shipping.geocode.failed", code: outcome.code },
      "Búsqueda de dirección sin resultado utilizable",
    );
    return NextResponse.json(
      {
        error: shippingErrorText(
          requestLocale(request.headers),
          geocodeErrorKey(outcome.code),
        ),
        code: outcome.code,
      },
      { status: outcome.status, headers: { "Cache-Control": "no-store" } },
    );
  }

  return NextResponse.json(
    { results: outcome.value },
    { status: 200, headers: { "Cache-Control": "no-store" } },
  );
}
