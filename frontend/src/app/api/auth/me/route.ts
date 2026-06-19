import { NextResponse } from "next/server";
import { getSession, fetchGraphQLAsViewer } from "@/lib/auth/session";
import { VIEWER_QUERY } from "@/lib/auth/mutations";
import { logger } from "@/lib/observability/logger";
import type { ViewerResponse } from "@/types/auth";

export const dynamic = "force-dynamic";

/**
 * GET /api/auth/me
 * Devuelve el usuario autenticado (o 401). Útil para que el cliente conozca el
 * estado de sesión sin acceder nunca al token (que es httpOnly).
 */
export async function GET() {
  const session = await getSession();
  if (!session) {
    logger.info({ event: "auth.me.unauthenticated" }, "Consulta de sesión sin autenticar");
    return NextResponse.json({ user: null }, { status: 401 });
  }

  try {
    const data = await fetchGraphQLAsViewer<ViewerResponse>(VIEWER_QUERY, {
      revalidate: 0,
    });
    logger.info({ event: "auth.me.success", userId: session.userId }, "Sesión de usuario consultada");
    return NextResponse.json({ user: data.viewer }, { status: 200 });
  } catch (error) {
    logger.error({ event: "auth.me.error", err: error instanceof Error ? error.message : error }, "Error al obtener datos del usuario");
    return NextResponse.json(
      { error: "No se pudo obtener el usuario." },
      { status: 502 },
    );
  }
}
