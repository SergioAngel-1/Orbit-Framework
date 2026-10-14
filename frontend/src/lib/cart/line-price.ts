// ============================================================================
//  Descuento DE PRODUCTO (precio rebajado en WooCommerce) en una línea del
//  carrito. No confundir con el de cupón: la Store API ya cobra el rebajado
//  —`prices.price` es el vigente y de él salen `line_subtotal`/`line_total`—;
//  aquí solo se decide si hay un precio regular mayor que ENSEÑAR tachado.
//
//  Puro: lo usan el cajón del carrito, la página de carrito y el checkout.
//  Importes en unidades menores, como los manda la Store API.
// ============================================================================

interface LinePrices {
  quantity: number;
  prices: { price: string; regular_price: string };
}

/** Precio regular por UNIDAD si el vigente es menor (hay rebaja); si no, `null`. */
export function regularUnitAmount(item: LinePrices): string | null {
  const regular = Number(item.prices.regular_price);
  const current = Number(item.prices.price);
  if (!Number.isFinite(regular) || !Number.isFinite(current)) return null;
  return regular > current ? String(regular) : null;
}
