import "server-only";
import { stripHtml } from "@/lib/format";
import { getSiteConfig } from "@/lib/config";
import { isModuleEnabled } from "@/lib/config/modules";
import { getCartProductRefs } from "@/lib/catalog/coupon-refs";
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
//  Punto de extensión: la traducción de los nombres de producto al idioma de la
//  página (i18n de contenido, tarea 51) y los datos que una instancia quiera
//  añadir a cada línea (enlace, volumen…) se hacen aquí, en el servidor: el
//  navegador no tiene el catálogo.
// ============================================================================

export async function completeStoreCart(cart: StoreCart): Promise<StoreCart> {
  const described = await withCouponDescriptions(cart);
  if (described.items.length === 0) return described;
  const named = {
    ...described,
    items: described.items.map((item) => ({ ...item, name: stripHtml(item.name) })),
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
