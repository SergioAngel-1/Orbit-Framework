import { routing } from "@/i18n/routing";

// ============================================================================
//  Idioma de una petición al BFF.
//
//  Los Route Handlers no están bajo `[locale]`, así que no reciben el idioma de
//  la página. El cliente lo manda en `X-HWE-Locale` (el `lang` del documento).
//  Solo vale uno de los idiomas del sitio: cualquier otro valor cae al idioma por
//  defecto y nunca llega a una query.
// ============================================================================

export const LOCALE_HEADER = "X-HWE-Locale";

/** Idioma pedido por el cliente, validado contra `routing.locales`. */
export function requestLocale(headers: Headers): string {
  const requested = headers.get(LOCALE_HEADER)?.trim() ?? "";
  return (routing.locales as readonly string[]).includes(requested)
    ? requested
    : routing.defaultLocale;
}
