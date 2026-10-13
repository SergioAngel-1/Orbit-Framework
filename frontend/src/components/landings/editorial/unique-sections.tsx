import type { ReactNode } from "react";
import type { EditorialRegistryEntry } from "@/lib/landings/registry";
import type { EditorialLanding } from "@/lib/landings/types";

// ============================================================================
//  Secciones ÚNICAS de las landings con diseño propio (de la instancia).
//
//  El núcleo solo conoce las CLAVES (`instanceRegistry.landings.staticEntries.<id>
//  .uniqueSections`): datos, nunca componentes. Este archivo es el mapa
//  `clave → pieza de código` y es de la INSTANCIA: vacío en el framework. Una
//  instancia lo rellena (ver `examples/instance-minimal`).
//
//  Cada pieza recibe la landing ya resuelta al idioma y devuelve un nodo (o
//  `null` para auto-ocultarse: p. ej. el editor apagó el slot del que lee). Para
//  leer una sección base que la única absorbe (`consumesBase`) usa
//  `findConsumedSection(landing.sections, "s3")`: busca por SLOT, no por posición.
// ============================================================================

export interface UniqueSectionContext {
  landing: EditorialLanding;
  entry: EditorialRegistryEntry;
  locale: string;
}

export type UniqueSectionRenderer = (context: UniqueSectionContext) => ReactNode;

/** `clave → pieza`. Vacío en el framework. */
export const UNIQUE_SECTIONS: Record<string, UniqueSectionRenderer> = {};

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
