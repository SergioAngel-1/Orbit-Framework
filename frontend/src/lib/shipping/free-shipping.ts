import type { StoreCart } from "@/types/woocommerce";

/**
 * Estado de la regla de «Envío gratis» tal como lo publica WordPress en
 * `cart.extensions.hwe_free_shipping` (módulo `shipping`, clase `FreeShipping`).
 * Importes en unidades menores, como el resto de la Store API.
 *
 * La regla se decide SOLO en WordPress —es quien pone la tarifa a 0—; este
 * módulo traduce ese estado a lo que se pinta, sin recalcular nada.
 */
export interface FreeShippingState {
  enabled: boolean;
  threshold: string;
  eligible_subtotal: string;
  remaining: string;
  qualifies: boolean;
  city: "eligible" | "not_eligible" | "unknown";
  all_cities: boolean;
  scoped: boolean;
}

export type FreeShippingNotice =
  /** El pedido ya no paga envío. */
  | { kind: "reached" }
  /**
   * La ciudad de entrega no lo admite: no hay nada que prometer a este pedido,
   * solo decir en qué ciudades aplica.
   */
  | { kind: "otherCities" }
  /** Llega al mínimo, pero aún no se sabe la ciudad de entrega. */
  | { kind: "pendingCity"; hints: FreeShippingHints }
  /** Falta compra para el mínimo. `progress` va de 0 a 1. */
  | {
      kind: "remaining";
      remaining: string;
      progress: number;
      hints: FreeShippingHints;
    };

export interface FreeShippingHints {
  /** No todas las ciudades lo admiten y aún no se conoce la del pedido. */
  selectedCities: boolean;
  /** Solo una parte del catálogo suma para el mínimo. */
  selectedProducts: boolean;
}

const CITY_STATES = new Set(["eligible", "not_eligible", "unknown"]);

function isAmount(value: unknown): value is string {
  return typeof value === "string" && /^\d+$/.test(value);
}

/**
 * Lee el estado del carrito validándolo: `parseCart` del cliente es un cast, y
 * un WordPress sin el módulo (o con una versión anterior) no trae la extensión.
 */
export function readFreeShipping(
  cart: Pick<StoreCart, "extensions"> | null | undefined,
): FreeShippingState | null {
  const raw = cart?.extensions?.hwe_free_shipping;
  if (!raw || typeof raw !== "object") return null;
  const state = raw as Partial<FreeShippingState>;
  if (
    typeof state.enabled !== "boolean" ||
    typeof state.qualifies !== "boolean" ||
    !isAmount(state.threshold) ||
    !isAmount(state.eligible_subtotal) ||
    !isAmount(state.remaining) ||
    typeof state.city !== "string" ||
    !CITY_STATES.has(state.city)
  ) {
    return null;
  }
  return {
    enabled: state.enabled,
    threshold: state.threshold,
    eligible_subtotal: state.eligible_subtotal,
    remaining: state.remaining,
    qualifies: state.qualifies,
    city: state.city,
    all_cities: state.all_cities === true,
    scoped: state.scoped === true,
  };
}

/** El envío de este carrito ya es gratis por la regla de la tienda. */
export function qualifiesForFreeShipping(
  cart: Pick<StoreCart, "extensions"> | null | undefined,
): boolean {
  const state = readFreeShipping(cart);
  return state !== null && state.enabled && state.qualifies;
}

/**
 * Qué aviso de envío gratis corresponde a este carrito, o `null` si ninguno:
 * regla apagada, o carrito sin nada que la alcance o que no se envía.
 */
export function resolveFreeShippingNotice(
  cart: Pick<StoreCart, "extensions" | "needs_shipping"> | null | undefined,
): FreeShippingNotice | null {
  if (!cart?.needs_shipping) return null;
  const state = readFreeShipping(cart);
  if (!state || !state.enabled) return null;
  if (state.city === "not_eligible") return { kind: "otherCities" };
  if (state.qualifies) return { kind: "reached" };

  const hints: FreeShippingHints = {
    selectedCities: state.city === "unknown" && !state.all_cities,
    selectedProducts: state.scoped,
  };

  const threshold = Number(state.threshold);
  const remaining = Number(state.remaining);
  if (remaining <= 0) {
    // Con la ciudad ya admitida y el mínimo alcanzado, WordPress habría
    // marcado `qualifies`: aquí solo se llega sin ciudad.
    return state.city === "unknown" ? { kind: "pendingCity", hints } : null;
  }

  const progress =
    threshold > 0
      ? Math.min(1, Math.max(0, Number(state.eligible_subtotal) / threshold))
      : 0;
  return { kind: "remaining", remaining: state.remaining, progress, hints };
}
