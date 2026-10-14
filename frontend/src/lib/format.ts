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

/**
 * Caracteres invisibles que ensucian el texto importado de otra web: controles
 * C0, DEL, zero-width (\u200B-\u200D) y BOM (\uFEFF).
 */
const INVISIBLE_CHARS =
  /[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F\u200B-\u200D\uFEFF]/g;

/**
 * Quita las secuencias de escape "\r"/"\n"/"\t" que una importación guardó COMO
 * TEXTO LITERAL (barra invertida + letra, no saltos de línea reales) y los
 * caracteres invisibles. A diferencia de `cleanExcerpt`, NO llama a
 * `stripHtml` ni colapsa el blanco real: es seguro de aplicar sobre HTML rico
 * (p. ej. antes de `sanitizeHtml()`) sin destruir las etiquetas.
 */
function stripLiteralEscapes(text: string): string {
  return text
    .replace(/\\[rnt]/g, " ")
    .replace(INVISIBLE_CHARS, "")
    .replace(/ {2,}/g, " ")
    .trim();
}

/**
 * Límites de bloque del HTML de WordPress → saltos de línea reales.
 *
 * `wpautop` publica los párrafos como `<p>` y los saltos simples como `<br>`.
 * Quitar las etiquetas a pelo con `stripHtml` pega el final de un bloque con el
 * principio del siguiente, así que la separación que el autor escribió
 * desaparece ANTES de que nadie pueda decidir qué hacer con ella.
 */
function blocksToNewlines(html: string): string {
  return html.replace(/<\/p>\s*<p[^>]*>/gi, "\n\n").replace(/<br\s*\/?>/gi, "\n");
}

/**
 * Separador de bloques en un resumen de UNA línea. Los saltos de la descripción
 * son decisión editorial: colapsarlos a un espacio convertía una etiqueta corta
 * («Cualidad: Calma») y su cuerpo en una frase corrida. Es un salto REAL y no un
 * signo de puntuación, así que es compatible hacia atrás: en HTML el blanco
 * colapsa y quien no pida nada ve un espacio; quien quiera la estructura añade
 * `whitespace-pre-line`. Vive aquí y no en cada tarjeta para que todas las
 * vistas (ficha, catálogo, blog, `<meta description>`) coincidan.
 */
export const EXCERPT_SEPARATOR = "\n";

/**
 * Resumen listo para pintar en una tarjeta o bajo el título.
 *
 * El HTML del editor trae etiquetas (`<strong>`, `<em>`), entidades sin
 * decodificar, saltos `\r\n\r\n` entre párrafos y algún carácter de ancho cero.
 * Sin limpiar, la tarjeta muestra texto con huecos raros; sin truncar, rompe la
 * retícula. Los bloques se unen con {@link EXCERPT_SEPARATOR}. Trunca por
 * palabra completa (nunca a mitad de palabra, salvo que no haya ningún blanco) y
 * no deja puntuación colgando antes de los puntos suspensivos.
 *
 * @param html     Texto o HTML de origen.
 * @param maxChars Longitud máxima del resultado sin contar los puntos suspensivos.
 */
export function cleanExcerpt(html?: string | null, maxChars = 120): string {
  if (!html) return "";

  const text = stripLiteralEscapes(stripHtml(blocksToNewlines(html)))
    .replace(/\u00a0/g, " ")
    .split(/\r?\n/)
    .map((line) => line.replace(/\s+/g, " ").trim())
    .filter((line) => line !== "")
    .join(EXCERPT_SEPARATOR);

  if (text === "") return "";
  if (text.length <= maxChars) return text;

  // El corte busca el último BLANCO, no el último " ": con el salto de bloque
  // como separador, un `lastIndexOf(" ")` se lo saltaba y podía partir la
  // primera palabra del cuerpo por la mitad.
  const cut = text.slice(0, maxChars);
  const lastBreak = cut.search(/\s(?=\S*$)/);
  const base = lastBreak > 0 ? cut.slice(0, lastBreak) : cut;

  return base.replace(/[\s.,;:–—-]+$/u, "") + "…";
}

/**
 * Contenido de usuario (reseñas, comentarios) a texto plano, preservando los
 * saltos de línea como `\n`: WPGraphQL lo devuelve ya pasado por `wpautop`, y
 * sin esto se vería el HTML crudo. A diferencia de `cleanExcerpt`, no trunca ni
 * junta los bloques: pensado para `white-space: pre-line`.
 */
export function htmlToPlainText(html?: string | null): string {
  if (!html) return "";
  return stripHtml(blocksToNewlines(html));
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
