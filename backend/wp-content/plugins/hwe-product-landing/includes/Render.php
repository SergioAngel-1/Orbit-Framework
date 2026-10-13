<?php
namespace HWE\ProductLanding;

/**
 * Render del metabox "Landing educativa del producto".
 *
 * Solo dibuja el formulario: el guardado vive en Save, el JS de los repeaters y del selector de
 * medios en assets/admin.js, y la exposición GraphQL en Graphql.
 *
 * Los textos de cada campo escalar son los del idioma por defecto; su versión en cada idioma
 * secundario se edita en la caja «Traducciones» de la ficha (mu-plugin `hwe-content-i18n`, a la
 * que `Translations` registra estos campos). Las filas de las listas (componentes, cualidades,
 * consejos) llevan sus traducciones dentro de la propia fila.
 */
final class Render {

    /**
     * Agrupación visual de Fields::SCALAR_FIELDS en fieldsets. El orden aquí
     * es el orden de pintado; 'repeater' (si existe) se inserta tras los
     * campos escalares de la sección.
     */
    private const SECTIONS = [
        ['legend' => 'Publicación y SEO',                    'fields' => ['published', 'seo_title', 'seo_description', 'seo_og_image']],
        ['legend' => 'Paleta de la landing',                 'fields' => ['accent_1', 'accent_2']],
        ['legend' => '1 · Hero',                             'fields' => ['hero_subtitle', 'hero_image']],
        ['legend' => '2 · Educativa',                        'fields' => ['section_edu_enabled', 'edu_kicker', 'edu_title', 'edu_body', 'edu_image']],
        ['legend' => '3 · Sobre el producto + componentes',  'fields' => ['about_title', 'about_body'], 'repeater' => 'components'],
        ['legend' => '4 · Cualidades',                       'fields' => ['section_qualities_enabled', 'qualities_title'], 'repeater' => 'qualities'],
        ['legend' => '5 · Consejos',                         'fields' => ['section_advice_enabled', 'advice_title', 'advice_intro'], 'repeater' => 'advice'],
        ['legend' => '6 · CTA de compra',                    'fields' => ['cta_title', 'cta_text']],
        ['legend' => '7 · Video',                            'fields' => ['section_video_enabled', 'video_url', 'video_caption', 'video_poster', 'video_date']],
        ['legend' => '8 · Cierre',                           'fields' => ['closing_title', 'closing_text', 'closing_cta_label', 'closing_cta_href']],
    ];

    /** Formulario completo del metabox. */
    public static function metabox(int $postId): string {
        $html = '<div class="hwe-landing-metabox">';
        if (Translations::locales() !== []) {
            $html .= '<p class="description">Los textos de este formulario son los del idioma por defecto. '
                . 'Su traducción a cada idioma se escribe en la caja «Traducciones» de esta ficha; '
                . 'las filas de las listas (componentes, cualidades y consejos) llevan las suyas dentro de cada fila.</p>';
        }
        foreach (self::SECTIONS as $section) {
            $html .= '<fieldset class="hwe-landing-section">';
            $html .= '<legend>' . esc_html($section['legend']) . '</legend>';
            foreach ($section['fields'] as $key) {
                $html .= self::scalarRow($postId, $key, Fields::SCALAR_FIELDS[$key]);
            }
            if (!empty($section['repeater'])) {
                $repKey = $section['repeater'];
                $html .= self::repeater($repKey, Fields::REPEATERS[$repKey], $postId);
            }
            $html .= '</fieldset>';
        }
        $html .= '</div>';
        return $html;
    }

    /** Un campo escalar: label + control (en el idioma por defecto). */
    private static function scalarRow(int $postId, string $key, array $spec): string {
        $name  = 'hwe_landing[' . $key . ']';
        $value = get_post_meta($postId, Fields::metaKey($key), true);
        $isBool = $spec['type'] === 'boolean';

        $html = '<div class="hwe-landing-field">';
        if (!$isBool) {
            $html .= '<label class="hwe-landing-label">' . esc_html($spec['label']) . '</label>';
        }
        $html .= '<div class="hwe-landing-control">' . self::field($name, $spec, $value) . '</div>';
        $html .= self::hint($spec);
        $html .= '</div>';
        return $html;
    }

    /**
     * Ayuda bajo el control (`Fields::SCALAR_FIELDS[...]['hint']`), una vez por campo.
     */
    private static function hint(array $spec): string {
        $hint = (string) ($spec['hint'] ?? '');
        return $hint === '' ? '' : '<p class="description hwe-landing-hint">' . esc_html($hint) . '</p>';
    }

