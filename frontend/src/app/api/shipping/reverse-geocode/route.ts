import { NextResponse } from "next/server";
import { BodyTooLarge, bodyTooLarge, readJsonBody } from "@/lib/api/body";
import { getSiteConfig } from "@/lib/config";
import { getSession } from "@/lib/auth/session";
import { guardMutation } from "@/lib/api/guard";
import { handleApiError } from "@/lib/api/errors";
import { requireModule } from "@/lib/api/modules";
import { requestLocale } from "@/lib/i18n/request-locale";
import { locationSchema } from "@/lib/validation/address";
import { logger } from "@/lib/observability/logger";
import { reverseGeocodePoint } from "@/lib/shipping/geocode";
import { geocodeErrorKey, shippingErrorText } from "@/lib/shipping/errors";

export const dynamic = "force-dynamic";

// ============================================================================
//  POST /api/shipping/reverse-geocode — `{ lat, lng }` → `{ label }`.
//
//  Confirma con una dirección legible el pin que marcó el cliente. Mismas guardas que el
//  buscador (`/api/shipping/geocode`): módulo, guard con límite propio, sesión o compra de
//  invitado. Las coordenadas se validan aquí (numéricas, en rango, no 0,0): lo que no lo es
//  es un 422 y no llega a WordPress.
// ============================================================================

const RATE_LIMIT = { name: "shipping_geocode", limit: 10, windowSeconds: 60 } as const;

export async function POST(request: Request) {
  const config = await getSiteConfig();
  const off = requireModule(config, "shipping");
  if (off) return off;

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

  const parsed = locationSchema.safeParse(body);
  if (!parsed.success) {
    return NextResponse.json(
      { error: "Datos inválidos.", details: parsed.error.flatten().fieldErrors },
      { status: 422 },
    );
  }

  const outcome = await reverseGeocodePoint(parsed.data);
  if (!outcome.ok) {
    logger.warn(
      { event: "shipping.reverse_geocode.failed", code: outcome.code },
      "Coordenada sin dirección utilizable",
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

  return NextResponse.json(outcome.value, {
    status: 200,
    headers: { "Cache-Control": "no-store" },
  });
}
