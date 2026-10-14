// ============================================================================
//  Medios de pago que ofrece el checkout. Módulo puro (sin `server-only`): lo
//  comparten el BFF, las páginas de checkout y el formulario, que es cliente.
//
//  Tres medios INDEPENDIENTES, cada uno con su interruptor en Control Center →
//  Pasarelas de pago (`payments.*_enabled`). Cada uno crea el pedido con su
//  propio gateway de WooCommerce, así que el pedido recuerda cómo se pidió
//  pagar:
//   - En línea (`hwe_offline`): cobra la pasarela del BFF. Si sus credenciales
//     son de PRUEBAS, el checkout lo avisa (`isActiveGatewaySandbox`).
//   - Link de pago por WhatsApp (`hwe_whatsapp_link`): sin cobro en línea; el
//     pedido queda «En espera» y la tienda envía el enlace.
//   - Efectivo (`cod`, «Contra reembolso» nativo): se paga al recibir.
// ============================================================================

import type { SiteConfig } from "@/lib/config/types";

/** Gateway del pago en línea (lo registra `woocommerce-headless.php`). */
export const ONLINE_PAYMENT_METHOD = "hwe_offline";
/** Gateway del link de pago por WhatsApp (lo registra `woocommerce-headless.php`). */
export const WHATSAPP_PAYMENT_METHOD = "hwe_whatsapp_link";
/** Pasarela nativa de WooCommerce para pago en efectivo al recibir. */
export const CASH_PAYMENT_METHOD = "cod";

export type PaymentChoice = "online" | "whatsapp" | "cash";

const METHOD_BY_CHOICE: Record<PaymentChoice, string> = {
  online: ONLINE_PAYMENT_METHOD,
  whatsapp: WHATSAPP_PAYMENT_METHOD,
  cash: CASH_PAYMENT_METHOD,
};

/**
 * Medios activos, en el orden en que se pintan. Con los tres apagados queda el
 * pago en línea: un checkout sin ningún medio no podría cerrar la compra.
 */
export function enabledPaymentChoices(
  config: Pick<SiteConfig, "payments">,
): PaymentChoice[] {
  const { gateway_enabled, whatsapp_enabled, cash_enabled } = config.payments;
  const choices: PaymentChoice[] = [];
  if (gateway_enabled) choices.push("online");
  if (whatsapp_enabled) choices.push("whatsapp");
  if (cash_enabled) choices.push("cash");
  return choices.length > 0 ? choices : ["online"];
}

/** Gateway de WooCommerce con el que se crea el pedido para ese medio. */
export function paymentMethodFor(choice: PaymentChoice): string {
  return METHOD_BY_CHOICE[choice];
}

/** Medio del checkout que corresponde a un gateway de pedido (o `null`). */
export function paymentChoiceFor(method: string): PaymentChoice | null {
  const entry = Object.entries(METHOD_BY_CHOICE).find(([, id]) => id === method);
  return entry ? (entry[0] as PaymentChoice) : null;
}

/** ¿Tras crear el pedido hay que llevar al cliente a la pasarela? */
export function shouldStartGatewayPayment(choice: PaymentChoice): boolean {
  return choice === "online";
}

/** Líneas del pedido para el mensaje de WhatsApp: «• 2 × Producto (detalle)». */
export function formatOrderItemsForMessage(
  items: ReadonlyArray<{ name: string; quantity: number; details?: readonly string[] }>,
): string {
  return items
    .filter((item) => item.name.trim() !== "")
    .map((item) => {
      const details = (item.details ?? []).filter((d) => d.trim() !== "");
      const suffix = details.length > 0 ? ` (${details.join("; ")})` : "";
      return `• ${item.quantity} × ${item.name.trim()}${suffix}`;
    })
    .join("\n");
}
