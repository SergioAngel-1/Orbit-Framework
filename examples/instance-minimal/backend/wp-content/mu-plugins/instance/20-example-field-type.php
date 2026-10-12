<?php
/**
 * Ejemplo mínimo de instancia (20): un tipo de campo propio — PREPARADO, pendiente de activar.
 *
 * Se activa en la tarea 26 del plan v2, que crea el registro de tipos de campo
 * (`hwe_control_center_field_types`). Hasta entonces este archivo no ejecuta nada: es el
 * hueco documentado donde la instancia registrará su tipo.
 *
 * Cuando el registro exista, descomenta el bloque de abajo y crea la clase en este mismo
 * archivo o en una subcarpeta cargada desde aquí (el cargador solo hace `require` de los
 * `.php` de primer nivel; las subcarpetas las carga cada archivo).
 *
 * ```php
 * add_filter('hwe_control_center_field_types', static function (array $types): array {
 *     $types['example_upper'] = new ExampleUpperField();
 *     return $types;
 * });
 * ```
 */

if (!defined('ABSPATH')) {
    exit;
}

// TODO(T26): registrar `example_upper` en `hwe_control_center_field_types` cuando exista el registro.
