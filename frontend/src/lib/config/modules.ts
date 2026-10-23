import type { ModuleId, SiteConfig } from "./types";

export type { ModuleId };

/** Ids del catálogo, en el mismo orden que `Modules::CORE_IDS` en PHP. */
export const MODULE_IDS: readonly ModuleId[] = [
  "banners",
  "shipping",
  "product-landing",
  "landings",
  "consent-log",
  "site-reviews",
  "coupon-rules",
  "customer-identity",
  "external-integration",
  "loyalty",
  "memberships",
];

/**
 * ¿Está activo el módulo? Lee `config.modules`, que WordPress calcula según si
 * el plugin está activo (ver `Modules::activeMap`). Con el módulo apagado, su
 * lector devuelve vacío o nulo y su UI no se pinta (contrato de módulo).
 */
export function isModuleEnabled(
  config: Pick<SiteConfig, "modules">,
  id: ModuleId,
): boolean {
  return config.modules?.[id] === true;
}
