import { NextResponse } from "next/server";
import { resetPasswordSchema } from "@/lib/validation/auth";
import { guardMutation } from "@/lib/api/guard";
import { logger } from "@/lib/observability/logger";

export const dynamic = "force-dynamic";

const WP_INTERNAL = process.env.WORDPRESS_INTERNAL_API_URL?.replace("/graphql", "") ?? "http://wordpress:80";

export async function POST(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "reset_password", limit: 5, windowSeconds: 300, strict: true },
  });
  if (blocked) return blocked;

  let body: unknown;
  try {
    body = await request.json();
  } catch {
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const parsed = resetPasswordSchema.safeParse(body);
  if (!parsed.success) {
    return NextResponse.json(
      { error: "Datos inválidos.", details: parsed.error.flatten().fieldErrors },
      { status: 422 },
    );
  }

  try {
    const res = await fetch(`${WP_INTERNAL}/wp-json/hwe/v1/auth/reset-password`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        key: parsed.data.key,
        login: parsed.data.login,
        new_password: parsed.data.password,
      }),
    });

    if (!res.ok) {
      const data = await res.json().catch(() => ({})) as { error?: string };
      return NextResponse.json(
        { error: data.error || "El enlace no es válido o ya expiró." },
        { status: 400 },
      );
    }

    logger.info({ event: "auth.reset_password.success" }, "Contraseña restablecida");
    return NextResponse.json({ ok: true }, { status: 200 });
  } catch (error) {
    logger.error({ event: "auth.reset_password.error", err: error instanceof Error ? error.message : error });
    return NextResponse.json({ error: "Error de conexión con el servidor." }, { status: 502 });
  }
}
