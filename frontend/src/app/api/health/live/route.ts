import { NextResponse } from "next/server";

export const dynamic = "force-dynamic";

// ============================================================================
//  GET /api/health/live — LIVENESS probe.
//
//  Comprueba SOLO que el proceso responde, sin tocar dependencias (Redis/WP).
//  Pensado para el liveness probe de un orquestador: si esto falla, reinicia el
//  contenedor. Para readiness (con dependencias) usar `GET /api/health?ready=1`.
// ============================================================================

export async function GET() {
  return NextResponse.json(
    { status: "ok", uptime: Math.round(process.uptime()) },
    { status: 200, headers: { "Cache-Control": "no-store" } },
  );
}
