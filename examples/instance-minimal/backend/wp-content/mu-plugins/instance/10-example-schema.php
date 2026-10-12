<?php
/**
 * Ejemplo mínimo de instancia (10): un campo propio en el Control Center.
 *
 * Una instancia añade sus ajustes al grupo raíz `instance` (la pestaña «Instancia» del
 * panel) con el filtro `hwe_control_center_schema`. Los campos `public => true` salen por
 * `/wp-json/hwe/v1/config` en `instance.example.greeting`, y el frontend los lee con
 * `getSiteConfig()` (ver `frontend/src/instance/config.d.ts` de este ejemplo).
 *
 * Reglas (ver `mu-plugins/instance/README.md`):
 *  · solo se admiten claves raíz del núcleo y `instance`: lo demás se descarta y se registra;
 *  · un grupo del núcleo eliminado se restaura;
 *  · ordena los archivos con prefijos numéricos (`10-…`, `20-…`) si el orden importa.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Añade el grupo `example` al grupo raíz `instance`.
 *
 * Función nombrada (y no closure anónima) para que sea reutilizable y probable:
 * el test del ejemplo la llama directamente con el esquema del núcleo.
 *
 * @param array<string,array> $schema Esquema del núcleo.
 * @return array<string,array>
 */
function hwe_example_add_fields(array $schema): array {
    $schema['instance']['children']['example'] = [
        'type'     => 'group',
        'label'    => 'Ejemplo',
        'children' => [
            'greeting' => [
                'type'    => 'text',
                'label'   => 'Saludo',
                'default' => 'Hola',
                'public'  => true,
            ],
        ],
    ];
    return $schema;
}

add_filter('hwe_control_center_schema', 'hwe_example_add_fields');
