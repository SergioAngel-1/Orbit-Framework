import type { Locale } from "next-intl";
import { getPathname } from "@/i18n/navigation";

// ============================================================================
//  URL de retorno tras el pago.
// ============================================================================

/**
 * Ruta pública de la pantalla de «gracias» (`/checkout/return`) para `locale`,
 * con la referencia del pedido.
 *
 * Existe porque componerla a mano (`/checkout/return?ref=...`) ignora el
 * prefijo de idioma y los `pathnames` que una instancia declare en
 * `i18n/routing.ts`: es el caso que la regla «navega siempre desde
 * `@/i18n/navigation`» existe para evitar, solo que aquí la URL no la consume
 * un `<Link>` sino la pasarela, que la usa como destino del redirect post-pago.
 *
 * El `?ref=` no es opcional: sin él, la pantalla no sabe qué pedido mostrar.
 */
export function checkoutReturnPath(locale: Locale, reference: number | string): string {
  return `${getPathname({ locale, href: "/checkout/return" })}?ref=${reference}`;
}

/** Igual que {@link checkoutReturnPath}, pero absoluta — es lo que espera la pasarela. */
export function checkoutReturnUrl(
  origin: string,
  locale: Locale,
  reference: number | string,
): string {
  return `${origin.replace(/\/$/, "")}${checkoutReturnPath(locale, reference)}`;
}
