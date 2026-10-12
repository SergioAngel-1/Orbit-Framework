<?php
/**
 * Ejemplo mínimo de instancia (20): un tipo de campo propio.
 *
 * Se activa en la tarea 26 del plan v2 con el registro `hwe_control_center_field_types`:
 * `example_upper` es un texto que se guarda en mayúsculas. El test del ejemplo
 * (`ExamplePublicConfigTest`) lo registra y comprueba su render y su saneado.
 *
 * Patrón para un tipo propio: una clase que cumple
 * `HWE\ControlCenter\FieldTypes\FieldType` (pintar, sanear, declarar persistencia) más una
 * función nombrada que la añade al mapa (las funciones nombradas se pueden probar; ver el
 * test). La persistencia es `config`: sale por la API pública si el nodo es `public`.
 */

if (!defined('ABSPATH')) {
    exit;
}

class HWE_Example_Upper_Field implements \HWE\ControlCenter\FieldTypes\FieldType {

    public function id(): string {
        return 'example_upper';
    }

    public function persistence(): string {
        return self::CONFIG;
    }

    public function render(array $field, string $id, string $name, mixed $value, array $path, array $context = []): string {
        $v = esc_attr((string) ($value ?? ''));
        return "<input type=\"text\" id=\"{$id}\" name=\"{$name}\" value=\"{$v}\" class=\"hwe-example-upper\">";
    }

    public function sanitize(array $field, mixed $raw): mixed {
        return strtoupper(sanitize_text_field((string) ($raw ?? '')));
    }
}

/**
 * Añade el tipo del ejemplo al mapa (función nombrada para poder probarla; ver el test).
 *
 * @param array<string,\HWE\ControlCenter\FieldTypes\FieldType> $types
 * @return array<string,\HWE\ControlCenter\FieldTypes\FieldType>
 */
function hwe_example_field_types(array $types): array {
    $types['example_upper'] = new HWE_Example_Upper_Field();
    return $types;
}

add_filter('hwe_control_center_field_types', 'hwe_example_field_types');
