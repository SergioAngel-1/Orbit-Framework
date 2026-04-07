<?php
/**
 * Validación server-side de órdenes antes de crear en WooCommerce
 * 
 * Previene manipulación de descuentos, fee_lines, set_paid y shipping_lines
 * por parte de clientes que intercepten las peticiones HTTP.
 * 
 * Se ejecuta via el filtro woocommerce_rest_pre_insert_shop_order_object
 * DESPUÉS de que WooCommerce construya el objeto $order pero ANTES de guardarlo.
 * 
 * @package Starter
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validar fee_lines, set_paid y shipping_lines al crear una orden vía REST API
 */
add_filter('woocommerce_rest_pre_insert_shop_order_object', 'starter_validate_order_data', 20, 2);
function starter_validate_order_data($order, $request) {
    $body = $request->get_json_params();
    if (empty($body)) {
        return $order;
    }

    $is_update = $order->get_id() > 0;

    // ── Para actualizaciones (PUT): validar cambio de estado ─────────────
    if ($is_update) {
        $validation = starter_validate_order_status_change($order, $body);
        if (is_wp_error($validation)) {
            return $validation;
        }
        return $order;
    }

    // ── Para creación (POST): validar datos de la orden ────────────────

    // NOTA: La validación de customer_id (anti-IDOR) se hace en el proxy HTTP client
    // (class-wc-http-client.php) donde get_current_user_id() tiene el contexto JWT correcto.
    // En este hook (woocommerce_rest_pre_insert_shop_order_object) la petición llega vía
    // OAuth server-to-server y get_current_user_id() no refleja al usuario final.

    // ── 1. Validar set_paid ──────────────────────────────────────────────
    $validation = starter_validate_set_paid($order, $body);
    if (is_wp_error($validation)) {
        return $validation;
    }

    // ── 2. Validar fee_lines ─────────────────────────────────────────────
    $validation = starter_validate_fee_lines($order, $body);
    if (is_wp_error($validation)) {
        return $validation;
    }

    // ── 3. Validar shipping_lines ────────────────────────────────────────
    $validation = starter_validate_shipping_lines($order, $body);
    if (is_wp_error($validation)) {
        return $validation;
    }

    return $order;
}

/**
 * Validar cambios de estado de orden vía proxy (PUT /orders/{id})
 * 
 * Los usuarios solo pueden hacer transiciones específicas:
 * - cancelled: un usuario puede cancelar su propio pedido si está en pending/on-hold
 * 
 * Transiciones como processing→completed se manejan exclusivamente vía
 * /starter/v1/reviews/confirm-order (que valida propiedad + estado).
 * Los admins acceden directamente a WooCommerce admin, no al proxy.
 */
function starter_validate_order_status_change($order, $body) {
    // Si no se está cambiando el status, permitir (ej: actualizar dirección)
    if (!isset($body['status'])) {
        return true;
    }

    $new_status = sanitize_text_field($body['status']);
    $current_status = $order->get_status();
    $user_id = get_current_user_id();

    // Admins pueden hacer cualquier cambio
    if (current_user_can('manage_woocommerce')) {
        return true;
    }

    // Transiciones permitidas para usuarios normales vía proxy
    $allowed_transitions = array(
        // El usuario puede cancelar un pedido pendiente o en espera
        'pending'  => array('cancelled'),
        'on-hold'  => array('cancelled'),
    );

    $allowed_statuses = isset($allowed_transitions[$current_status]) ? $allowed_transitions[$current_status] : array();

    if (!in_array($new_status, $allowed_statuses, true)) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[Starter Order Validation] Cambio de estado rechazado: usuario %d intentó %s→%s en orden %d',
                $user_id,
                $current_status,
                $new_status,
                $order->get_id()
            ));
        }

        return new WP_Error(
            'forbidden_status_change',
            sprintf(
                'No tienes permiso para cambiar el estado de "%s" a "%s".',
                $current_status,
                $new_status
            ),
            array('status' => 403)
        );
    }

    return true;
}

