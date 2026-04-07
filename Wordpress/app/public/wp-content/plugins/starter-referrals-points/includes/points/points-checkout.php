<?php
/**
 * Procesamiento de puntos en checkout
 * 
 * Funciones para procesar el uso de Virtual Coins durante el checkout.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}



/**
 * Crear cupones dinámicos de Virtual Coins cuando WooCommerce los solicite
 */
function starter_rp_get_virtual_coins_coupon_data($data, $coupon_code) {
    // Verificar si es un cupón de Virtual Coins
    if (strpos($coupon_code, 'virtual-coins-') !== 0) {
        return $data; // No es nuestro cupón
    }
    
    starter_rp_log('Starter RP: Creando cupón dinámico: ' . $coupon_code);
    
    // Extraer información del código del cupón
    // Formato: virtual-coins-{timestamp}-{puntos}fc
    $parts = explode('-', $coupon_code);
    
    if (count($parts) < 4) {
        starter_rp_log('Starter RP: Formato de cupón inválido: ' . $coupon_code);
        return $data; // Formato inválido
    }
    
    $points_part = end($parts); // último elemento: "{puntos}fc"
    $points_used = intval(str_replace('fc', '', $points_part));
    
    if ($points_used <= 0) {
        starter_rp_log('Starter RP: Cantidad de puntos inválida en cupón: ' . $coupon_code);
        return $data;
    }
    
    // Verificar usuario logueado
    if (!is_user_logged_in()) {
        starter_rp_log('Starter RP: Usuario no logueado intentando usar cupón: ' . $coupon_code);
        return $data;
    }
    
    $user_id = get_current_user_id();
    
    // Verificar permisos del usuario
    if (!starter_rp_can_user_use_points($user_id)) {
        starter_rp_log('Starter RP: Usuario sin permisos: ' . $user_id . ' para cupón: ' . $coupon_code);
        return $data;
    }
    
    // Verificar balance disponible
    $user_points = starter_rp_get_user_points($user_id);
    if (!$user_points || $user_points['balance'] < $points_used) {
        starter_rp_log('Starter RP: Balance insuficiente - Requeridos: ' . $points_used . ', Disponibles: ' . ($user_points ? $user_points['balance'] : 0));
        return $data;
    }
    
    // Validar configuraciones del sistema
    $options = Starter_RP()->get_options();
    
    $min_points = $options['min_points_redemption'] ?? 100;
    $max_points = $options['max_points_per_order'] ?? 0;
    
    if ($points_used < $min_points) {
        starter_rp_log('Starter RP: Puntos insuficientes - Mínimo: ' . $min_points . ', Enviados: ' . $points_used);
        return $data;
    }
    
    if ($max_points > 0 && $points_used > $max_points) {
        starter_rp_log('Starter RP: Puntos exceden máximo - Máximo: ' . $max_points . ', Enviados: ' . $points_used);
        return $data;
    }
    
    // Calcular descuento
    $conversion_rate = (float) ($options['points_conversion_rate'] ?? 0.1);
    $discount_amount = $points_used * $conversion_rate;
    
    // Crear datos del cupón dinámico
    $coupon_data = array(
        'discount_type' => 'fixed_cart',
        'coupon_amount' => $discount_amount,
        'individual_use' => 'no',
        'product_ids' => array(),
        'exclude_product_ids' => array(),
        'usage_limit' => 1,
        'usage_limit_per_user' => 1,
        'limit_usage_to_x_items' => null,
        'free_shipping' => 'no',
        'product_categories' => array(),
        'exclude_product_categories' => array(),
        'exclude_sale_items' => 'no',
        'minimum_amount' => '',
        'maximum_amount' => '',
        'customer_email' => array(),
        'description' => 'Descuento dinámico por uso de ' . $points_used . ' Virtual Coins'
    );
    
    starter_rp_log('Starter RP: Cupón dinámico creado - ' . $coupon_code . ' por ' . wc_price($discount_amount) . ' (' . $points_used . ' FC)');
    
    return $coupon_data;
}
add_filter('woocommerce_get_shop_coupon_data', 'starter_rp_get_virtual_coins_coupon_data', 10, 2);

/**
 * Procesar el uso de Virtual Coins cuando se aplica un cupón dinámico
 */
