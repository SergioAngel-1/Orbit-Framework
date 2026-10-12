<?php

namespace HWE\ControlCenter\FieldTypes;

/** Interruptor booleano (checkbox con `hidden 0` para que el apagado también llegue en el POST). */
final class BooleanType implements FieldType {

    public function id(): string {
        return 'boolean';
    }

    public function persistence(): string {
        return self::CONFIG;
    }

    public function render(array $field, string $id, string $name, mixed $value, array $path, array $context = []): string {
        $c     = ((bool) $value) ? ' checked' : '';
        $label = esc_html($field['label'] ?? '');
        return <<<HTML
<label class="hwe-toggle">
    <input type="hidden" name="{$name}" value="0">
    <input type="checkbox" id="{$id}" name="{$name}" value="1"{$c}>
    <span class="hwe-toggle-track">
        <span class="hwe-toggle-thumb"></span>
    </span>
    <span class="hwe-toggle-label">{$label}</span>
</label>
HTML;
    }

    public function sanitize(array $field, mixed $raw): mixed {
        return !empty($raw) && $raw !== '0';
    }
}
