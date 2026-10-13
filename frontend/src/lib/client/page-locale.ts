"use client";

import { LOCALE_HEADER } from "@/lib/i18n/request-locale";

/**
 * Cabecera con el idioma de la página (`<html lang>`), para que el BFF devuelva
 * los textos en él (nombres de producto del carrito…). Sin documento (SSR) o sin
 * `lang`, no manda nada y el BFF usa el idioma por defecto.
 */
export function pageLocaleHeaders(): Record<string, string> {
  const lang = typeof document !== "undefined" ? document.documentElement.lang : "";
  return lang ? { [LOCALE_HEADER]: lang } : {};
}
