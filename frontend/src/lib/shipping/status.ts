import type { StoreCart } from "@/types/woocommerce";
import type {
  CityRateType,
  RateDetails,
  ShippingMode,
  ShippingStatus,
  ShippingStatusCode,
} from "./types";

/**
 * Lectores del estado del envío y del detalle de cada tarifa. Son puros y tolerantes: los datos
 * vienen de `cart.extensions.hwe_shipping` y de las metas de `shipping_rates`, y WordPress sin el
 * módulo (o con una versión anterior) no los trae. Una forma inesperada da `null`; nunca lanza.
 * No hay lógica de precio aquí: el coste lo calcula WordPress.
 */

const MODES: readonly ShippingMode[] = ["fixed", "distance", "reserve", "none"];
const STATUSES: readonly ShippingStatusCode[] = [
  "ok",
  "needs_address",
  "needs_location",
  "out_of_coverage",
];
const CITY_TYPES: readonly CityRateType[] = ["fixed", "distance", "reserve"];

/** Número finito y no negativo (un JSON puede traer cualquier cosa). */
function isNonNegativeNumber(value: unknown): value is number {
  return typeof value === "number" && Number.isFinite(value) && value >= 0;
}

/**
 * Estado del envío del carrito, o `null` si la extensión no está o no tiene la forma del
 * contrato (modo y estado de la lista; los datos de distancia solo si son números).
 */
export function readShippingStatus(
  cart: Pick<StoreCart, "extensions"> | null | undefined,
): ShippingStatus | null {
  const raw = cart?.extensions?.hwe_shipping;
  if (!raw || typeof raw !== "object" || Array.isArray(raw)) return null;
  const state = raw as Record<string, unknown>;
  if (
    !MODES.includes(state.mode as ShippingMode) ||
    !STATUSES.includes(state.status as ShippingStatusCode)
  ) {
    return null;
  }

  const result: ShippingStatus = {
    mode: state.mode as ShippingMode,
    status: state.status as ShippingStatusCode,
  };
  for (const field of ["distance_km", "duration_min"] as const) {
    const value = state[field];
    if (value === undefined) continue;
    if (!isNonNegativeNumber(value)) return null;
    result[field] = value;
  }
  if (state.fallback !== undefined) {
    if (typeof state.fallback !== "boolean") return null;
    result.fallback = state.fallback;
  }
  return result;
}

/**
 * Detalle de una tarifa `hwe_city_rate:*` a partir de sus metas. `null` si la tarifa no lleva
 * las metas del módulo (otro método, o WordPress sin él) o si el id o el tipo no son válidos.
 * Los km se leen como texto numérico; si no lo son, `distanceKm` es `null` (sin inventar 0).
 */
export function readRateDetails(
  rate: { meta_data?: { key: string; value: string }[] } | null | undefined,
): RateDetails | null {
  const meta = new Map((rate?.meta_data ?? []).map((m) => [m.key, m.value]));
  const optionId = meta.get("hwe_option_id");
  const cityType = meta.get("hwe_city_type");
  if (typeof optionId !== "string" || optionId === "") return null;
  if (!CITY_TYPES.includes(cityType as CityRateType)) return null;

  const km = meta.get("hwe_distance_km");
  const distanceKm =
    cityType === "distance" && typeof km === "string" && /^\d+(\.\d+)?$/.test(km)
      ? Number(km)
      : null;

  return {
    optionId,
    cityType: cityType as CityRateType,
    distanceKm,
    fallback: meta.get("hwe_fallback") === "yes",
  };
}
