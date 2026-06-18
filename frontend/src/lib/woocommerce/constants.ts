// ============================================================================
//  Constantes del módulo de tienda (WooCommerce).
// ============================================================================

/** Cookie httpOnly que guarda el Cart-Token de la Store API. */
export const CART_TOKEN_COOKIE = process.env.CART_COOKIE_NAME || "hwe_cart";

/** Vida de la cookie del carrito (segundos). Por defecto 7 días. */
export const CART_TOKEN_MAX_AGE =
  Number(process.env.CART_COOKIE_MAX_AGE_DAYS || 7) * 24 * 60 * 60;
