// ============================================================================
//  Compra de un producto por el cliente, vista desde la reseña de su ficha.
//
//  Reseñar exige un pedido COMPLETADO que lo contenga, la misma regla que la
//  calificación del pedido (`canRateOrder`): el cliente ya lo tiene en la mano.
//  Si el único pedido con el producto sigue «Procesando», la ficha le ofrece
//  «Recibí mi producto», que completa ESE pedido y habilita la reseña.
//
//  Módulo puro: lo usan el BFF y los tests.
// ============================================================================

import type { WooOrder } from "@/types/woocommerce";
import { reviewableProductIds } from "./order-review";

export interface PurchaseOrder {
  id: number;
  number: string;
  /** Productos reseñables del pedido (incluido el de la ficha). */
  productIds: number[];
}

export interface ProductPurchase {
  /** El pedido completado MÁS RECIENTE con el producto: el que habilita reseñar. */
  completed: PurchaseOrder | null;
  /** El pedido en preparación más reciente con el producto. */
  processing: PurchaseOrder | null;
}

function toPurchaseOrder(order: Pick<WooOrder, "id" | "number" | "line_items">) {
  return {
    id: order.id,
    number: order.number || String(order.id),
    // Los mismos que «Calificar pedido» (sin las líneas no reseñables).
    productIds: reviewableProductIds(order.line_items ?? []),
  };
}

/**
 * Recorre los pedidos (más reciente primero) y se queda con el primer
 * completado y el primer en preparación que contienen el producto. `found`
 * acumula entre páginas; con un completado ya no hace falta seguir buscando.
 */
export function collectProductPurchase(
  orders: readonly Pick<WooOrder, "id" | "number" | "status" | "line_items">[],
  productId: number,
  found: ProductPurchase = { completed: null, processing: null },
): ProductPurchase {
  const next = { ...found };
  for (const order of orders) {
    if (next.completed) break;
    if (!(order.line_items ?? []).some((l) => l.product_id === productId)) continue;
    if (order.status === "completed") next.completed = toPurchaseOrder(order);
    else if (order.status === "processing" && !next.processing) {
      next.processing = toPurchaseOrder(order);
    }
  }
  return next;
}
