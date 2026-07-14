import { NextResponse } from "next/server";
import { verify } from "otplib";
import { twoFactorSetupSchema } from "@/lib/validation/auth";
import { requireSession } from "@/lib/auth/session";
import { guardMutation } from "@/lib/api/guard";
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

  // Verify TOTP code
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
      body: JSON.stringify({ secret: parsed.data.secret }),
    });

    if (!res.ok) {
      return NextResponse.json(
        { error: "No se pudo guardar la configuración 2FA." },
        { status: 502 },
      );
    }

    // WP genera los códigos de recuperación al activar y los devuelve UNA vez.
    const data = (await res.json().catch(() => ({}))) as { recovery_codes?: string[] };

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