function starter_rp_process_virtual_coins_coupon_usage($order_id) {
    starter_rp_log('Starter RP: Iniciando procesamiento de cupón para pedido ' . $order_id);
    
    $order = wc_get_order($order_id);
    
    if (!$order) {
        starter_rp_log('Starter RP: No se pudo obtener el pedido ' . $order_id);
        return;
    }
    
    // Verificar cupones aplicados
    $coupons = $order->get_coupon_codes();
    $virtual_coins_coupon = null;
    
    foreach ($coupons as $coupon_code) {
        if (strpos($coupon_code, 'virtual-coins-') === 0) {
            $virtual_coins_coupon = $coupon_code;
            break;
        }
    }
    
    if (!$virtual_coins_coupon) {
        starter_rp_log('Starter RP: No hay cupón de Virtual Coins en el pedido ' . $order_id);
        return; // No hay cupón de Virtual Coins
    }
    
    starter_rp_log('Starter RP: Cupón encontrado: ' . $virtual_coins_coupon);
    
    // Extraer puntos del código del cupón
    $parts = explode('-', $virtual_coins_coupon);
    $points_part = end($parts);
    $points_used = intval(str_replace('fc', '', $points_part));
    
    if ($points_used <= 0) {
        starter_rp_log('Starter RP: Error al extraer puntos del cupón ' . $virtual_coins_coupon);
        return;
    }
    
    $user_id = $order->get_user_id();
    if (!$user_id) {
        starter_rp_log('Starter RP: Pedido ' . $order_id . ' no tiene usuario asignado');
        return;
    }
    
    // Verificar si ya se procesaron los puntos
    if ($order->get_meta('_virtual_coins_processed')) {
        starter_rp_log('Starter RP: Virtual Coins ya procesados para pedido ' . $order_id);
        return;
    }
    
    // Calcular descuento aplicado
    $options = Starter_RP()->get_options();
    $conversion_rate = (float) ($options['points_conversion_rate'] ?? 0.1);
    
    $discount_amount = $points_used * $conversion_rate;
    
    // Deducir puntos del usuario
    $result = starter_rp_use_points(
        $user_id,
        $points_used,
        sprintf('Puntos utilizados en pedido #%d (cupón: %s)', $order_id, $virtual_coins_coupon),
        $order_id
    );
    
    if ($result) {
        // Guardar metadatos (prefijos nuevo y legacy para compatibilidad)
        $order->update_meta_data('_virtual_coins_used', $points_used);
        $order->update_meta_data('_virtual_coins_discount', $discount_amount);
        $order->update_meta_data('_starter_points_used', $points_used);
        $order->update_meta_data('_starter_points_discount', $discount_amount);
        $order->update_meta_data('_virtual_coins_coupon_code', $virtual_coins_coupon);
        $order->update_meta_data('_virtual_coins_processed', 'yes');
        $order->save();
        
        // Añadir nota al pedido
        $order->add_order_note(
            sprintf(
                'Se utilizaron %d Virtual Coins (descuento: %s) mediante cupón %s',
                $points_used,
                wc_price($discount_amount),
                $virtual_coins_coupon
            ),
            false,
            true
        );
        
        starter_rp_log('Starter RP: Procesado exitosamente cupón ' . $virtual_coins_coupon . ' - ' . $points_used . ' FC deducidos');
    } else {
        starter_rp_log('Starter RP: Error al deducir puntos para cupón ' . $virtual_coins_coupon);
        
        $order->add_order_note(
            sprintf(
                'Error al procesar cupón de Virtual Coins: %s',
                $virtual_coins_coupon
            )
        );
    }
}
add_action('woocommerce_checkout_order_processed', 'starter_rp_process_virtual_coins_coupon_usage', 10, 1);
add_action('woocommerce_order_status_processing', 'starter_rp_process_virtual_coins_coupon_usage', 10, 1);
add_action('woocommerce_order_status_completed', 'starter_rp_process_virtual_coins_coupon_usage', 10, 1);

/**
 * Revertir el uso de Virtual Coins si se cancela el pedido
 */
