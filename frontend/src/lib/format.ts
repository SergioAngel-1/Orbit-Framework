const currencyFormatters = new Map<string, Intl.NumberFormat>();

/**
 * Extrae un número de un importe (número, "58000", "$58.000", HTML Woo, etc.).
 * Soporta miles con punto (`58.000`) y decimales con coma.
 */
export function parseMoneyAmount(value?: string | number | null): number | null {
  if (value == null || value === "") return null;
  if (typeof value === "number") {
    return Number.isFinite(value) ? value : null;
  }

  let s = value
    .replace(/&nbsp;/g, " ")
    .replace(/&euro;/g, "€")
    .replace(/&pound;/g, "£")
    .replace(/&#36;/g, "$")
    .replace(/&amp;/g, "&")
    .replace(/<[^>]*>/g, "")
    .trim();

  s = s
    .replace(/\b(?:COP|USD|EUR)\b/gi, "")
    .replace(/[$€£]/g, "")
    .trim();
  if (!/\d/.test(s)) return null;

  if (/^\d{1,3}(\.\d{3})+(,\d+)?$/.test(s)) {
    s = s.replace(/\./g, "").replace(",", ".");
  } else if (/^\d+,\d+$/.test(s)) {
    s = s.replace(",", ".");
  } else {
    s = s.replace(/\s/g, "").replace(/,(?=\d{3}(\D|$))/g, "");
  }

  const cleaned = s.replace(/[^\d.-]/g, "");
  if (!cleaned || cleaned === "-" || cleaned === "." || cleaned === "-.") {
    return null;
  }
  const n = Number(cleaned);
  return Number.isFinite(n) ? n : null;
}

/**
 * Formatea un importe (en unidades mayores) con Intl, en el idioma de la página.
 *
 * `decimals` son los decimales de la tienda: los de WooCommerce
 * (`config.ecommerce.price_decimals`) para precios de catálogo y el
 * `currency_minor_unit` de la Store API para importes del carrito. Sin ellos,
 * los de Intl para esa moneda. El núcleo no conoce ninguna moneda en concreto:
 * una tienda que cobra sin decimales lo configura en WooCommerce.
 */
export function formatMoney(
  amount: number,
  currencyCode: string,
  locale: string,
  decimals?: number | null,
): string {
  const code = currencyCode.toUpperCase();
  const digits =
    typeof decimals === "number" &&
    Number.isInteger(decimals) &&
    decimals >= 0 &&
    decimals <= 20
      ? decimals
      : undefined;
  const key = `${locale}|${code}|${digits ?? ""}`;
  try {
    let formatter = currencyFormatters.get(key);
    if (!formatter) {
      formatter = new Intl.NumberFormat(locale, {
        style: "currency",
        currency: code,
        ...(digits !== undefined
          ? { minimumFractionDigits: digits, maximumFractionDigits: digits }
          : {}),
      });
      currencyFormatters.set(key, formatter);
    }
    return formatter.format(amount);
  } catch {
    // Código de moneda o locale que Intl no conoce: mejor un importe legible que nada.
    return `${amount.toFixed(digits ?? 2)} ${code}`;
  }
}

/**
 * Precio de catálogo / pedidos WC REST (unidades mayores), vía {@link formatMoney}.
 * Si no se puede parsear un string, devuelve el texto limpio de HTML.
 */
export function formatPrice(
  price: string | number | null | undefined,
  currencyCode: string,
  locale: string,
  decimals?: number | null,
): string {
  if (price == null || price === "") return "";
  const n = parseMoneyAmount(price);
  if (n != null) return formatMoney(n, currencyCode, locale, decimals);
  if (typeof price === "number") return "";

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
 * Importe de Store API (unidades menores / céntimos). `minorUnit` convierte a
 * unidades mayores y es también el número de decimales que se muestran (la
 * Store API lo deriva de los decimales configurados en WooCommerce).
 */
export function formatStoreAmount(
  amount: string | null | undefined,
  minorUnit: number,
  currencyCode: string,
  locale: string,
): string {
  if (amount == null || amount === "") return "";
  const n = Number(amount);
  if (Number.isNaN(n)) return amount;

  const major = n / Math.pow(10, minorUnit);
  return formatMoney(major, currencyCode, locale, minorUnit);
}

export function stripHtml(html?: string | null): string {
  if (!html) return "";
  const decoded = html.replace(
    /&(#\d+|#x[0-9a-fA-F]+|[a-zA-Z]+);/g,
    (_, entity: string) => {
      if (entity.startsWith("#x") || entity.startsWith("#X")) {
        return String.fromCodePoint(parseInt(entity.slice(2), 16));
      }
      if (entity.startsWith("#")) {
        return String.fromCodePoint(parseInt(entity.slice(1), 10));
      }
      const map: Record<string, string> = {
        amp: "&",
        lt: "<",
        gt: ">",
        quot: '"',
        apos: "'",
        nbsp: " ",
        hellip: "…",
        mdash: "—",
        ndash: "–",
        laquo: "«",
        raquo: "»",
        ldquo: "“",
        rdquo: "”",
        lsquo: "‘",
        rsquo: "’",
        bull: "•",
        middot: "·",
        copy: "©",
        reg: "®",
        trade: "™",
        aacute: "á",
        eacute: "é",
        iacute: "í",
        oacute: "ó",
        uacute: "ú",
        Aacute: "Á",
        Eacute: "É",
        Iacute: "Í",
        Oacute: "Ó",
        Uacute: "Ú",
        ntilde: "ñ",
        Ntilde: "Ñ",
        uuml: "ü",
        Uuml: "Ü",
        iexcl: "¡",
        iquest: "¿",
      };
      return map[entity] ?? `&${entity};`;
    },
  );
  return decoded.replace(/<[^>]*>/g, "").trim();
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
