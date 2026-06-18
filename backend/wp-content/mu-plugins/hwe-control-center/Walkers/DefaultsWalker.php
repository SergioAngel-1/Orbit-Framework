<?php

namespace HWE\ControlCenter\Walkers;

use HWE\ControlCenter\Walker;

/**
 * Extrae el árbol de valores por defecto definidos en el esquema.
 *
 * Uso:
 *   $defaults = (new DefaultsWalker)->walk(Schema::get());
 */
class DefaultsWalker extends Walker {

    protected function visitField(array $field, string $key, array $path, mixed $value): mixed {
        return $field['default'] ?? null;
    }

    protected function visitGroup(array $group, string $key, array $path, array $children): mixed {
        return $children;
    }
}