/**
 * Validar que set_paid solo se use en contextos legítimos
 * 
 * set_paid=true solo es válido si:
 * - payment_method es 'wompi' (tarjeta) — se verificará después vía webhook
 * - payment_method es 'virtual_coins' y los FC cubren el total
 */
function starter_validate_set_paid($order, $body) {
    $set_paid = isset($body['set_paid']) ? (bool) $body['set_paid'] : false;

    if (!$set_paid) {
        return true;
    }

    $payment_method = $order->get_payment_method();

    // Wompi (tarjeta): permitir set_paid — la verificación real la hace el webhook
    if ($payment_method === 'wompi') {
        return true;
    }

    // Virtual Coins: verificar que el saldo cubra el total
    if ($payment_method === 'virtual_coins') {
        $user_id = $order->get_customer_id();
        if (!$user_id) {
            return new WP_Error(
                'invalid_set_paid',
                'set_paid requiere un usuario autenticado para pago con Virtual Coins.',
                array('status' => 403)
            );
        }

        if (function_exists('starter_rp_get_user_points')) {
            $user_points = starter_rp_get_user_points($user_id);
            $balance = isset($user_points['balance']) ? floatval($user_points['balance']) : 0;

            // Obtener tasa de conversión
            $conversion_rate = function_exists('site_get_option') ? floatval(site_get_option('virtual_currency_conversion_rate', 0.1)) : 0.1;
            if (function_exists('Starter_RP')) {
                $options = Starter_RP()->get_options();
                $conversion_rate = floatval($options['points_conversion_rate'] ?? $conversion_rate);
            }

            $order_total = floatval($order->get_total());
            $fc_needed = $conversion_rate > 0 ? ceil($order_total / $conversion_rate) : PHP_INT_MAX;

            if ($balance < $fc_needed) {
                return new WP_Error(
                    'insufficient_virtual_coins',
                    sprintf(
                        'Saldo insuficiente de Virtual Coins. Necesitas %s FC pero tienes %s FC.',
                        number_format($fc_needed),
                        number_format($balance)
                    ),
                    array('status' => 400)
                );
            }
        }

        return true;
    }

    // Cualquier otro método de pago con set_paid=true es sospechoso
    // Forzar set_paid=false para evitar marcar como pagada sin verificación
    $order->set_date_paid(null);
    $order->set_status('pending');

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(sprintf(
            '[Starter Order Validation] set_paid=true rechazado para método "%s" en orden del usuario %d. Forzando pending.',
            $payment_method,
            $order->get_customer_id()
        ));
    }

    return true;
}

/**
 * Validar fee_lines: descuentos de membresía y Virtual Coins
 * 
 * Recalcula los descuentos server-side y compara con los enviados.
 * Si hay discrepancia significativa, rechaza la orden.
 */
function starter_validate_fee_lines($order, $body) {
    $fee_lines = isset($body['fee_lines']) ? $body['fee_lines'] : [];

    if (empty($fee_lines)) {
        return true;
    }

    $user_id = $order->get_customer_id();

    foreach ($fee_lines as $fee) {
        $fee_total = floatval($fee['total'] ?? 0);
        $fee_name = $fee['name'] ?? '';

        // Solo validar fees negativos (descuentos)
        if ($fee_total >= 0) {
            continue;
        }

        $discount_amount = abs($fee_total);

        // ── Fee de descuento por membresía ────────────────────────────────
        if (starter_fee_is_membership_discount($fee_name)) {
            $validation = starter_validate_membership_discount_fee($user_id, $order, $discount_amount);
            if (is_wp_error($validation)) {
                return $validation;
            }
        }

        // ── Fee de Virtual Coins ───────────────────────────────────────────
        if (starter_fee_is_virtual_coins($fee_name)) {
            $validation = starter_validate_virtual_coins_fee($user_id, $discount_amount);
            if (is_wp_error($validation)) {
                return $validation;
            }
        }
    }

    return true;
}

