import type { BaseSectionId, EditorialRegistryEntry } from "@/lib/landings/registry";
import { faqAsideSection } from "@/lib/landings/faq-aside";
import type { EditorialLanding } from "@/lib/landings/types";

// ============================================================================
//  El flujo BASE de una landing editorial: los slots que tienen contenido, en el
//  orden del molde. Sobre él, `resolveSections` retira lo que una sección única
//  absorbe e inserta las únicas en su ancla.
//
//    hero → entradilla → secciones (por `order`) → cita → preguntas → cierre
//
//  La banda de productos se inserta tras el slot que el panel elige
//  (`products.after`, por defecto la FAQ) y no antes del hero ni después del
//  cierre: un ancla que no está en el flujo la deja justo antes del cierre.
//
//  Módulo PURO.
// ============================================================================

type FlowSource = Pick<
  EditorialLanding,
  "lead" | "sections" | "quote" | "faq" | "products" | "closing"
>;

/**
 * La FAQ junto a una sección (`faqAside`): solo cuenta si hay FAQ con preguntas Y el slot está
 * activo. Con el slot apagado desde el panel la FAQ vuelve a su banda del final en vez de
 * desaparecer de la página.
 */
export function faqAsideFor(
  landing: Pick<EditorialLanding, "faq" | "sections">,
  entry: Pick<EditorialRegistryEntry, "faqAside">,
): BaseSectionId | null {
  const slot = faqAsideSection(entry);
  if (!slot || !landing.faq) return null;
  return landing.sections.some((section) => section.id === slot) ? slot : null;
}

export function buildBaseFlow(
  landing: FlowSource,
  entry: Pick<EditorialRegistryEntry, "faqAside">,
): { id: BaseSectionId }[] {
  const aside = faqAsideFor(landing, entry);
  const base: { id: BaseSectionId }[] = [
    { id: "hero" },
    ...(landing.lead ? [{ id: "lead" as const }] : []),
    ...landing.sections.map((section) => ({ id: section.id })),
    ...(landing.quote ? [{ id: "quote" as const }] : []),
    ...(landing.faq && !aside ? [{ id: "faq" as const }] : []),
    ...(landing.closing ? [{ id: "closing" as const }] : []),
  ];
  if (landing.products) {
    const anchor = base.findIndex((section) => section.id === landing.products!.after);
    const closing = base.findIndex((section) => section.id === "closing");
    const at = anchor !== -1 ? anchor + 1 : closing !== -1 ? closing : base.length;
    base.splice(at, 0, { id: "products" });
  }
  return base;
}
