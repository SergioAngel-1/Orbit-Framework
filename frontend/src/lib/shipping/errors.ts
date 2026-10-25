import { catalogFor } from "@/i18n/messages/catalogs";

// ============================================================================
//  Textos de error del envío para el cliente, compuestos en el BFF.
//
//  Las claves viven en `messages/` (`shippingErrors`) y se eligen con el idioma de la
//  petición (`X-HWE-Locale`), igual que los textos de `lib/loyalty/describe.ts`. El código
//  que llega del proveedor (WordPress) se traduce a una clave cerrada: nunca se muestra un
//  mensaje del proveedor ni un código sin mapear.
// ============================================================================

export type ShippingErrorKey = keyof ReturnType<typeof catalogFor>["shippingErrors"];

/** Texto de un error de envío en el idioma pedido (cualquier idioma sin catálogo cae a `es`). */
export function shippingErrorText(locale: string, key: ShippingErrorKey): string {
  return catalogFor(locale).shippingErrors[key];
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
