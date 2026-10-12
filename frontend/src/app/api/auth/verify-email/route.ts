import { NextResponse } from "next/server";
import { requireSession } from "@/lib/auth/session";
import { guardMutation } from "@/lib/api/guard";
import { verifyEmailBodySchema } from "@/lib/validation/auth";
import { logger } from "@/lib/observability/logger";
import { wpFetch } from "@/lib/wordpress/internal-fetch";

export const dynamic = "force-dynamic";

export async function POST(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "verify_email", limit: 5, windowSeconds: 60 },
  });
  if (blocked) return blocked;

  let body: unknown;
  try {
    body = await request.json();
  } catch {
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const parsed = verifyEmailBodySchema.safeParse(body);
  if (!parsed.success) {
    logger.warn(
      { event: "auth.verify_email.validation_error" },
      "Validación fallida al verificar el email",
    );
    return NextResponse.json(
      { error: "Datos inválidos.", details: parsed.error.flatten().fieldErrors },
      { status: 422 },
    );
  }

  let session;
  try {
    session = await requireSession();
  } catch {
    return NextResponse.json({ error: "No autenticado." }, { status: 401 });
  }

  try {
    const res = await wpFetch(`/wp-json/hwe/v1/auth/verify-email`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${session.token}`,
      },
      body: JSON.stringify({ token: parsed.data.token }),
    });

    if (!res.ok) {
      const data = (await res.json().catch(() => ({}))) as { error?: string };
      return NextResponse.json(
        { error: data.error || "Token inválido." },
        { status: 400 },
      );
    }

    logger.info({ event: "auth.verify_email.success", userId: session.userId });
    return NextResponse.json({ ok: true }, { status: 200 });
  } catch (error) {
    logger.error({
      event: "auth.verify_email.error",
      err: error instanceof Error ? error.message : error,
    });
    return NextResponse.json(
      { error: "Error de conexión con el servidor." },
      { status: 502 },
    );
  }
}
