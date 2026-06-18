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
  email: z.string().email().max(200).optional(),
  phone: z.string().max(40).optional(),
});

/* ------------------------------ Checkout ------------------------------ */

export const checkoutSchema = z.object({
  billing_address: addressSchema,
  shipping_address: addressSchema.optional(),
  payment_method: z.string().min(1).max(100),
  // Datos específicos de la pasarela (p. ej. token de Stripe). Se valida
  // como pares clave/valor; la pasarela real se integra en la Fase 6.
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
  })
  .strict();

export type CheckoutInput = z.infer<typeof checkoutSchema>;
export type CustomerUpdateInput = z.infer<typeof customerUpdateSchema>;
