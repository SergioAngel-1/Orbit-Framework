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

export interface StoreCoupon {
  code: string;
  label: string;
  discount_type: string;
  totals: {
    currency_code: string;
    currency_minor_unit: number;
    total_discount: string;
    total_discount_tax: string;
  };
}

export interface StoreShippingRate {
  rate_id: string;
  name: string;
  description?: string;
  delivery_time?: string;
  price: string;
  taxes: string;
  instance_id: number;
  method_id: string;
  meta_data?: { key: string; value: string }[];
  selected: boolean;
  currency_code: string;
  currency_minor_unit: number;
}

export interface StoreShippingPackage {
  package_id: number;
  name: string;
  destination: {
    address_1: string;
    address_2?: string;
    city: string;
    state?: string;
    postcode: string;
    country: string;
  };
  shipping_rates: StoreShippingRate[];
}

export interface StoreCartTotals {
  total_items: string;
  total_items_tax: string;
  total_fees: string;
  total_fees_tax: string;
  total_discount: string;
  total_discount_tax: string;
  total_shipping: string;
  total_shipping_tax: string;
  total_price: string;
  total_tax: string;
  tax_lines: { name: string; price: string; rate: string }[];
  currency_code: string;
  currency_symbol: string;
  currency_minor_unit: number;
  currency_decimal_separator: string;
  currency_thousand_separator: string;
  currency_prefix: string;
  currency_suffix: string;
}

export interface StoreCart {
  items: StoreCartItem[];
  items_count: number;
  needs_payment: boolean;
  needs_shipping: boolean;
  coupons: StoreCoupon[];
  shipping_rates: StoreShippingPackage[];
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
  variation_id?: number;
  quantity: number;
  sku?: string;
  price: number;
  total: string;
  subtotal: string;
  image?: { src: string };
}

export interface WooOrderCoupon {
  id: number;
  code: string;
  discount: string;
}

export interface WooOrder {
  id: number;
  number: string;
  status: string;
  currency: string;
  total: string;
  subtotal: string;
  total_discount: string;
  total_shipping: string;
  total_tax: string;
  customer_id: number;
  customer_note?: string;
  date_created: string;
  date_modified: string;
  billing: WooAddress;
  shipping: WooAddress;
  line_items: WooOrderLineItem[];
  coupon_lines: WooOrderCoupon[];
  payment_method: string;
  payment_method_title: string;
  transaction_id?: string;
}

export interface WooCustomer {
  id: number;
  email: string;
  first_name: string;
  last_name: string;
  username: string;
  billing: WooAddress;
  shipping: WooAddress;
  meta_data?: { id?: number; key: string; value: string }[];
}

export interface WooProductReview {
  id: number;
  date_created: string;
  review: string;
  rating: number;
  reviewer: string;
  reviewer_email: string;
  verified: boolean;
  status: string;
}
