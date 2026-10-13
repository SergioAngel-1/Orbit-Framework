import "server-only";
import { wcFetch } from "@/lib/woocommerce/client";
import { logger } from "@/lib/observability/logger";
import { checkoutSeedFromCustomer, type CheckoutCustomerSeed } from "./customer-seed";
import type { WooCustomer } from "@/types/woocommerce";

/**
 * Perfil y libreta del cliente con sesión, en UNA lectura de wc/v3, para que la
 * página del checkout los entregue ya en el HTML. Sin esto el formulario los
 * pedía al hidratar y, mientras tanto, se pintaba vacío y «sin direcciones».
 *
 * `null` si WordPress no responde: el formulario los pide entonces él mismo.
 */
export async function getCheckoutCustomerSeed(
  userId: string | number,
  defaultCountry: string,
): Promise<CheckoutCustomerSeed | null> {
  try {
    const customer = await wcFetch<WooCustomer>(`/customers/${Number(userId)}`);
    return checkoutSeedFromCustomer(customer, defaultCountry);
  } catch (error) {
    logger.warn(
      {
        event: "checkout.customer_seed.error",
        userId: String(userId),
        err: error instanceof Error ? error.message : error,
      },
      "No se pudo precargar el cliente del checkout: lo pedirá el formulario",
    );
    return null;
  }
}