function starter_rp_revert_checkout_points_usage($order_id) {
    $order = wc_get_order($order_id);
    
    if (!$order) {
        return;
    }
    
    // Verificar si se procesaron Virtual Coins
    $coins_processed = $order->get_meta('_virtual_coins_processed');
    
    if (!$coins_processed) {
        return; // No se procesaron puntos
    }
    
    $virtual_coins_used = $order->get_meta('_virtual_coins_used');
    if (!$virtual_coins_used) {
        $virtual_coins_used = $order->get_meta('_starter_points_used');
    }
    $user_id = $order->get_user_id();
    
    if (!$virtual_coins_used || !$user_id) {
        return;
    }
    
    // Devolver los puntos al usuario
    $result = starter_rp_add_points(
        $user_id,
        $virtual_coins_used,
        'refund',
        sprintf('Reembolso de Virtual Coins por cancelación del pedido #%d', $order_id),
        $order_id,
        null // Sin fecha de expiración para reembolsos
    );
    
    if ($result) {
        // Marcar como revertido
        $order->update_meta_data('_virtual_coins_processed', 'reverted');
        
        // Obtener información del cupón usado
        $coupon_code = $order->get_meta('_virtual_coins_coupon_code');
        
        $order->save();
        
        // Añadir nota al pedido
        $order->add_order_note(
            sprintf(
                'Se reembolsaron %d Virtual Coins al usuario por cancelación del pedido%s',
                $virtual_coins_used,
                $coupon_code ? ' (cupón: ' . $coupon_code . ')' : ''
            ),
            false,
            true
        );
        
        starter_rp_log('Starter RP: Reembolsados ' . $virtual_coins_used . ' Virtual Coins para pedido cancelado ' . $order_id . ($coupon_code ? ' (cupón: ' . $coupon_code . ')' : ''));
    }
}
add_action('woocommerce_order_status_cancelled', 'starter_rp_revert_checkout_points_usage', 10, 1);
add_action('woocommerce_order_status_refunded', 'starter_rp_revert_checkout_points_usage', 10, 1);
add_action('woocommerce_order_status_failed', 'starter_rp_revert_checkout_points_usage', 10, 1);

/**
 * Procesar aplicación de Virtual Coins cuando un pedido es creado vía REST API.
 * Este hook cubre el flujo headless donde no se dispara `woocommerce_checkout_order_processed`.
 *
 * @param WC_Order        $order     Objeto del pedido recién creado.
 * @param WP_REST_Request $request   Objeto de la solicitud REST.
 * @param bool            $creating  True si el pedido se está creando (false si es update).
 */
function starter_rp_handle_rest_order_creation($order, $request, $creating) {
    starter_rp_log('Starter RP: Hook REST disparado - Order ID: ' . ($order ? $order->get_id() : 'null') . ', Creating: ' . ($creating ? 'true' : 'false'));
    
    // Solo procesar en la creación inicial del pedido
    if (!$creating || !($order instanceof WC_Order)) {
        starter_rp_log('Starter RP: Hook REST - No es creación o no es WC_Order válido');
        return;
    }

    // Evitar procesar varias veces
    if ($order->get_meta('_virtual_coins_processed')) {
        starter_rp_log('Starter RP: Hook REST - Ya procesado previamente');
        return;
    }

    // Comprobar si el pedido incluye meta de puntos utilizados
    $points_used = (int) $order->get_meta('_virtual_coins_used');
    starter_rp_log('Starter RP: Hook REST - Puntos desde meta: ' . $points_used);

    if ($points_used <= 0) {
        // Buscar en fees negativos de Virtual Coins
        $fees = $order->get_fees();
        starter_rp_log('Starter RP: Hook REST - Número de fees: ' . count($fees));
        foreach ($fees as $fee) {
            $fee_name = $fee->get_name();
            starter_rp_log('Starter RP: Hook REST - Fee encontrado: ' . $fee_name . ' (' . $fee->get_total() . ')');
            if (strpos($fee_name, 'Virtual Coins') !== false && $fee->get_total() < 0) {
                // Extraer cantidad de puntos del nombre del fee
                if (preg_match('/(\d+)\s*Virtual Coins/', $fee_name, $matches)) {
                    $points_used = intval($matches[1]);
                    starter_rp_log('Starter RP: Hook REST - Puntos extraídos del fee: ' . $points_used);
                    break;
                }
            }
        }
        
        // Si no hay fees, buscar cupones por compatibilidad
        if ($points_used <= 0) {
            $coupon_codes = $order->get_coupon_codes();
            starter_rp_log('Starter RP: Hook REST - Cupones: ' . implode(', ', $coupon_codes));
            foreach ($coupon_codes as $code) {
                if (strpos($code, 'virtual-coins-') === 0) {
                    $parts = explode('-', $code);
                    $points_part = end($parts);
                    $points_used = intval(str_replace('fc', '', $points_part));
                    starter_rp_log('Starter RP: Hook REST - Puntos extraídos del cupón: ' . $points_used);
                    break;
                }
            }
        }
    }

    if ($points_used <= 0) {
        // No hay puntos que procesar
        starter_rp_log('Starter RP: Hook REST - No hay puntos para procesar');
        return;
    }

    starter_rp_log('Starter RP: Hook REST - Procesando ' . $points_used . ' puntos para pedido ' . $order->get_id());

    $options          = Starter_RP()->get_options();
    $conversion_rate  = (float) ($options['points_conversion_rate'] ?? 0.1);
    $expected_discount = $points_used * $conversion_rate;

    // Verificar si ya existe un fee de descuento de Virtual Coins
    $discount_already_applied = false;
    $fees = $order->get_fees();
    foreach ($fees as $fee) {
        if (strpos($fee->get_name(), 'Virtual Coins') !== false && $fee->get_total() < 0) {
            $discount_already_applied = true;
            break;
        }
    }

    if (!$discount_already_applied) {
        // No existe descuento, añadir fee negativo para representar el uso de puntos
        $fee = new WC_Order_Item_Fee();
        $fee->set_name(sprintf(__('Descuento por %d Virtual Coins', 'starter-rp'), $points_used));
        $fee->set_amount(-$expected_discount);
        $fee->set_total(-$expected_discount);
        $fee->set_tax_class('');
        $fee->set_tax_status('none');
        $order->add_item($fee);

        // Recalcular totales para aplicar el descuento
        $order->calculate_totals(true);
        starter_rp_log('Starter RP: Hook REST - Fee de descuento añadido: ' . $expected_discount);
    }

    // Descontar puntos al usuario y registrar transacción si aún no se ha hecho
    $user_id = $order->get_user_id();
    starter_rp_log('Starter RP: Hook REST - User ID: ' . $user_id);
    if ($user_id) {
        $deducted = starter_rp_use_points(
            $user_id,
            $points_used,
            sprintf('Puntos utilizados en pedido #%d (REST)', $order->get_id()),
            $order->get_id()
        );

        starter_rp_log('Starter RP: Hook REST - Resultado deducción: ' . ($deducted ? 'éxito' : 'fallo'));
        if ($deducted) {
            $order->add_order_note(sprintf('Se utilizaron %d Virtual Coins como descuento (REST API).', $points_used));
            starter_rp_log('Starter RP: Hook REST - Nota añadida al pedido');
        } else {
            starter_rp_log('Starter RP: Hook REST - ERROR: No se pudieron deducir los puntos');
        }
    }

    // Marcar como procesado para evitar duplicados
    $order->update_meta_data('_virtual_coins_discount', $expected_discount);
    $order->update_meta_data('_starter_points_used', $points_used);
    $order->update_meta_data('_starter_points_discount', $expected_discount);
    $order->update_meta_data('_virtual_coins_processed', 'yes');
    $order->save();
    starter_rp_log('Starter RP: Hook REST - Metadatos guardados y pedido marcado como procesado');
}
add_action('woocommerce_rest_insert_shop_order', 'starter_rp_handle_rest_order_creation', 10, 3);

