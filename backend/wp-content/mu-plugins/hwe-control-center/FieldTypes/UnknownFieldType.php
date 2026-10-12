<?php

namespace HWE\ControlCenter\FieldTypes;

use HWE\ControlCenter\Log;

/**
 * Sustituto de un tipo desconocido (p. ej. de un módulo desactivado).
 *
 * Se pinta como texto DESHABILITADO y se registra una vez por proceso: el formulario nunca
 * rompe por un tipo que no existe, y el aviso dice cuál falta. Al estar deshabilitado, el
 * navegador no lo envía; si aun así llega algo (POST manipulado), se sanea como texto, y si
 * no llega nada no se persiste nada (`SKIP`): un tipo desconocido nunca borra su valor
 * almacenado al guardar.
 */
final class UnknownFieldType implements FieldType {

    public function __construct(private readonly string $unknownId) {
        Log::once(
            'field-types:unknown:' . $this->unknownId,
            sprintf('tipo de campo desconocido «%s»: se muestra deshabilitado (¿un módulo desactivado?).', $this->unknownId)
        );
    }

    public function id(): string {
        return $this->unknownId;
    }

    public function persistence(): string {
        return self::CONFIG;
    }

    public function render(array $field, string $id, string $name, mixed $value, array $path, array $context = []): string {
        $v     = esc_attr((string) ($value ?? ''));
        $label = esc_html($field['label'] ?? $this->unknownId);
        return "<input type=\"text\" id=\"{$id}\" value=\"{$v}\" disabled aria-label=\"{$label} (tipo desconocido)\">";
    }

    public function sanitize(array $field, mixed $raw): mixed {
        if ($raw === null) {
            return self::SKIP;
        }
        return sanitize_text_field((string) $raw);
    }
}
