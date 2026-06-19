import { NextResponse } from "next/server";
import { verify as verifyTotp } from "otplib";
import { requireSession } from "@/lib/auth/session";
import { guardMutation } from "@/lib/api/guard";
import { twoFactorCodeSchema } from "@/lib/validation/auth";
import { logger } from "@/lib/observability/logger";

export const dynamic = "force-dynamic";

const WP_INTERNAL = process.env.WORDPRESS_INTERNAL_API_URL?.replace("/graphql", "") ?? "http://wordpress:80";

/**
 * POST /api/auth/2fa/disable
 * Desactiva 2FA. Exige RE-VERIFICACIÓN con un código TOTP vigente (o, si se
 * envía, un código de recuperación) para que un robo de sesión no baste para
 * quitar el segundo factor.
 */
export async function POST(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "2fa_disable", limit: 3, windowSeconds: 60, strict: true },
  });
  if (blocked) return blocked;

  let session;
  try {
    session = await requireSession();
  } catch {
    return NextResponse.json({ error: "No autenticado." }, { status: 401 });
  }

  let body: { code?: string; recoveryCode?: string };
  try {
    body = (await request.json()) as { code?: string; recoveryCode?: string };
  } catch {
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  // Re-verificación obligatoria antes de desactivar.
  try {
    if (body.recoveryCode) {
      const recRes = await fetch(`${WP_INTERNAL}/wp-json/hwe/v1/auth/2fa-recovery/verify`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Authorization: `Bearer ${session.token}` },
        body: JSON.stringify({ code: body.recoveryCode }),
        cache: "no-store",
      });
      if (!recRes.ok) {
        return NextResponse.json({ error: "Código de recuperación inválido." }, { status: 401 });
      }
    } else {
      const parsed = twoFactorCodeSchema.safeParse({ code: body.code });
      if (!parsed.success) {
        return NextResponse.json({ error: "Código requerido para desactivar 2FA." }, { status: 422 });
      }
      const secretRes = await fetch(`${WP_INTERNAL}/wp-json/hwe/v1/auth/2fa-secret`, {
        headers: { Authorization: `Bearer ${session.token}` },
        cache: "no-store",
      });
      if (!secretRes.ok) {
        return NextResponse.json({ error: "Error al obtener configuración 2FA." }, { status: 502 });
      }
      const secretData = (await secretRes.json()) as { secret: string | null };
      if (!secretData.secret) {
        return NextResponse.json({ error: "2FA no está configurado." }, { status: 400 });
      }
      const totpResult = await verifyTotp({ token: parsed.data.code, secret: secretData.secret });
      if (!totpResult.valid) {
        return NextResponse.json({ error: "Código inválido." }, { status: 401 });
      }
    }
  } catch (error) {
    logger.error({ event: "2fa.disable.verify_error", err: error instanceof Error ? error.message : error });
    return NextResponse.json({ error: "Error de conexión." }, { status: 502 });
  }

  // Verificado: limpiar el secreto (y los códigos de recuperación en WP).
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
