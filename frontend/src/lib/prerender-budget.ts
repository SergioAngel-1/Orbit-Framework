// ============================================================================
//  Qué se hornea en `next build` y qué se deja para ISR bajo demanda.
//
//  ── El problema ────────────────────────────────────────────────────────────
//  Un catálogo grande (cientos de fichas y entradas, en dos idiomas) son miles
//  de páginas. El prerender consulta al MISMO WordPress que, en una máquina
//  pequeña, comparte CPU con el propio build: cuanto más trabaja el builder,
//  más lento responde el CMS y más tarda el builder. Un build de ese tamaño en
//  un VPS de 2 vCPU no termina en un tiempo razonable.
//
//  ── Por qué la cola larga gana poco estando horneada ───────────────────────
//  Las fichas y entradas llevan `revalidate`: a los pocos minutos de desplegar
//  ya están obsoletas y se regeneran bajo demanda de todos modos. Hornearlas
//  compra frescura durante unos minutos, a cambio de mucho tiempo de build. Y no
//  hay coste de SEO: el sitemap NO sale de `generateStaticParams`.
//
//  ── Lo que se paga ─────────────────────────────────────────────────────────
//  El PRIMER visitante de una página no horneada espera a que se genere; una
//  vez, no por visitante.
//
//  ── Qué SÍ se sigue horneando ──────────────────────────────────────────────
//  Solo se recorta la COLA LARGA (fichas de producto y entradas de blog). Las
//  páginas de entrada —home, tienda, categorías, legales— siguen
//  prerenderizándose: son pocas y las que recibe primero el tráfico frío.
// ============================================================================

/** Cómo se trata la cola larga en `generateStaticParams`. */
export type PrerenderPolicy = "none" | "all";

/**
 * Política de prerender de la cola larga, leída de `PRERENDER_LONG_TAIL`.
 *
 * - `"all"` (por defecto) → se hornea el catálogo entero. Es lo que cabe en un
 *   build holgado y una tienda de tamaño medio.
 * - `"none"` → `generateStaticParams` devuelve lista vacía y todo se genera bajo
 *   demanda con ISR. Recomendado para un catálogo grande o una máquina justa.
 *
 * Cualquier otro valor cae al defecto: un valor mal escrito no debe cambiar en
 * silencio cómo se despliega.
 */
export function prerenderPolicy(
  env: Record<string, string | undefined> = process.env,
): PrerenderPolicy {
  return env.PRERENDER_LONG_TAIL?.trim().toLowerCase() === "none" ? "none" : "all";
}

/**
 * Params de la cola larga según la política: con `"none"` devuelve lista vacía
 * **sin llamar a `load`**. Recibe el cargador y no la lista ya resuelta, y el
 * orden importa: consultar el catálogo entero para que la política lo tire a la
 * basura es justo el coste que esto evita.
 */
export async function prerenderLongTail<T>(
  load: () => Promise<T[]>,
  env: Record<string, string | undefined> = process.env,
): Promise<T[]> {
  return prerenderPolicy(env) === "none" ? [] : load();
}
