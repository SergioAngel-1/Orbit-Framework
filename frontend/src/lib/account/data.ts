import "server-only";
import { wcFetch, wcFetchPage } from "@/lib/woocommerce/client";
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

export interface CustomerOrdersQuery<K extends keyof WooOrder = keyof WooOrder> {
  page?: number;
  /** Tamaño de página, de 1 a 100 (el máximo de wc/v3). */
  perPage?: number;
  /** Estados de WooCommerce (`processing`, `completed`…). Sin él, todos. */
  status?: readonly string[];
  /** Solo pedidos posteriores a esta fecha ISO 8601. */
  after?: string;
  /** Solo pedidos anteriores a esta fecha ISO 8601. */
  before?: string;
  /** Campos a traer (`_fields`), para no descargar el pedido entero. */
  fields?: readonly K[];
}

export interface CustomerOrdersPage<K extends keyof WooOrder = keyof WooOrder> {
  orders: Pick<WooOrder, K>[];
  /** Pedidos del cliente que cumplen los filtros, en todas las páginas. */
  total: number;
  totalPages: number;
}

/** Entero ≥ 1; cualquier otro valor cae al indicado (WooCommerce respondería 400). */
function positiveInteger(value: number, fallback: number): number {
  const integer = Math.trunc(value);
  return Number.isFinite(integer) && integer >= 1 ? integer : fallback;
}

/**
 * Una página de pedidos del cliente de la sesión, con filtros y totales. Es lo que necesita
 * una lista paginada o con filtros: no hace falta leer todo el historial para saber cuántos hay
 * ni para filtrarlo. El cliente sale SIEMPRE de la sesión. Fechas o estados que WooCommerce no
 * acepte son un `WooCommerceError` 400.
 */
export async function getCustomerOrdersPage<K extends keyof WooOrder = keyof WooOrder>({
  page = 1,
  perPage = 20,
  status,
  after,
  before,
  fields,
}: CustomerOrdersQuery<K> = {}): Promise<CustomerOrdersPage<K>> {
  const session = await requireSession();
  const { items, total, totalPages } = await wcFetchPage<Pick<WooOrder, K>>(`/orders`, {
    query: {
      customer: Number(session.userId),
      page: positiveInteger(page, 1),
      per_page: Math.min(100, positiveInteger(perPage, 20)),
      orderby: "date",
      order: "desc",
      status: status?.length ? status.join(",") : undefined,
      after,
      before,
      _fields: fields?.length ? fields.join(",") : undefined,
    },
  });
  return { orders: items, total, totalPages };
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
