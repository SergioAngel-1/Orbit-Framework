// ============================================================================
//  Helpers de formato (cliente + servidor; sin dependencias pesadas).
// ============================================================================

/** Limpia el precio que devuelve WooGraphQL (entidades HTML + etiquetas). */
export function formatPrice(price?: string | null): string {
  if (!price) return "";
  return price
    .replace(/&nbsp;/g, " ")
    .replace(/&euro;/g, "€")
    .replace(/&pound;/g, "£")
    .replace(/&#36;/g, "$")
    .replace(/&amp;/g, "&")
    .replace(/<[^>]*>/g, "")
    .trim();
}

/**
 * Formatea un importe de la Store API (entero en unidades menores, p. ej.
 * "1000" con minorUnit 2 -> "10.00 EUR").
 */
export function formatStoreAmount(
  amount?: string | null,
  minorUnit = 2,
  currencyCode = "",
): string {
  if (amount == null || amount === "") return "";
  const n = Number(amount);
  if (Number.isNaN(n)) return amount;
  const value = (n / Math.pow(10, minorUnit)).toFixed(minorUnit);
  return currencyCode ? `${value} ${currencyCode}` : value;
}

/** Convierte un fragmento HTML simple en texto plano (para tarjetas/excerpts). */
export function stripHtml(html?: string | null): string {
  if (!html) return "";
  return html
    .replace(/<[^>]*>/g, "")
    .replace(/&hellip;/g, "…")
    .replace(/&nbsp;/g, " ")
    .replace(/&amp;/g, "&")
    .trim();
}

/** Formatea una fecha ISO en español. */
export function formatDate(iso: string): string {
  try {
    return new Intl.DateTimeFormat("es-ES", {
      day: "numeric",
      month: "long",
      year: "numeric",
    }).format(new Date(iso));
  } catch {
    return iso;
  }
}
