<?php

namespace HWE\ControlCenter\SchemaGroups;

/**
 * Esquema del núcleo: envío, grupo reservado al módulo `shipping`.
 *
 * Trait de `HWE\ControlCenter\Schema`: el árbol de configuración se reparte por tema para que
 * cada archivo se lea de una vez. `Schema::core()` los junta en el orden de las pestañas.
 */
trait Shipping {

    /**
     * @return array<string,array>
     */
    private static function shippingGroups(): array {
        return [

            /* ------------------------------------------------------------------ */
            /* ENVÍO                                                               */
            /* ------------------------------------------------------------------ */
            // Grupo RESERVADO al módulo `shipping`: sin él no hay nada que configurar
            // (el envío se ajusta en WooCommerce → Ajustes → Envío) y la pestaña no se
            // pinta. El módulo añade aquí sus campos con `hwe_control_center_schema`.
            'shipping' => [
                'type'     => 'group',
                'label'    => 'Envío',
                'children' => [],
            ],

        ];
    }
}
