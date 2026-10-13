/**
 * Ruta interna de una entrada del blog. `urlSlug` lo resuelven al locale las
 * lecturas de `lib/blog/posts.ts` (slug en inglés en /en, si lo hay); sin él
 * se usa el slug nativo, que la ficha en /en redirige al inglés.
 *
 * Módulo PURO: lo usan tarjetas de cliente y vistas de servidor.
 */
export function blogPostHref(post: { slug: string; urlSlug?: string | null }): string {
  return `/blog/${post.urlSlug || post.slug}`;
}
