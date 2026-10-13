import type { BaseSectionId, EditorialRegistryEntry } from "@/lib/landings/registry";

export type RenderableSection =
  { id: BaseSectionId } | { id: `unique:${string}`; key: string };

/**
 * El flujo de render de una landing: las secciones base menos las que una única absorbe, con las
 * únicas insertadas tras su ancla.
 *
 * `consumesBase` filtra ANTES de resolver las anclas `after`: si fuera después, `after` apuntaría
 * a un índice desplazado por las bases ya retiradas. Un ancla que apunte a un slot CONSUMIDO no
 * falla: la sección se empuja al FINAL del flujo, en silencio (ver `unplacedAnchors`).
 */
export function resolveSections(
  base: { id: BaseSectionId }[],
  entry: Pick<EditorialRegistryEntry, "consumesBase" | "uniqueSections">,
): RenderableSection[] {
  const consumed = new Set<BaseSectionId>(entry.consumesBase ?? []);
  const out: RenderableSection[] = base.filter((section) => !consumed.has(section.id));
  // Cuántas únicas se han insertado ya para cada ancla: sin este contador, cada
  // `splice(at + 1, …)` mete la nueva DELANTE de las anteriores y el orden de declaración sale
  // invertido (visible con 2+ únicas sobre la misma `after`).
  const placed = new Map<BaseSectionId, number>();
  for (const unique of entry.uniqueSections) {
    const at = out.findIndex(
      (section) => "id" in section && section.id === unique.after,
    );
    const node: RenderableSection = { id: `unique:${unique.key}`, key: unique.key };
    if (at === -1) {
      out.push(node);
      continue;
    }
    const offset = placed.get(unique.after) ?? 0;
    out.splice(at + 1 + offset, 0, node);
    placed.set(unique.after, offset + 1);
  }
  return out;
}

/**
 * Las secciones únicas cuya ancla no está en el flujo (consumida o ausente) y que por tanto van
 * al final. No es un error, pero casi siempre es un descuido de quien declara la entrada: sirve
 * para avisarlo (test de la instancia o log).
 */
export function unplacedAnchors(
  base: { id: BaseSectionId }[],
  entry: Pick<EditorialRegistryEntry, "consumesBase" | "uniqueSections">,
): string[] {
  const consumed = new Set<BaseSectionId>(entry.consumesBase ?? []);
  const available = new Set(base.filter((s) => !consumed.has(s.id)).map((s) => s.id));
  return entry.uniqueSections.filter((u) => !available.has(u.after)).map((u) => u.key);
}
