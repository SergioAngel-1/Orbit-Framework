import { NextResponse } from "next/server";
import QRCode from "qrcode";
import { requireSession } from "@/lib/auth/session";
import { getPending2faSecret } from "@/lib/auth/two-factor-pending";
import { twoFactorIssuer } from "@/lib/auth/two-factor-issuer";
import { buildOtpauthUri } from "@/lib/auth/otpauth";
import { getCustomer } from "@/lib/account/data";
import { logger } from "@/lib/observability/logger";

export const dynamic = "force-dynamic";

/**
 * GET /api/auth/2fa/qr
 *
 * Renderiza el QR del alta de 2FA EN NUESTRO SERVIDOR. Antes se delegaba en
 * api.qrserver.com, lo que enviaba el secreto TOTP compartido a un tercero
 * dentro de la URL (queda en sus logs y en el historial del navegador).
 *
 * Este endpoint NO acepta el secreto del cliente (un `?otpauth=` en la URL
 * seguiría filtrándolo en logs propios, en el historial y en la cabecera
 * Referer, y nos convertiría en un generador de QR arbitrarios). Exige sesión y
 * lee el secreto pendiente que `POST /2fa/setup` guardó en el servidor, por
 * `userId`: cualquier parámetro de la URL se ignora sin excepción.
 *
 * La respuesta es `no-store`: la imagen contiene el secreto.
 */
export async function GET() {
  let session;
  try {
    session = await requireSession();
  } catch {
    return NextResponse.json({ error: "No autenticado." }, { status: 401 });
  }

  const secret = await getPending2faSecret(session.userId);
  if (!secret) {
    return NextResponse.json(
      { error: "No hay una configuración 2FA pendiente." },
      { status: 404 },
    );
  }

  let account = "user";
  try {
    const customer = await getCustomer();
    if (customer.email) account = customer.email;
  } catch {
    /* etiqueta genérica si no se puede resolver el email */
  }

  const uri = buildOtpauthUri({
    issuer: await twoFactorIssuer(),
    account,
    secret,
  });

  let svg: string;
  try {
    svg = await QRCode.toString(uri, {
      type: "svg",
      margin: 1,
      width: 200,
      errorCorrectionLevel: "M",
    });
  } catch (error) {
    // No se registra la URI: contiene el secreto TOTP.
    logger.error({
      event: "auth.2fa_qr.render_error",
      err: error instanceof Error ? error.message : error,
    });
    return NextResponse.json(
      { error: "No se pudo generar el código QR." },
      { status: 500 },
    );
  }

  return new NextResponse(svg, {
    headers: {
      "Content-Type": "image/svg+xml; charset=utf-8",
      "Cache-Control": "no-store",
    },
  });
}
