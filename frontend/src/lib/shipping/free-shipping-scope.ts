import { categoryHref } from "@/lib/catalog/product-href";
import { localeSuffix } from "@/lib/i18n/pick-localized";

/**
 * A qué aplica el envío gratis, listo para pintar en un idioma: las categorías
 * elegidas en el panel (cada una con toda su descendencia, que también suma) y
 * los productos sueltos. Puro: lo comparten el BFF, que lo arma, y el «?» del
 * aviso del carrito, que lo pinta.
 */
export interface FreeShippingScopeCategory {
  name: string;
  /** Ruta INTERNA de la categoría en la tienda, con el tramo de su idioma. */
  path: string;
  children: { name: string; path: string }[];
}

export interface FreeShippingScope {
  categories: FreeShippingScopeCategory[];
  products: string[];
  /** Ciudades que admiten el envío gratis, por su nombre. */
  cities: string[];
}

export const EMPTY_FREE_SHIPPING_SCOPE: FreeShippingScope = {
  categories: [],
  products: [],
  cities: [],
};

/** Cuántas ciudades se nombran en el aviso antes de «y N más». */
export const CITIES_INLINE_LIMIT = 4;

/**
 * Qué ciudades nombra el aviso: todas si caben; si no, las primeras y cuántas
 * quedan (nunca «y 1 más»: ahí cabe la propia ciudad).
 */
export function summarizeCities(
  cities: string[],
  expanded = false,
): { shown: string[]; hidden: number } {
  if (expanded || cities.length <= CITIES_INLINE_LIMIT) {
    return { shown: cities, hidden: 0 };
  }
  const shown = cities.slice(0, CITIES_INLINE_LIMIT - 1);
  return { shown, hidden: cities.length - shown.length };
}

function text(value: unknown): string {
  return typeof value === "string" ? value.trim() : "";
}

/** Traducción de `entry` al idioma (mapa `translations` por sufijo de idioma), campo a campo. */
function translation(
  entry: Record<string, unknown>,
  locale: string,
): { name: string; slug: string } {
  const all = entry.translations;
  const mine =
    all && typeof all === "object" && !Array.isArray(all)
      ? (all as Record<string, unknown>)[localeSuffix(locale)]
      : undefined;
  if (!mine || typeof mine !== "object") return { name: "", slug: "" };
  const fields = mine as Record<string, unknown>;
  return { name: text(fields.name), slug: text(fields.slug) };
}

function localizedTerm(
  raw: unknown,
  locale: string,
): { name: string; path: string } | null {
  if (!raw || typeof raw !== "object") return null;
  const entry = raw as Record<string, unknown>;
  const slug = text(entry.slug);
  const name = text(entry.name);
  if (slug === "" || name === "") return null;
  // Campo a campo: una traducción a medias cae al idioma por defecto sin romper el resto.
  const translated = translation(entry, locale);
  return {
    name: translated.name || name,
    path: categoryHref({ slug, urlSlug: translated.slug || null }),
  };
}

/**
 * Normaliza el payload crudo de `GET /wp-json/hwe-shipping/v1/free-shipping/scope`
 * al idioma pedido (cualquiera de los del sitio).
 */
export function normalizeFreeShippingScope(
  raw: unknown,
  locale: string,
): FreeShippingScope {
  if (!raw || typeof raw !== "object") return EMPTY_FREE_SHIPPING_SCOPE;
  const { categories, products, cities } = raw as Record<string, unknown>;

  const outCategories: FreeShippingScopeCategory[] = [];
  for (const entry of Array.isArray(categories) ? categories : []) {
    const term = localizedTerm(entry, locale);
    if (!term) continue;
    const rawChildren = (entry as Record<string, unknown>).children;
    const children = (Array.isArray(rawChildren) ? rawChildren : [])
      .map((child) => localizedTerm(child, locale))
      .filter((child): child is { name: string; path: string } => child !== null);
    outCategories.push({ ...term, children });
  }

  const outProducts: string[] = [];
  for (const entry of Array.isArray(products) ? products : []) {
    if (!entry || typeof entry !== "object") continue;
    const product = entry as Record<string, unknown>;
    const name = translation(product, locale).name || text(product.name);
    if (name !== "") outProducts.push(name);
  }

  const outCities = (Array.isArray(cities) ? cities : [])
    .map(text)
    .filter((city) => city !== "");

  return { categories: outCategories, products: outProducts, cities: outCities };
}
