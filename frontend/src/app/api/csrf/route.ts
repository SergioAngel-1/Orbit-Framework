import { NextResponse } from "next/server";
import { issueCsrfToken } from "@/lib/security/csrf";

export const dynamic = "force-dynamic";

/**
 * GET /api/csrf
 * Emite un token CSRF: lo fija en la cookie `hwe_csrf` (legible por el cliente)
 * y lo devuelve en el cuerpo. El cliente debe reenviarlo en la cabecera
 * `X-CSRF-Token` en toda petición de escritura.
 */
export async function GET() {
  const csrfToken = await issueCsrfToken();
  return NextResponse.json({ csrfToken });
}
