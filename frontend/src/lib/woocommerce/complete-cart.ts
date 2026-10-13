import "server-only";
import { stripHtml } from "@/lib/format";
import { getSiteConfig } from "@/lib/config";
import { isModuleEnabled } from "@/lib/config/modules";
import { getCartProductRefs } from "@/lib/catalog/coupon-refs";
import { routing } from "@/i18n/routing";
import { getProductsByIds } from "@/lib/catalog/products";
import { logger } from "@/lib/observability/logger";
import { withCouponDescriptions } from "./coupon-descriptions";
import type { StoreCart } from "@/types/woocommerce";

// ============================================================================
//  El carrito, completado en el BFF antes de salir al navegador.
//
//  Todas las rutas que devuelven un `StoreCart` pasan por aquí: es el punto
//  único donde se completa lo que la Store API no trae.
//   - La descripción (y el «envío gratis») de los cupones aplicados.
//   - El nombre de cada línea como TEXTO: la Store API lo manda pasado por
//     `wptexturize` («Curso &#8211; Taller») y React lo pinta tal cual, con los
//     códigos a la vista.
//   - Con el módulo `coupon-rules` y los cupones activos, la referencia de
//     catálogo de cada línea (`couponRef`: slug y categorías con ancestros) para
//     que el navegador decida qué cupones públicos aplican a algo del carrito.
//     El navegador no tiene el árbol de categorías.
//
//   - El nombre de cada línea en el idioma de la página (`X-HWE-Locale`), si el
//     producto tiene traducción. Las líneas de variación y los productos sin
//     traducción conservan el nombre que manda la Store API.
//
//  Punto de extensión: los datos que una instancia quiera añadir a cada línea
//  (enlace, volumen…) se hacen aquí, en el servidor: el navegador no tiene el
//  catálogo.
// ============================================================================

/**
 * Nombres de producto en `locale` por id de producto. Vacío en el idioma por
 * defecto (el nombre de la Store API ya es el nativo) y ante un fallo del
 * catálogo, que queda en el log: el carrito sigue con los nombres nativos.
 */
async function localizedNames(
  ids: readonly number[],
  locale: string,
): Promise<Map<number, string>> {
  if (locale === routing.defaultLocale) return new Map();
  try {
    const products = await getProductsByIds(ids, locale);
    return new Map(products.map((product) => [product.databaseId, product.name]));
  } catch (error) {
    logger.warn(
      {
        event: "cart.localized_names_failed",
        locale,
        err: error instanceof Error ? error.message : error,
      },
      "No se pudieron traducir los nombres del carrito",
    );
    return new Map();
  }
}

export async function completeStoreCart(
  cart: StoreCart,
  locale: string = routing.defaultLocale,
): Promise<StoreCart> {
  const described = await withCouponDescriptions(cart);
  if (described.items.length === 0) return described;
  const names = await localizedNames(
    described.items.map((item) => item.id),
    locale,
  );
  const named = {
    ...described,
    items: described.items.map((item) => ({
      ...item,
      name: stripHtml(names.get(item.id) ?? item.name),
    })),
  };

  const config = await getSiteConfig();
  if (!isModuleEnabled(config, "coupon-rules") || !config.ecommerce.coupons_enabled) {
    return named;
  }
  // Fail-soft: sin catálogo las líneas llegan sin referencia y el carrito sigue.
  const refs = await getCartProductRefs(named.items.map((item) => item.id));
  if (refs.size === 0) return named;
  return {
    ...named,
    items: named.items.map((item) => {
      const ref = refs.get(item.id);
      return ref ? { ...item, couponRef: ref } : item;
    }),
  };
}
