import type { InstanceRegistry } from "@/lib/instance/types";

/**
 * Registro de la instancia de EJEMPLO (ver `examples/instance-minimal/README.md`).
 *
 * En el job «contrato» de CI este archivo se superpone al registro vacío del framework
 * para demostrar que una instancia puede declarar tags ISR propios: `/api/revalidate`
 * acepta `example` además de los tags del núcleo.
 */
export const instanceRegistry: InstanceRegistry = {
  revalidationTags: ["example"],
};
