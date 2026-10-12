import "server-only";
import { logger } from "@/lib/observability/logger";
import type { Session } from "@/types/auth";
import { wpFetch } from "@/lib/wordpress/internal-fetch";

/**
 * ¿El email del usuario de la sesión está verificado en WordPress?
 *
 * Falla CERRADO: ante un error de WordPress, de red o una respuesta que no sea
 * exactamente `email_verified: true`, devuelve `false` (y lo registra).
 */
export async function isEmailVerified(session: Session): Promise<boolean> {
  try {
    const res = await wpFetch(`/wp-json/hwe/v1/auth/me`, {
      headers: { Authorization: `Bearer ${session.token}` },
      cache: "no-store",
    });
    if (!res.ok) {
      logger.warn(
        { event: "auth.email_verified.check_failed", status: res.status },
        "No se pudo comprobar la verificación del email",
      );
      return false;
    }
    const data = (await res.json()) as { email_verified?: unknown };
    return data.email_verified === true;
  } catch (error) {
    logger.warn(
      {
        event: "auth.email_verified.check_failed",
        err: error instanceof Error ? error.message : String(error),
      },
      "No se pudo comprobar la verificación del email",
    );
    return false;
  }
}
