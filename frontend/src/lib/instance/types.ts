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
 * - `decorPresets` (módulo `banners`): visuales decorativos de plantilla.
 * - `landings` (tarea 58), `productFields` (tarea 60).
 */

/** Política de inversión de un visual decorativo en tema oscuro (ver `lib/banners/decor-presets`). */
export type DecorInvert = "theme" | "light" | "never";

/** Un visual decorativo de plantilla de un slide de banner. */
export interface DecorPreset {
  /** Ruta pública del archivo (SVG) en `frontend/public`. */
  src: string;
  /** Por defecto, `theme`. */
  invert?: DecorInvert;
}

export interface InstanceRegistry {
  /** Tags ISR extra que `/api/revalidate` acepta además de los del núcleo. */
  revalidationTags: string[];
  /**
   * Manifiesto de las tarjetas sociales estáticas de la instancia
   * (`"<vista>.<idioma>" → hash`), que escribe `npm run og:generate`. Vacío =
   * todas las vistas usan la tarjeta dinámica de marca.
   */
  ogCards?: Record<string, string>;
  /**
   * Visuales decorativos de plantilla de los slides de banner: clave → archivo. El orden
   * importa (el primero es el respaldo) y las claves deben coincidir con las del filtro
   * PHP `hwe_banners_decor_presets`.
   */
  decorPresets?: Record<string, DecorPreset>;
}
