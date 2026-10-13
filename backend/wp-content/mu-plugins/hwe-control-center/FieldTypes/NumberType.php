<?php

namespace HWE\ControlCenter\FieldTypes;

/** Entero con `min`/`max` del esquema (el servidor vuelve a acotarlo al guardar). */
final class NumberType implements FieldType {

    public function id(): string {
        return 'number';
    }

    public function persistence(): string {
        return self::CONFIG;
    }

    public function render(array $field, string $id, string $name, mixed $value, array $path, array $context = []): string {
        $v     = esc_attr((string) ($value ?? ''));
        $attrs = '';
        foreach (['min', 'max'] as $bound) {
            if (isset($field[$bound])) {
                $attrs .= sprintf(' %s="%d"', $bound, (int) $field[$bound]);
            }
        }
        return "<input type=\"number\" step=\"1\"{$attrs} id=\"{$id}\" name=\"{$name}\" value=\"{$v}\" class=\"small-text\">";
    }

    public function sanitize(array $field, mixed $raw): mixed {
        $trimmed = trim((string) ($raw ?? ''));
        if (!preg_match('/^-?\d+$/', $trimmed)) {
            return (string) ($field['default'] ?? '');
        }
        $n = (int) $trimmed;
        if (isset($field['min'])) {
            $n = max((int) $field['min'], $n);
        }
        if (isset($field['max'])) {
            $n = min((int) $field['max'], $n);
        }
        return (string) $n;
    }
}