    /** Un control por tipo. `$name` es el name completo del input. */
    private static function field(string $name, array $spec, mixed $value): string {
        $v = (string) ($value ?? '');
        // Booleano nunca guardado ('' = el metabox aún no se ha enviado para
        // este producto, p. ej. una landing publicada desde HWE Control Center):
        // se pinta según el `default` del spec (ver Fields::SCALAR_FIELDS), no
        // siempre desmarcado. Tras el primer guardado, el <input type="hidden">
        // que acompaña al checkbox garantiza un '1'/'0' explícito en el meta.
        $boolChecked = $v === '' ? !empty($spec['default']) : ($v === '1');
        return match ($spec['type']) {
            'boolean'  => sprintf(
                '<label><input type="hidden" name="%1$s" value="0"><input type="checkbox" name="%1$s" value="1" %2$s> %3$s</label>',
                esc_attr($name), checked($boolChecked, true, false), esc_html($spec['label'])
            ),
            'textarea' => sprintf('<textarea name="%s" rows="3" class="widefat">%s</textarea>', esc_attr($name), esc_textarea($v)),
            'richtext' => self::richtext($name, $v),
            'color'    => self::color($name, $v),
            'image'    => self::media($name, $v),
            'select'   => self::select($name, $v, self::resolveOptions($spec)),
            'url'      => sprintf('<input type="url" name="%s" value="%s" class="widefat">', esc_attr($name), esc_attr($v)),
            default    => sprintf('<input type="text" name="%s" value="%s" class="widefat">', esc_attr($name), esc_attr($v)),
        };
    }

    /**
     * Selector de color (WP color picker / Iris — lo engancha assets/admin.js
     * por la clase `.hwe-landing-color`). El valor viaja como texto (hex
     * `#rrggbb` o vacío); vacío = "usar el color de la plantilla". Las
     * sugerencias `data-palette` salen de `Palette::suggestions()`, derivadas
     * de la paleta de marca actual.
     */
    private static function color(string $name, string $v): string {
        $palette = wp_json_encode(Palette::suggestions());
        return sprintf(
            '<input type="text" class="hwe-landing-color" name="%s" value="%s" data-palette="%s" placeholder="#rrggbb — vacío = plantilla">',
            esc_attr($name),
            esc_attr($v),
            esc_attr($palette !== false ? $palette : '[]')
        );
    }

    /** Editor quicktags-only (sin TinyMCE, sin botones de medios). */
    private static function richtext(string $name, string $v): string {
        $editorId = sanitize_key(str_replace(['[', ']'], ['_', ''], $name));
        ob_start();
        wp_editor($v, $editorId, [
            'textarea_name'  => $name,
            'tinymce'        => false,
            'quicktags'      => ['buttons' => 'strong,em,link,ul,ol,li,close'],
            'media_buttons'  => false,
            'textarea_rows'  => 8,
        ]);
        return (string) ob_get_clean();
    }

    /**
     * Picker de medioteca (wp.media). Mismo markup que
     * AdminFormWalker::renderImage() — assets/admin.js (Task 4) enlaza
     * .hwe-media-pick / .hwe-media-clear.
     */
    private static function media(string $name, string $v): string {
        $nameAttr = esc_attr($name);
        $val      = esc_attr($v);
        $url      = esc_url($v);
        $preview  = $v !== '' ? '<img src="' . $url . '" alt="">' : '';

        return '<span class="hwe-media">'
            . '<span class="hwe-media-row">'
            . '<input type="url" class="hwe-media-url" name="' . $nameAttr . '" value="' . $val . '" readonly placeholder="https://…">'
            . '<button type="button" class="button hwe-media-pick">Seleccionar</button>'
            . '<button type="button" class="button hwe-media-clear">Quitar</button>'
            . '</span>'
            . '<span class="hwe-media-preview">' . $preview . '</span>'
            . '</span>';
    }

    /** `<select>` con opciones ya resueltas (val => label). */
    private static function select(string $name, string $v, array $options): string {
        $opts = '';
        foreach ($options as $val => $label) {
            $opts .= sprintf(
                '<option value="%s" %s>%s</option>',
                esc_attr((string) $val),
                selected($v, (string) $val, false),
                esc_html((string) $label)
            );
        }
        return sprintf('<select name="%s" class="widefat">%s</select>', esc_attr($name), $opts);
    }

