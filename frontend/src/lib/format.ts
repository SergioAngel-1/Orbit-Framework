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

export function stripHtml(html?: string | null): string {
  if (!html) return "";
  return html
    .replace(/<[^>]*>/g, "")
    .replace(/&hellip;/g, "…")
    .replace(/&nbsp;/g, " ")
    .replace(/&amp;/g, "&")
    .trim();
}

export function formatDate(iso: string, locale = "es"): string {
  try {
    return new Intl.DateTimeFormat(locale === "en" ? "en-US" : "es-ES", {
      day: "numeric",
      month: "long",
      year: "numeric",
    }).format(new Date(iso));
  } catch {
    return iso;
  }
}
