// ============================================================================
//  Ayuda con un pedido del historial por WhatsApp. No hay cancelación en el
//  sitio a propósito: un pedido «En espera» puede estar a medio pagar (link
//  de WhatsApp, transferencia) y cancelarlo lo decide la tienda. El botón
//  solo abre el chat con el pedido ya escrito.
//
//  Módulo puro: lo usan las vistas de servidor y los tests.
// ============================================================================

export type OrderSupportAction = "cancel" | "problem";

/**
 * Qué ayuda se ofrece según el estado del pedido:
 * - «En espera» (`on-hold`): pedir la cancelación.
 * - «Completado»: «Tuve un problema con mi pedido».
 * El resto no ofrece nada: en preparación aún no hay entrega que reclamar y
 * un pago pendiente se cancela solo si no se paga.
 */
export function orderSupportAction(status: string): OrderSupportAction | null {
  if (status === "on-hold") return "cancel";
  if (status === "completed") return "problem";
  return null;
}
