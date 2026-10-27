import { NextResponse } from "next/server";
import { BodyTooLarge, bodyTooLarge, readJsonBody } from "@/lib/api/body";
import { forgotPasswordSchema } from "@/lib/validation/auth";
import { guardMutation } from "@/lib/api/guard";
import { logger } from "@/lib/observability/logger";
import { wpFetch } from "@/lib/wordpress/internal-fetch";
import { authEmailLocale, authEmailUrl, frontendOrigin } from "@/lib/auth/email-links";

export const dynamic = "force-dynamic";

export async function POST(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "forgot_password", limit: 3, windowSeconds: 300, strict: true },
  });
  if (blocked) return blocked;

  let body: unknown;
  try {
    body = await readJsonBody(request);
  } catch (error) {
    if (error instanceof BodyTooLarge) return bodyTooLarge();
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const parsed = forgotPasswordSchema.safeParse(body);
  if (!parsed.success) {
    return NextResponse.json(
      { error: "Datos inválidos.", details: parsed.error.flatten().fieldErrors },
      { status: 422 },
    );
  }

  const locale = authEmailLocale(parsed.data.locale);
  try {
    const res = await wpFetch(`/wp-json/hwe/v1/auth/forgot-password`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        user_login: parsed.data.email,
        frontend_url: frontendOrigin(),
        reset_url: authEmailUrl("/reset-password", locale),
        locale,
      }),
    });

    if (!res.ok) {
      logger.warn({ event: "auth.forgot_password.wp_error", status: res.status });
      return NextResponse.json(
        { error: "No se pudo procesar la solicitud." },
        { status: 502 },
      );
    }

    logger.info(
      { event: "auth.forgot_password.success" },
      "Email de recuperación enviado",
    );
    return NextResponse.json({ ok: true }, { status: 200 });
  } catch (error) {
    logger.error({
      event: "auth.forgot_password.error",
      err: error instanceof Error ? error.message : error,
    });
    return NextResponse.json(
      { error: "Error de conexión con el servidor." },
      { status: 502 },
    );
  }
}
