import "server-only";
import { cache } from "react";
import { wpFetch } from "@/lib/wordpress/internal-fetch";
import { logger } from "@/lib/observability/logger";
import { getCategoryPaths } from "@/lib/catalog/coupon-refs";
import {
  couponAppliesToCategory,
  couponAppliesToProduct,
  isCouponActive,
  parsePublicCoupons,
  shouldShowCoupon,
  type NoticeCoupon,
  type PublicCoupon,
} from "./coupon-scope";

export type { NoticeCoupon };

function stamp(coupons: PublicCoupon[]): NoticeCoupon[] {
  const now = Date.now();
  return coupons.map((c) => ({ ...c, activeAtRender: isCouponActive(c, now) }));
}

/**
 * Los que se enseñan YA a un cliente cuyo historial se conoce (`usedUp`): para
 * páginas dinámicas, que no necesitan adelantarse a los que empiezan pronto. La
 * decisión es `shouldShowCoupon`, la misma del navegador.
 */
function stampVisible(
  coupons: PublicCoupon[],
  usedUp: ReadonlySet<string>,
): NoticeCoupon[] {
  return stamp(coupons).filter((c) =>
    shouldShowCoupon(c, { active: c.activeAtRender, usedUp }),
  );
}

// ============================================================================
//  Cupones PÚBLICOS, leídos del plugin `hwe-coupon-rules` (módulo
//  `coupon-rules`): `GET /wp-json/hwe/v1/coupons/public`, solo con el secreto
//  interno (`wpFetch`).
//
//  La caché dura lo mismo que el ISR de categoría y ficha (1 h) a propósito: un
//  `revalidate` más corto en un fetch ACORTA el de toda la ruta y multiplicaría
//  las regeneraciones contra WordPress. Lo inmediato llega por el tag
//  `coupons`, que WordPress dispara al guardar un cupón o al agotarse; las
//  FECHAS no tienen evento, así que la lista incluye también los cupones que
//  empiezan en las próximas 3 h y el navegador oculta el aviso fuera de su
//  ventana. Esos todavía no vigentes solo llegan a las páginas ISR (ficha y
//  categoría); las dinámicas (carrito, checkout) reciben únicamente los
//  vigentes, para que el código de uno futuro no viaje en su HTML ni en las
//  props de cliente.
//
//  Fail-soft: sin lista (WP caído, módulo apagado) no se anuncia nada; el
//  cupón sigue funcionando al escribirlo.
// ============================================================================

const COUPONS_REVALIDATE = 3600;

export const getPublicCoupons = cache(async (): Promise<PublicCoupon[]> => {
  if (!process.env.HWE_REVALIDATION_SECRET) return [];
  try {
    const res = await wpFetch("/wp-json/hwe/v1/coupons/public", {
      next: { revalidate: COUPONS_REVALIDATE, tags: ["coupons"] },
    });
    if (!res.ok) {
      logger.warn({ event: "coupons.public.http", status: res.status });
      return [];
    }
    return parsePublicCoupons(await res.json());
  } catch (error) {
    logger.warn({
      event: "coupons.public.error",
      err: error instanceof Error ? error.message : error,
    });
    return [];
  }
});

/** Las categorías dadas más todos sus ancestros (slugs nativos). */
async function withAncestors(slugs: readonly string[]): Promise<string[]> {
  const paths = await getCategoryPaths();
  const all = new Set<string>(slugs);
  for (const slug of slugs) {
    for (const ancestor of paths.get(slug) ?? []) all.add(ancestor);
  }
  return [...all];
}

/** Cupones públicos que aplican a un producto (ficha). */
export async function getProductCoupons(product: {
  slug: string;
  categorySlugs: readonly string[];
  onSale?: boolean;
}): Promise<NoticeCoupon[]> {
  const coupons = await getPublicCoupons();
  if (coupons.length === 0) return [];
  const categorySlugs = await withAncestors(product.categorySlugs);
  return stamp(
    coupons.filter((c) => couponAppliesToProduct(c, { ...product, categorySlugs })),
  );
}

/** Cupones públicos que se anuncian en la página de una categoría. */
export async function getCategoryCoupons(
  categorySlug: string,
): Promise<NoticeCoupon[]> {
  const coupons = await getPublicCoupons();
  if (coupons.length === 0) return [];
  const path = await withAncestors([categorySlug]);
  return stamp(coupons.filter((c) => couponAppliesToCategory(c, path)));
}

/**
 * Códigos de los cupones públicos que este cliente YA NO puede usar: agotó su
 * límite de usos por cliente, es de primera compra y ya compró, o tiene un
 * pedido pendiente con él. Lo decide WordPress con sus propias cuentas
 * (`GET hwe/v1/coupons/public/used-up`), por cliente y SIN caché: la lista
 * pública es compartida y no puede saberlo. En minúsculas, como los compara
 * WooCommerce.
 *
 * Fail-soft: si no responde, no se quita nada (el cupón se ofrecería y
 * WordPress lo rechazaría al aplicarlo con su mensaje).
 */
export async function getUsedUpCouponCodes(userId: string): Promise<Set<string>> {
  const id = Number(userId);
  if (!process.env.HWE_REVALIDATION_SECRET || !Number.isInteger(id) || id <= 0) {
    return new Set();
  }
  try {
    const res = await wpFetch(`/wp-json/hwe/v1/coupons/public/used-up?user_id=${id}`, {
      cache: "no-store",
    });
    if (!res.ok) {
      logger.warn({ event: "coupons.used_up.http", status: res.status });
      return new Set();
    }
    // Se lee como texto y se corta en la primera llave: un aviso de PHP
    // («Deprecated…») impreso antes del JSON rompía `res.json()` y, al ser
    // fail-soft, el cupón agotado volvía a ofrecerse.
    const raw = await res.text();
    const start = raw.indexOf("{");
    const body = JSON.parse(start >= 0 ? raw.slice(start) : raw) as { codes?: unknown };
    const codes = Array.isArray(body.codes) ? body.codes : [];
    return new Set(
      codes
        .filter((c): c is string => typeof c === "string")
        .map((c) => c.toLowerCase()),
    );
  } catch (error) {
    logger.warn({
      event: "coupons.used_up.error",
      err: error instanceof Error ? error.message : error,
    });
    return new Set();
  }
}

/**
 * Cupones públicos para el carrito y el checkout, SIN los que el cliente con
 * sesión ya agotó (`getUsedUpCouponCodes`) y SIN los que aún no empiezan.
 * Cuál aplica a qué artículo lo decide el navegador con el carrito
 * (`couponAppliesToCart`). Un invitado los ve todos: sin cuenta no hay historial
 * con el que descartarlos (el de primera compra, además, le pide iniciar sesión).
 */
export async function getCartCoupons(userId?: string | null): Promise<NoticeCoupon[]> {
  const coupons = await getPublicCoupons();
  // Sin cupones, o sin ninguno que dependa del cliente, la pregunta por su
  // historial es una llamada a WordPress (por página y por cliente, sin caché)
  // que no puede cambiar la respuesta.
  const usedUp =
    userId && coupons.some((c) => c.perUserLimited)
      ? await getUsedUpCouponCodes(userId)
      : new Set<string>();
  return stampVisible(coupons, usedUp);
}
