<?php

namespace HWE\ControlCenter\FieldTypes;

/** Texto de una línea (`<input type="text">`). */
final class TextType implements FieldType {

    public function id(): string {
        return 'text';
    }

    public function persistence(): string {
        return self::CONFIG;
    }

    public function render(array $field, string $id, string $name, mixed $value, array $path, array $context = []): string {
        $v = esc_attr((string) ($value ?? ''));
        return "<input type=\"text\" id=\"{$id}\" name=\"{$name}\" value=\"{$v}\">";
    }

    public function sanitize(array $field, mixed $raw): mixed {
        return sanitize_text_field((string) ($raw ?? ''));
    }
}
