import "server-only";
import { getSiteConfig } from "@/lib/config";
import { CONFIG_DEFAULTS } from "@/lib/config/defaults";

/**
 * Nombre del sitio tal como lo ve el usuario en su app de autenticación (el
 * «emisor» del `otpauth://`). Sale de la config del sitio —la marca nunca se
 * lee de una constante ni de una variable de entorno— y, si está vacía, del
 * valor por defecto del framework.
 */
export async function twoFactorIssuer(): Promise<string> {
  const config = await getSiteConfig();
  return config.brand.name?.trim() || CONFIG_DEFAULTS.brand.name;
}