/**
 * Detectar si un fee es de descuento por membresía
 */
function starter_fee_is_membership_discount($name) {
    $name_lower = mb_strtolower($name);
    return strpos($name_lower, 'membresía') !== false
        || strpos($name_lower, 'membresia') !== false
        || strpos($name_lower, 'membership') !== false;
}

/**
 * Detectar si un fee es de Virtual Coins
 */
function starter_fee_is_virtual_coins($name) {
    $name_lower = mb_strtolower($name);
    $vc_name = function_exists('site_get_vc_name') ? mb_strtolower(site_get_vc_name()) : 'virtual coins';
    $vc_short = function_exists('site_get_vc_short') ? mb_strtolower(site_get_vc_short()) : 'vc';
    return strpos($name_lower, $vc_name) !== false
        || strpos($name_lower, $vc_short) !== false;
}

/**
 * Validar descuento de membresía contra la configuración real del usuario
 */
function starter_validate_membership_discount_fee($user_id, $order, $claimed_discount) {
    if (!$user_id) {
        return new WP_Error(
            'invalid_membership_discount',
            'Descuento de membresía requiere usuario autenticado.',
            array('status' => 403)
        );
    }

    // Obtener el porcentaje máximo de descuento permitido para este usuario
    $max_percentage = 0;

    if (function_exists('starter_benefit_registry')) {
        $registry = starter_benefit_registry();

        // Verificar descuento por categoría
        $cat_handler = $registry->get('category_discount');
        if ($cat_handler) {
            $config = $cat_handler->get_config_for_user($user_id);
            if ($config && isset($config['percentage'])) {
                $max_percentage = max($max_percentage, floatval($config['percentage']));
            }
        }

        // Verificar descuento por eventos
        $events_handler = $registry->get('events_discount');
        if ($events_handler) {
            $config = $events_handler->get_config_for_user($user_id);
            if ($config && isset($config['percentage'])) {
                $max_percentage = max($max_percentage, floatval($config['percentage']));
            }
        }
    }

    if ($max_percentage <= 0) {
        return new WP_Error(
            'no_membership_discount',
            'Tu membresía no tiene descuento activo.',
            array('status' => 403)
        );
    }

    // Calcular el descuento máximo posible sobre el subtotal de la orden
    $order_subtotal = floatval($order->get_subtotal());
    $max_allowed_discount = $order_subtotal * ($max_percentage / 100);

    // Tolerancia del 5% para diferencias de redondeo de moneda
    $rounding_buffer = function_exists('site_get_currency_rounding') ? site_get_currency_rounding() * 2 : 100;
    $tolerance = $max_allowed_discount * 0.05;
    $max_with_tolerance = $max_allowed_discount + $tolerance + $rounding_buffer;

    if ($claimed_discount > $max_with_tolerance) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[Starter Order Validation] Descuento de membresía manipulado. Usuario: %d, Reclamado: %s, Máximo permitido: %s (subtotal: %s, porcentaje: %s%%)',
                $user_id,
                number_format($claimed_discount),
                number_format($max_with_tolerance),
                number_format($order_subtotal),
                $max_percentage
            ));
        }

        return new WP_Error(
            'manipulated_membership_discount',
            sprintf(
                'El descuento de membresía excede el máximo permitido. Máximo: %s',
                function_exists('site_format_price') ? site_format_price($max_allowed_discount) : '$' . number_format($max_allowed_discount, 0, ',', '.')
            ),
            array('status' => 400)
        );
    }

    return true;
}

/**
 * Validar descuento de Virtual Coins contra el saldo real del usuario
 */
