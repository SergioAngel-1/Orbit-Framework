import type { BaseSectionId, EditorialRegistryEntry } from "@/lib/landings/registry";

/**
 * El slot (`s1`…`s5`) junto al que se pinta la FAQ de una landing, o `null` si va como banda
 * propia al final (lo normal).
 *
 * Es el mismo criterio que `consumesBase` —la FAQ se retira del RENDER pero no del DATO—, y la
 * entrada lo declara con `faqAside`. El valor es el SLOT, nunca una posición: el editor puede
 * reordenar las secciones desde el panel.
 *
 * Que la FAQ no se pinte como banda no la saca de los datos estructurados: la ruta emite el
 * `FAQPage` desde `landing.faq`, la pinte quien la pinte.
 */
export function faqAsideSection(
  entry: Pick<EditorialRegistryEntry, "faqAside">,
): BaseSectionId | null {
  const slot = entry.faqAside;
  return slot && ["s1", "s2", "s3", "s4", "s5"].includes(slot) ? slot : null;
}
