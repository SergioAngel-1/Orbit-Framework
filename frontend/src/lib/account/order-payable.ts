// ============================================================================
//  ¿Un pedido admite todavía un intento de cobro?
//
//  Lógica pura (sin dependencias de componentes ni de red) para poder usarla
//  tanto en la ficha de pedido (Server Component) como en tests.
// ============================================================================

/**
 * Estados de pedido en los que todavía tiene sentido ofrecer "pagar".
 *
 * `pending` es el estado en que queda el pedido antes del cobro; `failed` y
 * `on-hold` son reintentos legítimos. Todo lo demás (pagado, completado,
 * reembolsado, cancelado) NO debe ofrecer un botón de pago.
 * Lista blanca a propósito: un estado desconocido no ofrece pagar.
 */
const PAYABLE = new Set(["pending", "failed", "on-hold"]);

export function isOrderPayable(status: string): boolean {
  return PAYABLE.has(status);
}
