// ============================================================================
//  «Calificar pedido»: la reseña del SITIO que un cliente deja sobre un pedido
//  (no la de un producto). La guarda WordPress (plugin `hwe-site-reviews`: CPT
//  «Reseñas del sitio» + el espejo `_hwe_site_review` en el pedido, que es lo
//  que se lee aquí: llega en el `meta_data` de wc/v3 sin otra petición).
//
//  Módulo puro: lo usan el BFF, las vistas de servidor y los tests.
// ============================================================================

import type { WooOrder, WooOrderLineItem } from "@/types/woocommerce";

/** Meta del pedido con el espejo de su reseña. */
export const SITE_REVIEW_ORDER_META = "_hwe_site_review";

/**
 * Solo un pedido «Completado» se califica (la reseña del sitio y, con ella, la
 * de sus productos): es el que el cliente ya tiene en la mano. Un pedido
 * «Procesando» —«En preparación» en la tienda— lo pasa a «Completado» el propio
 * cliente con «Recibí mi pedido» (`canConfirmReceipt`), y entonces se habilita.
 * Debe coincidir con `RATEABLE_STATUSES` del plugin.
 */
const RATEABLE_STATUS = "completed";

/** Estado en el que el cliente puede confirmar que recibió el pedido. */
const RECEIVABLE_STATUS = "processing";

export interface SiteReview {
  rating: number;
  content: string;
  /** ISO 8601. */
  date: string;
}

/**
 * ¿Se puede calificar el pedido? La misma regla vale para publicar la reseña
 * en sus productos.
 */
export function canRateOrder(status: string): boolean {
  return status === RATEABLE_STATUS;
}

/** ¿Puede el cliente marcar el pedido como recibido («Recibí mi pedido»)? */
export function canConfirmReceipt(status: string): boolean {
  return status === RECEIVABLE_STATUS;
}

/** La reseña del pedido, o `null` si aún no se calificó. */
export function siteReviewFromMeta(meta: WooOrder["meta_data"]): SiteReview | null {
  const value = meta?.find((m) => m.key === SITE_REVIEW_ORDER_META)?.value;
  if (!value || typeof value !== "object") return null;
  const { rating, content, date } = value as Record<string, unknown>;
  const stars = Number(rating);
  if (!Number.isInteger(stars) || stars < 1 || stars > 5) return null;
  return {
    rating: stars,
    content: typeof content === "string" ? content : "",
    date: typeof date === "string" ? date : "",
  };
}

/**
 * Meta de una línea que la excluye de las reseñas de producto: un producto
 * compuesto cuya ficha no muestra reseñas (una mezcla a medida, un servicio…),
 * donde una reseña nunca se vería. La pone quien arma la línea (un módulo o la
 * instancia, p. ej. con `hwe_order_item_details`); el núcleo solo la respeta.
 */
export const NOT_REVIEWABLE_LINE_META = "_hwe_not_reviewable";

/**
 * Productos distintos del pedido que se pueden reseñar: ni los borrados
 * (`product_id` 0) ni las líneas marcadas con {@link NOT_REVIEWABLE_LINE_META}.
 */
export function reviewableProductIds(lines: readonly WooOrderLineItem[]): number[] {
  return [
    ...new Set(
      lines
        .filter(
          (l) => !(l.meta_data ?? []).some((m) => m.key === NOT_REVIEWABLE_LINE_META),
        )
        .map((l) => l.product_id)
        .filter((id) => id > 0),
    ),
  ];
}

/**
 * Qué ofrece la vista sobre la calificación de un pedido:
 * - `rate`: «Calificar pedido» (Completado, sin reseña).
 * - `receive`: «Recibí mi pedido» (Procesando): al confirmarlo pasa a `rate`.
 * - `null`: nada que hacer (sin reseña y en otro estado).
 * Una reseña ya hecha se enseña siempre, cambie después lo que cambie el estado.
 */
export type OrderReviewMode = "rate" | "receive" | null;

export interface OrderReviewState {
  show: boolean;
  review: SiteReview | null;
  mode: OrderReviewMode;
  /**
   * Productos del pedido que el cliente AÚN NO reseñó: en los que el modal
   * ofrece publicar también la calificación. Sin `reviewed`, todos.
   */
  productCount: number;
  /**
   * El pedido tiene productos reseñables y el cliente ya los reseñó todos: el
   * modal lo dice en vez del check. Calificar el pedido sigue valiendo —valora
   * la compra, no los productos— y nunca toca esas reseñas.
   */
  allProductsReviewed: boolean;
  /** Algunos (no todos) ya están reseñados: el check lo aclara. */
  someProductsReviewed: boolean;
}

/**
 * @param reviewed productos que el cliente ya reseñó (`reviewedProductIds`). Si
 * no se conocen, se ofrecen todos: el servidor se salta igualmente los ya
 * reseñados, así que nunca se duplica una reseña.
 */
export function orderReviewState(
  order: Pick<WooOrder, "status" | "meta_data" | "line_items">,
  reviewed: ReadonlySet<number> = new Set(),
): OrderReviewState {
  const review = siteReviewFromMeta(order.meta_data);
  const mode: OrderReviewMode = review
    ? null
    : canRateOrder(order.status)
      ? "rate"
      : canConfirmReceipt(order.status)
        ? "receive"
        : null;
  // También en `receive`: al confirmar la recepción el modal ya lo necesita.
  const ids = reviewableProductIds(order.line_items ?? []);
  const pending = ids.filter((id) => !reviewed.has(id)).length;
  return {
    show: review !== null || mode !== null,
    review,
    mode,
    productCount: pending,
    allProductsReviewed: ids.length > 0 && pending === 0,
    someProductsReviewed: pending > 0 && pending < ids.length,
  };
}
