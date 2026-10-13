import { NextResponse } from "next/server";
import { guardMutation } from "@/lib/api/guard";
import { getSiteConfig } from "@/lib/config";
import { requireModule } from "@/lib/api/modules";
import { parseConsentRecordRequest } from "@/lib/analytics/consent-record";
import { recordConsentDecision } from "@/lib/analytics/consent-ledger";
import { routing } from "@/i18n/routing";

export const dynamic = "force-dynamic";

// ============================================================================
//  POST /api/consent — registra una decisión del banner de cookies como
//  PRUEBA (ver `lib/analytics/consent-ledger.ts`). Responde `{ receipt }` y
//  fija la cookie httpOnly `hwe_consent` con ese comprobante.
//
//  Sin CSRF, igual que `/api/consent-stats`: el visitante de primera visita
//  no tiene token, y pedírselo añadiría otra petición a cada primera entrada
//  justo cuando la decisión tiene que quedar registrada. Lo acotan Origin
//  (proxy + guard: una petición cross-site con la sesión de otro no pasa) y
//  el rate-limit. El usuario y todo lo que da valor de prueba lo pone el
//  servidor, nunca el cuerpo.
//
//  El rate-limit es por IP y por MINUTO: tras un CGNAT de operadora móvil
//  cientos de visitantes comparten IP, y con el de antes (60 por hora) las
//  decisiones de la hora punta esperaban en la cola del navegador hasta otra
//  visita. Cada petición despierta WordPress, de ahí que no sea más alto.
//
//  503 si WordPress no lo guardó: el navegador conserva la decisión en cola y
//  la reenvía en la siguiente carga.
// ============================================================================

export async function POST(request: Request) {
  // Es del módulo `consent-log`: apagado, la ruta no existe (el banner sigue
  // funcionando con el consentimiento local y no encola nada).
  const disabled = requireModule(await getSiteConfig(), "consent-log");
  if (disabled) return disabled;

  const blocked = await guardMutation(request, {
    csrf: false,
    rateLimit: { name: "consent-record", limit: 20, windowSeconds: 60 },
  });
  if (blocked) return blocked;

  let body: unknown;
  try {
    body = await request.json();
  } catch {
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }
  const parsed = parseConsentRecordRequest(body, routing.locales);
  if (!parsed) {
    return NextResponse.json({ error: "Decisión no válida." }, { status: 400 });
  }

  const result = await recordConsentDecision(request, parsed);
  if (!result.ok) {
    return result.reason === "nothing_to_consent"
      ? NextResponse.json({ error: "No hay nada que consentir." }, { status: 409 })
      : NextResponse.json(
          { error: "Registro no disponible." },
          { status: 503, headers: { "Cache-Control": "no-store" } },
        );
  }
  return NextResponse.json(
    { receipt: result.receipt },
    { headers: { "Cache-Control": "no-store" } },
  );
}
