<?php

namespace HWE\ControlCenter\FieldTypes;

/**
 * Selector de la medioteca compartido por los tipos `image` y `file`.
 *
 * Persiste la URL del attachment. El JS (`assets/admin.js`, `initMediaPickers`) abre
 * `wp.media` al pulsar «Seleccionar» y rellena el input (readonly) más la vista previa.
 */
final class MediaPicker {

    /**
     * @param string               $variant `'image'` (vista previa `<img>`) o `'file'`
     *                                      (vista previa con el nombre del archivo).
     * @param array<string,mixed>  $field   Nodo del esquema (`accept`: extensiones con coma
     *                                      para `file`, p. ej. `'pdf,zip'`).
     */
    public static function render(string $id, string $name, string $value, array $field, string $variant): string {
        $v      = esc_attr($value);
        $accept = esc_attr((string) ($field['accept'] ?? ''));
        $pick   = esc_html(__('Seleccionar', 'hwe'));
        $clear  = esc_html(__('Quitar', 'hwe'));

        if ($value !== '' && $variant === 'file') {
            $preview = '<code>' . esc_html((string) basename((string) wp_parse_url($value, PHP_URL_PATH))) . '</code>';
        } elseif ($value !== '') {
            $preview = '<img src="' . esc_url($value) . '" alt="">';
        } else {
            $preview = '';
        }

        return <<<HTML
<span class="hwe-media" data-accept="{$accept}">
    <span class="hwe-media-row">
        <input type="url" class="hwe-media-url" id="{$id}" name="{$name}" value="{$v}" readonly placeholder="https://…">
        <button type="button" class="button hwe-media-pick">{$pick}</button>
        <button type="button" class="button hwe-media-clear">{$clear}</button>
    </span>
    <span class="hwe-media-preview">{$preview}</span>
</span>
HTML;
    }

    /**
     * Persiste como URL relativa a `/wp-content/uploads/` para no atar la BD a `localhost`
     * (dev) y que la misma fila sirva en producción. URLs externas (CDN) o ya relativas se
     * conservan tal cual (con query/fragment si los hubiera).
     */
    public static function sanitize(mixed $value): string {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return '';
        }
        // Se busca en el PATH de la URL (wp_parse_url), no en la cadena completa: una
        // coincidencia por substring también «detectaba» la ruta si aparecía en el query
        // (p. ej. `?redirect=...wp-content/uploads/...`), con resultados impredecibles.
        $parts  = wp_parse_url($raw);
        $path   = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        $needle = 'wp-content/uploads/';
        $pos    = strpos($path, $needle);
        if ($pos !== false) {
            $relative = '/' . substr($path, $pos);
            if (!empty($parts['query'])) {
                $relative .= '?' . $parts['query'];
            }
            if (!empty($parts['fragment'])) {
                $relative .= '#' . $parts['fragment'];
            }
            return esc_url_raw($relative);
        }
        return esc_url_raw($raw);
    }
}
