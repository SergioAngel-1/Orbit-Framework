import { checkAddress, packCountry } from "@/lib/geo/address";
import type { GeoPack } from "@/lib/geo/types";
import { locationSchema } from "@/lib/validation/address";
import type { StoreCart } from "@/types/woocommerce";
import type { SavedAddress } from "@/types/address";

/**
 * Destino de envío tal como se le comunica a WooCommerce (`/cart/update-customer`).
 * Puro: lo usan el BFF (`lib/woocommerce/cart-destination.ts`) y el checkout.
 *
 * La calle y el pin (`location`) no los calcula WooCommerce: los guarda WordPress en el punto
 * de entrega del envío por distancia (ver `shipping.ts`), así que el BFF los envía aparte.
 */
export interface CartDestination {
  /**
   * Calle. Opcional para los llamadores que no la tienen (el checkout compara solo ciudad y
   * división): sin ella, `cartHasDestination` no compara la calle.
   */
  address_1?: string;
  city: string;
  country: string;
  state: string;
  postcode: string;
  location?: { lat: number; lng: number };
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
  const destination: CartDestination = {
    address_1: address?.address_1?.trim() ?? "",
    city,
    country,
    state: checked.state,
    postcode: address?.postcode?.trim() ?? "",
  };
  // Una ubicación malformada se descarta (no se propaga): sin pin no hay tarifa por distancia.
  const location = locationSchema.safeParse(address?.location);
  if (location.success) destination.location = location.data;
  return destination;
}

/**
 * El carrito ya tiene ese destino: no hace falta otra ida a WordPress. La calle forma parte del
 * destino porque el pin del cliente depende de ella (cambiarla invalida el punto).
 */
export function cartHasDestination(
  cart: Pick<StoreCart, "shipping_address"> | null | undefined,
  destination: CartDestination,
): boolean {
  const current = cart?.shipping_address;
  if (
    destination.address_1 !== undefined &&
    (current?.address_1 ?? "").trim() !== destination.address_1
  ) {
    return false;
  }
  return (
    (current?.city ?? "").trim() === destination.city &&
    (current?.country ?? "").trim().toUpperCase() === destination.country &&
    (current?.state ?? "").trim() === destination.state
  );
}
