import type { StoreCartItem, WooMetaData, WooOrderLineItem } from "@/types/woocommerce";

// ============================================================================
//  Detalles de línea (módulo `order-item-details`): lectura.
//
//  La instancia declara en WordPress (`hwe_order_item_details`) qué datos lleva una línea
//  además del producto. Aquí solo se LEE, siempre por clave conocida: nunca se vuelcan todos
//  los `item_data` o `meta_data` de una línea (llevan metas internas de WooCommerce y de otros
//  módulos que no son para pintar).
//
//    · carrito (Store API): entrada oculta `hwe_item_<clave>` con el dato máquina; la visible
//      es el respaldo legible, con la etiqueta del idioma por defecto de WordPress.
//    · pedido (wc/v3): meta visible `meta_key` (el valor que ven los correos) y oculta
//      `_hwe_item_<clave>` con el dato máquina.
// ============================================================================

/** Prefijo de la entrada oculta del `item_data` del carrito. */
export const CART_ITEM_DATA_PREFIX = "hwe_item_";
/** Prefijo de la meta oculta de la línea de pedido. */
export const ORDER_ITEM_HIDDEN_META_PREFIX = "_hwe_item_";

/** Dato máquina de un detalle en una línea del carrito, o `null` si no lo lleva. */
export function cartItemDetailData(
  item: Pick<StoreCartItem, "item_data">,
  key: string,
): string | null {
  const wanted = CART_ITEM_DATA_PREFIX + key;
  const entry = item.item_data?.find((data) => data.name === wanted);
  return entry && typeof entry.value === "string" && entry.value !== ""
    ? entry.value
    : null;
}

/** Valor visible de un detalle en una línea del carrito, por la etiqueta declarada. */
export function cartItemDetailValue(
  item: Pick<StoreCartItem, "item_data">,
  label: string,
): string | null {
  const entry = item.item_data?.find((data) => !data.hidden && data.name === label);
  return entry && typeof entry.value === "string" && entry.value !== ""
    ? entry.value
    : null;
}

function metaValue(
  meta: readonly WooMetaData[] | undefined,
  key: string,
): string | null {
  const entry = meta?.find((m) => m.key === key);
  if (!entry) return null;
  const { value } = entry;
  if (typeof value === "string") return value === "" ? null : value;
  if (typeof value === "number") return String(value);
  return null;
}

/** Valor visible de un detalle en una línea de pedido, por su `meta_key` declarada. */
export function orderItemDetailValue(
  line: Pick<WooOrderLineItem, "meta_data">,
  metaKey: string,
): string | null {
  return metaValue(line.meta_data, metaKey);
}

/** Dato máquina de un detalle en una línea de pedido (meta oculta `_hwe_item_<clave>`). */
export function orderItemDetailData(
  line: Pick<WooOrderLineItem, "meta_data">,
  key: string,
): string | null {
  const entry = line.meta_data?.find(
    (m) => m.key === ORDER_ITEM_HIDDEN_META_PREFIX + key,
  );
  if (!entry) return null;
  if (typeof entry.value === "string") return entry.value === "" ? null : entry.value;
  // wc/v3 devuelve una meta guardada como array/objeto ya decodificada: se re-serializa.
  return entry.value == null ? null : JSON.stringify(entry.value);
}
