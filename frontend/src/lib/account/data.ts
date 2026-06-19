import "server-only";
import { wcFetch } from "@/lib/woocommerce/client";
import { requireSession } from "@/lib/auth/session";
import type { WooCustomer, WooOrder } from "@/types/woocommerce";

// ============================================================================
//  Datos de la cuenta del usuario autenticado (server-side, vía wc/v3 + ck/cs).
//  El id se toma siempre de la sesión → el usuario solo accede a lo suyo.
// ============================================================================

export async function getCustomer(): Promise<WooCustomer> {
  const session = await requireSession();
  return wcFetch<WooCustomer>(`/customers/${Number(session.userId)}`);
}

export async function getCustomerOrders(): Promise<WooOrder[]> {
  const session = await requireSession();
  return wcFetch<WooOrder[]>(`/orders`, {
    query: { customer: Number(session.userId), per_page: 20, orderby: "date" },
  });
}

/**
 * Obtiene un pedido por ID verificando que pertenece al usuario autenticado.
 * Lanza FORBIDDEN si el pedido no le pertenece (anti-IDOR).
 */
export async function getOrderById(orderId: number): Promise<WooOrder> {
  const session  = await requireSession();
  const order    = await wcFetch<WooOrder>(`/orders/${orderId}`);

  if (order.customer_id !== Number(session.userId)) {
    throw new Error("FORBIDDEN");
  }
  return order;
}
