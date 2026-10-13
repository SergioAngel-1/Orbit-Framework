import "server-only";
import { storeFetch, type StoreApiResult } from "@/lib/woocommerce/store-client";
import { wcFetch } from "@/lib/woocommerce/client";
import { destinationFromAddress } from "@/lib/shipping/destination";
import { defaultAddress, parseSavedAddresses } from "@/lib/customer/addresses";
import { getSiteConfig } from "@/lib/config";
import { isModuleEnabled } from "@/lib/config/modules";
import { getGeoPack } from "@/lib/geo";
import { logger } from "@/lib/observability/logger";
import type { StoreCart, WooCustomer } from "@/types/woocommerce";
import type { SavedAddress } from "@/types/address";

// ============================================================================
//  Destino de envío del carrito de un cliente CON SESIÓN (módulo `shipping`).
//
//  La tarifa por ciudad y el envío gratis se deciden dentro de WooCommerce con la
//  ciudad de `WC()->customer`. Esa ciudad no sale de la libreta de la cuenta
//  (`hwe_addresses`, un meta propio que Woo no lee): solo existe si alguien se la
//  comunica con `/cart/update-customer`, y Woo la guarda en la SESIÓN del carrito,
//  no en el perfil. Sin esto, el cajón y `/cart` tratan al cliente como a un
//  anónimo («Disponible para …») hasta que pasa por el checkout.
//
//  Este módulo la fija desde el servidor: al abrir sesión, al cambiar la libreta
//  y —como red— al leer un carrito que llega sin ciudad (sesión de Woo caducada, o
//  descartada por Woo al cambiar el perfil). Con el módulo apagado no hace nada.
// ============================================================================

interface CartAuth {
  cartToken: string | null;
  authToken: string | null;
}

/**
 * Clientes sin ninguna dirección con ciudad, para no repetir la lectura de wc/v3
 * en cada carga de página. En memoria del proceso y con caducidad corta: solo
 * ahorra trabajo, nunca decide nada (guardar una dirección lo limpia).
 */
const NO_DESTINATION_TTL_MS = 5 * 60_000;
const noDestination = new Map<string, number>();

function knownWithoutDestination(userId: string): boolean {
  const until = noDestination.get(userId);
  if (until === undefined) return false;
  if (until > Date.now()) return true;
  noDestination.delete(userId);
  return false;
}

/** ¿Está activo el módulo `shipping`? Sin él, nada de este archivo actúa. */
async function shippingEnabled(): Promise<boolean> {
  return isModuleEnabled(await getSiteConfig(), "shipping");
}

/**
 * Comunica a Woo el destino de la dirección PREDETERMINADA de una libreta ya
 * leída. Best-effort: devuelve el carrito recalculado, o `null` si no había nada
 * que fijar, el módulo está apagado o WordPress no respondió (el checkout lo
 * vuelve a fijar).
 */
export async function pushCartDestination(
  userId: string | number,
  addresses: readonly SavedAddress[],
  auth: CartAuth,
): Promise<StoreApiResult<StoreCart> | null> {
  if (!(await shippingEnabled())) return null;
  const key = String(userId);
  const destination = destinationFromAddress(
    await getGeoPack(),
    defaultAddress(addresses),
  );
  if (!destination) {
    noDestination.set(key, Date.now() + NO_DESTINATION_TTL_MS);
    return null;
  }
  noDestination.delete(key);
  try {
    return await storeFetch<StoreCart>("/cart/update-customer", {
      method: "POST",
      body: { shipping_address: destination },
      cartToken: auth.cartToken,
      authToken: auth.authToken,
    });
  } catch (error) {
    logger.warn(
      {
        event: "cart.destination.error",
        userId: key,
        err: error instanceof Error ? error.message : error,
      },
      "No se pudo fijar el destino de envío del carrito (best-effort)",
    );
    return null;
  }
}

/** Como `pushCartDestination`, leyendo antes la libreta de la cuenta (wc/v3). */
export async function syncCartDestination(
  userId: string | number,
  auth: CartAuth,
): Promise<StoreApiResult<StoreCart> | null> {
  if (!(await shippingEnabled())) return null;
  let addresses: SavedAddress[];
  try {
    const customer = await wcFetch<WooCustomer>(`/customers/${Number(userId)}`);
    addresses = parseSavedAddresses(customer.meta_data);
  } catch (error) {
    logger.warn(
      {
        event: "cart.destination.addresses_error",
        userId: String(userId),
        err: error instanceof Error ? error.message : error,
      },
      "No se pudo leer la libreta para fijar el destino (best-effort)",
    );
    return null;
  }
  return pushCartDestination(userId, addresses, auth);
}

/**
 * Red para las lecturas del carrito: si el de un cliente con sesión necesita
 * envío y llega SIN ciudad, se la pone desde su libreta. Con ciudad no hace nada
 * —la del perfil o la que fijó el checkout mandan—, y sin sesión tampoco.
 */
export async function ensureCartDestination(
  cart: StoreCart,
  userId: string | number | null | undefined,
  auth: CartAuth,
): Promise<StoreApiResult<StoreCart> | null> {
  if (!userId || !auth.authToken) return null;
  if (!cart.needs_shipping || cart.items_count === 0) return null;
  if ((cart.shipping_address?.city ?? "").trim()) return null;
  if (knownWithoutDestination(String(userId))) return null;
  return syncCartDestination(userId, auth);
}

/** Solo para tests: olvida a los clientes anotados sin destino. */
export function resetCartDestinationCache(): void {
  noDestination.clear();
}
