/**
 * Contrato de datos núcleo ↔ instancia (frontend).
 *
 * `InstanceRegistry` describe TODO lo que el núcleo puede leer de la instancia:
 * solo datos, nunca componentes de React. La instancia lo rellena en
 * `src/instance/registry.ts`, que es el ÚNICO módulo de instancia que el núcleo
 * importa (ver la regla ESLint «Frontera núcleo/instancia»).
 *
 * Campos que existirán (cada uno lo añade su tarea):
 * - `revalidationTags` (esta tarea): tags ISR extra para `/api/revalidate`.
 * - `landings` (tarea 58), `productFields` (tarea 60), `decorPresets` (tarea 55).
 */
export interface InstanceRegistry {
  /** Tags ISR extra que `/api/revalidate` acepta además de los del núcleo. */
  revalidationTags: string[];
  /**
   * Manifiesto de las tarjetas sociales estáticas de la instancia
   * (`"<vista>.<idioma>" → hash`), que escribe `npm run og:generate`. Vacío =
   * todas las vistas usan la tarjeta dinámica de marca.
   */
  ogCards?: Record<string, string>;
}
