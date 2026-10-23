import "server-only";
import { storeFetch, type StoreApiResult } from "@/lib/woocommerce/store-client";
import type { Location } from "@/lib/validation/address";
import type { StoreCart } from "@/types/woocommerce";

// ============================================================================
//  Punto de entrega del carrito (pin del cliente), por la extensión de la Store API del módulo
//  `shipping` (`DeliveryPoint.php` en WordPress).
//
//  Con pin, WordPress guarda el punto junto a la HUELLA de la calle, ciudad y país: por eso el
//  `set_point` lleva EXACTAMENTE los datos que se acaban de enviar a `update-customer`. Sin pin,
//  `clear_point`. Se llama siempre que el destino se fija, aunque Woo no cambie, porque el carrito
//  no expone el pin y la sesión puede haberlo perdido.
// ============================================================================

/** Namespace de la extensión del punto de entrega en la Store API. */
export const DELIVERY_POINT_NAMESPACE = "hwe_shipping";

/** Datos del destino tal como se enviaron a Woo: la huella del punto. */
export interface DeliveryPointTarget {
  address_1: string;
  city: string;
  country: string;
}

interface CartAuth {
  cartToken: string | null;
  authToken: string | null;
}

/**
 * Fija el punto de entrega con el pin y la huella del destino, o lo borra si no hay pin. Un pin
 * sin calle no se puede huellar: se trata como sin pin (WordPress lo rechazaría).
 */
export function syncDeliveryPoint(
  target: DeliveryPointTarget,
  location: Location | undefined,
  auth: CartAuth,
): Promise<StoreApiResult<StoreCart>> {
  const data =
    location && target.address_1.trim() !== ""
      ? {
          action: "set_point",
          lat: location.lat,
          lng: location.lng,
          address_1: target.address_1,
          city: target.city,
          country: target.country,
        }
      : { action: "clear_point" };

  return storeFetch<StoreCart>("/cart/extensions", {
    method: "POST",
    body: { namespace: DELIVERY_POINT_NAMESPACE, data },
    cartToken: auth.cartToken,
    authToken: auth.authToken,
  });
}
