import "server-only";
import { instanceRegistry } from "@/instance/registry";
import { isFallbackSiteConfig } from "./remote";
import type { ColorScheme, SiteConfig } from "./types";

const SCHEMES: readonly ColorScheme[] = ["system", "light", "dark"];

function isColorScheme(value: unknown): value is ColorScheme {
  return SCHEMES.includes(value as ColorScheme);
}

/**
 * Esquema de color efectivo del sitio (`design.color_scheme`, Control Center).
 *
 * Si WordPress no respondió, la config es el respaldo del código y no dice nada del sitio: manda
 * `InstanceRegistry.colorSchemeFallback`, para que una instancia sin tema oscuro no cambie de aspecto
 * por un fallo de la API. Un valor desconocido equivale a no fijar nada.
 */
export function resolveColorScheme(
  config: SiteConfig,
  fallback: ColorScheme | undefined = instanceRegistry.colorSchemeFallback,
): ColorScheme {
  if (isFallbackSiteConfig(config)) return isColorScheme(fallback) ? fallback : "system";
  const scheme = config.design?.color_scheme;
  return isColorScheme(scheme) ? scheme : "system";
}
