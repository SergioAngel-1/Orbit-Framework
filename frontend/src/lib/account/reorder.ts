// ============================================================================
//  «Volver a pedir»: qué líneas de un pedido anterior pueden volver al carrito
//  y con qué cantidad.
//
//  Se decide en dos tiempos. Primero aquí, con el estado ACTUAL de cada
//  producto en wc/v3 (publicado, comprable, stock): lo que ya se sabe que no
//  entra no se intenta, y la cantidad se recorta al stock disponible en vez de
//  rechazar la línea entera. Después, la Store API tiene la última palabra al
//  añadir (stock ya ocupado por el propio carrito, una validación de un módulo
//  que rechaza la línea…): su rechazo se traduce con `reorderReasonFromError`.
//
//  Módulo puro: lo usan el Route Handler y los tests.
// ============================================================================

import type { WooOrderLineItem } from "@/types/woocommerce";

/** Lo que se pide de cada producto a wc/v3 (`_fields`). */
export const REORDER_PRODUCT_FIELDS =
  "id,status,purchasable,stock_status,manage_stock,stock_quantity,backorders_allowed";

export interface ReorderProduct {
  id: number;
  status: string;
  purchasable: boolean;
  stock_status: string;
  manage_stock: boolean;
  stock_quantity: number | null;
  backorders_allowed: boolean;
}

/**
 * Por qué una línea no volvió al carrito:
 * - `unavailable`: el producto ya no existe, no está publicado o no se vende.
 * - `out_of_stock`: agotado (o el carrito ya ocupa todo el stock).
 */
export type ReorderSkipReason = "unavailable" | "out_of_stock";

export interface ReorderLine {
  productId: number;
  name: string;
  quantity: number;
  /** Cantidad del pedido original, si se recortó al stock disponible. */
  requested?: number;
}

export interface ReorderSkipped {
  name: string;
  reason: ReorderSkipReason;
}

export interface ReorderPlan {
  add: ReorderLine[];
  skipped: ReorderSkipped[];
}

/** Lo que el BFF devuelve al navegador junto al carrito. */
export interface ReorderSummary {
  added: { productId: number; name: string; quantity: number; requested?: number }[];
  skipped: ReorderSkipped[];
}

/** Ids de producto distintos de las líneas (los que hay que consultar). */
export function reorderProductIds(lines: readonly WooOrderLineItem[]): number[] {
  return [...new Set(lines.map((l) => l.product_id).filter((id) => id > 0))];
}

/**
 * Stock que se puede pedir, o `null` si no hay techo (sin gestión de
 * inventario, o con reservas permitidas).
 */
function stockCap(product: ReorderProduct): number | null {
  if (!product.manage_stock || product.backorders_allowed) return null;
  return Math.max(0, product.stock_quantity ?? 0);
}

export function planReorder(
  lines: readonly WooOrderLineItem[],
  products: readonly ReorderProduct[],
): ReorderPlan {
  const byId = new Map(products.map((p) => [p.id, p]));
  const plan: ReorderPlan = { add: [], skipped: [] };

  for (const line of lines) {
    const product = line.product_id > 0 ? byId.get(line.product_id) : undefined;
    if (!product || product.status !== "publish" || !product.purchasable) {
      plan.skipped.push({ name: line.name, reason: "unavailable" });
      continue;
    }
    const cap = stockCap(product);
    if (product.stock_status === "outofstock" || cap === 0) {
      plan.skipped.push({ name: line.name, reason: "out_of_stock" });
      continue;
    }
    const requested = Math.max(1, line.quantity);
    const quantity = cap === null ? requested : Math.min(requested, cap);
    plan.add.push({
      productId: product.id,
      name: line.name,
      quantity,
      ...(quantity < requested ? { requested } : {}),
    });
  }
  return plan;
}

/** Rechazo de `cart/add-item` de la Store API → motivo para el cliente. */
export function reorderReasonFromError(code: string | undefined): ReorderSkipReason {
  if (!code) return "unavailable";
  if (code.includes("stock")) return "out_of_stock";
  return "unavailable";
}
