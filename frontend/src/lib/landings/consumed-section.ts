import type { LandingContentSection } from "@/lib/landings/types";

// ============================================================================
//  Localiza la sección de contenido que una sección ÚNICA absorbe
//  (`consumesBase` en la entrada del registro).
//
//  Se busca por SLOT (`s1`…`s5`), nunca por posición: `landing.sections` viene
//  filtrada por `enabled` y ordenada por `order`, así que el índice no dice nada
//  estable sobre qué escribió el editor dónde.
//
//  `consumesBase` retira la sección del RENDER pero no del DATO, así que aquí
//  sigue estando. Si el editor desactiva el slot, no llega y se devuelve `null`:
//  la sección única se auto-oculta en vez de pintar un hueco.
// ============================================================================

export function findConsumedSection(
  sections: LandingContentSection[],
  slot: LandingContentSection["id"],
): LandingContentSection | null {
  return sections.find((section) => section.id === slot) ?? null;
}