    /** Resuelve las opciones del campo (hoy, los íconos) y antepone "— Ninguno —". */
    private static function resolveOptions(array $spec): array {
        $options = ($spec['options'] ?? '') === 'icons' ? Fields::icons() : [];
        return ['' => '— Ninguno —'] + $options;
    }

    /**
     * Bloque repeater completo: filas existentes + botón "+ Añadir" + la
     * plantilla `<script type="text/template">` con el placeholder __INDEX__.
     * La estructura la consume assets/admin.js (Task 4).
     */
    private static function repeater(string $key, array $repSpec, int $postId): string {
        $rows = get_post_meta($postId, $repSpec['meta_key'], true);
        if (!is_array($rows)) {
            $rows = [];
        }
        $rows  = array_values($rows);
        $count = count($rows);

        $rowsHtml = '';
        foreach ($rows as $i => $rowValues) {
            $rowsHtml .= self::repeaterRow($key, $repSpec['row_fields'], $i, is_array($rowValues) ? $rowValues : []);
        }

        $maxRows = $repSpec['max_rows'] ?? null;
        $maxAttr = $maxRows !== null ? ' data-max-rows="' . esc_attr((string) $maxRows) . '"' : '';
        $hint    = $maxRows !== null
            ? '<p class="hwe-landing-repeater-hint">Máximo ' . esc_html((string) $maxRows) . ' — las filas de más allá no se muestran en la landing publicada.</p>'
            : '';

        // Los `hint` de los campos de fila (hoy, medidas de imagen) se pintan
        // UNA vez en la cabecera y no dentro de cada fila: son idénticos en
        // todas y repetirlos 11 veces sepulta el repeater.
        foreach ($repSpec['row_fields'] as $rowField => $rowSpec) {
            $rowHint = (string) ($rowSpec['hint'] ?? '');
            if ($rowHint === '') {
                continue;
            }
            $hint .= '<p class="hwe-landing-repeater-hint"><strong>'
                . esc_html((string) ($rowSpec['label'] ?? $rowField)) . ':</strong> '
                . esc_html($rowHint) . '</p>';
        }

        return '<div class="hwe-landing-repeater" data-repeater="' . esc_attr($key) . '" data-next-index="' . esc_attr((string) $count) . '"' . $maxAttr . '>'
            . '<h4 class="hwe-landing-repeater-title">' . esc_html($repSpec['label']) . '</h4>'
            . $hint
            . '<div class="hwe-landing-rows">' . $rowsHtml . '</div>'
            . '<button type="button" class="hwe-landing-add">+ Añadir</button>'
            . self::repeaterTemplate($key)
            . '</div>';
    }

    /** Plantilla de fila del repeater (placeholder literal __INDEX__). */
    public static function repeaterTemplate(string $key): string {
        $repSpec = Fields::REPEATERS[$key];
        return '<script type="text/template" id="hwe-landing-tpl-' . esc_attr($key) . '">'
            . self::repeaterRow($key, $repSpec['row_fields'], '__INDEX__', [])
            . '</script>';
    }

    /** Una fila del repeater: cada row_field + su control por idioma secundario + "×". */
    private static function repeaterRow(string $repeaterKey, array $rowFields, int|string $index, array $values): string {
        $html = '<div class="hwe-landing-row">';
        foreach ($rowFields as $field => $spec) {
            $name = 'hwe_landing_' . $repeaterKey . '[' . $index . '][' . $field . ']';
            $val  = $values[$field] ?? '';

            $html .= '<div class="hwe-landing-row-field">';
            $html .= '<label class="hwe-landing-label">' . esc_html($spec['label']) . '</label>';
            $html .= self::field($name, $spec, $val);

            if (!empty($spec['translatable'])) {
                foreach (Translations::locales() as $suffix) {
                    $trName = 'hwe_landing_' . $repeaterKey . '[' . $index . '][i18n][' . $suffix . '][' . $field . ']';
                    $trVal  = $values['i18n'][$suffix][$field] ?? '';
                    $html .= '<label class="hwe-landing-label hwe-landing-label-tr">'
                        . esc_html(sprintf('%s (opcional)', Translations::label($suffix))) . '</label>';
                    $html .= self::field($trName, $spec, $trVal);
                }
            }
            $html .= '</div>';
        }
        $html .= '<button type="button" class="hwe-landing-remove">×</button>';
        $html .= '</div>';
        return $html;
    }
}
