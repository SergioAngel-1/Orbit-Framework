<?php

namespace HWE\ControlCenter\FieldTypes;

/** Dirección de correo (`<input type="email">`, saneada con `sanitize_email`). */
final class EmailType implements FieldType {

    public function id(): string {
        return 'email';
    }

    public function persistence(): string {
        return self::CONFIG;
    }

    public function render(array $field, string $id, string $name, mixed $value, array $path, array $context = []): string {
        $v = esc_attr((string) ($value ?? ''));
        return "<input type=\"email\" id=\"{$id}\" name=\"{$name}\" value=\"{$v}\">";
    }

    public function sanitize(array $field, mixed $raw): mixed {
        return sanitize_email((string) ($raw ?? ''));
    }
}
