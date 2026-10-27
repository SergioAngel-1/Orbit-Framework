import { NextResponse } from "next/server";
import { BodyTooLarge, bodyTooLarge, readJsonBody } from "@/lib/api/body";
import { guardMutation } from "@/lib/api/guard";
import { hasValidInternalSecret } from "@/lib/security/internal-secret";
import { getSiteConfig } from "@/lib/config";
import { requireModule } from "@/lib/api/modules";
import {
  MAX_REPORT_DAYS,
  parseConsentEvent,
  readConsentStats,
  recordConsentEvent,
  resolveTimeZone,
} from "@/lib/analytics/consent-stats";
import { logger } from "@/lib/observability/logger";

export const dynamic = "force-dynamic";

// ============================================================================
//  /api/consent-stats — totales anónimos de la respuesta al banner de cookies
//  (ver `lib/analytics/consent-stats.ts`).
//
//  POST (navegador): `{ event, variant }` → +1 en el contador de la hora.
//    Sin CSRF a propósito: el visitante de primera visita no tiene token, y
//    pedírselo costaría otra petición en cada entrada al sitio para proteger
//    un contador que no actúa en nombre de nadie — quien quisiera inflarlo
//    podría pedir su propio token igual. Lo acotan Origin (proxy + guard) y
//    un rate-limit por IP, por MINUTO y holgado: los móviles salen por CGNAT y
//    cientos de visitantes comparten IP. Con el tope de antes
//    (30 por hora) una IP de operadora agotaba el cupo y la medición perdía,
//    sin reintento, a todos los que venían detrás. Inflar el contador desde
//    un script sigue siendo posible con cualquier tope: no es una prueba.
//
//  GET (WordPress, «Consentimientos de cookies»): `?days=14&tz=Europe/Madrid`
//    con `X-HWE-Internal-Secret`. Devuelve el informe por día local.
// ============================================================================

export async function POST(request: Request) {
  // La medición se lee en la pantalla del módulo `consent-log`: sin él nadie la
  // vería, así que no se cuenta.
  const disabled = requireModule(await getSiteConfig(), "consent-log");
  if (disabled) return disabled;

  const blocked = await guardMutation(request, {
    csrf: false,
    rateLimit: { name: "consent-stats", limit: 60, windowSeconds: 60 },
  });
  if (blocked) return blocked;

  let body: unknown;
  try {
    body = await readJsonBody(request);
  } catch (error) {
    if (error instanceof BodyTooLarge) return bodyTooLarge();
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }
  const parsed = parseConsentEvent(body);
  if (!parsed) {
    return NextResponse.json({ error: "Evento no válido." }, { status: 400 });
  }

  const stored = await recordConsentEvent(parsed.event, parsed.variant);
  if (!stored) {
    logger.warn(
      { event: "consent_stats.unavailable" },
      "Medición de consentimiento sin Redis",
    );
  }
  // 204 también sin Redis: el navegador no tiene nada que hacer con el fallo.
  return new NextResponse(null, { status: 204 });
}

export async function GET(request: Request) {
  if (!hasValidInternalSecret(request)) {
    return NextResponse.json({ error: "No autorizado." }, { status: 401 });
  }

  const url = new URL(request.url);
  const requested = Number(url.searchParams.get("days") ?? 14);
  const days = Number.isInteger(requested)
    ? Math.min(Math.max(requested, 1), MAX_REPORT_DAYS)
    : 14;
  const timeZone = resolveTimeZone(url.searchParams.get("tz"));

  const report = await readConsentStats(days, timeZone);
  if (!report) {
    return NextResponse.json(
      { error: "Almacén de estadísticas no disponible." },
      { status: 503, headers: { "Cache-Control": "no-store" } },
    );
  }
  return NextResponse.json(
    { period: days, timeZone, days: report.days, totals: report.totals },
    { headers: { "Cache-Control": "no-store" } },
  );
}
