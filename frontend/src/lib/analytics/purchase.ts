// ============================================================================
//  Qué pedido cuenta como COMPRA para la medición, y con qué identificador se
//  deduplica. Puro: lo comparten la página de gracias (navegador) y el envío
//  desde el servidor (Conversions API de Meta), que tienen que coincidir en
//  las dos cosas o la compra se cuenta dos veces —o ninguna—.
// ============================================================================

import { ONLINE_PAYMENT_METHOD } from "@/lib/payments/checkout-options";

/** Estados de WooCommerce de un pedido cobrado (sin `refunded`: ya no es una venta). */
const PURCHASE_STATUSES = new Set(["processing", "completed"]);

/**
 * Una compra es un pedido PAGADO EN LÍNEA: lo marcó pagado el webhook
 * verificado de la pasarela. El efectivo (`cod`) queda «Procesando» al
 * crearse sin haber cobrado nada, y el link de WhatsApp se cobra fuera del
 * sitio, así que ninguno de los dos cuenta aunque su estado lo parezca.
 */
export function countsAsPurchase(order: {
  status: string;
  payment_method: string;
}): boolean {
  return (
    PURCHASE_STATUSES.has(order.status) &&
    order.payment_method === ONLINE_PAYMENT_METHOD
  );
}

/**
 * `event_id` de la compra, el MISMO en el píxel del navegador y en la
 * Conversions API: es lo que hace que Meta la cuente una sola vez.
 */
export function purchaseEventId(orderId: string | number): string {
  return `purchase_${orderId}`;
}
