import { NextResponse } from "next/server";
import { cookies } from "next/headers";
import { AUTH_COOKIE, REFRESH_COOKIE } from "@/lib/auth/constants";
import { expiredCookieOptions } from "@/lib/security/cookies";
import { guardMutation } from "@/lib/api/guard";
import { requireSession } from "@/lib/auth/session";
import { revokeToken } from "@/lib/auth/revocation";
import { clearAuthenticatedCart } from "@/lib/woocommerce/cart-session";
import { logger } from "@/lib/observability/logger";
import { wpFetch } from "@/lib/wordpress/internal-fetch";

export const dynamic = "force-dynamic";

/**
 * POST /api/auth/logout-all
 * Cierra TODAS las sesiones del usuario: rota su secreto JWT en WordPress
 * (invalida los refresh tokens existentes), revoca el access token actual y
 * borra las cookies de este dispositivo.
 */
export async function POST(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "logout_all", limit: 5, windowSeconds: 60, strict: true },
  });
  if (blocked) return blocked;

  let session;
  try {
    session = await requireSession();
  } catch {
    return NextResponse.json({ error: "No autenticado." }, { status: 401 });
  }

  try {
    const res = await wpFetch(`/wp-json/hwe/v1/auth/logout-all`, {
      method: "POST",
      headers: { Authorization: `Bearer ${session.token}` },
      cache: "no-store",
    });
    if (!res.ok) {
      return NextResponse.json(
        { error: "No se pudieron cerrar las sesiones." },
        { status: 502 },
      );
    }
  } catch (error) {
    logger.error({
      event: "auth.logout_all.error",
      err: error instanceof Error ? error.message : error,
    });
    return NextResponse.json({ error: "Error de conexión." }, { status: 502 });
  }

  // El carrito de la cuenta (WooCommerce lo liga al cliente) y la cookie del
  // carrito de este dispositivo: la sesión siguiente no hereda lo del cliente.
  await clearAuthenticatedCart(session.token);

  // Revocar el access token actual y limpiar cookies del dispositivo.
  await revokeToken(session.token);
  const store = await cookies();
  store.set(AUTH_COOKIE, "", expiredCookieOptions("/"));
  store.set(REFRESH_COOKIE, "", expiredCookieOptions("/"));

  logger.info(
    { event: "auth.logout_all", userId: session.userId },
    "Todas las sesiones cerradas",
  );
  return NextResponse.json({ ok: true }, { status: 200 });
}
