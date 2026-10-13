import "server-only";
import { wcFetch } from "./client";
import { logger } from "@/lib/observability/logger";
import type { StoreCart } from "@/types/woocommerce";

// ============================================================================
//  Descripción (y «envío gratis») de los cupones APLICADOS al carrito.
//
//  La Store API no la manda en `cart.coupons[]` (solo `code`, `discount_type`
//  y `totals`), y la UI la necesita para explicar qué hace el cupón activo
//  («10% en tu primera compra»). Se lee de la WC REST (`wc/v3/coupons`, con
//  `ck/cs`, solo servidor) y SOLO para códigos que ya están en el carrito del
//  propio visitante: un endpoint que devolviera la descripción de cualquier
//  código serviría para adivinar cupones.
//
//  Caché en memoria del proceso (5 min por código): la descripción la edita
//  el cliente muy de vez en cuando, y sin caché cada lectura del carrito
//  sumaría una petición a WordPress. Fail-soft: si falla, el carrito llega sin
//  descripción y la UI cae al código.
// ============================================================================

const TTL_MS = 5 * 60_000;

interface CouponInfo {
  description: string | null;
  /** «Envío gratis» del cupón: la tarifa queda a 0 (HWE Shipping). */
  freeShipping: boolean;
}

const NONE: CouponInfo = { description: null, freeShipping: false };
const cache = new Map<string, { value: CouponInfo; at: number }>();

async function describe(code: string): Promise<CouponInfo> {
  const key = code.toLowerCase();
  const hit = cache.get(key);
  if (hit && Date.now() - hit.at < TTL_MS) return hit.value;
  try {
    const rows = await wcFetch<
      { code?: string; description?: string; free_shipping?: boolean }[]
    >("/coupons", {
      query: { code: key },
    });
    const row = rows.find((r) => r.code?.toLowerCase() === key);
    const value: CouponInfo = {
      description: row?.description?.trim() || null,
      freeShipping: row?.free_shipping === true,
    };
    cache.set(key, { value, at: Date.now() });
    return value;
  } catch (error) {
    logger.warn({
      event: "coupon.description.error",
      err: error instanceof Error ? error.message : error,
    });
    return NONE;
  }
}

/**
 * Añade `description` (si el cupón la tiene) y `freeShipping` a cada cupón
 * aplicado. `freeShipping` es lo que deja a la UI decir «Envío: gratis» en
 * vez de «se calcula en el pago» cuando la tarifa queda a 0 por el cupón.
 */
export async function withCouponDescriptions(cart: StoreCart): Promise<StoreCart> {
  if (!cart.coupons?.length) return cart;
  const infos = await Promise.all(cart.coupons.map((c) => describe(c.code)));
  return {
    ...cart,
    coupons: cart.coupons.map((c, i) => {
      const { description, freeShipping } = infos[i];
      return description ? { ...c, description, freeShipping } : { ...c, freeShipping };
    }),
  };
}
