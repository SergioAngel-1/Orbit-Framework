import "server-only";
import { revalidateTag } from "next/cache";
import { getSiteConfig } from "@/lib/config";
import { wcFetch } from "@/lib/woocommerce/client";
import { invalidateCachedEligibility } from "@/lib/account/review-eligibility";
import { resolveReviewerName } from "./reviewer-name";
import { orderReviewState, reviewableProductIds } from "@/lib/account/order-review";
import type { WooCustomer, WooOrder, WooProductReview } from "@/types/woocommerce";

/** Tope defensivo de páginas de {@link reviewedProductIds}. */
const REVIEWED_MAX_PAGES = 10;

/**
 * Productos de la lista que el cliente YA reseñó: filtra por su correo y por
 * todos los ids a la vez, normalmente en una sola petición. Pagina por si
 * acaso: con más de 100 reseñas suyas entre esos productos, la primera página
 * no las traería todas y se ofrecería —o se intentaría— reseñar de nuevo.
 */
export async function reviewedProductIds(
  customer: Pick<WooCustomer, "email">,
  productIds: readonly number[],
): Promise<Set<number>> {
  const reviewed = new Set<number>();
  if (productIds.length === 0 || !customer.email) return reviewed;
  const perPage = 100;
  for (let page = 1; page <= REVIEWED_MAX_PAGES; page++) {
    const batch = await wcFetch<WooProductReview[]>("/products/reviews", {
      query: {
        product: productIds.join(","),
        reviewer_email: customer.email,
        per_page: perPage,
        page,
        _fields: "id,product_id",
      },
    });
    for (const r of batch) reviewed.add(r.product_id);
    if (batch.length < perPage) break;
  }
  return reviewed;
}

/**
 * Lo que las vistas de pedido (historial, detalle, gracias) pasan a
 * `orderReviewState`: qué productos de los pedidos que ofrecen calificar o
 * recibir ya reseñó el cliente. Una sola consulta para todos. Si falla, o sin
 * perfil, vacío: el modal ofrecería todos y el servidor se saltaría igualmente
 * los ya reseñados.
 */
export async function reviewedProductsForOrders(
  customer: Pick<WooCustomer, "email"> | null,
  orders: readonly Pick<WooOrder, "status" | "meta_data" | "line_items">[],
): Promise<Set<number>> {
  if (!customer) return new Set();
  const ids = [
    ...new Set(
      orders
        .filter((o) => orderReviewState(o).mode !== null)
        .flatMap((o) => reviewableProductIds(o.line_items ?? [])),
    ),
  ];
  try {
    return await reviewedProductIds(customer, ids);
  } catch {
    return new Set();
  }
}

/**
 * Tras crear reseñas de un pedido: la elegibilidad cacheada de TODOS sus
 * productos cambia —la del reseñado pasa a «ya reseñado» y la de los demás
 * cuenta un producto pendiente menos en el check «misma reseña para el
 * pedido»—, así que se invalidan todas, no solo las reseñadas.
 */
export async function invalidateOrderEligibility(
  userId: number | string,
  productIds: readonly number[],
): Promise<void> {
  await Promise.all(productIds.map((pid) => invalidateCachedEligibility(userId, pid)));
}

/**
 * Publica la MISMA reseña en varios productos de un pedido completado del
 * cliente, saltando los que ya reseñó. La comparten «Calificar pedido» (con
 * todos los productos del pedido) y la ficha (con los demás productos del
 * pedido de ese producto). Como desde la ficha: aprobadas, `verified`, nombre
 * del perfil —nunca de un cuerpo—, y en un solo lote. Quien llama ya comprobó
 * que el pedido es del cliente y está completado.
 *
 * Devuelve cuántas se crearon.
 */
export async function publishProductReviews(input: {
  userId: number;
  customer: WooCustomer;
  productIds: readonly number[];
  rating: number;
  content: string;
}): Promise<number> {
  // Defensa en profundidad: la comparten «Calificar pedido» y la ficha, y una
  // ruta nueva no debe poder publicar reseñas de producto con la funcionalidad
  // apagada. Se comprueba antes de leer nada de WooCommerce.
  const config = await getSiteConfig();
  if (!config.ecommerce.reviews_enabled) return 0;

  const { userId, customer, productIds, rating, content } = input;
  const reviewed = await reviewedProductIds(customer, productIds);
  const pending = productIds.filter((pid) => !reviewed.has(pid));
  if (pending.length === 0) return 0;

  const reviewer = resolveReviewerName(customer);
  const batch = await wcFetch<{
    create?: (WooProductReview & { error?: unknown })[];
  }>("/products/reviews/batch", {
    method: "POST",
    body: {
      create: pending.map((pid) => ({
        product_id: pid,
        review: content,
        reviewer,
        reviewer_email: customer.email,
        rating,
        status: "approved",
        verified: true,
      })),
    },
  });
  const created = (batch.create ?? []).filter((r) => !r.error && r.id);

  // Sin esto la reseña tarda el ISR de la ficha en verse y la elegibilidad
  // cacheada seguiría ofreciendo el formulario.
  revalidateTag("products", "max");
  await invalidateOrderEligibility(userId, productIds);
  return created.length;
}
