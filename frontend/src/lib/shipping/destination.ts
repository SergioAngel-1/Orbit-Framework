import { checkAddress, packCountry } from "@/lib/geo/address";
import type { GeoPack } from "@/lib/geo/types";
import type { StoreCart } from "@/types/woocommerce";
import type { SavedAddress } from "@/types/address";

/**
 * Destino de envío tal como se le comunica a WooCommerce (`/cart/update-customer`).
 * Puro: lo usan el BFF (`lib/woocommerce/cart-destination.ts`) y el checkout.
 */
export interface CartDestination {
  city: string;
  country: string;
  state: string;
  postcode: string;
}

/**
 * Destino que se comunica a Woo para una dirección; `null` si no trae ciudad o
 * no se puede resolver (país desconocido o división que el paquete de país no
 * reconoce). El estado sale como lo exige WooCommerce (código de la división del
 * paquete); nunca se inventa uno.
 */
export function destinationFromAddress(
  pack: GeoPack,
  address: SavedAddress | null | undefined,
): CartDestination | null {
  const city = address?.city?.trim() ?? "";
  if (!city) return null;
  const raw = (address?.country ?? "").trim().toUpperCase();
  const country = raw.length === 2 ? raw : packCountry(pack);
  if (country.length !== 2) return null;
  const checked = checkAddress(pack, {
    city,
    country,
    state: address?.state,
    postcode: address?.postcode,
  });
  if (checked.issues.some((issue) => issue.field === "state")) return null;
  return {
    city,
    country,
    state: checked.state,
    postcode: address?.postcode?.trim() ?? "",
  };
}

/** El carrito ya tiene ese destino: no hace falta otra ida a WordPress. */
export function cartHasDestination(
  cart: Pick<StoreCart, "shipping_address"> | null | undefined,
  destination: CartDestination,
): boolean {
  const current = cart?.shipping_address;
  return (
    (current?.city ?? "").trim() === destination.city &&
    (current?.country ?? "").trim().toUpperCase() === destination.country &&
    (current?.state ?? "").trim() === destination.state
  );
}
