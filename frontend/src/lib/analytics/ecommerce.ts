// ============================================================================
//  Parámetros de los eventos de e-commerce. El modelo es el de GA4
//  (`currency`, `value`, `items[]` y, en `purchase`, `transaction_id`) y de él
//  salen los demás vocabularios: Meta (`metaEvent`) y TikTok (`tiktokParams`).
//  Puro: lo consumen `trackEvent` y quien dispara cada evento, y el envío de la
//  compra desde el servidor (Conversions API), que así manda EXACTAMENTE lo
//  mismo que el píxel del navegador.
//
//  La forma importa aunque ningún envío falle: con `amount` en vez de `value`
//  GA4 registra la compra con ingresos 0, y sin `transaction_id` no la
//  deduplica.
// ============================================================================

import type { StoreCart, StoreCartItem, WooOrderLineItem } from "@/types/woocommerce";
import { parseMoneyAmount } from "@/lib/format";
import { purchaseEventId } from "./purchase";

export type EcommerceEventName =
  "view_item" | "add_to_cart" | "begin_checkout" | "purchase";

export interface AnalyticsItem {
  /**
   * Id del producto de WooCommerce (el de la variación si la hubiera). Es el
   * `content_ids` de Meta: el mismo en todos los eventos y en los dos idiomas.
   * No el SKU: no todos los productos lo tienen.
   */
  item_id: string;
  item_name: string;
  /** Precio unitario en unidades MAYORES (no céntimos). */
  price: number;
  quantity: number;
  /**
   * Categoría del producto (`item_category` en GA4, `content_category` en Meta).
   * Opcional: el núcleo no la deduce; quien arma los parámetros (la instancia)
   * la rellena si quiere medirla.
   */
  item_category?: string;
}

export interface EcommerceParams {
  currency: string;
  /** Importe del evento en unidades mayores. */
  value: number;
  items: AnalyticsItem[];
  /** Solo en `purchase`: el id del pedido, con el que GA4 deduplica. */
  transaction_id?: string;
}

/**
 * La categoría que comparten TODOS los artículos, o `undefined` si no la hay o
 * difieren (Meta pide una sola `content_category`: mejor ninguna que una
 * inventada).
 */
function commonCategory(items: readonly AnalyticsItem[]): string | undefined {
  const first = items[0]?.item_category;
  if (!first) return undefined;
  return items.every((item) => item.item_category === first) ? first : undefined;
}

/**
 * `view_item`: el producto de la ficha, valorado por su precio. Sin precio
 * cargado se mide igual la visita, con valor 0.
 */
export function viewItemParams(
  product: { databaseId: number; name: string; price: string | null },
  currency: string,
  category?: string,
): EcommerceParams {
  // WooGraphQL publica el precio ya formateado («$25.300», con entidades HTML).
  const price = parseMoneyAmount(product.price) ?? 0;
  return {
    currency,
    value: price,
    items: [
      {
        item_id: String(product.databaseId),
        item_name: product.name,
        price,
        quantity: 1,
        ...(category ? { item_category: category } : {}),
      },
    ],
  };
}

/** Importe de la Store API (unidades menores, en texto) a unidades mayores. */
export function storeAmountToMajor(amount: string, minorUnit: number): number {
  const n = Number(amount);
  return Number.isFinite(n) ? n / 10 ** minorUnit : 0;
}

function cartItemToAnalytics(item: StoreCartItem, quantity: number): AnalyticsItem {
  return {
    item_id: String(item.id),
    item_name: item.name,
    price: storeAmountToMajor(item.prices.price, item.prices.currency_minor_unit),
    quantity,
  };
}

/**
 * `add_to_cart`: lo AÑADIDO (no la línea entera, que puede traer unidades de
 * antes), leído del carrito que devolvió la Store API. Null si la línea no
 * está: sin precio no hay evento que valga.
 */
export function addToCartParams(
  cart: StoreCart,
  productId: number,
  quantity: number,
): EcommerceParams | null {
  const line = cart.items.find((item) => item.id === productId);
  if (!line || quantity <= 0) return null;
  const item = cartItemToAnalytics(line, quantity);
  return {
    currency: line.prices.currency_code || cart.totals.currency_code,
    value: item.price * quantity,
    items: [item],
  };
}

