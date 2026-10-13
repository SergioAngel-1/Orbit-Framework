<?php

namespace HWE\ControlCenter\FieldTypes;

/**
 * Pares `valor | etiqueta` (una línea por par).
 *
 * El nodo puede traer `placeholders` (dos), `add_label` y `max`. El valor guardado es el
 * mismo string multilínea de siempre: solo cambia la forma de editarlo.
 */
final class PairsType implements FieldType {

    public function id(): string {
        return 'pairs';
    }

    public function persistence(): string {
        return self::CONFIG;
    }

    public function render(array $field, string $id, string $name, mixed $value, array $path, array $context = []): string {
        return RowsEditor::render($id, $name, (string) ($value ?? ''), 'pairs', $field);
    }

    public function sanitize(array $field, mixed $raw): mixed {
        return sanitize_textarea_field((string) ($raw ?? ''));
    }
}
