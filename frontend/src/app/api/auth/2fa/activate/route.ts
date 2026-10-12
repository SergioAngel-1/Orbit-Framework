import { NextResponse } from "next/server";
import { verify } from "otplib";
import { twoFactorSetupSchema } from "@/lib/validation/auth";
import { requireSession } from "@/lib/auth/session";
import { isEmailVerified } from "@/lib/auth/email-verified";
import { guardMutation } from "@/lib/api/guard";
import { delPending2faSecret } from "@/lib/auth/two-factor-pending";
import { logger } from "@/lib/observability/logger";

export const dynamic = "force-dynamic";

const WP_INTERNAL =
  process.env.WORDPRESS_INTERNAL_API_URL?.replace("/graphql", "") ??
  "http://wordpress:80";

export async function POST(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "2fa_activate", limit: 5, windowSeconds: 60, strict: true },
  });
  if (blocked) return blocked;

  let body: unknown;
  try {
    body = await request.json();
  } catch {
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const parsed = twoFactorSetupSchema.safeParse(body);
  if (!parsed.success) {
    return NextResponse.json(
      { error: "Datos inválidos.", details: parsed.error.flatten().fieldErrors },
      { status: 422 },
    );
  }

  // Sesión y email primero: la verificación TOTP es la parte cara y no se paga
  // para quien no tiene sesión ni email verificado.
  let session;
  try {
    session = await requireSession();
  } catch {
    return NextResponse.json({ error: "No autenticado." }, { status: 401 });
  }

  if (!(await isEmailVerified(session))) {
    logger.info(
      { event: "2fa.activate.email_unverified", userId: session.userId },
      "2FA bloqueado: email no verificado",
    );
    return NextResponse.json(
      { error: "Verifica tu email antes de activar 2FA." },
      { status: 403 },
    );
  }

  // Código TOTP (CPU-bound, solo tras las comprobaciones de sesión y email).
  const result = await verify({
    token: parsed.data.code,
    secret: parsed.data.secret,
  });

  if (!result.valid) {
    logger.warn({ event: "2fa.activate.invalid_code" }, "Código 2FA inválido");
    return NextResponse.json(
      { error: "Código inválido. Intenta de nuevo." },
      { status: 400 },
    );
  }

  try {
    const res = await fetch(`${WP_INTERNAL}/wp-json/hwe/v1/auth/2fa-secret`, {
      method: "PUT",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${session.token}`,
      },
      // WP re-verifica el código contra el secreto antes de persistirlo (defensa
      // en profundidad: /wp-json/hwe/v1/* es alcanzable sin pasar por este BFF,
      // así que no puede fiarse solo del chequeo ya hecho arriba).
      body: JSON.stringify({ secret: parsed.data.secret, code: parsed.data.code }),
    });

    if (!res.ok) {
      return NextResponse.json(
        { error: "No se pudo guardar la configuración 2FA." },
        { status: 502 },
      );
    }

    // WP genera los códigos de recuperación al activar y los devuelve UNA vez.
    const data = (await res.json().catch(() => ({}))) as { recovery_codes?: string[] };

    // El secreto ya no está "pendiente": es el activo. GET /2fa/qr no debe poder
    // seguir sirviéndolo como QR escaneable durante el resto del TTL. Solo se
    // invalida tras guardarlo: si WordPress falla, el alta se puede reintentar.
    await delPending2faSecret(session.userId);

    logger.info({ event: "2fa.activate.success", userId: session.userId });
    return NextResponse.json(
      { ok: true, recovery_codes: data.recovery_codes ?? [] },
      { status: 200 },
    );
  } catch (error) {
    logger.error({
      event: "2fa.activate.error",
      err: error instanceof Error ? error.message : error,
    });
    return NextResponse.json({ error: "Error de conexión." }, { status: 502 });
  }
}
