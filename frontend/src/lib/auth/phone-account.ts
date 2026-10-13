import "server-only";
import { wpFetch } from "@/lib/wordpress/internal-fetch";
import { logger } from "@/lib/observability/logger";

// ============================================================================
//  Teléfono de la cuenta en WordPress (`hwe-auth.php`, solo con el secreto
//  interno y `auth.phone_login_enabled`). El teléfono es el `billing_phone`
//  de WooCommerce en E.164 y es único por cuenta.
// ============================================================================

async function post(path: string, body: Record<string, unknown>): Promise<Response> {
  return wpFetch(`/wp-json/hwe/v1/auth/${path}`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
    cache: "no-store",
  });
}

/**
 * Teléfono → identificador de login (el email de la cuenta). `null` si no hay
 * exactamente una cuenta con ese teléfono o WordPress no responde.
 */
export async function resolvePhoneToLogin(phone: string): Promise<string | null> {
  try {
    const res = await post("resolve-login", { phone });
    if (!res.ok) return null;
    const data = (await res.json()) as { login?: unknown };
    return typeof data.login === "string" && data.login !== "" ? data.login : null;
  } catch (error) {
    logger.warn({
      event: "auth.phone.resolve_error",
      err: error instanceof Error ? error.message : String(error),
    });
    return null;
  }
}

/** ¿Está libre el teléfono? Ante un fallo, `null`: el registro no debe seguir a ciegas. */
export async function isPhoneAvailable(phone: string): Promise<boolean | null> {
  try {
    const res = await post("phone-available", { phone });
    if (!res.ok) return null;
    const data = (await res.json()) as { available?: unknown };
    return typeof data.available === "boolean" ? data.available : null;
  } catch (error) {
    logger.warn({
      event: "auth.phone.available_error",
      err: error instanceof Error ? error.message : String(error),
    });
    return null;
  }
}

/** Guarda el teléfono en una cuenta recién creada. `false` si no se pudo (p. ej. 409). */
export async function attachPhoneToAccount(
  userId: number,
  phone: string,
): Promise<boolean> {
  try {
    const res = await post("attach-profile", { user_id: userId, phone });
    if (!res.ok) {
      logger.warn({ event: "auth.phone.attach_failed", status: res.status, userId });
      return false;
    }
    return true;
  } catch (error) {
    logger.warn({
      event: "auth.phone.attach_error",
      userId,
      err: error instanceof Error ? error.message : String(error),
    });
    return false;
  }
}
