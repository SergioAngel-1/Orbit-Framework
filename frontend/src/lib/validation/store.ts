import { z } from "zod";

// ============================================================================
//  Validación de entrada para los endpoints de tienda (carrito/checkout/cliente).
//  Nunca se reenvía a WooCommerce un payload sin validar/acotar.
// ============================================================================

/* ------------------------------ Carrito ------------------------------- */

export const addItemSchema = z.object({
  id: z.number().int().positive(),
  quantity: z.number().int().min(1).max(999).default(1),
});

export const updateItemSchema = z.object({
  key: z.string().min(1).max(100),
  quantity: z.number().int().min(0).max(999),
});

export const removeItemSchema = z.object({
  key: z.string().min(1).max(100),
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
  rate_id:    z.string().min(1).max(200),
});

/* ------------------------------ Reseñas ------------------------------- */

export const createReviewSchema = z.object({
  rating:  z.number().int().min(1).max(5),
  content: z.string().min(10).max(3000).trim(),
  name:    z.string().min(1).max(100).trim(),
  email:   z.string().email().max(200).trim(),
});

/* ------------------------------ Dirección ----------------------------- */

const addressSchema = z.object({
  first_name: z.string().max(100).optional(),
  last_name:  z.string().max(100).optional(),
  company:    z.string().max(100).optional(),
  address_1:  z.string().max(200).optional(),
  address_2:  z.string().max(200).optional(),
  city:       z.string().max(100).optional(),
  state:      z.string().max(100).optional(),
  postcode:   z.string().max(20).optional(),
  country:    z.string().length(2).optional(),
  email:      z.string().email().max(200).optional(),
  phone:      z.string().max(40).optional(),
});

/* ------------------------------ Checkout ------------------------------ */

export const checkoutSchema = z.object({
  billing_address:  addressSchema,
  shipping_address: addressSchema.optional(),
  payment_method:   z.string().min(1).max(100),
  payment_data:     z
    .array(z.object({ key: z.string().max(100), value: z.string().max(2000) }))
    .max(50)
    .optional(),
  customer_note:    z.string().max(2000).optional(),
});

/* ------------------------- Actualización cliente ---------------------- */

export const customerUpdateSchema = z
  .object({
    first_name: z.string().max(100).optional(),
    last_name:  z.string().max(100).optional(),
    billing:    addressSchema.optional(),
    shipping:   addressSchema.optional(),
  })
  .strict();

export type CheckoutInput       = z.infer<typeof checkoutSchema>;
export type CustomerUpdateInput = z.infer<typeof customerUpdateSchema>;
export type CreateReviewInput   = z.infer<typeof createReviewSchema>;
