<?php

namespace HWE\ControlCenter\FieldTypes;

/**
 * Tramo de URL (`sanitize_title`: solo `[a-z0-9-]`).
 *
 * Ni las barras ni los acentos pueden colarse en la ruta pública. Se pinta como un input
 * de texto normal.
 */
final class SlugType implements FieldType {

    public function id(): string {
        return 'slug';
    }

    public function persistence(): string {
        return self::CONFIG;
    }

    public function render(array $field, string $id, string $name, mixed $value, array $path, array $context = []): string {
        $v = esc_attr((string) ($value ?? ''));
        return "<input type=\"text\" id=\"{$id}\" name=\"{$name}\" value=\"{$v}\">";
    }

    public function sanitize(array $field, mixed $raw): mixed {
        return sanitize_title((string) ($raw ?? ''));
    }
}
