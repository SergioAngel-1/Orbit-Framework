<?php
/**
 * Transacciones Invisibles de Virtual Coins para Pedidos
 * 
 * Cuando un pedido se completa (cualquier método de pago):
 * 1. Se crea una transacción de "compra de FC" por el monto del pedido
 * 2. Se crea una transacción de "pago con FC" para el pedido
 * 
 * Esto aplica para TODOS los métodos de pago:
 * - Tarjeta (se procesa cuando Wompi confirma)
 * - Transferencia bancaria (se procesa cuando admin marca como completo)
 * - Efectivo (se procesa cuando admin marca como completo)
 * 
 * @package Starter
 * @since 1.0.0
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hook cuando un pedido cambia a estado "completed"
 * Ejecuta las transacciones invisibles de FC
 * 
 * FIX #1 + #4: Bloqueo atómico con flag "processing" para evitar race conditions.
 * FIX #2: Usa $wpdb->insert_id en lugar de query frágil ORDER BY id DESC.
 * FIX #3: Rollback con starter_rp_deduct_points() y tipo 'reversal'.
 * FIX #5: Logs informativos condicionados a WP_DEBUG.
 */
add_action('woocommerce_order_status_completed', 'starter_process_order_fc_transactions', 10, 1);
function starter_process_order_fc_transactions($order_id) {
    global $wpdb;
    
    $order = wc_get_order($order_id);
    
    if (!$order) {
        error_log('[Starter FC Transactions] Pedido no encontrado: ' . $order_id);
        return;
    }
    
    $user_id = $order->get_user_id();
    
    if (!$user_id) {
        error_log('[Starter FC Transactions] Pedido sin usuario asociado: ' . $order_id);
        return;
    }
    
    // ── FIX #1 + #4: Bloqueo atómico con post_meta ─────────────────────────
    // Usar update_post_meta atómico para evitar race conditions.
    // Si ya está en 'yes' o 'processing', otro hilo ya lo está manejando.
    $fc_processed = get_post_meta($order_id, '_starter_fc_transactions_processed', true);
    
    if ($fc_processed === 'yes') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Starter FC Transactions] Transacciones ya procesadas para pedido: ' . $order_id);
        }
        return;
    }
    
    // Si ya está en processing, verificar timeout (60s)
    if ($fc_processed === 'processing') {
        $processing_since = get_post_meta($order_id, '_starter_fc_processing_started', true);
        if ($processing_since && (time() - intval($processing_since)) < 60) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Starter FC Transactions] Pedido #' . $order_id . ' ya en procesamiento por otro hilo');
            }
            return;
        }
        // Más de 60s → asumir que el proceso anterior falló, reintentar
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Starter FC Transactions] Pedido #' . $order_id . ' en processing >60s, reintentando');
        }
    }
    
    // Marcar como "processing" ANTES de empezar operaciones
    // Paso 1: Asegurar que el meta existe con valor vacío (idempotente, no-op si ya existe).
    // add_post_meta con $unique=true no inserta si ya hay una fila con esta meta_key.
    // Aquí la race window de add_post_meta no importa porque el valor es '' (no 'processing'),
    // así que incluso si dos hilos insertan, el lock real lo hace el UPDATE atómico abajo.
    add_post_meta($order_id, '_starter_fc_transactions_processed', '', true);
    
    // Paso 2: UPDATE atómico — solo un hilo puede cambiar meta_value de '' o 'processing expirado'
    // a 'processing'. Si rows_affected = 0, otro hilo ya tomó el lock.
    $rows_affected = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->postmeta} SET meta_value = 'processing' 
         WHERE post_id = %d AND meta_key = '_starter_fc_transactions_processed' AND meta_value IN ('', %s)",
        $order_id, $fc_processed ?: ''
    ));
    
    if ($rows_affected === 0) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Starter FC Transactions] Pedido #' . $order_id . ' — otro hilo tomó el lock');
        }
        return;
    }
    
    // Guardar timestamp de inicio de procesamiento
    update_post_meta($order_id, '_starter_fc_processing_started', time());
    
    // ── Guards ───────────────────────────────────────────────────────────────
    
    // Verificar si este pedido tiene un pago con tarjeta pendiente de procesar
    // En ese caso, checkout-card-payment.php se encargará de las transacciones FC
    // cuando Wompi confirme el pago (evita duplicados para pagos PSE/Nequi que quedan PENDING)
    $card_payment_pending = get_post_meta($order_id, '_starter_card_payment_pending', true);
    if ($card_payment_pending === 'yes') {
        // Liberar el lock — no nos corresponde procesar este pedido
        update_post_meta($order_id, '_starter_fc_transactions_processed', '');
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $card_reference = get_post_meta($order_id, '_starter_card_payment_reference', true);
            error_log(sprintf(
                '[Starter FC Transactions] Pedido #%d tiene pago con tarjeta pendiente (Ref: %s). Esperando confirmación de Wompi.',
                $order_id, $card_reference
            ));
        }
        return;
    }
    
    // Belt-and-suspenders: si el método de pago es 'wompi', verificar directamente
    // en la tabla de pagos con tarjeta. Esto cubre la race condition donde set_paid=true
    // dispara woocommerce_order_status_completed ANTES de que link-order haya marcado
    // _starter_card_payment_pending en la orden.
    if ($order->get_payment_method() === 'wompi') {
        $card_ref_from_meta = get_post_meta($order_id, '_starter_card_payment_reference', true);
        $has_card_payment = false;
        
        if (!empty($card_ref_from_meta)) {
            $has_card_payment = true;
        } else {
            // Buscar en la tabla de pagos pendientes por order_id
            $card_table = $wpdb->prefix . 'starter_pending_card_payments';
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $card_table)) === $card_table) {
                $card_record = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, reference, status FROM `{$card_table}` WHERE order_id = %d LIMIT 1",
                    $order_id
                ));
                if ($card_record) {
                    $has_card_payment = true;
                }
            }
        }
        
        if ($has_card_payment) {
            // checkout-card-payment processor ya maneja (o manejará) las transacciones FC
            update_post_meta($order_id, '_starter_fc_transactions_processed', '');
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[Starter FC Transactions] Pedido #%d usa Wompi como método de pago (Ref: %s). Delegando FC a card-payment processor.',
                    $order_id, $card_ref_from_meta ?: 'lookup-by-order'
                ));
            }
            return;
        }
    }
    
    // Verificar que las funciones de puntos estén disponibles
    if (!function_exists('starter_rp_add_points') || !function_exists('starter_rp_use_points')) {
        error_log('[Starter FC Transactions] Funciones de puntos no disponibles');
        update_post_meta($order_id, '_starter_fc_transactions_processed', '');
        return;
    }
    
    $order_total = floatval($order->get_total());
    $payment_method = $order->get_payment_method();
    
    // Calcular FC equivalentes (usando tasa de conversión del sistema)
    $conversion_rate = function_exists('site_get_option') ? floatval(site_get_option('virtual_currency_conversion_rate', 0.1)) : 0.1;
    if (function_exists('Starter_RP')) {
        $options = Starter_RP()->get_options();
        $conversion_rate = floatval($options['points_conversion_rate'] ?? $conversion_rate);
    }
    
    // FC necesarios para cubrir el pedido
    $fc_for_order = $conversion_rate > 0 ? round($order_total / $conversion_rate) : 0;
    
    if ($fc_for_order <= 0) {
        error_log('[Starter FC Transactions] FC calculados es 0 o negativo para pedido: ' . $order_id);
        update_post_meta($order_id, '_starter_fc_transactions_processed', '');
        return;
    }
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(sprintf(
            '[Starter FC Transactions] Procesando pedido #%d - User: %d, Total: %s, FC: %d, Método: %s',
            $order_id, $user_id, $order_total, $fc_for_order, $payment_method
        ));
    }
    
    // ── PASO 1: Crear transacción de "compra de FC" ─────────────────────────
    $purchase_description = sprintf(
        'Compra de %s Virtual Coins para aporte #%d',
        number_format($fc_for_order),
        $order_id
    );
    
    $fc_purchase_result = starter_rp_add_points(
        $user_id,
        $fc_for_order,
        'checkout_purchase', // Tipo específico para compras de checkout
        $purchase_description,
        $order_id, // Referencia al pedido
        0
    );
    
    if (!$fc_purchase_result) {
        error_log('[Starter FC Transactions] Error al crear transacción de compra de FC para pedido: ' . $order_id);
        update_post_meta($order_id, '_starter_fc_transactions_processed', '');
        return;
    }
    
    // FIX #2: Obtener TX ID de forma segura usando $wpdb->insert_id
    // starter_rp_add_points() hace $wpdb->insert() internamente,
    // así que insert_id contiene el ID de la última inserción
    $fc_purchase_transaction_id = $wpdb->insert_id;
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(sprintf(
            '[Starter FC Transactions] FC comprados - Pedido: #%d, User: %d, FC: %d, TxID: %s',
            $order_id, $user_id, $fc_for_order, $fc_purchase_transaction_id
        ));
    }
    
    // ── PASO 2: Crear transacción de "pago con FC" ──────────────────────────
    $payment_description = sprintf(
        'Aporte #%d pagado con %s Virtual Coins',
        $order_id,
        number_format($fc_for_order)
    );
    
    // FIX #7: skip_balance_check=true porque ACABAMOS de acreditar estos FC en PASO 1.
    // Esto elimina la race condition donde otro proceso concurrente drena el balance
    // entre add_points (PASO 1) y use_points (PASO 2).
    $fc_payment_result = starter_rp_use_points(
        $user_id,
        $fc_for_order,
        $payment_description,
        $order_id, // Referencia al pedido
        true       // skip_balance_check: flujo interno atómico
    );
    
    if (!$fc_payment_result) {
        error_log('[Starter FC Transactions] Error al crear transacción de pago con FC para pedido: ' . $order_id);
        
        // FIX #3+#7: Revertir con operación directa a DB para evitar que el balance check
        // de deduct_points también falle por la misma race condition.
        $points_table = $wpdb->prefix . 'starter_user_points';
        $transactions_table = $wpdb->prefix . 'starter_points_transactions';
        
        $wpdb->query($wpdb->prepare("
            UPDATE $points_table SET 
                points = points - %d,
                last_update = %s
            WHERE user_id = %d
        ", $fc_for_order, current_time('mysql'), $user_id));
        
        $wpdb->insert($transactions_table, [
            'user_id'         => $user_id,
            'points'          => -$fc_for_order,
            'type'            => 'reversal',
            'description'     => sprintf('Reversión de compra FC por error en aporte - Pedido #%d', $order_id),
            'reference_id'    => $order_id,
            'expiration_date' => null,
            'created_at'      => current_time('mysql'),
        ]);
        
        if (function_exists('starter_rp_invalidate_user_points_cache_now')) {
            starter_rp_invalidate_user_points_cache_now($user_id);
        }
        
        error_log(sprintf(
            '[Starter FC Transactions] Reversión directa aplicada para pedido #%d - %d FC revertidos para usuario %d',
            $order_id, $fc_for_order, $user_id
        ));
        
        // Liberar lock para permitir reintento
        update_post_meta($order_id, '_starter_fc_transactions_processed', '');
        return;
    }
    
    // FIX #2: Obtener TX ID de forma segura usando $wpdb->insert_id
    $fc_payment_transaction_id = $wpdb->insert_id;
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(sprintf(
            '[Starter FC Transactions] FC usados para aporte - Pedido: #%d, User: %d, FC: %d, TxID: %s',
            $order_id, $user_id, $fc_for_order, $fc_payment_transaction_id
        ));
    }
    
    // ── Marcar como completado ───────────────────────────────────────────────
    update_post_meta($order_id, '_starter_fc_transactions_processed', 'yes');
    update_post_meta($order_id, '_starter_fc_purchase_transaction_id', $fc_purchase_transaction_id);
    update_post_meta($order_id, '_starter_fc_payment_transaction_id', $fc_payment_transaction_id);
    update_post_meta($order_id, '_starter_fc_amount', $fc_for_order);
    
    // Agregar nota al pedido
    $order->add_order_note(sprintf(
        'Transacciones de Virtual Coins procesadas: %s FC comprados y utilizados como aporte voluntario.',
        number_format($fc_for_order)
    ));
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(sprintf(
            '[Starter FC Transactions] Pedido #%d completado exitosamente - FC: %d',
            $order_id, $fc_for_order
        ));
    }
    
    // Disparar acción para que otros módulos puedan reaccionar
    do_action('starter_order_fc_transactions_completed', $order_id, $user_id, $fc_for_order);
}

