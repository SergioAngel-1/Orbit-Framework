import { NextResponse } from "next/server";
import { requireSession } from "@/lib/auth/session";
import { guardMutation } from "@/lib/api/guard";
import { logger } from "@/lib/observability/logger";

export const dynamic = "force-dynamic";

const WP_INTERNAL = process.env.WORDPRESS_INTERNAL_API_URL?.replace("/graphql", "") ?? "http://wordpress:80";

export async function POST(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "2fa_disable", limit: 3, windowSeconds: 60 },
  });
  if (blocked) return blocked;

  let session;
  try {
    session = await requireSession();
  } catch {
    return NextResponse.json({ error: "No autenticado." }, { status: 401 });
  }

  try {
    const res = await fetch(`${WP_INTERNAL}/wp-json/hwe/v1/auth/2fa-secret`, {
      method: "PUT",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${session.token}`,
      },
      body: JSON.stringify({ secret: null }),
    });

    if (!res.ok) {
      return NextResponse.json({ error: "No se pudo desactivar 2FA." }, { status: 502 });
    }

    logger.info({ event: "2fa.disable.success", userId: session.userId });
    return NextResponse.json({ ok: true }, { status: 200 });
  } catch (error) {
    logger.error({ event: "2fa.disable.error", err: error instanceof Error ? error.message : error });
    return NextResponse.json({ error: "Error de conexión." }, { status: 502 });
  }
}
