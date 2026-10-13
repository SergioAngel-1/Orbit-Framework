<?php

namespace HWE\ControlCenter\SchemaGroups;

use HWE\ControlCenter\FreeShippingRule;

/**
 * Esquema del núcleo: tienda (moneda, país, flags) y activación de banners.
 *
 * Trait de `HWE\ControlCenter\Schema`: el árbol de configuración se reparte por tema para que
 * cada archivo se lea de una vez. `Schema::core()` los junta en el orden de las pestañas.
 */
trait Store {

    /**
     * @return array<string,array>
     */
    private static function storeGroups(): array {
        return [

            /* ------------------------------------------------------------------ */
            /* TIENDA / E-COMMERCE                                                 */
            /* ------------------------------------------------------------------ */
            'ecommerce' => [
                'type'     => 'group',
                'label'    => 'Tienda',
                'children' => [
                    'currency'          => [
                        'type'        => 'select',
                        'label'       => 'Moneda',
                        'default'     => 'EUR',
                        'public'      => true,
                        'options'     => self::currencyOptions(),
                        'description' => 'Moneda de la tienda (ISO-4217). Al guardar se sincroniza con la moneda nativa de WooCommerce (Ajustes → General).',
                    ],
                    'country'           => ['type' => 'text',    'label' => 'País por defecto',      'default' => 'ES',   'public' => true],
                    'products_per_page' => ['type' => 'text',    'label' => 'Productos por página',  'default' => '12',   'public' => true],
                    'reviews_enabled'   => ['type' => 'boolean', 'label' => 'Habilitar reseñas',    'default' => false,  'public' => true],
                    'wishlist_enabled'  => ['type' => 'boolean', 'label' => 'Habilitar wishlist',   'default' => false,  'public' => true],
                    'coupons_enabled'   => ['type' => 'boolean', 'label' => 'Habilitar cupones',    'default' => false,  'public' => true],
                    'search_enabled'    => ['type' => 'boolean', 'label' => 'Habilitar búsqueda',   'default' => true,   'public' => true],
                    'checkout_login_required' => [
                        'type'        => 'boolean',
                        'label'       => 'Exigir inicio de sesión para comprar',
                        'default'     => true,
                        'public'      => true,
                        'description' => 'Activado = solo los clientes con cuenta pueden hacer un pedido. Desactivado = también se puede comprar como invitado. Al guardar se sincroniza con el ajuste nativo de WooCommerce «Permitir a los clientes realizar pedidos sin una cuenta».',
                    ],
                    'free_shipping' => [
                        'type'     => 'group',
                        'label'    => 'Envío gratis',
                        'children' => [
                            'enabled'    => [
                                'type'        => 'boolean',
                                'label'       => 'Activar envío gratis',
                                'default'     => false,
                                'description' => 'El envío del pedido sale gratis cuando la compra llega al valor mínimo con productos de los que aplican. Requiere el módulo `shipping` (ver `hwe_shipping_free_rule`).',
                            ],
                            'min_amount' => [
                                'type'        => 'number',
                                'label'       => 'Valor mínimo de compra',
                                'default'     => '0',
                                'min'         => 0,
                                'description' => 'En la moneda de la tienda, sin puntos ni decimales. 0 = sin mínimo.',
                            ],
                            'categories' => [
                                'type'        => 'product_multiselect',
                                'label'       => 'Categorías a las que aplica',
                                'default'     => '',
                                'options'     => FreeShippingRule::categoryOptions(),
                                'description' => 'Sin ninguna categoría ni producto marcado, aplica a todo el catálogo.',
                            ],
                            'products'   => [
                                'type'        => 'product_multiselect',
                                'label'       => 'Productos a los que aplica',
                                'default'     => '',
                                'options'     => FreeShippingRule::productOptions(),
                                'description' => 'Productos sueltos que también suman, además de los de las categorías de arriba.',
                            ],
                        ],
                    ],
                ],
            ],

            /* ------------------------------------------------------------------ */
            /* BANNERS (activación; la autoría vive en el plugin HWE Banners)      */
            /* ------------------------------------------------------------------ */
            'banners' => [
                'type'     => 'group',
                'label'    => 'Banners',
                'children' => [
                    'enabled' => [
                        'type'        => 'boolean',
                        'label'       => 'Activar sistema de banners',
                        'default'     => false,
                        'public'      => true,
                        'description' => 'Activa el carrusel/banners en el frontend. La autoría de los banners vive en el plugin "HWE Banners" (menú Banners de wp-admin).',
                    ],
                ],
            ],

        ];
    }

    /**
     * Opciones del select de moneda: todas las que reconoce WooCommerce
     * (`get_woocommerce_currencies()`, el mismo catálogo que usa internamente
     * para checkout/pedidos/pasarelas), para que el código elegido aquí sea
     * siempre uno que WooCommerce también entienda al sincronizarlo
     * (ver `WooCurrencySync`).
     *
     * @return array<string,string> código ISO-4217 => "CÓDIGO – Nombre"
     */
    private static function currencyOptions(): array {
        if (!function_exists('get_woocommerce_currencies')) {
            return ['EUR' => 'EUR'];
        }

        $options = [];
        foreach (get_woocommerce_currencies() as $code => $name) {
            $options[$code] = $code . ' – ' . $name;
        }
        ksort($options);

        return $options;
    }
}
