import { NextResponse } from "next/server";
import { issueCsrfToken } from "@/lib/security/csrf";
import { logger } from "@/lib/observability/logger";

export const dynamic = "force-dynamic";

/**
 * GET /api/csrf
 * Emite un token CSRF: lo fija en la cookie `hwe_csrf` (legible por el cliente)
 * y lo devuelve en el cuerpo. El cliente debe reenviarlo en la cabecera
 * `X-CSRF-Token` en toda petición de escritura.
 */
export async function GET() {
  const csrfToken = await issueCsrfToken();
  logger.info({ event: "csrf.token_issued" }, "Token CSRF generado");
  // El token es por navegador y la respuesta fija una cookie: no debe cachearse.
  return NextResponse.json(
    { csrfToken },
    { headers: { "Cache-Control": "private, no-store" } },
  );
}
