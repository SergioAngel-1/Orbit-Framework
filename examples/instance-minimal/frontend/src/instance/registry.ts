import type { InstanceRegistry } from "@/lib/instance/types";

/**
 * Registro de la instancia de EJEMPLO (ver `examples/instance-minimal/README.md`).
 *
 * En el job «contrato» de CI este archivo se superpone al registro vacío del framework
 * para demostrar que una instancia puede declarar tags ISR propios (`/api/revalidate`
 * acepta `example` además de los tags del núcleo) y una landing con diseño propio.
 */
export const instanceRegistry: InstanceRegistry = {
  revalidationTags: ["example"],
  // Campos de producto declarativos: las mismas claves y tipos que `hwe_product_fields` del
  // backend (`mu-plugins/instance/50-example-product-field.php`).
  productFields: {
    example_usage: { type: "textarea", label: "Modo de uso" },
    example_weight: { type: "number", label: "Peso (g)" },
  },
  landings: {
    // La ruta `/example` de este ejemplo es de UN solo tramo: una landing de raíz no puede
    // ocuparlo (el test de segmentos reservados lo exige).
    reservedRootSegments: ["example"],
    staticEntries: {
      // Debe coincidir con `hwe_landings_static_entries` del backend
      // (`mu-plugins/instance/40-example-landing.php`).
      "example-guide": {
        label: "Guía de ejemplo",
        base: "area",
        jsonLd: "article",
        // La sección única `example-callout` va tras la sección 1 (`s1`) y absorbe la FAQ.
        uniqueSections: [{ key: "example-callout", after: "s1" }],
        consumesBase: ["faq"],
      },
    },
  },
};
