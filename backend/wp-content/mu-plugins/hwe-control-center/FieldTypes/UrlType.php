<?php

namespace HWE\ControlCenter\FieldTypes;

/** URL (`<input type="url">`, saneada con `esc_url_raw`). */
final class UrlType implements FieldType {

    public function id(): string {
        return 'url';
    }

    public function persistence(): string {
        return self::CONFIG;
    }

    public function render(array $field, string $id, string $name, mixed $value, array $path, array $context = []): string {
        $v = esc_attr((string) ($value ?? ''));
        return "<input type=\"url\" id=\"{$id}\" name=\"{$name}\" value=\"{$v}\">";
    }

    public function sanitize(array $field, mixed $raw): mixed {
        return esc_url_raw((string) ($raw ?? ''));
    }
}
