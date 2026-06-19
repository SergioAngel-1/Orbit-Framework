import { NextResponse } from "next/server";
import { requireSession } from "@/lib/auth/session";
import { logger } from "@/lib/observability/logger";

export const dynamic = "force-dynamic";

const WP_INTERNAL = process.env.WORDPRESS_INTERNAL_API_URL?.replace("/graphql", "") ?? "http://wordpress:80";

export async function GET() {
  let session;
  try {
    session = await requireSession();
  } catch {
    return NextResponse.json({ error: "No autenticado." }, { status: 401 });
  }

  try {
    const res = await fetch(`${WP_INTERNAL}/wp-json/hwe/v1/auth/me`, {
      headers: {
        Authorization: `Bearer ${session.token}`,
      },
      cache: "no-store",
    });

    if (!res.ok) {
      logger.warn({ event: "auth.me.wp_error", status: res.status });
      return NextResponse.json({ error: "No se pudo obtener la información del usuario." }, { status: 502 });
    }

    const data = await res.json() as { id: number; email: string; display_name: string; email_verified: boolean };
    return NextResponse.json(data, { status: 200 });
  } catch (error) {
    logger.error({ event: "auth.me.error", err: error instanceof Error ? error.message : error });
    return NextResponse.json({ error: "Error de conexión." }, { status: 502 });
  }
}
