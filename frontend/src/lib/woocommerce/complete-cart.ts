import "server-only";
import { stripHtml } from "@/lib/format";
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
//
//  Punto de extensión: la traducción de los nombres de producto al idioma de la
//  página (i18n de contenido, tarea 51) y los datos que una instancia quiera
//  añadir a cada línea (enlace, volumen…) se hacen aquí, en el servidor: el
//  navegador no tiene el catálogo.
// ============================================================================

export async function completeStoreCart(cart: StoreCart): Promise<StoreCart> {
  const described = await withCouponDescriptions(cart);
  if (described.items.length === 0) return described;
  return {
    ...described,
    items: described.items.map((item) => ({ ...item, name: stripHtml(item.name) })),
  };
}
