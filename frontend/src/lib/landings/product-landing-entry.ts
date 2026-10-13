import type { EditorialRegistryEntry } from "@/lib/landings/registry";

// ============================================================================
//  La entrada del registro de una landing editorial ASOCIADA A UN PRODUCTO.
//
//  Esta vía no tiene URL libre (el registro de URL libre la excluye), pero su
//  ENTRADA sí está en el registro «sin `editorial`»: con ella viajan sus secciones
//  únicas y lo que absorben, declarados por la instancia como en cualquier
//  landing con diseño propio. Aquí solo se elige la entrada del identificador o,
//  si no hay, un molde genérico (una landing sin secciones únicas es válida y usa
//  el molde completo).
//
//  OJO: un ancla `after` que apunte a un slot CONSUMIDO no falla al construir:
//  `resolveSections` no lo encuentra y empuja la sección al FINAL del flujo, en
//  silencio (`unplacedAnchors` lo detecta).
// ============================================================================

/**
 * `registry` es el registro SIN `editorial` (la forma de 1 argumento de
 * `buildEditorialRegistry`); `landingSlug` es el IDENTIFICADOR de la landing, no el slug del
 * producto: coinciden a menudo, pero no tienen por qué.
 */
export function productLandingEntry(
  registry: Record<string, EditorialRegistryEntry>,
  landingSlug: string,
  label: string,
): EditorialRegistryEntry {
  const known = registry[landingSlug];
  if (known) return known;
  return {
    slug: landingSlug,
    // La URL de esta vía NO sale de `base` y `path`: la resuelve `resolveProductLearnHref`. Se
    // rellenan por contrato del tipo.
    path: landingSlug,
    paths: {},
    base: "area",
    label,
    jsonLd: "article",
    uniqueSections: [],
    consumesBase: [],
    faqAside: null,
    seoFallback: null,
    hints: {},
    dynamic: true,
  };
}
