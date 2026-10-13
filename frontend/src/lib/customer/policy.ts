import type { SiteConfig } from "@/lib/config/types";
import { isModuleEnabled } from "@/lib/config/modules";
import type { GeoDocumentType, GeoPack } from "@/lib/geo/types";

/**
 * Qué pide el módulo `customer-identity` en esta tienda.
 *
 * - Módulo apagado: nada (`enabled: false`); el checkout y el registro no piden
 *   documento ni términos.
 * - Módulo encendido: siempre pide la aceptación de términos, y el documento solo
 *   si el paquete de país tiene tipos de documento (`documentTypes`).
 */
export interface IdentityPolicy {
  enabled: boolean;
  /** Tipos admitidos; vacío = no se pide documento. */
  documentTypes: GeoDocumentType[];
}

export function identityPolicy(
  config: Pick<SiteConfig, "modules">,
  pack: Pick<GeoPack, "documentTypes">,
): IdentityPolicy {
  if (!isModuleEnabled(config, "customer-identity")) {
    return { enabled: false, documentTypes: [] };
  }
  return { enabled: true, documentTypes: pack.documentTypes };
}