/**
 * Mostrar información de transacciones FC en el admin del pedido
 */
add_action('woocommerce_admin_order_data_after_billing_address', 'starter_display_order_fc_info', 10, 1);
function starter_display_order_fc_info($order) {
    $fc_processed = get_post_meta($order->get_id(), '_starter_fc_transactions_processed', true);
    
    if ($fc_processed !== 'yes') {
        return;
    }
    
    $fc_amount = get_post_meta($order->get_id(), '_starter_fc_amount', true);
    $fc_purchase_id = get_post_meta($order->get_id(), '_starter_fc_purchase_transaction_id', true);
    $fc_payment_id = get_post_meta($order->get_id(), '_starter_fc_payment_transaction_id', true);
    
    ?>
    <div class="order_data_column" style="margin-top: 20px; padding: 10px; background: #f0f6fc; border-left: 4px solid #2271b1;">
        <h4 style="margin: 0 0 10px 0; color: #2271b1;">
            <span class="dashicons dashicons-money-alt" style="vertical-align: middle;"></span>
            Transacciones de Virtual Coins
        </h4>
        <p style="margin: 5px 0;">
            <strong>FC Procesados:</strong> <?php echo number_format($fc_amount); ?> FC
        </p>
        <p style="margin: 5px 0; font-size: 12px; color: #666;">
            <strong>Compra FC (TX #<?php echo esc_html($fc_purchase_id); ?>):</strong> Adquisición de Virtual Coins
        </p>
        <p style="margin: 5px 0; font-size: 12px; color: #666;">
            <strong>Aporte FC (TX #<?php echo esc_html($fc_payment_id); ?>):</strong> Contribución voluntaria al fondo común
        </p>
    </div>
    <?php
}

/**
 * FIX #6: Agregar columna de FC en la lista de pedidos del admin
 * Registra hooks tanto para post-type clásico como para HPOS
 */
add_filter('manage_edit-shop_order_columns', 'starter_add_fc_column_to_orders', 20);
add_filter('woocommerce_shop_order_list_table_columns', 'starter_add_fc_column_to_orders', 20);
function starter_add_fc_column_to_orders($columns) {
    $new_columns = array();
    
    foreach ($columns as $key => $column) {
        $new_columns[$key] = $column;
        
        // Agregar después de la columna de total
        if ($key === 'order_total') {
            $new_columns['fc_transactions'] = 'Virtual Coins';
        }
    }
    
    return $new_columns;
}

add_action('manage_shop_order_posts_custom_column', 'starter_display_fc_column_content', 10, 2);
add_action('woocommerce_shop_order_list_table_custom_column', 'starter_display_fc_column_content_hpos', 10, 2);

function starter_display_fc_column_content($column, $post_id) {
    if ($column !== 'fc_transactions') {
        return;
    }
    starter_render_fc_column_value($post_id);
}

function starter_display_fc_column_content_hpos($column, $order) {
    if ($column !== 'fc_transactions') {
        return;
    }
    // HPOS pasa el objeto WC_Order, no el post_id
    $order_id = is_object($order) ? $order->get_id() : $order;
    starter_render_fc_column_value($order_id);
}

/**
 * Renderizar el valor de la columna FC (compartido entre hooks clásico y HPOS)
 */
function starter_render_fc_column_value($order_id) {
    $fc_processed = get_post_meta($order_id, '_starter_fc_transactions_processed', true);
    
    if ($fc_processed === 'yes') {
        $fc_amount = get_post_meta($order_id, '_starter_fc_amount', true);
        echo '<span style="color: #2271b1; font-weight: 500;">' . number_format($fc_amount) . ' FC</span>';
        echo '<br><small style="color: #666;">✓ Procesado</small>';
    } else {
        echo '<span style="color: #999;">—</span>';
    }
}
