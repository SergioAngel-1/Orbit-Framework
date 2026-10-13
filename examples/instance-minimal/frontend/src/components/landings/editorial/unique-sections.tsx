import type { ReactNode } from "react";
import { findConsumedSection } from "@/lib/landings/consumed-section";
import type { EditorialRegistryEntry } from "@/lib/landings/registry";
import type { EditorialLanding } from "@/lib/landings/types";

// ============================================================================
//  Secciones ÚNICAS de las landings con diseño propio — versión del ejemplo
//  (`examples/instance-minimal`). Sustituye al mapa vacío del framework.
//
//  La clave `example-callout` la declara `instanceRegistry.landings.staticEntries`
//  (datos); aquí vive su pieza de código. Absorbe la FAQ (`consumesBase: ["faq"]`):
//  la FAQ sale del RENDER pero no del DATO, así que la sección la lee de
//  `landing.faq` y se auto-oculta si el editor no la rellenó.
// ============================================================================

export interface UniqueSectionContext {
  landing: EditorialLanding;
  entry: EditorialRegistryEntry;
  locale: string;
}

export type UniqueSectionRenderer = (context: UniqueSectionContext) => ReactNode;

export const UNIQUE_SECTIONS: Record<string, UniqueSectionRenderer> = {
  "example-callout": ({ landing }) => {
    // Lee por SLOT, no por posición: con `s1` apagado desde el panel, no hay nada que mostrar.
    const first = findConsumedSection(landing.sections, "s1");
    if (!first && !landing.faq) return null;
    return (
      <aside
        data-testid="example-callout"
        className="mx-auto my-8 max-w-3xl rounded-xl border border-brand p-6"
      >
        <p className="font-semibold">Sección única de ejemplo</p>
        {landing.faq && <p>Preguntas que absorbe: {landing.faq.items.length}</p>}
      </aside>
    );
  },
};

/** Los nodos de las secciones únicas que declara `entry`, por clave (las sin pieza se omiten). */
export function renderUniqueSections(
  context: UniqueSectionContext,
): Record<string, ReactNode> {
  const out: Record<string, ReactNode> = {};
  for (const { key } of context.entry.uniqueSections) {
    const node = UNIQUE_SECTIONS[key]?.(context);
    if (node) out[key] = node;
  }
  return out;
}
