import type { ProductUrlEntry } from "./products";

// ============================================================================
//  Agrupación del índice del catálogo (`/products/archive`) por inicial.
//  Lógica PURA: la página solo pinta lo que sale de aquí.
// ============================================================================

export interface ArchiveProduct {
  slug: string;
  urlSlug: string;
  name: string;
}

export interface ArchiveGroup {
  /** Letra (sin tilde, en mayúscula) o `#` para lo que no empieza por letra. */
  initial: string;
  products: ArchiveProduct[];
}

function initialOf(name: string, locale: string): string {
  const first = name.trim().charAt(0).normalize("NFD").charAt(0);
  return /\p{L}/u.test(first) ? first.toLocaleUpperCase(locale) : "#";
}

/** Fichas en el idioma de la página, por orden alfabético y agrupadas por inicial. */
export function groupProductsByInitial(
  entries: readonly ProductUrlEntry[],
  locale: string,
): ArchiveGroup[] {
  const collator = new Intl.Collator(locale, { sensitivity: "base", numeric: true });
  const products = entries
    .map((entry) => ({
      slug: entry.slug,
      urlSlug: entry.slugs[locale] ?? entry.slug,
      name: (entry.names[locale] ?? entry.slug).trim() || entry.slug,
    }))
    .sort((a, b) => collator.compare(a.name, b.name));

  const groups: ArchiveGroup[] = [];
  for (const product of products) {
    const initial = initialOf(product.name, locale);
    const last = groups[groups.length - 1];
    if (last && last.initial === initial) last.products.push(product);
    else groups.push({ initial, products: [product] });
  }
  // `#` (cifras, símbolos) al final, no intercalado por el orden de la colación.
  return [
    ...groups.filter((g) => g.initial !== "#"),
    ...groups.filter((g) => g.initial === "#"),
  ];
}
