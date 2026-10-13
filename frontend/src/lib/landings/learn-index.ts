// ============================================================================
//  Agrupación de las landings de PRODUCTO en el índice del área educativa
//  (`/learn`).
//
//  Enumerar las fichas en una sola rejilla alfabética no dice que unas son una
//  crema y otras la misma familia. Agrupar por la categoría CANÓNICA del producto
//  —la misma que ya fija su URL— da el corte sin inventar una taxonomía paralela:
//  el rótulo de cada grupo es el nombre real del término en WooCommerce.
//
//  Vive en `lib/` y no en la vista por la regla de frontera: es lógica de
//  dominio, es pura y por tanto testeable sin la pila WP.
// ============================================================================

export interface LearnProductGroup<T> {
  /** Slug de la categoría canónica; sirve de `key` y de ancla. */
  slug: string;
  /** Nombre del término tal cual lo publica WooCommerce. */
  name: string;
  items: T[];
}

export interface LearnGroupResolvers<T> {
  /** Categoría canónica del ítem (la que ya decide su URL). */
  category: (item: T) => string;
  /** Nombre visible de una categoría. Vacío ⇒ se usa el propio slug. */
  name: (slug: string) => string;
  /** Título del ítem, para ordenar dentro del grupo. */
  title: (item: T) => string;
}

/**
 * Reparte los productos en grupos ordenados.
 *
 * · Los grupos siguen `order` (la decisión editorial de la instancia:
 *   `instanceRegistry.landings.productGroupOrder`); el resto va detrás por nombre. No se deduce
 *   del catálogo: por tamaño de grupo saldría al revés.
 * · Dentro de cada grupo, orden alfabético por título con la colación del idioma.
 * · Un grupo vacío no existe: solo se crean los que tienen productos.
 *
 * Basta con nombrar la cabeza: lo que NO esté en `order` no desaparece.
 */
export function groupLearnProducts<T>(
  items: readonly T[],
  resolve: LearnGroupResolvers<T>,
  order: readonly string[] = [],
  locale = "es",
): LearnProductGroup<T>[] {
  const byCategory = new Map<string, T[]>();
  for (const item of items) {
    const slug = resolve.category(item);
    const bucket = byCategory.get(slug);
    if (bucket) bucket.push(item);
    else byCategory.set(slug, [item]);
  }

  return [...byCategory.entries()]
    .map(([slug, groupItems]) => ({
      slug,
      name: resolve.name(slug).trim() || slug,
      items: [...groupItems].sort((a, b) =>
        resolve.title(a).localeCompare(resolve.title(b), locale),
      ),
    }))
    .sort((a, b) => {
      // `indexOf` devuelve -1 para lo no curado: se manda al final con un rango mayor que
      // cualquier posición de la lista (en -1 sería el PRIMERO).
      const rankA = order.indexOf(a.slug);
      const rankB = order.indexOf(b.slug);
      const rankedA = rankA === -1 ? order.length : rankA;
      const rankedB = rankB === -1 ? order.length : rankB;
      if (rankedA !== rankedB) return rankedA - rankedB;
      return a.name.localeCompare(b.name, locale);
    });
}
