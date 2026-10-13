<?php

namespace HWE\ControlCenter\SchemaGroups;

/**
 * Esquema del núcleo: landings, grupo reservado al módulo `landings`.
 *
 * Trait de `HWE\ControlCenter\Schema`: el árbol de configuración se reparte por tema para que
 * cada archivo se lea de una vez. `Schema::core()` los junta en el orden de las pestañas.
 */
trait Landings {

    /**
     * @return array<string,array>
     */
    private static function landingGroups(): array {
        return [

            /* ------------------------------------------------------------------ */
            /* LANDINGS                                                            */
            /* ------------------------------------------------------------------ */
            // Grupo RESERVADO al módulo `landings`: sin él no hay nada que configurar y la
            // pestaña no se pinta. El módulo añade aquí el registro de landings y el contenido
            // de cada una con `hwe_control_center_schema` (no van en `instance`: una clave raíz
            // ajena al núcleo se descarta).
            'landings' => [
                'type'     => 'group',
                'label'    => 'Landings',
                'children' => [],
            ],

        ];
    }
}
