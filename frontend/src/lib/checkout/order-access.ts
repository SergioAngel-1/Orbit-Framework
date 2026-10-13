// ============================================================================
//  ¿Quién puede ver / pagar un pedido? (anti-IDOR)
//
//  Dos vías de propiedad, excluyentes en la práctica:
//   - Cliente con sesión: `order.customer_id` es su id.
//   - Invitado (checkout sin login, `ecommerce.checkout_login_required` apagado):
//     el pedido tiene `customer_id = 0` y la única prueba de propiedad es su
//     `order_key` (`wc_order_…`), el mismo secreto que WooCommerce usa en su
//     página nativa de «pedido recibido». El BFF la guarda en una cookie
//     httpOnly al crear el pedido (`guest-order-cookie.ts`); el navegador
//     nunca la lee.
//
//  Módulo puro (sin cookies ni red) para poder testearlo.
// ============================================================================

import type { WooOrder } from "@/types/woocommerce";

export interface GuestOrderCredential {
  orderId: number;
  orderKey: string;
}

const ORDER_KEY_PATTERN = /^wc_order_[A-Za-z0-9]+$/;

/** `order_key` no lleva `:`, así que sirve de separador sin escapar nada. */
export function serializeGuestOrderCredential(
  credential: GuestOrderCredential,
): string {
  return `${credential.orderId}:${credential.orderKey}`;
}

export function parseGuestOrderCredential(
  raw: string | null | undefined,
): GuestOrderCredential | null {
  if (!raw) return null;
  const sep = raw.indexOf(":");
  if (sep <= 0) return null;
  const idPart = raw.slice(0, sep);
  const orderKey = raw.slice(sep + 1);
  if (!/^\d+$/.test(idPart) || !ORDER_KEY_PATTERN.test(orderKey)) return null;
  const orderId = Number(idPart);
  if (!Number.isSafeInteger(orderId) || orderId <= 0) return null;
  return { orderId, orderKey };
}

/** Comparación en tiempo constante respecto al contenido (no a la longitud). */
function safeEqual(a: string, b: string): boolean {
  if (a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return diff === 0;
}

export function canAccessOrder(
  order: Pick<WooOrder, "id" | "customer_id" | "order_key">,
  viewer: { userId?: string | number | null; guest?: GuestOrderCredential | null },
): boolean {
  const userId = Number(viewer.userId);
  if (Number.isInteger(userId) && userId > 0 && order.customer_id === userId) {
    return true;
  }

  const guest = viewer.guest;
  // Solo pedidos que siguen siendo de invitado: si el pedido se ligó después
  // a una cuenta, la cookie ya no basta y manda la sesión.
  if (!guest || order.customer_id !== 0 || !order.order_key) return false;
  return guest.orderId === order.id && safeEqual(guest.orderKey, order.order_key);
}
