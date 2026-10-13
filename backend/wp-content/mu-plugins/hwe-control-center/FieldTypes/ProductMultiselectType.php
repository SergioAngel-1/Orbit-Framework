<?php

namespace HWE\ControlCenter\FieldTypes;

/**
 * Multiselección de productos (lista de checkboxes).
 *
 * El nodo declara `options` (slug → etiqueta), que la instancia resuelve (p. ej. por
 * categoría con `wc_get_products`); el tipo solo pinta y valida contra esas opciones. El
 * valor guardado es un string `"slug-1 | slug-2"`.
 *
 * El formulario envía un array (`name="…[]"`), pero el formato de almacenamiento (y el de
 * `instance.config.json` para `wp hwe setup`) es el string: ambos se aceptan al sanear.
 */
final class ProductMultiselectType implements FieldType {

    public function id(): string {
        return 'product_multiselect';
    }

    public function persistence(): string {
        return self::CONFIG;
    }

    public function render(array $field, string $id, string $name, mixed $value, array $path, array $context = []): string {
        $options = $field['options'] ?? [];
        if ($options === []) {
            return '<p class="description">' . esc_html(__('No hay productos para elegir.', 'hwe')) . '</p>';
        }

        $selected = array_flip(array_filter(
            array_map('trim', explode('|', (string) ($value ?? ''))),
            static fn (string $s): bool => $s !== ''
        ));

        $items = '';
        $i     = 0;
        foreach ($options as $val => $label) {
            $slug       = (string) $val;
            $checkboxId = esc_attr("{$id}_{$i}");
            $checked    = array_key_exists($slug, $selected) ? ' checked' : '';
            $items     .= sprintf(
                '<label class="hwe-checkbox-item" for="%1$s"><input type="checkbox" id="%1$s" name="%2$s[]" value="%3$s"%4$s><span>%5$s</span></label>',
                $checkboxId,
                esc_attr($name),
                esc_attr($slug),
                $checked,
                esc_html((string) $label)
            );
            $i++;
        }

        return "<div class=\"hwe-checkbox-list\" id=\"{$id}\">{$items}</div>";
    }

    public function sanitize(array $field, mixed $raw): mixed {
        $options = $field['options'] ?? [];
        if (is_string($raw)) {
            $items = array_map('trim', explode('|', $raw));
        } else {
            $items = is_array($raw) ? $raw : [];
        }
        $valid = [];
        foreach ($items as $item) {
            $slug = sanitize_text_field((string) $item);
            if ($slug !== '' && array_key_exists($slug, $options)) {
                $valid[] = $slug;
            }
        }
        return implode(' | ', $valid);
    }
}
