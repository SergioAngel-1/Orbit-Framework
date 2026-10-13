import "server-only";
import { wcFetch } from "@/lib/woocommerce/client";
import { requireSession } from "@/lib/auth/session";
import type { WooCustomer, WooOrder } from "@/types/woocommerce";
import { collectProductPurchase, type ProductPurchase } from "./product-purchase";

// ============================================================================
//  Datos de la cuenta del usuario autenticado (server-side, vía wc/v3 + ck/cs).
//  El id se toma siempre de la sesión → el usuario solo accede a lo suyo.
// ============================================================================

export async function getCustomer(): Promise<WooCustomer> {
  const session = await requireSession();
  return wcFetch<WooCustomer>(`/customers/${Number(session.userId)}`);
}

export async function getCustomerOrders(page = 1, perPage = 20): Promise<WooOrder[]> {
  const session = await requireSession();
  return wcFetch<WooOrder[]>(`/orders`, {
    query: {
      customer: Number(session.userId),
      page,
      per_page: perPage,
      orderby: "date",
      order: "desc",
    },
  });
}

/** Tope defensivo de páginas para {@link findProductPurchase} (ver ahí). */
const PURCHASE_CHECK_MAX_PAGES = 20;

/**
 * Los pedidos del usuario autenticado que contienen el producto: el completado
 * más reciente (el que habilita reseñarlo) y el en preparación más reciente (el
 * que la ficha ofrece marcar como recibido). Ver `product-purchase.ts`.
 *
 * WC REST no permite filtrar `/orders` por producto, así que se recorre por
 * página (más reciente primero) hasta encontrar un completado o agotar el
 * historial: mirar solo los 100 más recientes rechazaría a quien sí compró el
 * producto en un pedido más antiguo. El tope de páginas es defensivo (hasta
 * 2000 pedidos del cliente).
 *
 * `_fields` recorta la respuesta a lo único que se mira (~9× menos bytes con
 * solo `line_items`): no ahorra CPU en WordPress, sí ancho de banda y el
 * parseo de JSON del BFF, que se paga por página recorrida.
 */
export async function findProductPurchase(productId: number): Promise<ProductPurchase> {
  const session = await requireSession();
  const perPage = 100;
  let found: ProductPurchase = { completed: null, processing: null };

  for (let page = 1; page <= PURCHASE_CHECK_MAX_PAGES; page++) {
    const orders = await wcFetch<WooOrder[]>(`/orders`, {
      query: {
        customer: Number(session.userId),
        status: "processing,completed",
        per_page: perPage,
        page,
        orderby: "date",
        order: "desc",
        _fields: "id,number,status,line_items",
      },
    });
    found = collectProductPurchase(orders, productId, found);
    if (found.completed || orders.length < perPage) break;
  }
  return found;
}

/**
 * Obtiene un pedido por ID verificando que pertenece al usuario autenticado.
 * Lanza FORBIDDEN si el pedido no le pertenece (anti-IDOR).
 */
export async function getOrderById(orderId: number): Promise<WooOrder> {
  const session = await requireSession();
  const order = await wcFetch<WooOrder>(`/orders/${orderId}`);

  if (order.customer_id !== Number(session.userId)) {
    throw new Error("FORBIDDEN");
  }
  return order;
}
