import type { SiteConfig } from "@/lib/config/types";

// ============================================================================
//  Piezas comunes de los constructores de JSON-LD: URL base, listas del panel y
//  `PostalAddress`. Funciones puras.
// ============================================================================

/** Quita la barra final de una URL base. */
export function cleanBase(base: string): string {
  return (base || "").replace(/\/$/, "");
}

/** Parsea una lista separada por líneas o comas (p. ej. knowsAbout). */
export function parseList(raw?: string | null): string[] {
  if (!raw) return [];
  return raw
    .split(/[\n,]/)
    .map((s) => s.trim())
    .filter((s) => s.length > 0);
}

/**
 * `PostalAddress` con las partes que Google pide por separado
 * (`addressLocality`, `addressRegion`, `postalCode`): sin ellas los
 * resultados enriquecidos avisan «Falta addressLocality / postalCode». Salen
 * de campos propios del panel, no de trocear el texto libre de la dirección;
 * un campo vacío simplemente no se emite.
 */
export function postalAddress(
  config: SiteConfig,
  parts: { street: string; city?: string; region?: string; postalCode?: string },
): Record<string, unknown> {
  const address: Record<string, unknown> = {
    "@type": "PostalAddress",
    streetAddress: parts.street,
  };
  const city = (parts.city || "").trim();
  const region = (parts.region || "").trim();
  const postalCode = (parts.postalCode || "").trim();
  if (city) address.addressLocality = city;
  if (region) address.addressRegion = region;
  if (postalCode) address.postalCode = postalCode;
  if (config.ecommerce.country) address.addressCountry = config.ecommerce.country;
  return address;
}
