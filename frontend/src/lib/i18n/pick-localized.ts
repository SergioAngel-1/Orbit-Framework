/**
 * Resuelve un campo de contenido bilingüe: en inglés usa la traducción si
 * existe y no está vacía, si no cae al español. En cualquier otro locale
 * siempre devuelve español — nunca se muestra inglés fuera de /en.
 *
 * La generalización a N idiomas llega en la tarea 51 (hwe-content-i18n): esta
 * versión cubre los campos `_en` del Control Center (tagline, descripción, FAQ).
 */
export function pickLocalized(
  es: string | null | undefined,
  en: string | null | undefined,
  locale: string,
): string | null {
  if (locale === "en" && en) return en;
  return es ?? null;
}
