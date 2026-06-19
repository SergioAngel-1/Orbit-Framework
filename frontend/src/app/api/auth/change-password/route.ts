import { NextResponse } from "next/server";
import { z } from "zod";
import { requireSession } from "@/lib/auth/session";
import { guardMutation } from "@/lib/api/guard";
import { logger } from "@/lib/observability/logger";

export const dynamic = "force-dynamic";

const WP_INTERNAL = process.env.WORDPRESS_INTERNAL_API_URL?.replace("/graphql", "") ?? "http://wordpress:80";

const changePasswordSchema = z.object({
  currentPassword: z.string().min(1, "Contraseña actual requerida").max(200),
  newPassword: z.string().min(8, "Mínimo 8 caracteres").max(200),
});

export async function POST(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "change_password", limit: 5, windowSeconds: 60 },
  });
  if (blocked) return blocked;

  let body: unknown;
  try {
    body = await request.json();
  } catch {
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const parsed = changePasswordSchema.safeParse(body);
  if (!parsed.success) {
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

  // First verify current password by attempting login
  try {
    const loginRes = await fetch(`${WP_INTERNAL}/graphql`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        query: `mutation Login($username: String!, $password: String!) {
          login(input: { username: $username, password: $password }) { authToken }
        }`,
        variables: { username: session.userId, password: parsed.data.currentPassword },
      }),
    });
    const loginData = await loginRes.json() as { data?: { login?: { authToken?: string } }; errors?: unknown };
    if (!loginData.data?.login?.authToken) {
      return NextResponse.json({ error: "La contraseña actual no es correcta." }, { status: 403 });
    }

    // Update password via WP REST API
    const updateRes = await fetch(`${WP_INTERNAL}/wp-json/wp/v2/users/${session.userId}`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${loginData.data.login.authToken}`,
      },
      body: JSON.stringify({ password: parsed.data.newPassword }),
    });

    if (!updateRes.ok) {
      logger.warn({ event: "auth.change_password.wp_error", status: updateRes.status });
      return NextResponse.json({ error: "No se pudo cambiar la contraseña." }, { status: 502 });
    }

    logger.info({ event: "auth.change_password.success", userId: session.userId });
    return NextResponse.json({ ok: true }, { status: 200 });
  } catch (error) {
    logger.error({ event: "auth.change_password.error", err: error instanceof Error ? error.message : error });
    return NextResponse.json({ error: "Error de conexión." }, { status: 502 });
  }
}
