import { NextResponse } from "next/server";
import { verify as verifyTotp } from "otplib";
import { requireSession } from "@/lib/auth/session";
import { guardMutation } from "@/lib/api/guard";
import { disable2faBodySchema } from "@/lib/validation/auth";
import { delPending2faSecret } from "@/lib/auth/two-factor-pending";
import { logger } from "@/lib/observability/logger";

export const dynamic = "force-dynamic";

const WP_INTERNAL =
  process.env.WORDPRESS_INTERNAL_API_URL?.replace("/graphql", "") ??
  "http://wordpress:80";

/**
 * POST /api/auth/2fa/disable
 * Desactiva 2FA. Exige RE-VERIFICACIÓN con un código TOTP vigente (o, si se
 * envía, un código de recuperación) para que un robo de sesión no baste para
 * quitar el segundo factor.
 *
 * La prueba se reenvía a WordPress, que la exige por su cuenta: el endpoint
 * `hwe/v1/auth/2fa-secret` es alcanzable sin pasar por este BFF, así que no
 * puede fiarse de las comprobaciones de aquí.
 *
 * Con un código de recuperación NO se llama antes a `2fa-recovery/verify`: ese
 * endpoint lo CONSUME, y el PUT de abajo lo encontraría ya gastado. Es WordPress
 * quien lo verifica y consume, una sola vez, al desactivar.
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

  let body: unknown;
  try {
    body = await request.json();
  } catch {
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const parsed = disable2faBodySchema.safeParse(body);
  if (!parsed.success) {
    logger.warn(
      { event: "2fa.disable.validation_error" },
      "Validación fallida al desactivar 2FA",
    );
    return NextResponse.json(
      { error: "Datos inválidos.", details: parsed.error.flatten().fieldErrors },
      { status: 422 },
    );
  }
  const { code, recoveryCode } = parsed.data;

  // La prueba que se enviará a WordPress junto con el borrado del secreto. Si llegan
  // los dos códigos manda el de recuperación.
  let proof: { code: string } | { recovery_code: string };

  if (recoveryCode) {
    proof = { recovery_code: recoveryCode };
  } else if (code) {
    // Se comprueba aquí para no molestar a WordPress con códigos que ya sabemos
    // inválidos; WP lo vuelve a verificar al desactivar.
    try {
      const secretRes = await fetch(`${WP_INTERNAL}/wp-json/hwe/v1/auth/2fa-secret`, {
        headers: { Authorization: `Bearer ${session.token}` },
        cache: "no-store",
      });
      if (!secretRes.ok) {
        return NextResponse.json(
          { error: "Error al obtener configuración 2FA." },
          { status: 502 },
        );
      }
      const secretData = (await secretRes.json()) as { secret: string | null };
      if (!secretData.secret) {
        return NextResponse.json(
          { error: "2FA no está configurado." },
          { status: 400 },
        );
      }
      const totpResult = await verifyTotp({
        token: code,
        secret: secretData.secret,
      });
      if (!totpResult.valid) {
        return NextResponse.json({ error: "Código inválido." }, { status: 401 });
      }
    } catch (error) {
      logger.error({
        event: "2fa.disable.verify_error",
        err: error instanceof Error ? error.message : error,
      });
      return NextResponse.json({ error: "Error de conexión." }, { status: 502 });
    }
    proof = { code };
  } else {
    // Inalcanzable: el schema exige uno de los dos. Estrecha el tipo y, si alguien
    // relajara el schema, falla cerrado en vez de desactivar sin prueba.
    return NextResponse.json({ error: "Datos inválidos." }, { status: 422 });
  }

  // Limpiar el secreto (y los códigos de recuperación en WP), con la prueba.
  try {
    const res = await fetch(`${WP_INTERNAL}/wp-json/hwe/v1/auth/2fa-secret`, {
      method: "PUT",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${session.token}`,
      },
      body: JSON.stringify({ secret: null, ...proof }),
    });

    if (res.status === 403) {
      // WP rechazó la prueba (código incorrecto, o de recuperación ya usado).
      logger.warn({ event: "2fa.disable.proof_rejected", userId: session.userId });
      return NextResponse.json(
        {
          error: recoveryCode ? "Código de recuperación inválido." : "Código inválido.",
        },
        { status: 401 },
      );
    }
    if (!res.ok) {
      return NextResponse.json(
        { error: "No se pudo desactivar 2FA." },
        { status: 502 },
      );
    }

    // Defensivo: si había un intento de re-alta a medias (POST /2fa/setup
    // llamado de nuevo con 2FA ya activo), su secreto pendiente no debe seguir
    // vivo tras desactivar.
    await delPending2faSecret(session.userId);

    logger.info({ event: "2fa.disable.success", userId: session.userId });
    return NextResponse.json({ ok: true }, { status: 200 });
  } catch (error) {
    logger.error({
      event: "2fa.disable.error",
      err: error instanceof Error ? error.message : error,
    });
    return NextResponse.json({ error: "Error de conexión." }, { status: 502 });
  }
}
