import { NextResponse } from "next/server";
import { cookies } from "next/headers";
import { AUTH_COOKIE, REFRESH_COOKIE } from "@/lib/auth/constants";
import { expiredCookieOptions } from "@/lib/security/cookies";
import { guardMutation } from "@/lib/api/guard";

export const dynamic = "force-dynamic";

/**
 * POST /api/auth/logout
 * Borra las cookies de sesión. (El refresh token del plugin no se revoca por
 * GraphQL; para invalidación global se rota el "user secret" en WordPress.)
 */
export async function POST(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "logout", limit: 30, windowSeconds: 60 },
  });
  if (blocked) return blocked;

  const store = await cookies();
  store.set(AUTH_COOKIE, "", expiredCookieOptions("/"));
  store.set(REFRESH_COOKIE, "", expiredCookieOptions("/"));

  return NextResponse.json({ ok: true }, { status: 200 });
}
