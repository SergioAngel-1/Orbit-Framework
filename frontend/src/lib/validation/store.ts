import { routing } from "@/i18n/routing";
import { documentInputSchema, termsAcceptedField } from "./identity";
import { z } from "zod";

// ============================================================================
//  Validación de entrada para los endpoints de tienda (carrito/checkout/cliente).
//  Nunca se reenvía a WooCommerce un payload sin validar/acotar.
// ============================================================================

/* ------------------------------ Carrito ------------------------------- */

/** Clave de un detalle de línea: la misma regla que `hwe_order_item_details()` en WordPress. */
export const ORDER_ITEM_DETAIL_KEY = /^[a-z][a-z0-9_]{0,39}$/;

/**
 * Datos de extensión de un `cart/add-item` (módulo `order-item-details`). El BFF solo los
 * ACOTA —claves y tamaños—; WordPress descarta las claves que la instancia no declaró y valida
 * el valor con el validador del detalle: nada de lo que llega aquí es de fiar por sí mismo.
 */
const itemExtensionValue = z.union([
  z.string().max(1000),
  z.number().finite(),
  z.boolean(),
  z.array(z.string().max(200)).max(20),
]);

export const itemExtensionsSchema = z
  .record(z.string().regex(ORDER_ITEM_DETAIL_KEY), itemExtensionValue)
  .refine((value) => Object.keys(value).length <= 8, {
    message: "Demasiados datos de extensión.",
  });

export const addItemSchema = z.object({
  id: z.number().int().positive(),
  quantity: z.number().int().min(1).max(999).default(1),
  extensions: itemExtensionsSchema.optional(),
});

export const updateItemSchema = z.object({
  key: z.string().min(1).max(100),
  quantity: z.number().int().min(0).max(999),
});

export const removeItemSchema = z.object({
  key: z.string().min(1).max(100),
});

/**
 * Canje de fidelización del carrito: el cliente solo declara la CANTIDAD de
 * puntos que quiere usar. El importe lo calcula y lo recorta WordPress en la
 * extensión de la Store API; el BFF nunca acepta un importe desde el navegador.
 */
export const cartPointsSchema = z.object({
  points: z.number().int().min(0),
});

/* ------------------------------ Cupones ------------------------------- */

export const applyCouponSchema = z.object({
  code: z.string().min(1).max(100).trim(),
});

export const removeCouponSchema = z.object({
  code: z.string().min(1).max(100).trim(),
});

/* ------------------------------ Envío --------------------------------- */

export const selectShippingRateSchema = z.object({
  package_id: z.number().int().min(0),
  rate_id: z.string().min(1).max(200),
});

/**
 * Destino de envío del carrito: lo que necesita WooCommerce para calcular la
 * tarifa (la del módulo `shipping` es por CIUDAD). No es la dirección completa
 * del pedido: esa viaja en el checkout. El estado se normaliza en la ruta con el
 * paquete de país.
 */
export const shippingDestinationSchema = z.object({
  city: z.string().trim().min(1).max(100),
  country: z
    .string()
    .trim()
    .length(2)
    .transform((c) => c.toUpperCase()),
  state: z.string().trim().max(50).optional(),
  postcode: z.string().trim().max(20).optional(),
});

/* ------------------------------ Reseñas ------------------------------- */

// El nombre y el email del autor se derivan SIEMPRE del perfil autenticado (ver
// POST /api/store/reviews/[productId]), nunca del cuerpo: si no, cualquier petición
// directa podría publicar una reseña atribuida al nombre o al email de un tercero.
// Zod descarta las claves que no declara, así que un `name`/`email` enviado se ignora.
export const createReviewSchema = z.object({
  rating: z.number().int().min(1).max(5),
  content: z.string().min(10).max(3000).trim(),
});

/**
 * Reseña desde la ficha. `sameForOrder`: publicar la misma reseña en los demás
 * productos del pedido completado del que sale (los aún sin reseñar).
 */
export const productReviewSchema = createReviewSchema.extend({
  sameForOrder: z.boolean().default(false),
});

/**
 * Calificación de un pedido (reseña del sitio). `products`: publicar además el
 * mismo texto y la misma nota en los productos del pedido.
 */
export const orderReviewSchema = createReviewSchema.extend({
  products: z.boolean().default(false),
});

/* ------------------------------ Wishlist ------------------------------ */

export const wishlistBodySchema = z.object({
  productId: z.number().int().positive(),
});

/* ------------------------------ Dirección ----------------------------- */

const addressSchema = z.object({
  first_name: z.string().max(100).optional(),
  last_name: z.string().max(100).optional(),
  company: z.string().max(100).optional(),
  address_1: z.string().max(200).optional(),
  address_2: z.string().max(200).optional(),
  city: z.string().max(100).optional(),
  state: z.string().max(100).optional(),
  postcode: z.string().max(20).optional(),
  country: z.string().length(2).optional(),
  email: z.email().max(200).optional(),
  phone: z.string().max(40).optional(),
});

/* ------------------------------ Checkout ------------------------------ */

export const checkoutSchema = z.object({
  billing_address: addressSchema,
  /**
   * Medición: lo que declara el navegador sobre el consentimiento y el idioma
   * de la página (para la URL de «gracias» de la Conversions API). Se valida y,
   * sin `consent`, no se guarda nada más del contexto.
   */
  tracking: z
    .object({
      consent: z.boolean(),
      locale: z.enum(routing.locales),
    })
    .optional(),
  shipping_address: addressSchema.optional(),
  /**
   * Documento del comprador (módulo `customer-identity`). Aquí solo se acota la
   * forma; el contenido lo valida el BFF contra el paquete de país y la unicidad
   * y la escritura única las decide WordPress (`attach-identity`, 409).
   */
  identity: documentInputSchema.optional(),
  /** Aceptación de términos: solo vale `true`; la fecha la pone el servidor. */
  terms_accepted: termsAcceptedField.optional(),
  /**
   * Métodos de envío extra elegidos (módulo `shipping`): ids del CPT. Se vuelven a
   * leer frescos de WordPress al crear el pedido: el importe cobrado es el vigente.
   */
  shipping_method_ids: z.array(z.number().int().positive()).max(20).optional(),
  payment_method: z.string().min(1).max(100),
  payment_data: z
    .array(z.object({ key: z.string().max(100), value: z.string().max(2000) }))
    .max(50)
    .optional(),
  customer_note: z.string().max(2000).optional(),
});

/* ------------------------- Actualización cliente ---------------------- */

export const customerUpdateSchema = z
  .object({
    first_name: z.string().max(100).optional(),
    last_name: z.string().max(100).optional(),
    billing: addressSchema.optional(),
    shipping: addressSchema.optional(),
    /** Documento (módulo `customer-identity`): se registra solo si la cuenta no tiene uno. */
    identity: documentInputSchema.optional(),
  })
  .strict();

export type CheckoutInput = z.infer<typeof checkoutSchema>;
export type CustomerUpdateInput = z.infer<typeof customerUpdateSchema>;
export type CreateReviewInput = z.infer<typeof createReviewSchema>;
