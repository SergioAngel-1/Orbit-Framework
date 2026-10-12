<?php

namespace HWE\ControlCenter\FieldTypes;

/** Color hex (`<input type="color">` + campo de texto). Solo acepta `#rrggbb`; lo demás se vacía. */
final class ColorType implements FieldType {

    public function id(): string {
        return 'color';
    }

    public function persistence(): string {
        return self::CONFIG;
    }

    public function render(array $field, string $id, string $name, mixed $value, array $path, array $context = []): string {
        $v = esc_attr((string) ($value ?? ($field['default'] ?? '#000000')));
        return <<<HTML
<span class="hwe-color-picker">
    <span class="hwe-color-preview" style="background:{$v}"></span>
    <input type="color" id="{$id}" name="{$name}" value="{$v}">
    <input type="text" id="{$id}_text" name="{$name}" value="{$v}" class="small-text" maxlength="7"
        aria-label="Código hex del color">
</span>
HTML;
    }

    public function sanitize(array $field, mixed $raw): mixed {
        $value = (string) ($raw ?? '');
        return (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : '';
    }
}
