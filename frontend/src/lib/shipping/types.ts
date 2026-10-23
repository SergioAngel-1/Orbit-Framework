export interface ShippingMethod {
  id: number;
  name: string;
  /** Importe adicional en unidades mayores de la moneda de la tienda (p. ej. 5). 0 = sin costo extra. */
  addedValue: number;
}

/**
 * Estado del envío del destino actual, tal como lo publica WordPress en
 * `cart.extensions.hwe_shipping` (módulo `shipping`, `ShippingStatus`). Todo lo que no es `ok`
 * significa sin tarifas para ese destino; la UI lo explica en vez de decir «sin envío».
 */
export type ShippingMode = "fixed" | "distance" | "reserve" | "none";

export type ShippingStatusCode =
  "ok" | "needs_address" | "needs_location" | "out_of_coverage";

export interface ShippingStatus {
  mode: ShippingMode;
  status: ShippingStatusCode;
  /** Solo con `mode: distance` y `status: ok`. */
  distance_km?: number;
  duration_min?: number;
  fallback?: boolean;
}

export type CityRateType = "fixed" | "distance" | "reserve";

/**
 * Detalle de una tarifa `hwe_city_rate:*`, leído de sus metas (la Store API las da como texto).
 * `distanceKm` solo tiene valor en las opciones por distancia.
 */
export interface RateDetails {
  optionId: string;
  cityType: CityRateType;
  distanceKm: number | null;
  fallback: boolean;
}
