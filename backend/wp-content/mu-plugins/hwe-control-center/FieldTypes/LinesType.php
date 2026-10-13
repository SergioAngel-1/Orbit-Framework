<?php

namespace HWE\ControlCenter\FieldTypes;

/**
 * Lista editable de líneas (una entrada por línea).
 *
 * El nodo puede traer `placeholder`, `add_label` y `max`. El valor guardado es el mismo
 * string multilínea de siempre: solo cambia la forma de editarlo.
 */
final class LinesType implements FieldType {

    public function id(): string {
        return 'lines';
    }

    public function persistence(): string {
        return self::CONFIG;
    }

    public function render(array $field, string $id, string $name, mixed $value, array $path, array $context = []): string {
        return RowsEditor::render($id, $name, (string) ($value ?? ''), 'lines', $field);
    }

    public function sanitize(array $field, mixed $raw): mixed {
        return sanitize_textarea_field((string) ($raw ?? ''));
    }
}
