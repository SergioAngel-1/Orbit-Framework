<?php

namespace HWE\ControlCenter\FieldTypes;

/** Desplegable (`options: valor → etiqueta`). Un valor fuera de la lista cae al `default`. */
final class SelectType implements FieldType {

    public function id(): string {
        return 'select';
    }

    public function persistence(): string {
        return self::CONFIG;
    }

    public function render(array $field, string $id, string $name, mixed $value, array $path, array $context = []): string {
        $options = $field['options'] ?? [];
        $current = (string) ($value ?? '');
        $opts    = '';
        foreach ($options as $val => $label) {
            $selected = ($current === (string) $val) ? ' selected' : '';
            $opts    .= sprintf(
                '<option value="%s"%s>%s</option>',
                esc_attr((string) $val),
                $selected,
                esc_html($label)
            );
        }
        return "<select id=\"{$id}\" name=\"{$name}\">{$opts}</select>";
    }

    public function sanitize(array $field, mixed $raw): mixed {
        $value   = (string) ($raw ?? '');
        $options = $field['options'] ?? [];
        return array_key_exists($value, $options) ? $value : (string) ($field['default'] ?? '');
    }
}
