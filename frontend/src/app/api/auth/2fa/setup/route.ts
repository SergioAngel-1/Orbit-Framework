import { NextResponse } from "next/server";
import { generateSecret } from "otplib";
import { requireSession } from "@/lib/auth/session";
import { isEmailVerified } from "@/lib/auth/email-verified";
import { guardMutation } from "@/lib/api/guard";
import { setPending2faSecret } from "@/lib/auth/two-factor-pending";
import { twoFactorIssuer } from "@/lib/auth/two-factor-issuer";
import { logger } from "@/lib/observability/logger";

export const dynamic = "force-dynamic";

export async function POST(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "2fa_setup", limit: 5, windowSeconds: 60, strict: true },
  });
  if (blocked) return blocked;

  let session;
  try {
    session = await requireSession();
  } catch {
    return NextResponse.json({ error: "No autenticado." }, { status: 401 });
  }

  // Sin email verificado no se emite ningún secreto: un segundo factor ligado a
  // una cuenta cuyo email nadie ha confirmado no protege nada.
  if (!(await isEmailVerified(session))) {
    logger.info(
      { event: "2fa.setup.email_unverified", userId: session.userId },
      "2FA bloqueado: email no verificado",
    );
    return NextResponse.json(
      { error: "Verifica tu email antes de activar 2FA." },
      { status: 403 },
    );
  }

  const secret = generateSecret();
  // El secreto queda PENDIENTE en el servidor: GET /2fa/qr lo sirve por sesión,
  // así que ya no viaja en ninguna URL. Es best-effort: sin Redis no hay QR, y
  // el cliente recurre al código manual que ya recibe aquí.
  await setPending2faSecret(session.userId, secret);

  logger.info({ event: "2fa.setup.generated" }, "Secreto 2FA generado");
  return NextResponse.json(
    { secret, site_name: await twoFactorIssuer() },
    { status: 200 },
  );
}
