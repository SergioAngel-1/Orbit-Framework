import { NextResponse } from "next/server";
import { requireSession } from "@/lib/auth/session";
import { guardMutation } from "@/lib/api/guard";
import { logger } from "@/lib/observability/logger";
import { wpFetch } from "@/lib/wordpress/internal-fetch";
import { authEmailLocale, authEmailUrl } from "@/lib/auth/email-links";

export const dynamic = "force-dynamic";

export async function POST(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: {
      name: "resend_verification",
      limit: 3,
      windowSeconds: 120,
      strict: true,
    },
  });
  if (blocked) return blocked;

  let session;
  try {
    session = await requireSession();
  } catch {
    return NextResponse.json({ error: "No autenticado." }, { status: 401 });
  }

  // Cuerpo opcional: `{ locale }` del visitante (sin cuerpo, el idioma por defecto).
  const body = (await request.json().catch(() => null)) as { locale?: unknown } | null;
  const locale = authEmailLocale(body?.locale);

  try {
    const res = await wpFetch(`/wp-json/hwe/v1/auth/send-verification`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${session.token}`,
      },
      body: JSON.stringify({
        verification_url: authEmailUrl("/verify-email", locale),
        locale,
      }),
    });

    if (!res.ok) {
      const data = (await res.json().catch(() => ({}))) as { error?: string };
      const status = res.status === 400 ? 400 : 502;
      return NextResponse.json(
        { error: data.error || "No se pudo enviar el email." },
        { status },
      );
    }

    logger.info({ event: "auth.resend_verification.success" });
    return NextResponse.json({ ok: true }, { status: 200 });
  } catch (error) {
    logger.error({
      event: "auth.resend_verification.error",
      err: error instanceof Error ? error.message : error,
    });
    return NextResponse.json({ error: "Error de conexión." }, { status: 502 });
  }
}
