<?php
/**
 * Plugin Name: WooCommerce Shipping Config
 * Description: Aplica la configuración de envío del HWE Control Center a WooCommerce:
 *              coste de tarifa plana y umbral de envío gratis configurables desde
 *              Ajustes → HWE Config → Envío. Sin editar WooCommerce directamente.
 * Author:      Headless Web Ecosystem
 * Version:     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Devuelve la config de envío efectiva desde el Control Center.
 * Fallback a valores por defecto si el Control Center no está disponible.
 */
function hwe_shipping_config(): array {
    $get = static function ( array $path, $default ) {
        if ( class_exists( '\\HWE\\ControlCenter\\Storage' ) ) {
            $v = \HWE\ControlCenter\Storage::get( $path, $default );
            return ( $v === null ) ? $default : $v;
        }
        return $default;
    };

    return [
        'flat_rate_cost' => (float) $get( [ 'shipping', 'flat_rate_cost' ], '4.99' ),
        'free_above'     => (float) $get( [ 'shipping', 'free_above' ],     '0'    ),
        'flat_label'     => (string) $get( [ 'shipping', 'flat_label' ],    'Envío estándar' ),
        'free_label'     => (string) $get( [ 'shipping', 'free_label' ],    'Envío gratuito' ),
    ];
}

/**
 * Aplica la config de envío a los métodos calculados por WooCommerce.
 * Se ejecuta después de que WC calcula las tarifas del paquete.
 */
add_filter(
    'woocommerce_package_rates',
    static function ( array $rates, array $package ): array {
        $cfg        = hwe_shipping_config();
        $cart_total = (float) WC()->cart->get_subtotal();
        $free_above = $cfg['free_above'];
        $is_free    = $free_above > 0 && $cart_total >= $free_above;

        foreach ( $rates as $rate_id => $rate ) {
            if ( $rate->method_id === 'flat_rate' ) {
                if ( $is_free ) {
                    // Envío gratis: ponemos coste 0 y cambiamos la etiqueta.
                    $rates[ $rate_id ]->cost  = 0;
                    $rates[ $rate_id ]->label = sanitize_text_field( $cfg['free_label'] );
                    // Vaciamos los impuestos de envío también.
                    $rates[ $rate_id ]->taxes = [];
                } else {
                    $rates[ $rate_id ]->cost  = $cfg['flat_rate_cost'];
                    $rates[ $rate_id ]->label = sanitize_text_field( $cfg['flat_label'] );
                }
            }
        }

        return $rates;
    },
    20,
    2
);
