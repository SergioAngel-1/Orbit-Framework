import es from "@/i18n/messages/es.json";
import en from "@/i18n/messages/en.json";

// ============================================================================
//  Textos de error del envío para el cliente, compuestos en el BFF.
//
//  Las claves viven en `messages/` (`shippingErrors`) y se eligen con el idioma de la
//  petición (`X-HWE-Locale`), igual que los textos de `lib/loyalty/describe.ts`. El código
//  que llega del proveedor (WordPress) se traduce a una clave cerrada: nunca se muestra un
//  mensaje del proveedor ni un código sin mapear.
// ============================================================================

export type ShippingErrorKey = keyof typeof es.shippingErrors;

const CATALOGS = { es, en } as const;

/** Texto de un error de envío en el idioma pedido (cualquier idioma sin catálogo cae a `es`). */
export function shippingErrorText(locale: string, key: ShippingErrorKey): string {
  const catalog = locale.toLowerCase().startsWith("en") ? CATALOGS.en : CATALOGS.es;
  return catalog.shippingErrors[key];
}

/** Clave de texto para un código de geocodificación de WordPress (los desconocidos, genéricos). */
export function geocodeErrorKey(code: string): ShippingErrorKey {
  switch (code) {
    case "no_results":
      return "geocodeNoResults";
    case "invalid_query":
    case "invalid_point":
      return "geocodeInvalid";
    case "geocoding_backoff":
      return "geocodeBackoff";
    default:
      return "geocodeUnavailable";
  }
}
