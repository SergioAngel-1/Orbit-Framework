import { parseSavedAddresses } from "@/lib/customer/addresses";
import { identityFromMeta, type CustomerIdentity } from "@/lib/customer/identity";
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
 * `identity` es el documento ya registrado y cuándo aceptó los términos (módulo
 * `customer-identity`); el formulario solo lo usa si el módulo está activo.
 */
export interface CheckoutCustomerSeed {
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
  /** Ciudad del perfil (la del último pedido): respaldo de una dirección sin ciudad. */
  city: string;
  /** División (departamento, provincia…) del perfil, o `""`. */
  state: string;
  country: string;
  /** Libreta de direcciones de la cuenta. */
  addresses: SavedAddress[];
  /** Documento y aceptación de términos de la cuenta (vacío si no hay). */
  identity: CustomerIdentity;
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
    state: billing?.state?.trim() || shipping?.state?.trim() || "",
    country: billing?.country?.trim() || shipping?.country?.trim() || defaultCountry,
    addresses: parseSavedAddresses(customer.meta_data),
    identity: identityFromMeta(customer.meta_data),
  };
}