/** `begin_checkout`: el carrito entero, valorado por su total. */
export function beginCheckoutParams(cart: StoreCart): EcommerceParams | null {
  if (cart.items.length === 0) return null;
  return {
    currency: cart.totals.currency_code,
    value: storeAmountToMajor(cart.totals.total_price, cart.totals.currency_minor_unit),
    items: cart.items.map((item) => cartItemToAnalytics(item, item.quantity)),
  };
}

/**
 * `purchase` desde el pedido de wc/v3, cuyos importes ya vienen en unidades
 * mayores. Null si el total no es un número: mejor ningún evento que uno con
 * ingresos 0.
 */
export function purchaseParams(
  orderId: string | number,
  total: string | number | null,
  currency: string,
  lineItems: ReadonlyArray<
    Pick<
      WooOrderLineItem,
      "product_id" | "variation_id" | "name" | "price" | "quantity"
    >
  >,
): EcommerceParams | null {
  const value = Number(total);
  if (total === null || total === "" || !Number.isFinite(value) || !currency) {
    return null;
  }
  return {
    transaction_id: String(orderId),
    currency,
    value,
    items: lineItems.map((item) => ({
      item_id: String(item.variation_id || item.product_id),
      item_name: item.name,
      price: Number(item.price) || 0,
      quantity: item.quantity,
    })),
  };
}

/**
 * Los mismos datos con el vocabulario del píxel de TikTok. SIN el nombre del
 * producto (`content_name`): un nombre puede describir salud u otros datos
 * sensibles que las plataformas de anuncios restringen. Con el id basta.
 */
export function tiktokParams(params: EcommerceParams): Record<string, unknown> {
  return {
    value: params.value,
    currency: params.currency,
    content_type: "product",
    contents: params.items.map((item) => ({
      content_id: item.item_id,
      price: item.price,
      quantity: item.quantity,
    })),
    ...(params.transaction_id ? { order_id: params.transaction_id } : {}),
  };
}

// ── Meta Pixel / Conversions API ────────────────────────────────────────────

const META_EVENT_NAMES: Record<EcommerceEventName, string> = {
  view_item: "ViewContent",
  add_to_cart: "AddToCart",
  begin_checkout: "InitiateCheckout",
  purchase: "Purchase",
};

export interface MetaEvent {
  /** Evento estándar de Meta (`ViewContent`, `AddToCart`…). */
  name: string;
  /** `custom_data` en la Conversions API; segundo argumento de `fbq('track')`. */
  params: Record<string, unknown>;
  /** Solo en la compra: el mismo en el navegador y en el servidor. */
  eventId?: string;
}

/**
 * El evento con los parámetros de Meta para cada caso. NUNCA lleva el nombre
 * del producto (`content_name`): Meta puede tratarlo como dato sensible.
 */
export function metaEvent(
  name: EcommerceEventName,
  params: EcommerceParams,
): MetaEvent {
  const base = {
    content_ids: params.items.map((item) => item.item_id),
    content_type: "product",
    value: params.value,
    currency: params.currency,
  };
  const contents = params.items.map((item) => ({
    id: item.item_id,
    quantity: item.quantity,
    item_price: item.price,
  }));
  const num_items = params.items.reduce((n, item) => n + item.quantity, 0);
  const category = commonCategory(params.items);
  const withCategory = category ? { content_category: category } : {};

  switch (name) {
    case "view_item":
      return { name: META_EVENT_NAMES[name], params: { ...base, ...withCategory } };
    case "add_to_cart":
      return {
        name: META_EVENT_NAMES[name],
        params: { ...base, ...withCategory, contents },
      };
    case "begin_checkout":
      return { name: META_EVENT_NAMES[name], params: { ...base, contents, num_items } };
    case "purchase": {
      const orderId = params.transaction_id;
      return {
        name: META_EVENT_NAMES[name],
        params: {
          ...base,
          ...withCategory,
          contents,
          num_items,
          ...(orderId ? { order_id: orderId } : {}),
        },
        ...(orderId ? { eventId: purchaseEventId(orderId) } : {}),
      };
    }
  }
}
