import type { InstanceRegistry } from "@/lib/instance/types";

/**
 * Registro de LA INSTANCIA (una tienda concreta construida sobre el framework).
 *
 * Esta carpeta (`src/instance/`) es propiedad de la instancia: el framework nunca la
 * toca salvo este registro vacío inicial. Es el ÚNICO módulo de instancia que el
 * núcleo importa (la regla ESLint «Frontera núcleo/instancia» lo impone): todo lo
 * demás de la instancia (`instance/lib/`, vistas, componentes) solo lo usa la
 * propia instancia.
 *
 * Rellena aquí los datos que el núcleo sabe leer (ver `InstanceRegistry`):
 * - `revalidationTags`: tags ISR extra para `/api/revalidate` (p. ej. `["example"]`).
 *   El plugin de WordPress debe registrar el mismo tag con `hwe_revalidation_tags`.
 */
export const instanceRegistry: InstanceRegistry = {
  revalidationTags: [],
};
