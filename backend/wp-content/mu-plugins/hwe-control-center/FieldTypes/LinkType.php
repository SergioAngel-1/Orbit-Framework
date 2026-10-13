<?php

namespace HWE\ControlCenter\FieldTypes;

/**
 * Botón/enlace a otra pantalla de admin (p. ej. «Gestionar banners»).
 *
 * No es un valor: no pinta ningún input y no envía nada en el POST. El nodo declara
 * `url` y opcionalmente `button_label`. Su saneado es siempre cadena vacía.
 */
final class LinkType implements FieldType {

    public function id(): string {
        return 'link';
    }

    public function persistence(): string {
        return self::CONFIG;
    }

    public function render(array $field, string $id, string $name, mixed $value, array $path, array $context = []): string {
        $url   = (string) ($field['url'] ?? '');
        $label = (string) ($field['button_label'] ?? ($field['label'] ?? 'Abrir'));
        if ($url === '') {
            return '<span class="description">—</span>';
        }
        return sprintf(
            '<a class="button button-secondary" href="%s">%s</a>',
            esc_url($url),
            esc_html($label)
        );
    }

    public function sanitize(array $field, mixed $raw): mixed {
        return '';
    }
}
