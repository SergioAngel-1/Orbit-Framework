/**
 * ¿Se puede cerrar el pedido?
 *
 * Si el carrito necesita envío y Woo no ofrece ninguna tarifa (zona mal
 * configurada, país no cubierto), la UI antes ocultaba la sección y cerraba el
 * pedido con envío 0. Aquí lo convertimos en un bloqueo explícito.
 */
export function canSubmitCheckout(
  cart: {
    needs_shipping: boolean;
    shipping_rates: { shipping_rates?: unknown[] }[];
  } | null,
): boolean {
  if (!cart) return false;
  if (!cart.needs_shipping) return true;
  return cart.shipping_rates.some((pkg) => (pkg.shipping_rates?.length ?? 0) > 0);
}
