<?php
/**
 * Redondeo de moneda configurable en pedidos WooCommerce
 * 
 * Ajusta los totales de cada línea del pedido al múltiplo configurado
 * en Site Settings (currency_rounding_multiple). Ejemplo: para COP se usa ×50,
 * para USD se usa ×1 (sin redondeo). Si el múltiplo es 1 o menor, este módulo
 * no hace nada.
 * 
 * @package Starter
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Redondear hacia arriba al múltiplo configurado
 * Usa site_round_currency() si está disponible, sino fallback a ceil con múltiplo 1
 * 
 * @param float $amount
 * @return float
 */
function starter_round_to_currency_multiple($amount) {
    if (function_exists('site_round_currency')) {
        return site_round_currency($amount);
    }
    return round($amount);
}

/**
 * Ajustar totales de líneas del pedido al múltiplo de moneda configurado
 * después de que WooCommerce crea la orden vía REST API.
 * 
 * Hook: woocommerce_rest_insert_shop_order_object
 * Se ejecuta después de que el pedido es creado/actualizado vía REST API.
 * 
 * @param WC_Order $order Objeto de la orden
 * @param WP_REST_Request $request Request original
 * @param bool $creating True si es una orden nueva
 */
function starter_round_order_totals($order, $request, $creating) {
    if (!$creating) {
        return;
    }

    // Obtener múltiplo de redondeo; si es 1 o menos, no hacer nada
    $multiple = function_exists('site_get_currency_rounding') ? site_get_currency_rounding() : 1;
    if ($multiple <= 1) {
        return;
    }

    $adjustments = [];
    $total_adjustment = 0;

    foreach ($order->get_items() as $item_id => $item) {
        $line_total = floatval($item->get_total());
        $rounded_total = starter_round_to_currency_multiple($line_total);
        $diff = $rounded_total - $line_total;

        if (abs($diff) >= 1) {
            $item->set_total($rounded_total);

            // Redondear también el subtotal si difiere
            $line_subtotal = floatval($item->get_subtotal());
            $rounded_subtotal = starter_round_to_currency_multiple($line_subtotal);
            if (abs($rounded_subtotal - $line_subtotal) >= 1) {
                $item->set_subtotal($rounded_subtotal);
            }

            $item->save();

            $adjustments[] = sprintf(
                '%s: %s → %s (+%s)',
                $item->get_name(),
                wc_price($line_total),
                wc_price($rounded_total),
                wc_price($diff)
            );
            $total_adjustment += $diff;
        }
    }

    if (!empty($adjustments)) {
        // Recalcular totales de la orden
        $order->calculate_totals(false);
        $order->save();

        $currency_code = function_exists('site_get_currency_code') ? site_get_currency_code() : 'N/A';

        // Añadir nota privada sutil (no visible para el cliente)
        $note = sprintf(
            'Ajuste automático %s ×%d: %s línea(s) redondeada(s). Diferencia total: +%s.',
            $currency_code,
            $multiple,
            count($adjustments),
            wc_price($total_adjustment)
        );

        $order->add_order_note($note, false, false);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[Starter Currency Rounding] Orden #%d: %s',
                $order->get_id(),
                implode(' | ', $adjustments)
            ));
        }
    }
}
add_action('woocommerce_rest_insert_shop_order_object', 'starter_round_order_totals', 20, 3);
