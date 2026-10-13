import type { WooCustomer } from "@/types/woocommerce";

/**
 * Nombre con el que se publica una reseña de producto — SIEMPRE el del perfil
 * de WooCommerce, nunca el de un cuerpo de petición (anti-suplantación). Lo
 * comparten la reseña desde la ficha (`/api/store/reviews/[productId]`, cuyo
 * GET lo enseña como «Publicando como…») y la que se publica desde un pedido
 * (`/api/store/orders/[id]/review`), para que las dos firmen igual.
 */
export function resolveReviewerName(
  customer: Pick<WooCustomer, "first_name" | "last_name">,
): string {
  return (
    [customer.first_name, customer.last_name].filter(Boolean).join(" ").trim() ||
    "Cliente verificado"
  );
}
