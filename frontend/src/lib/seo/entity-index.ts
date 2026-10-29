import type { Metadata } from "next";

// ============================================================================
//  Controles de indexación POR ENTIDAD (caja SEO del editor, `hwe-seo`).
//
//  `seoNoindex`: la ficha/entrada/categoría sigue accesible por su URL, pero va
//  `noindex, follow` —`follow` mantiene el descubrimiento de lo que enlaza— y
//  sale del sitemap y de `llms.txt`. Sin `alternates`: bajo `noindex` el
//  buscador ignora la canónica y el hreflang solo añade ruido.
//
//  `seoCanonical`: el contenido principal vive en OTRA URL. La canónica apunta a
//  ella, sin hreflang (anunciar idiomas de una URL que cede su canónica es
//  contradictorio), y esta URL tampoco se anuncia.
// ============================================================================

export interface EntityIndexControls {
  seoNoindex?: boolean | null;
  seoCanonical?: string | null;
}

/** ¿La entidad se anuncia (sitemap, `llms.txt`, índices)? */
export function isEntityAnnounced(controls: EntityIndexControls): boolean {
  return !controls.seoNoindex && !controls.seoCanonical?.trim();
}

/** Robots de una entidad marcada `noindex`. */
export const ENTITY_NOINDEX_ROBOTS = { index: false, follow: true } as const;

/**
 * `alternates` y `robots` de una entidad a partir de los suyos por defecto. La
 * canónica manual solo se acepta absoluta y http(s) (el backend ya la sanea; aquí
 * se vuelve a comprobar porque un valor roto descarta la página entera).
 */
export function entityIndexMetadata<A extends { canonical: string }>(
  alternates: A,
  controls: EntityIndexControls,
): { alternates?: A | { canonical: string }; robots?: Metadata["robots"] } {
  if (controls.seoNoindex) return { robots: ENTITY_NOINDEX_ROBOTS };
  const manual = controls.seoCanonical?.trim();
  if (manual && /^https?:\/\/[^\s/]+/i.test(manual)) {
    return { alternates: { canonical: manual } };
  }
  return { alternates };
}

/**
 * `alternates` y `robots` de una página que CUELGA de una entidad (la landing
 * `/products/<slug>/learn` de una ficha) y hereda su caja SEO. Coherente con el
 * sitemap, que omite la landing cuando la ficha no se anuncia:
 *
 *  - ficha `noindex` → la landing, también (`noindex, follow`, sin `alternates`);
 *  - ficha con canónica manual → la landing NO hereda esa canónica (apuntaría a la
 *    ficha ajena, que no es su contenido) ni se anuncia como indexable: `noindex,
 *    follow`, sin `alternates`. Inventar una canónica propia contradiría a la ficha.
 */
export function inheritedIndexMetadata<A>(
  alternates: A,
  parent: EntityIndexControls,
): { alternates?: A; robots?: Metadata["robots"] } {
  return isEntityAnnounced(parent) ? { alternates } : { robots: ENTITY_NOINDEX_ROBOTS };
}

/**
 * Los controles tal como llegan de GraphQL, sin claves vacías: una entidad sin
 * marcar no arrastra `seoNoindex: false` / `seoCanonical: null` por todo el código.
 */
export function readIndexControls(node: {
  seoNoindex?: boolean | null;
  seoCanonical?: string | null;
}): EntityIndexControls {
  const canonical = node.seoCanonical?.trim();
  return {
    ...(node.seoNoindex ? { seoNoindex: true } : {}),
    ...(canonical ? { seoCanonical: canonical } : {}),
  };
}
