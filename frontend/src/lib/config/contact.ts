import type { SiteConfig } from "./types";

// ============================================================================
//  Teléfono general del negocio.
//
//  Datos legales del Control Center tiene DOS campos —`phone` (fijo) y
//  `mobile` (celular)— y cada superficie leía uno u otro a mano, con el riesgo
//  de publicar el valor por defecto de un campo que la instancia nunca rellenó.
//  Por eso el teléfono del negocio se pide AQUÍ y no leyendo `legal.phone`
//  suelto: un solo sitio decide que, sin fijo, el número del negocio es el
//  celular.
// ============================================================================

/**
 * El fijo si está configurado; si no, el celular. Cadena vacía si no hay
 * ninguno — el llamante decide si eso es no pintar nada.
 */
export function businessPhone(config: SiteConfig): string {
  return (config.legal?.phone || "").trim() || (config.legal?.mobile || "").trim();
}