function starter_validate_virtual_coins_fee($user_id, $claimed_discount) {
    if (!$user_id) {
        return new WP_Error(
            'invalid_fc_discount',
            'Descuento de Virtual Coins requiere usuario autenticado.',
            array('status' => 403)
        );
    }

    if (!function_exists('starter_rp_get_user_points')) {
        // Si el sistema de puntos no está disponible, rechazar descuento FC
        return new WP_Error(
            'fc_system_unavailable',
            'Sistema de Virtual Coins no disponible.',
            array('status' => 500)
        );
    }

    $user_points = starter_rp_get_user_points($user_id);
    $balance = isset($user_points['balance']) ? floatval($user_points['balance']) : 0;

    // Obtener tasa de conversión
    $conversion_rate = function_exists('site_get_option') ? floatval(site_get_option('virtual_currency_conversion_rate', 0.1)) : 0.1;
    if (function_exists('Starter_RP')) {
        $options = Starter_RP()->get_options();
        $conversion_rate = floatval($options['points_conversion_rate'] ?? $conversion_rate);
    }

    // Valor monetario máximo que el usuario puede descontar
    $max_monetary_value = $balance * $conversion_rate;

    // Tolerancia para redondeo de moneda
    $rounding_buffer = function_exists('site_get_currency_rounding') ? site_get_currency_rounding() * 2 : 100;
    $max_with_tolerance = $max_monetary_value + $rounding_buffer;

    if ($claimed_discount > $max_with_tolerance) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[Starter Order Validation] Descuento FC manipulado. Usuario: %d, Reclamado: %s, Máximo: %s (balance: %s FC, rate: %s)',
                $user_id,
                number_format($claimed_discount),
                number_format($max_monetary_value),
                number_format($balance),
                $conversion_rate
            ));
        }

        return new WP_Error(
            'manipulated_fc_discount',
            sprintf(
                'El descuento de Virtual Coins excede tu saldo disponible. Máximo: %s',
                function_exists('site_format_price') ? site_format_price($max_monetary_value) : '$' . number_format($max_monetary_value, 0, ',', '.')
            ),
            array('status' => 400)
        );
    }

    return true;
}

/**
 * Validar shipping_lines contra métodos de envío configurados
 * 
 * Previene que un usuario envíe un precio de envío de $0 cuando debería pagar.
 */
function starter_validate_shipping_lines($order, $body) {
    $shipping_lines = isset($body['shipping_lines']) ? $body['shipping_lines'] : [];

    if (empty($shipping_lines)) {
        return true;
    }

    foreach ($shipping_lines as $shipping) {
        $shipping_total = floatval($shipping['total'] ?? 0);

        // Si el envío es $0, verificar que el usuario tenga el beneficio de envío gratis
        if ($shipping_total <= 0) {
            $user_id = $order->get_customer_id();
            $use_free_delivery = false;

            // Verificar meta_data de la orden para free delivery
            $meta_data = isset($body['meta_data']) ? $body['meta_data'] : [];
            foreach ($meta_data as $meta) {
                if (isset($meta['key']) && $meta['key'] === '_use_free_delivery_membership' && $meta['value'] === 'yes') {
                    $use_free_delivery = true;
                    break;
                }
            }

            if ($use_free_delivery && $user_id) {
                // Verificar que el usuario realmente tenga el beneficio de envío gratis
                if (function_exists('starter_benefit_registry')) {
                    $registry = starter_benefit_registry();
                    $delivery_handler = $registry->get('free_deliveries');

                    if ($delivery_handler) {
                        $is_enabled = $delivery_handler->is_enabled_for_user($user_id);
                        if (!$is_enabled) {
                            if (defined('WP_DEBUG') && WP_DEBUG) {
                                error_log(sprintf(
                                    '[Starter Order Validation] Envío gratis rechazado: usuario %d no tiene beneficio free_deliveries.',
                                    $user_id
                                ));
                            }

                            return new WP_Error(
                                'free_delivery_not_available',
                                'No tienes el beneficio de envío gratis disponible.',
                                array('status' => 400)
                            );
                        }
                    }
                }
            }
        }

        // Validar que el total de envío no sea negativo (manipulación)
        if ($shipping_total < 0) {
            return new WP_Error(
                'invalid_shipping_total',
                'El costo de envío no puede ser negativo.',
                array('status' => 400)
            );
        }
    }

    return true;
}
