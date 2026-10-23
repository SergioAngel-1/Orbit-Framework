import type { WooMetaData } from "@/types/woocommerce";

// ============================================================================
//  Contrato de fidelización que consume el checkout del BFF.
//
//  La prueba de «pagado con puntos» NO es el navegador: es la meta que escribe
//  WordPress al confirmar el cargo contra el libro mayor. El BFF la lee con el
//  cliente wc/v3 y solo entonces anuncia el pedido como pagado sin pasarela.
//
//  Los errores del canje viajan desde la Store API con códigos de WordPress;
//  aquí se traducen a motivos estables para que el frontend devuelva al
//  cliente al carrito sin interpretar mensajes.
// ============================================================================

/** Meta del pedido que marca un total 0 cubierto por el canje de fidelización. */
export const LOYALTY_PAID_WITH_POINTS_META = "_hwe_loyalty_paid_with_points";

/** Motivos estables de un fallo del canje al crear el pedido. */
export type LoyaltyCheckoutErrorReason =
  "loyalty_insufficient_points" | "loyalty_redeem_failed";

const REDEEM_ERROR_REASONS: Readonly<Record<string, LoyaltyCheckoutErrorReason>> = {
  hwe_loyalty_insufficient_points: "loyalty_insufficient_points",
  hwe_loyalty_redeem_failed: "loyalty_redeem_failed",
};

/** Código de error de WordPress → motivo estable del BFF (o `null` si es ajeno). */
export function loyaltyCheckoutErrorReason(
  code: string | null | undefined,
): LoyaltyCheckoutErrorReason | null {
  return code ? (REDEEM_ERROR_REASONS[code] ?? null) : null;
}

/**
 * ¿El pedido quedó pagado con puntos? Solo vale la marca exacta `yes`: una
 * meta ausente, un valor booleano o cualquier otro texto no lo confirman.
 */
export function orderPaidWithPoints(
  metaData: readonly WooMetaData[] | null | undefined,
): boolean {
  if (!Array.isArray(metaData)) return false;
  return metaData.some(
    (entry) => entry?.key === LOYALTY_PAID_WITH_POINTS_META && entry.value === "yes",
  );
}
