// ============================================================================
//  JSON-LD `CollectionPage` + `ItemList` para los listados de productos.
//  Funciones puras.
// ============================================================================

/** Tope de ítems en cualquier ItemList de catálogo (tienda/categoría/colecciones). */
export const COLLECTION_ITEM_LIST_LIMIT = 24;

export interface CollectionPageItem {
  name: string;
  url: string;
  image?: string | null;
}

export interface CollectionPageJsonLdInput {
  name: string;
  description?: string;
  url: string;
  items: CollectionPageItem[];
  /** Total real cuando `items` viene acotado a `COLLECTION_ITEM_LIST_LIMIT`. */
  totalItems?: number;
}

/** JSON-LD `CollectionPage` + `ItemList` para listados de productos. */
export function buildCollectionPageJsonLd({
  name,
  description,
  url,
  items,
  totalItems,
}: CollectionPageJsonLdInput): Record<string, unknown> {
  return {
    "@context": "https://schema.org",
    "@type": "CollectionPage",
    name,
    ...(description ? { description } : {}),
    url,
    mainEntity: {
      "@type": "ItemList",
      numberOfItems: totalItems ?? items.length,
      itemListElement: items.map((item, i) => ({
        "@type": "ListItem",
        position: i + 1,
        name: item.name,
        url: item.url,
        ...(item.image ? { image: item.image } : {}),
      })),
    },
  };
}
