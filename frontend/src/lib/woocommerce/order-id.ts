// ============================================================================
//  Id de pedido recibido en un webhook de WooCommerce.
//
//  Tras verificar la firma, `payload.id` sigue siendo «lo que mande el emisor»:
//  se interpola en la clave de Redis del estado del pedido (`order:status:<id>`) y
//  llega a los efectos. Solo se admite un id de pedido de verdad: entero positivo,
//  como número o como texto numérico. Cualquier otra forma (objeto, lista, texto
//  con separadores, cero a la izquierda…) se rechaza.
// ============================================================================

/** Dígitos de un id de WooCommerce: sin ceros a la izquierda y dentro del entero seguro. */
const NUMERIC_ID = /^[1-9]\d{0,15}$/;

/** Devuelve el id como número, o `null` si no es un id de pedido válido. */
export function parseWebhookOrderId(value: unknown): number | null {
  if (typeof value === "number") {
    return Number.isSafeInteger(value) && value > 0 ? value : null;
  }
  if (typeof value === "string" && NUMERIC_ID.test(value)) {
    const id = Number(value);
    return Number.isSafeInteger(id) ? id : null;
  }
  return null;
}
