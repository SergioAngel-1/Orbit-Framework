import { routing } from "@/i18n/routing";

/**
 * Sufijo de un idioma dentro de una clave: `pt-BR` → `pt_br`. Es la misma
 * normalización que aplica el Control Center (`Schema::localeSuffix`) al generar
 * las variantes de los campos traducibles.
 */
export function localeSuffix(locale: string): string {
  return locale.trim().toLowerCase().replace(/-/g, "_");
}

/**
 * Resuelve un campo traducible al idioma pedido.
 *
 * En el idioma por defecto devuelve `entity[field]`. En un idioma secundario
 * devuelve la variante `entity[`${field}_${sufijo}`]` si existe y no está vacía;
 * si no, cae al texto del idioma por defecto (nunca se muestra el texto de otro
 * idioma secundario). Sin valor, `null`.
 *
 * Las variantes las genera el Control Center para cada idioma de `i18n.locales`
 * en los campos marcados como `translatable` (p. ej. `brand.tagline_en`,
 * `geo.faq_fr`).
 */
export function pickLocalized(
  entity: object | null | undefined,
  field: string,
  locale: string,
): string | null {
  if (!entity) return null;
  const record = entity as Record<string, unknown>;
  const base = typeof record[field] === "string" ? (record[field] as string) : null;

  if (localeSuffix(locale) === localeSuffix(routing.defaultLocale)) return base;

  const translated = record[`${field}_${localeSuffix(locale)}`];
  if (typeof translated === "string" && translated.trim() !== "") return translated;
  return base;
}
