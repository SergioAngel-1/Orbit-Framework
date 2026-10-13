<?php
namespace HWE\ProductLanding;

/**
 * Idiomas de la landing y registro de sus campos en `hwe-content-i18n`.
 *
 * Los idiomas son los secundarios del sitio (`i18n.locales` del Control Center, vía
 * `hwe_i18n_locales()` del mu-plugin `hwe-content-i18n`), como sufijo de clave (`pt-BR` →
 * `pt_br`). Con un solo idioma no hay nada que traducir: no hay controles por idioma ni campos
 * registrados.
 *
 * Cada campo escalar traducible se declara en el registro `hwe_translatable_fields` del tipo
 * `product` con el nombre `landing_<campo>`: su traducción vive en la meta
 * `_hwe_landing_<campo>_<idioma>` —las mismas claves que ya se guardaban para el inglés, así que
 * no hay migración de datos— y se edita en la caja «Traducciones» de la ficha del producto.
 */
final class Translations {

    public static function register(): void {
        add_filter('hwe_translatable_fields', [self::class, 'registry']);
    }

    /** Sufijos de los idiomas secundarios del sitio; vacío si `hwe-content-i18n` no está o hay un solo idioma. @return list<string> */
    public static function locales(): array {
        if (!function_exists('hwe_i18n_locales')) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map(
            static fn ($locale): string => is_string($locale) ? sanitize_key($locale) : '',
            hwe_i18n_locales()
        ))));
    }

    /** `pt_br` → `PT-BR`: cómo se llama el idioma en el formulario. */
    public static function label(string $suffix): string {
        return strtoupper(str_replace('_', '-', $suffix));
    }

    /** Entrada del registro de un campo: cómo se edita en la caja «Traducciones». */
    private static function input(string $type): string {
        return match ($type) {
            'richtext' => 'html',
            'textarea' => 'textarea',
            default    => 'text',
        };
    }

    /**
     * Declara los campos traducibles de la landing en el tipo `product`.
     *
     * @param mixed $registry
     * @return mixed
     */
    public static function registry($registry) {
        if (!is_array($registry) || !isset($registry['product']['fields']) || !is_array($registry['product']['fields'])) {
            return $registry;
        }
        foreach (Fields::SCALAR_FIELDS as $key => $spec) {
            if (empty($spec['translatable'])) {
                continue;
            }
            $registry['product']['fields']['landing_' . $key] = [
                'label' => 'Landing · ' . $spec['label'],
                'input' => self::input($spec['type']),
            ];
        }
        return $registry;
    }
}
