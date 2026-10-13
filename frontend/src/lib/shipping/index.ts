import "server-only";
import { wpFetch } from "@/lib/wordpress/internal-fetch";
import { fetchWithRetry } from "@/lib/http/fetch-retry";
import { logReadFailure } from "@/lib/observability/read-failure";
import { isModuleEnabled } from "@/lib/config/modules";
import type { SiteConfig } from "@/lib/config/types";
import { normalizeShippingMethods } from "./normalize";
import {
  EMPTY_FREE_SHIPPING_SCOPE,
  normalizeFreeShippingScope,
  type FreeShippingScope,
} from "./free-shipping-scope";
import type { ShippingMethod } from "./types";

export type { ShippingMethod } from "./types";

const REVALIDATE_SECONDS = 3600;

/**
 * Métodos de envío activos (extras aditivos), para pintar los checkboxes del
 * checkout. Con el módulo `shipping` apagado (`enabled: false`) devuelve la lista
 * vacía sin llamar a WordPress.
 *
 * `fresh: true` evita la caché ISR (`no-store`): úsalo solo al aplicar los
 * `fee_lines` del pedido, donde el importe cobrado debe ser el vigente en ese
 * instante, no el de hace hasta una hora.
 */
export async function getShippingMethods(
  opts: { fresh?: boolean; enabled?: boolean } = {},
): Promise<ShippingMethod[]> {
  if (opts.enabled === false) return [];
  const init = opts.fresh
    ? { cache: "no-store" as const }
    : { next: { revalidate: REVALIDATE_SECONDS, tags: ["shipping"] } };

  try {
    const res = await fetchWithRetry(
      () => wpFetch("/wp-json/hwe-shipping/v1/methods", init),
      undefined,
      { label: "shipping-methods" },
    );
    if (!res.ok) {
      logReadFailure("shipping.methods_failed", `HTTP ${res.status}`, {
        message: "WordPress no sirvió los métodos de envío: no se ofrecen extras",
      });
      return [];
    }
    return normalizeShippingMethods(await res.json());
  } catch (error) {
    logReadFailure("shipping.methods_failed", error, {
      message: "No se pudieron leer los métodos de envío: no se ofrecen extras",
    });
    return [];
  }
}

/**
 * A qué aplica el envío gratis (categorías con su descendencia y productos), en
 * el idioma pedido, para el «?» del aviso del carrito. Caché con los tags
 * `shipping` y `site-config`: la regla se guarda en el Control Center, que
 * revalida el segundo. Con el módulo apagado, alcance vacío. Fail-soft: sin
 * respuesta, alcance vacío (y registrado).
 */
export async function getFreeShippingScope(
  locale: string,
  config?: Pick<SiteConfig, "modules">,
): Promise<FreeShippingScope> {
  if (config && !isModuleEnabled(config, "shipping")) return EMPTY_FREE_SHIPPING_SCOPE;
  try {
    const res = await fetchWithRetry(
      () =>
        wpFetch("/wp-json/hwe-shipping/v1/free-shipping/scope", {
          next: { revalidate: REVALIDATE_SECONDS, tags: ["shipping", "site-config"] },
        }),
      undefined,
      { label: "free-shipping-scope" },
    );
    if (!res.ok) {
      logReadFailure("shipping.scope_failed", `HTTP ${res.status}`, {
        message: "WordPress no sirvió el alcance del envío gratis",
      });
      return EMPTY_FREE_SHIPPING_SCOPE;
    }
    return normalizeFreeShippingScope(await res.json(), locale);
  } catch (error) {
    logReadFailure("shipping.scope_failed", error, {
      message: "No se pudo leer el alcance del envío gratis",
    });
    return EMPTY_FREE_SHIPPING_SCOPE;
  }
}
