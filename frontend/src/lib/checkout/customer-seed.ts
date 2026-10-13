import { parseSavedAddresses } from "@/lib/customer/addresses";
import type { SavedAddress } from "@/types/address";
import type { WooCustomer } from "@/types/woocommerce";

/**
 * Lo que el formulario del checkout necesita de la cuenta, y nada más: el
 * cliente de wc/v3 trae todo su `meta_data`, que no tiene por qué viajar al
 * navegador dentro del HTML de la página.
 *
 * Puro: lo arma el servidor (`getCheckoutCustomerSeed`) y, de respaldo, el
 * propio formulario con la respuesta de `/api/store/customer`.
 *
 * Punto de extensión (módulo `customer-identity`, tarea 53): aquí se sumará el
 * documento de identidad ya registrado.
 */
export interface CheckoutCustomerSeed {
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
  /** Ciudad del perfil (la del último pedido): respaldo de una dirección sin ciudad. */
  city: string;
  country: string;
  /** Libreta de direcciones de la cuenta. */
  addresses: SavedAddress[];
}

type SeedSource = Pick<
  WooCustomer,
  "email" | "first_name" | "last_name" | "billing" | "shipping" | "meta_data"
>;

/**
 * @param defaultCountry País de la tienda (`config.ecommerce.country`), cuando
 *   el cliente no tiene ninguno en su perfil.
 */
export function checkoutSeedFromCustomer(
  customer: SeedSource,
  defaultCountry: string,
): CheckoutCustomerSeed {
  const { billing, shipping } = customer;
  return {
    first_name: customer.first_name?.trim() || billing?.first_name?.trim() || "",
    last_name: customer.last_name?.trim() || billing?.last_name?.trim() || "",
    email: customer.email?.trim() || billing?.email?.trim() || "",
    phone: billing?.phone?.trim() || "",
    city: billing?.city?.trim() || shipping?.city?.trim() || "",
    country: billing?.country?.trim() || shipping?.country?.trim() || defaultCountry,
    addresses: parseSavedAddresses(customer.meta_data),
  };
}
