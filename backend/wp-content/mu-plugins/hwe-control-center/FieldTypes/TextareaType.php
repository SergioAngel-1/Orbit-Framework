<?php

namespace HWE\ControlCenter\FieldTypes;

/** Texto multilínea (una entrada por línea, p. ej. la FAQ). */
final class TextareaType implements FieldType {

    public function id(): string {
        return 'textarea';
    }

    public function persistence(): string {
        return self::CONFIG;
    }

    public function render(array $field, string $id, string $name, mixed $value, array $path, array $context = []): string {
        $v = esc_textarea((string) ($value ?? ''));
        return "<textarea id=\"{$id}\" name=\"{$name}\" rows=\"3\">{$v}</textarea>";
    }

    public function sanitize(array $field, mixed $raw): mixed {
        return sanitize_textarea_field((string) ($raw ?? ''));
    }
}
