import type { StoreCartItem } from "@/types/woocommerce";

/**
 * Ajustes de stock al recuperar un carrito ya persistido en Woo.
 * Puro (sin I/O) para poder unit-testear la política.
 */
export type CartStockAdjustment =
  | { action: "remove"; key: string; reason: "out_of_stock" | "below_minimum" }
  | {
      action: "clamp";
      key: string;
      quantity: number;
      reason: "insufficient_stock";
    };

/**
 * Decide qué líneas quitar o reducir según `quantity_limits` de la Store API
 * (el máximo ya refleja stock restante, backorders y sold_individually).
 */
export function planCartStockAdjustments(
  items: StoreCartItem[],
): CartStockAdjustment[] {
  const plans: CartStockAdjustment[] = [];

  for (const item of items) {
    const limits = item.quantity_limits;
    if (!limits) continue;

    const max = Number(limits.maximum);
    const min = Number(limits.minimum ?? 1);
    const qty = Number(item.quantity);

    if (!Number.isFinite(max) || !Number.isFinite(min) || !Number.isFinite(qty)) {
      continue;
    }

    // Sin unidades disponibles (u. máximas por debajo del mínimo de compra).
    if (max <= 0 || max < min) {
      plans.push({ action: "remove", key: item.key, reason: "out_of_stock" });
      continue;
    }

    if (qty < min) {
      plans.push({ action: "remove", key: item.key, reason: "below_minimum" });
      continue;
    }

    if (qty > max) {
      plans.push({
        action: "clamp",
        key: item.key,
        quantity: max,
        reason: "insufficient_stock",
      });
    }
  }

  return plans;
}
