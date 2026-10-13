<?php

namespace HWE\ControlCenter;

/**
 * Regla de «Envío gratis» (Control Center → Tienda → Envío gratis).
 *
 * El panel solo GUARDA la regla; quien la aplica es el módulo `shipping`, que la pide por
 * el filtro `hwe_shipping_free_rule` para seguir sin conocer el Control Center. Sin el
 * módulo `shipping`, esta regla no tiene efecto.
 */
class FreeShippingRule {

    private const PATH = ['ecommerce', 'free_shipping'];

    public static function register(): void {
        add_filter('hwe_shipping_free_rule', [self::class, 'rule']);
    }

    /**
     * @return array{enabled:bool,min_amount:float,categories:string[],products:string[]}
     */
    public static function rule(): array {
        $stored = Storage::get(self::PATH, []);
        $stored = is_array($stored) ? $stored : [];

        return [
            'enabled'    => !empty($stored['enabled']),
            // Unidades mayores de la moneda de la tienda (p. ej. 150000).
            'min_amount' => max(0.0, (float) ($stored['min_amount'] ?? 0)),
            'categories' => self::splitSlugs((string) ($stored['categories'] ?? '')),
            'products'   => self::splitSlugs((string) ($stored['products'] ?? '')),
        ];
    }

    /**
     * WooCommerce guarda en la sesión las tarifas ya calculadas de cada
     * paquete, con la versión del transient `shipping` dentro de la clave: sin
     * subirla, un carrito abierto seguiría con el envío de la regla anterior
     * hasta que cambiara su contenido o su destino.
     */
    public static function flushRates(): void {
        if (class_exists('WC_Cache_Helper')) {
            \WC_Cache_Helper::get_transient_version('shipping', true);
        }
    }

    /**
     * Opciones del selector de categorías: TODAS las de producto. Las ya guardadas que no
     * existan se conservan, o el primer guardado del panel las borraría (el saneado
     * descarta lo que no esté en la lista).
     *
     * @return array<string,string> slug => etiqueta
     */
    public static function categoryOptions(): array {
        $options = [];
        if (function_exists('taxonomy_exists') && taxonomy_exists('product_cat')) {
            $terms = get_terms([
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
            ]);
            if (!is_wp_error($terms) && is_array($terms)) {
                foreach ($terms as $term) {
                    if ($term instanceof \WP_Term) {
                        $options[$term->slug] = ($term->parent ? '— ' : '') . $term->name;
                    }
                }
            }
        }
        return self::withStored($options, 'categories');
    }

    /**
     * Opciones del selector de productos: los publicados, por nombre. Una sola
     * consulta de dos columnas —no objetos `WC_Product`—, porque el schema se
     * construye también fuera del panel.
     *
     * @return array<string,string> slug => nombre
     */
    public static function productOptions(): array {
        $options = null;
        if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb']) && method_exists($GLOBALS['wpdb'], 'get_results')) {
            $rows = $GLOBALS['wpdb']->get_results(
                "SELECT post_name, post_title FROM {$GLOBALS['wpdb']->posts}
                 WHERE post_type = 'product' AND post_status = 'publish'
                 ORDER BY post_title ASC"
            );
            $options = [];
            foreach ((array) $rows as $row) {
                $options[(string) $row->post_name] = (string) $row->post_title;
            }
        }
        return self::withStored((array) $options, 'products');
    }

    /**
     * @param array<string,string> $options
     * @return array<string,string>
     */
    private static function withStored(array $options, string $key): array {
        $stored = self::splitSlugs((string) Storage::get([...self::PATH, $key], ''));
        foreach ($stored as $slug) {
            if (!array_key_exists($slug, $options)) {
                $options[$slug] = $slug . ' (no encontrado)';
            }
        }
        return $options;
    }

    /** @return string[] */
    private static function splitSlugs(string $value): array {
        return array_values(array_filter(array_map('trim', explode('|', $value)), static fn (string $s): bool => $s !== ''));
    }
}
