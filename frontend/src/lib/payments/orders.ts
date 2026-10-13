import "server-only";
import { wcFetch } from "@/lib/woocommerce/client";
import type { WooOrder } from "@/types/woocommerce";

// ============================================================================
//  Máquina de estados del pedido para la conciliación de pagos.
//
//  El pedido se crea en WooCommerce ANTES del pago (estado "pending"). La
//  transición a "pagado" SOLO ocurre desde un webhook verificado (importe +
//  moneda + referencia validados). Todo esto usa wc/v3 (ck/cs server-only).
// ============================================================================

/**
 * Unidades menores por defecto para la conciliación: céntimos. Cada pasarela
 * declara las suyas en la verificación (`WebhookVerification.minorUnit`): Wompi
 * habla siempre en céntimos aunque la moneda no tenga decimales.
 */
const MINOR_UNIT = 2;

/** Estados de WooCommerce que ya cuentan como pagados / cerrados. */
const PAID_STATUSES = new Set(["processing", "completed", "refunded"]);

/** Convierte un total decimal de WC ("59.99") a unidades menores (5999). */
export function toMinorUnits(amount: string, minorUnit = MINOR_UNIT): number {
  return Math.round(Number(amount) * Math.pow(10, minorUnit));
}

/** Obtiene el pedido por id (referencia). Lanza WooCommerceError si no existe. */
export function getOrder(reference: string | number): Promise<WooOrder> {
  return wcFetch<WooOrder>(`/orders/${reference}`);
}

/** ¿El pedido ya está pagado/cerrado? Usado para idempotencia del webhook. */
export function isOrderPaid(order: WooOrder): boolean {
  return PAID_STATUSES.has(order.status);
}

/**
 * Valida que importe y moneda reportados por la pasarela coincidan con el
 * pedido. Si la pasarela no reporta importe/moneda, no se puede contrastar y
 * se considera no concordante (defensa: no marcar pagado a ciegas).
 */
export function paymentMatchesOrder(
  order: WooOrder,
  amountMinor?: number,
  currency?: string,
  minorUnit = MINOR_UNIT,
): boolean {
  if (amountMinor == null || !currency) return false;
  const expected = toMinorUnits(order.total, minorUnit);
  const sameAmount = expected === amountMinor;
  const sameCurrency = order.currency.toUpperCase() === currency.toUpperCase();
  return sameAmount && sameCurrency;
}

/**
 * Marca el pedido como pagado (status "processing" + `set_paid`).
 * Idempotente: si ya estaba pagado, no hace nada.
 */
export async function markOrderPaid(
  reference: string | number,
  transactionId?: string,
): Promise<WooOrder> {
  const order = await getOrder(reference);
  if (isOrderPaid(order)) {
    return order;
  }
  return wcFetch<WooOrder>(`/orders/${reference}`, {
    method: "PUT",
    body: {
      status: "processing",
      set_paid: true,
      ...(transactionId ? { transaction_id: transactionId } : {}),
    },
  });
}

/**
 * Pedido con «link de pago por WhatsApp» (`hwe_whatsapp_link`): sin cobro en
 * línea. En `pending` WooCommerce no manda NINGÚN correo y el pedido cuenta como
 * carrito abandonado que «mantener inventario» puede cancelar solo. `on-hold`
 * («En espera») es «pedido recibido, falta confirmar el pago»: dispara los
 * correos y el pedido sigue siendo pagable.
 */
export function markOrderAwaitingPaymentLink(
  reference: string | number,
): Promise<WooOrder> {
  return wcFetch<WooOrder>(`/orders/${reference}`, {
    method: "PUT",
    body: { status: "on-hold" },
  });
}

/** Marca el pedido como cancelado/fallido (pago rechazado o anulado). */
export async function markOrderCancelled(
  reference: string | number,
  status: "cancelled" | "failed" = "cancelled",
): Promise<WooOrder> {
  const order = await getOrder(reference);
  // No revertimos un pedido ya pagado desde un evento negativo tardío.
  if (isOrderPaid(order)) {
    return order;
  }
  return wcFetch<WooOrder>(`/orders/${reference}`, {
    method: "PUT",
    body: { status },
  });
}
