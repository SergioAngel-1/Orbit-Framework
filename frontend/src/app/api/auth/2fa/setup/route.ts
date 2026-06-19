import { NextResponse } from "next/server";
import { generateSecret } from "otplib";
import { requireSession } from "@/lib/auth/session";
import { guardMutation } from "@/lib/api/guard";
import { logger } from "@/lib/observability/logger";

export const dynamic = "force-dynamic";

export async function POST(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "2fa_setup", limit: 5, windowSeconds: 60, strict: true },
  });
  if (blocked) return blocked;

  try {
    await requireSession();
  } catch {
    return NextResponse.json({ error: "No autenticado." }, { status: 401 });
  }

  const secret = generateSecret();
  const siteName = process.env.NEXT_PUBLIC_SITE_NAME || "HeadlessWP";

  logger.info({ event: "2fa.setup.generated" }, "Secreto 2FA generado");
  return NextResponse.json({ secret, site_name: siteName }, { status: 200 });
}
