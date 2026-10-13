<?php
namespace HWE\ProductLanding;

/**
 * Guardado de la caja «Landing del producto».
 *
 * Engancha `save_post_product`, sanea y persiste los campos escalares (`_hwe_landing_<key>`, en el
 * idioma por defecto; sus traducciones las guarda `hwe-content-i18n`) y los 3 repeaters
 * (`_hwe_landing_components|qualities|advice`, cada uno un array de filas con `i18n.<idioma>`
 * anidado por cada idioma secundario del sitio), y llama a `WC_Product::save()` solo si algo
 * cambió. Con eso dispara el webhook `product.updated` de WooCommerce y, sin depender de él, pide
 * la revalidación del tag `products` en el frontend (`Revalidation`).
 */
final class Save {

    /** Guarda de re-entrada: `WC_Product::save()` vuelve a disparar `save_post_product`. */
    private static bool $saving = false;

    public static function register(): void {
        add_action('save_post_product', [self::class, 'save'], 10, 1);
    }

    public static function save(int $postId): void {
        if (self::$saving) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

        $nonce = isset($_POST['hwe_product_landing_nonce'])
            ? sanitize_text_field(wp_unslash($_POST['hwe_product_landing_nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'hwe_product_landing_save')) return;
        if (!current_user_can('edit_post', $postId)) return;

        $raw = isset($_POST['hwe_landing']) && is_array($_POST['hwe_landing'])
            ? wp_unslash($_POST['hwe_landing']) : [];

        $product = wc_get_product($postId);
        if (!$product instanceof \WC_Product) return;
        $changed = false;

        // --- Escalares ------------------------------------------------------
        foreach (Fields::SCALAR_FIELDS as $key => $spec) {
            $metaKey = Fields::metaKey($key);
            $value   = self::sanitizeScalar($spec['type'], $raw[$key] ?? '');
            $current = (string) $product->get_meta($metaKey, true);
            // Un booleano nunca guardado vale '' y su saneado da '0': son
            // el MISMO estado (apagado), no un cambio. Sin esta salvedad el
            // primer guardado de cualquier producto disparaba un save() y,
            // con él, el webhook `product.updated` y una revalidación.
            $unchanged = $value === $current
                || ($spec['type'] === 'boolean' && $current === '' && $value === '0');
            if (!$unchanged) $changed = true;
            self::stageMeta($product, $metaKey, $value, $spec['type']);
        }

        // --- Repeaters -----------------------------------------------------
        $locales = Translations::locales();
        foreach (Fields::REPEATERS as $repKey => $repSpec) {
            $rows    = [];
            $rawRows = isset($_POST["hwe_landing_$repKey"]) && is_array($_POST["hwe_landing_$repKey"])
                ? wp_unslash($_POST["hwe_landing_$repKey"]) : [];

            foreach ($rawRows as $rawRow) {
                if (!is_array($rawRow)) continue;
                $row        = [];
                $hasContent = false;
                foreach ($repSpec['row_fields'] as $f => $fSpec) {
                    $val     = self::sanitizeRowField($repKey, $f, $fSpec, $rawRow[$f] ?? '');
                    $row[$f] = $val;
                    if ($val !== '') $hasContent = true;
                    if (!empty($fSpec['translatable'])) {
                        // Una traducción por idioma secundario DEL SITIO: lo que llegue de otro
                        // idioma (o de uno que ya se quitó) no se guarda.
                        foreach ($locales as $suffix) {
                            $rawRowTr = is_array($rawRow['i18n'][$suffix] ?? null) ? $rawRow['i18n'][$suffix] : [];
                            $row['i18n'][$suffix][$f] = self::sanitizeRowField($repKey, $f, $fSpec, $rawRowTr[$f] ?? '');
                        }
                    }
                }
                if ($hasContent) $rows[] = $row; // fila 100% vacía = descartada
            }

            // Tope de filas por repeater (`max_rows` en Fields::REPEATERS; hoy
            // 11 en components y qualities, 20 en advice). `admin.js` deshabilita
            // "+ Añadir" al llegar al máximo, pero no elimina las filas
            // sobrantes de un producto que ya las tenía (migración, edición con
            // JS off, POST scripteado). Se recorta aquí para que el meta nunca
            // persista más de lo que la landing publicada va a pintar.
            // El número lo verifica `plugin-parity.test.ts` contra los MAX_ITEMS
            // del frontend: si cambia uno, el test exige cambiar el otro.
            $maxRows = $repSpec['max_rows'] ?? null;
            if ($maxRows !== null && count($rows) > (int) $maxRows) {
                $rows = array_slice($rows, 0, (int) $maxRows);
            }

            $metaKey = $repSpec['meta_key'];
            $current = $product->get_meta($metaKey, true);
            if ($rows !== (is_array($current) ? $current : [])) $changed = true;
            if ($rows === []) $product->delete_meta_data($metaKey);
            else $product->update_meta_data($metaKey, $rows);
        }

        if ($changed) {
            self::$saving = true;
            try {
                $product->save();
            } finally {
                self::$saving = false;
            }
            // No se depende del webhook de Woo (opcional): el módulo pide su propia revalidación.
            Revalidation::trigger();
        }
    }

    private static function sanitizeScalar(string $type, mixed $v): string {
        $v = (string) ($v ?? '');
        return match ($type) {
            'boolean'      => $v === '1' ? '1' : '0',
            'richtext'     => wp_kses_post($v),
            'textarea'     => sanitize_textarea_field($v),
            'image', 'url' => esc_url_raw($v),
            // Hex `#rrggbb` estricto en minúsculas; cualquier otra cosa (incl.
            // vacío) → '' y `stageMeta` borra el meta ⇒ la landing usa el
            // color de la plantilla base.
            'color'        => preg_match('/^#[0-9a-fA-F]{6}$/', trim($v)) ? strtolower(trim($v)) : '',
            default        => sanitize_text_field($v),
        };
    }

    /** Saneo de un campo de fila de repeater (sin richtext — constraint global). */
    private static function sanitizeRowField(string $repKey, string $field, array $fSpec, mixed $raw): string {
        $v = (string) ($raw ?? '');
        return match ($fSpec['type']) {
            'image'    => esc_url_raw($v),
            'select'   => array_key_exists($v, Fields::icons()) ? $v : '',
            'textarea' => sanitize_textarea_field($v),
            default    => sanitize_text_field($v),
        };
    }

    private static function stageMeta(\WC_Product $p, string $key, string $value, string $type): void {
        // boolean siempre se persiste (incl. "0"); el resto se borra si vacío.
        if ($value === '' && $type !== 'boolean') {
            $p->delete_meta_data($key);
        } else {
            $p->update_meta_data($key, $value);
        }
    }
}