/**
 * Hook adicional para procesar puntos después de que el pedido esté completamente creado
 * Se ejecuta como respaldo si el hook REST no funciona
 */
function starter_rp_process_rest_order_points($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) {
        starter_rp_log('Starter RP: Hook adicional - No se pudo obtener el pedido ' . $order_id);
        return;
    }
    
    // Solo procesar si aún no se han procesado los puntos
    if ($order->get_meta('_virtual_coins_processed')) {
        return;
    }
    
    // Verificar si tiene metadatos de puntos utilizados
    $points_used = (int) $order->get_meta('_virtual_coins_used');
    
    if ($points_used <= 0) {
        // Buscar en fees
        $fees = $order->get_fees();
        foreach ($fees as $fee) {
            $fee_name = $fee->get_name();
            if (strpos($fee_name, 'Virtual Coins') !== false && $fee->get_total() < 0) {
                if (preg_match('/(\d+)\s*Virtual Coins/', $fee_name, $matches)) {
                    $points_used = intval($matches[1]);
                    starter_rp_log('Starter RP: Hook adicional - Puntos extraídos del fee: ' . $points_used);
                    break;
                }
            }
        }
    }
    
    if ($points_used <= 0) {
        return;
    }
    
    $user_id = $order->get_user_id();
    if (!$user_id) {
        starter_rp_log('Starter RP: Hook adicional - No hay user_id en el pedido');
        return;
    }
    
    starter_rp_log('Starter RP: Hook adicional - Procesando ' . $points_used . ' puntos para usuario ' . $user_id);
    
    // Deducir puntos
    $deducted = starter_rp_use_points(
        $user_id,
        $points_used,
        sprintf('Puntos utilizados en pedido #%d', $order_id),
        $order_id
    );
    
    if ($deducted) {
        $order->update_meta_data('_virtual_coins_processed', 'yes');
        $order->save();
        $order->add_order_note(sprintf('Se utilizaron %d Virtual Coins como descuento (Hook adicional).', $points_used));
        starter_rp_log('Starter RP: Hook adicional - Puntos deducidos exitosamente');
    } else {
        starter_rp_log('Starter RP: Hook adicional - ERROR: No se pudieron deducir los puntos');
    }
}
add_action('woocommerce_new_order', 'starter_rp_process_rest_order_points', 20, 1);
add_action('woocommerce_checkout_order_processed', 'starter_rp_process_rest_order_points', 20, 1);

 