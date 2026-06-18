// ============================================================================
//  Tipos (parciales) de la API de WooCommerce.
//  Solo modelamos los campos que el BFF consume/expone. No pretende ser
//  exhaustivo: WooCommerce devuelve muchos más campos.
// ============================================================================

/* ----------------------------- Store API ------------------------------ */

export interface StoreCartImage {
  id: number;
  src: string;
  thumbnail: string;
  alt: string;
}

export interface StoreCartItem {
  key: string;
  id: number;
  quantity: number;
  name: string;
  short_description: string;
  permalink: string;
  images: StoreCartImage[];
  prices: {
    price: string;
    regular_price: string;
    sale_price: string;
    currency_code: string;
    currency_minor_unit: number;
  };
  totals: {
    line_subtotal: string;
    line_total: string;
  };
}

export interface StoreCartTotals {
  total_items: string;
  total_price: string;
  total_tax: string;
  currency_code: string;
  currency_minor_unit: number;
}

export interface StoreCart {
  items: StoreCartItem[];
  items_count: number;
  needs_payment: boolean;
  needs_shipping: boolean;
  totals: StoreCartTotals;
}

export interface StoreCheckoutResponse {
  order_id: number;
  status: string;
  order_key: string;
  payment_result: {
    payment_status: string;
    redirect_url: string;
  };
}

/* ------------------------------- wc/v3 -------------------------------- */

export interface WooAddress {
  first_name?: string;
  last_name?: string;
  company?: string;
  address_1?: string;
  address_2?: string;
  city?: string;
  state?: string;
  postcode?: string;
  country?: string;
  email?: string;
  phone?: string;
}

export interface WooOrderLineItem {
  id: number;
  name: string;
  product_id: number;
  quantity: number;
  total: string;
}

export interface WooOrder {
  id: number;
  status: string;
  currency: string;
  total: string;
  customer_id: number;
  date_created: string;
  billing: WooAddress;
  shipping: WooAddress;
  line_items: WooOrderLineItem[];
}

export interface WooCustomer {
  id: number;
  email: string;
  first_name: string;
  last_name: string;
  username: string;
  billing: WooAddress;
  shipping: WooAddress;
}
