<?php

namespace HWE\ControlCenter\SchemaGroups;

/**
 * Esquema del núcleo: fidelización, grupo reservado al módulo `loyalty`.
 *
 * Trait de `HWE\ControlCenter\Schema`: el árbol de configuración se reparte por tema para que
 * cada archivo se lea de una vez. `Schema::core()` los junta en el orden de las pestañas.
 */
trait Loyalty {

    /**
     * @return array<string,array>
     */
    private static function loyaltyGroups(): array {
        return [

            /* ------------------------------------------------------------------ */
            /* FIDELIZACIÓN                                                        */
            /* ------------------------------------------------------------------ */
            // Grupo RESERVADO al módulo `loyalty`: sin él no hay nada que configurar y la
            // pestaña no se pinta. El módulo añade aquí sus ajustes con
            // `hwe_control_center_schema` (no van en `instance`: una clave raíz ajena al
            // núcleo se descarta).
            'loyalty' => [
                'type'     => 'group',
                'label'    => 'Fidelización',
                'children' => [],
            ],

        ];
    }
}
