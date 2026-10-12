import { z } from "zod";

// ============================================================================
//  Validación de entrada para los endpoints de pago (capa agnóstica).
// ============================================================================

/**
 * `returnUrl` se le entrega a la pasarela como destino del redirect post-pago, así
 * que una URL cualquiera convertía el endpoint en un redirect abierto vía un
 * tercero. `z.url()` solo comprobaba que fuese una URL.
 *
 * El vector es estrecho (el endpoint exige sesión y CSRF), pero el candado no
 * restringe nada legítimo: `guardMutation` ya obliga a que el `Origin` de la
 * petición sea `ALLOWED_ORIGIN` y el cliente compone la URL con
 * `window.location.origin`. Se compara el ORIGEN parseado, no un prefijo de texto:
 * `https://tienda.example@evil.example` y `https://tienda.example.evil.example`
 * empiezan igual que el sitio y no lo son.
 *
 * Sin `ALLOWED_ORIGIN` (desarrollo) no se restringe, igual que `assertAllowedOrigin`;
 * en producción `secret-guard` la exige.
 */
export function isAllowedReturnUrl(url: string): boolean {
  const allowed = process.env.ALLOWED_ORIGIN;
  if (!allowed) return true;
  try {
    return new URL(url).origin === allowed;
  } catch {
    return false;
  }
}

/**
 * POST /api/payments/create — inicia el cobro de un pedido ya creado.
 * `reference` es el id del pedido WooCommerce (acepta número o string numérico).
 */
export const createPaymentSchema = z.object({
  reference: z
    .union([z.number().int().positive(), z.string().regex(/^\d+$/)])
    .transform((v) => String(v)),
  /** URL de retorno opcional (del propio sitio); si se omite, se usa la del entorno. */
  returnUrl: z
    .url()
    .max(2000)
    .refine(isAllowedReturnUrl, {
      message: "La URL de retorno debe apuntar a este sitio.",
    })
    .optional(),
});

export type CreatePaymentInput = z.infer<typeof createPaymentSchema>;
