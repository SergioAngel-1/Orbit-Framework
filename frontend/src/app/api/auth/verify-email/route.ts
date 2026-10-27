import { NextResponse } from "next/server";
import { BodyTooLarge, bodyTooLarge, readJsonBody } from "@/lib/api/body";
import { guardMutation } from "@/lib/api/guard";
import { verifyEmailBodySchema } from "@/lib/validation/auth";
import { logger } from "@/lib/observability/logger";
import { wpFetch } from "@/lib/wordpress/internal-fetch";

export const dynamic = "force-dynamic";

/**
 * POST /api/auth/verify-email
 *
 * Verificación por token, SIN sesión: el enlace del correo se abre muchas veces
 * en otro navegador o dispositivo. WordPress identifica al usuario por el token
 * (aleatorio, 256 bits). Reabrir un enlace ya usado responde OK.
 */
export async function POST(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "verify_email", limit: 5, windowSeconds: 60, strict: true },
  });
  if (blocked) return blocked;

  let body: unknown;
  try {
    body = await readJsonBody(request);
  } catch (error) {
    if (error instanceof BodyTooLarge) return bodyTooLarge();
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

  try {
    const res = await wpFetch(`/wp-json/hwe/v1/auth/verify-email`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ token: parsed.data.token }),
      cache: "no-store",
    });

    if (!res.ok) {
      const data = (await res.json().catch(() => ({}))) as { error?: string };
      return NextResponse.json(
        { error: data.error || "Token inválido." },
        { status: res.status === 400 ? 400 : 502 },
      );
    }

    logger.info({ event: "auth.verify_email.success" });
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
