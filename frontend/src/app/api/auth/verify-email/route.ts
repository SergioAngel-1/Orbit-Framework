import { NextResponse } from "next/server";
import { requireSession } from "@/lib/auth/session";
import { guardMutation } from "@/lib/api/guard";
import { logger } from "@/lib/observability/logger";

export const dynamic = "force-dynamic";

const WP_INTERNAL = process.env.WORDPRESS_INTERNAL_API_URL?.replace("/graphql", "") ?? "http://wordpress:80";

export async function POST(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "verify_email", limit: 5, windowSeconds: 60 },
  });
  if (blocked) return blocked;

  let body: { token?: string };
  try {
    body = await request.json() as { token?: string };
  } catch {
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  if (!body.token || typeof body.token !== "string") {
    return NextResponse.json({ error: "Token requerido." }, { status: 422 });
  }

  let session;
  try {
    session = await requireSession();
  } catch {
    return NextResponse.json({ error: "No autenticado." }, { status: 401 });
  }

  try {
    const res = await fetch(`${WP_INTERNAL}/wp-json/hwe/v1/auth/verify-email`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${session.token}`,
      },
      body: JSON.stringify({ token: body.token }),
    });

    if (!res.ok) {
      const data = await res.json().catch(() => ({})) as { error?: string };
      return NextResponse.json(
        { error: data.error || "Token inválido." },
        { status: 400 },
      );
    }

    logger.info({ event: "auth.verify_email.success", userId: session.userId });
    return NextResponse.json({ ok: true }, { status: 200 });
  } catch (error) {
    logger.error({ event: "auth.verify_email.error", err: error instanceof Error ? error.message : error });
    return NextResponse.json({ error: "Error de conexión con el servidor." }, { status: 502 });
  }
}
