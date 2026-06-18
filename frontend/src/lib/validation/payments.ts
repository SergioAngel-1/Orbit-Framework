import { z } from "zod";

// ============================================================================
//  Validación de entrada para los endpoints de pago (capa agnóstica).
// ============================================================================

/**
 * POST /api/payments/create — inicia el cobro de un pedido ya creado.
 * `reference` es el id del pedido WooCommerce (acepta número o string numérico).
 */
export const createPaymentSchema = z.object({
  reference: z
    .union([z.number().int().positive(), z.string().regex(/^\d+$/)])
    .transform((v) => String(v)),
  /** URL de retorno opcional; si se omite, se usa la del entorno. */
  returnUrl: z.string().url().max(2000).optional(),
});

export type CreatePaymentInput = z.infer<typeof createPaymentSchema>;
