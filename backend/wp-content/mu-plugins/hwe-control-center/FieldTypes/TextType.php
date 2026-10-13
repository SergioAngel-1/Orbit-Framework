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
        // Una lista de escalares (`["en", "fr"]` en un JSON de instancia) se une con
        // comas: `(string) $array` guardaría la palabra «Array» y, en campos como
        // `i18n.locales`, apagaría el idioma sin avisar. Lo que no es escalar se descarta.
        if (is_array($raw)) {
            $parts = array_filter(
                array_map(
                    static fn ($item) => is_scalar($item) ? trim((string) $item) : '',
                    $raw
                ),
                static fn (string $item) => $item !== ''
            );
            $raw = implode(', ', $parts);
        }
        return sanitize_text_field((string) ($raw ?? ''));
    }
}
