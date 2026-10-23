import "server-only";
import { storeFetch, type StoreApiResult } from "@/lib/woocommerce/store-client";
import type { Location } from "@/lib/validation/address";
import type { StoreCart } from "@/types/woocommerce";

// ============================================================================
//  Punto de entrega del carrito (pin del cliente), por la extensión de la Store API del módulo
//  `shipping` (`DeliveryPoint.php` en WordPress).
//
//  Con pin, WordPress guarda el punto junto a la HUELLA de la calle, ciudad y país: por eso el
//  `set_point` lleva EXACTAMENTE los datos que se acaban de enviar a `update-customer`.
//
//  Semántica de `location`: AUSENTE (`undefined`) = no se toca el punto (el pin pudo marcarse en
//  otra pantalla; el formulario del checkout no lo conoce y nunca lo envía; la huella de WordPress
//  ya lo invalida si cambian calle, ciudad o país); `null` = borrado explícito (`clear_point`).
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
 * Fija el punto de entrega con el pin y la huella del destino, lo borra con `null`, o no hace
 * nada (devuelve `null`) si `location` está ausente. Un pin sin calle no se puede huellar
 * (WordPress lo rechazaría): tampoco se envía nada.
 */
export async function syncDeliveryPoint(
  target: DeliveryPointTarget,
  location: Location | null | undefined,
  auth: CartAuth,
): Promise<StoreApiResult<StoreCart> | null> {
  let data: Record<string, unknown>;
  if (location === null) {
    data = { action: "clear_point" };
  } else if (location !== undefined && target.address_1.trim() !== "") {
    data = {
      action: "set_point",
      lat: location.lat,
      lng: location.lng,
      address_1: target.address_1,
      city: target.city,
      country: target.country,
    };
  } else {
    return null;
  }

  return storeFetch<StoreCart>("/cart/extensions", {
    method: "POST",
    body: { namespace: DELIVERY_POINT_NAMESPACE, data },
    cartToken: auth.cartToken,
    authToken: auth.authToken,
  });
}
