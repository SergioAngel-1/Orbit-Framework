<?php
/**
 * Card Payment Processor — Lógica central de transacciones FC y hooks de webhook
 * 
 * Responsabilidades:
 * - Procesar pago aprobado: crear transacciones invisibles de FC (compra + uso)
 * - Procesar pago rechazado/anulado: cancelar pago y orden WC
 * - Hooks al sistema de webhook de Wompi
 * 
 * @package Starter
 * @since 1.1.0
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// ─── Webhook Hooks ───────────────────────────────────────────────────────────

add_action('starter_wompi_payment_approved', 'starter_process_checkout_card_payment', 10, 2);
add_action('starter_wompi_payment_declined', 'starter_process_checkout_card_payment_declined', 10, 2);
add_action('starter_wompi_payment_voided', 'starter_process_checkout_card_payment_voided', 10, 2);
add_action('starter_wompi_payment_error', 'starter_process_checkout_card_payment_error', 10, 2);

// ─── Approved ────────────────────────────────────────────────────────────────

/**
 * Procesar pago aprobado de Wompi para checkout con tarjeta
 * 
 * Flujo atómico:
 * 1. SELECT FOR UPDATE (bloqueo exclusivo para evitar race condition webhook+frontend)
 * 2. Marcar como 'processing'
 * 3. Validar monto
 * 4. Crear transacción de compra de FC (add_points)
 * 5. Crear transacción de uso de FC (use_points)
 * 6. Marcar como 'completed'
 * 7. Sincronizar metadatos en la orden WC (si existe)
 *
 * @param string $reference    Referencia del pago (debe empezar con CPY-)
 * @param array  $transaction  Datos de la transacción de Wompi
 */
function starter_process_checkout_card_payment($reference, $transaction) {
    // Solo procesar pagos de checkout con tarjeta
    if (strpos($reference, 'CPY-') !== 0) {
        return;
    }

    global $wpdb;

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[Starter Card Payment] Procesando pago de checkout: ' . $reference);
    }

    // ── Paso 1: Bloqueo atómico ──────────────────────────────────────────────
    $wpdb->query('START TRANSACTION');

    $payment = starter_card_payment_get_for_update($reference);

    if (!$payment) {
        $wpdb->query('ROLLBACK');
        error_log('[Starter Card Payment] ERROR: No se encontró pago para referencia: ' . $reference);
        return;
    }

    // Ya procesado — liberar bloqueo y salir
    if ($payment->status === 'completed') {
        $wpdb->query('ROLLBACK');
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Starter Card Payment] Pago ya procesado anteriormente: ' . $reference);
        }
        return;
    }

    // Si está en 'processing' por otro hilo, verificar timeout (60s)
    if ($payment->status === 'processing') {
        $processing_since = strtotime($payment->processed_at ?: $payment->created_at);
        $elapsed = time() - $processing_since;
        if ($elapsed < 60) {
            $wpdb->query('ROLLBACK');
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Starter Card Payment] Pago en procesamiento por otro hilo: ' . $reference);
            }
            return;
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Starter Card Payment] Pago en processing por más de 60s, reintentando: ' . $reference);
        }
    }

    // Ignorar estados de error terminales
    if (in_array($payment->status, ['amount_mismatch', 'error', 'error_no_points_function'], true)) {
        $wpdb->query('ROLLBACK');
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Starter Card Payment] Pago con estado terminal de error: ' . $payment->status . ' - Ref: ' . $reference);
        }
        return;
    }

    // Solo procesar desde 'pending' o 'processing' expirado
    if (!in_array($payment->status, ['pending', 'processing'], true)) {
        $wpdb->query('ROLLBACK');
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Starter Card Payment] Pago en estado no procesable: ' . $payment->status . ' - Ref: ' . $reference);
        }
        return;
    }

    // ── Paso 2: Marcar como processing (dentro de la transacción) ────────────
    starter_card_payment_update($payment->id, [
        'status'       => 'processing',
        'processed_at' => current_time('mysql'),
    ]);
    $wpdb->query('COMMIT');

    // A partir de aquí no hay riesgo de race condition

    $user_id            = $payment->user_id;
    $fc_for_order       = $payment->fc_for_order;
    $total_with_fee     = $payment->total_with_fee;
    $wompi_tx_id        = $transaction['id'] ?? '';
    $order_id           = $payment->order_id;

    // ── Paso 3: Validar monto ────────────────────────────────────────────────
    $expected_cents  = (int) round($total_with_fee * 100);
    $received_cents  = (int) ($transaction['amount_in_cents'] ?? 0);

    if ($expected_cents !== $received_cents) {
        error_log(sprintf(
            '[Starter Card Payment] ERROR: Monto no coincide. Esperado: %d centavos, Recibido: %d centavos (Ref: %s)',
            $expected_cents, $received_cents, $reference
        ));
        starter_card_payment_mark_error($payment->id, 'amount_mismatch', $wompi_tx_id);
        return;
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(sprintf(
            '[Starter Card Payment] Procesando pago aprobado - Ref: %s, User: %d, FC: %d, Monto: %d centavos',
            $reference, $user_id, $fc_for_order, $received_cents
        ));
    }

    // ── Paso 4: Verificar funciones de puntos ────────────────────────────────
    if (!function_exists('starter_rp_add_points') || !function_exists('starter_rp_use_points')) {
        error_log('[Starter Card Payment] Funciones de puntos no disponibles');
        starter_card_payment_mark_error($payment->id, 'error_no_points_function', $wompi_tx_id);
        return;
    }

    $order_ref = $order_id ? "#$order_id" : "(Ref: $reference)";

    // ── Paso 5: Crear transacción de compra de FC ────────────────────────────
    $purchase_description = sprintf(
        'Compra de %s Virtual Coins para aporte %s',
        number_format($fc_for_order),
        $order_ref
    );

    $fc_purchase_result = starter_rp_add_points(
        $user_id,
        $fc_for_order,
        'checkout_purchase',
        $purchase_description,
        $order_id ?: 0,
        0 // Sin expiración para FC comprados
    );

    if (!$fc_purchase_result) {
        error_log('[Starter Card Payment] Error al crear transacción de compra de FC');
        starter_card_payment_mark_error($payment->id, 'error', $wompi_tx_id);
        return;
    }

    $fc_purchase_tx_id = $wpdb->insert_id;

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(sprintf(
            '[Starter Card Payment] FC comprados - User: %d, FC: %d, TxID: %s',
            $user_id, $fc_for_order, $fc_purchase_tx_id
        ));
    }

    // ── Paso 6: Crear transacción de pago con FC ─────────────────────────────
    $payment_description = sprintf(
        'Aporte %s pagado con %s Virtual Coins',
        $order_ref,
        number_format($fc_for_order)
    );

    // FIX #7: skip_balance_check=true porque ACABAMOS de acreditar estos FC en PASO 5.
    // Esto elimina la race condition donde otro proceso concurrente drena el balance
    // entre add_points (PASO 5) y use_points (PASO 6).
    $fc_payment_result = starter_rp_use_points(
        $user_id,
        $fc_for_order,
        $payment_description,
        $order_id ?: 0,
        true // skip_balance_check: flujo interno atómico
    );

    if (!$fc_payment_result) {
        error_log('[Starter Card Payment] Error al crear transacción de pago con FC');
        // FIX #7: Revertir con operación directa a DB para evitar que el balance check
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
            'description'     => sprintf('Reversión de compra FC por error en pago con tarjeta - Ref: %s', $reference),
            'reference_id'    => $order_id ?: 0,
            'expiration_date' => null,
            'created_at'      => current_time('mysql'),
        ]);
        
        if (function_exists('starter_rp_invalidate_user_points_cache_now')) {
            starter_rp_invalidate_user_points_cache_now($user_id);
        }
        
        error_log(sprintf(
            '[Starter Card Payment] Reversión directa aplicada - Ref: %s - %d FC revertidos para usuario %d',
            $reference, $fc_for_order, $user_id
        ));
        
        starter_card_payment_mark_error($payment->id, 'error', $wompi_tx_id, [
            'fc_purchase_transaction_id' => $fc_purchase_tx_id,
        ]);
        return;
    }

    $fc_payment_tx_id = $wpdb->insert_id;

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(sprintf(
            '[Starter Card Payment] FC usados para pago - User: %d, FC: %d, TxID: %s',
            $user_id, $fc_for_order, $fc_payment_tx_id
        ));
    }

    // ── Paso 7: Marcar como completed ────────────────────────────────────────
    starter_card_payment_update($payment->id, [
        'status'                     => 'completed',
        'wompi_transaction_id'       => $wompi_tx_id,
        'fc_purchase_transaction_id' => $fc_purchase_tx_id,
        'fc_payment_transaction_id'  => $fc_payment_tx_id,
        'processed_at'               => current_time('mysql'),
    ]);

    // ── Paso 7b: Crear orden WC server-side si el frontend no la creó ──────
    // En el flujo normal, el frontend crea la orden WC después del pago.
    // Si el frontend falla (cierre de pestaña, error de red, etc.), order_id
    // queda NULL y el pedido se pierde. Aquí creamos la orden como backup
    // usando los order_data guardados en el registro pending.
    if (!$order_id && !empty($payment->order_data)) {
        $created_order_id = starter_card_payment_create_wc_order($payment, $transaction, $reference);
        if ($created_order_id) {
            $order_id = $created_order_id;
            starter_card_payment_update($payment->id, ['order_id' => $order_id]);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[Starter Card Payment] Orden WC creada server-side como backup: #%d (Ref: %s)',
                    $order_id, $reference
                ));
            }
        }
    }

    // ── Paso 8: Sincronizar orden WC (si existe) ────────────────────
    if ($order_id) {
        starter_card_payment_sync_order_meta(
            $order_id, $fc_for_order, $fc_purchase_tx_id, $fc_payment_tx_id, $wompi_tx_id
        );
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(sprintf(
            '[Starter Card Payment] Pago completado exitosamente - Ref: %s, User: %d, OrderID: %s',
            $reference, $user_id, $order_id ?: 'N/A'
        ));
    }

    // Disparar acción para que otros módulos puedan reaccionar
    do_action('starter_checkout_card_payment_completed', $payment->id, $user_id, $fc_for_order, $reference);
}

// ─── Crear Orden WC (backup server-side) ───────────────────────────────────

/**
 * Crear orden WC usando los datos del pedido almacenados en order_data.
 * Se invoca solo cuando el frontend no pudo crear la orden (cierre de pestaña, error de red, etc.).
 * Sigue el mismo patrón que starter_create_fc_wc_order / starter_create_membership_wc_order.
 *
 * @param object $payment      Registro de pago de la DB (incluye order_data JSON)
 * @param array  $transaction  Datos de la transacción Wompi
 * @param string $reference    Referencia del pago
 * @return int|null ID de la orden WC creada o null si falla
 */
function starter_card_payment_create_wc_order($payment, $transaction, $reference) {
    if (!function_exists('wc_create_order')) {
        error_log('[Starter Card Payment WC Order] WooCommerce no disponible');
        return null;
    }

    try {
        $order_data = json_decode($payment->order_data, true);
        if (empty($order_data) || !is_array($order_data)) {
            error_log('[Starter Card Payment WC Order] order_data vacío o inválido para Ref: ' . $reference);
            return null;
        }

        $user_id = $payment->user_id;
        $user    = get_userdata($user_id);
        if (!$user) {
            error_log('[Starter Card Payment WC Order] Usuario no encontrado: ' . $user_id);
            return null;
        }

        // Crear la orden WC
        $order = wc_create_order([
            'customer_id' => $user_id,
            'status'      => 'processing',
        ]);

        if (is_wp_error($order)) {
            error_log('[Starter Card Payment WC Order] Error al crear orden: ' . $order->get_error_message());
            return null;
        }

        // Agregar line_items
        if (!empty($order_data['line_items']) && is_array($order_data['line_items'])) {
            foreach ($order_data['line_items'] as $item) {
                $product_id   = intval($item['product_id'] ?? 0);
                $quantity     = intval($item['quantity'] ?? 1);
                $variation_id = intval($item['variation_id'] ?? 0);

                $product = $variation_id ? wc_get_product($variation_id) : wc_get_product($product_id);
                if ($product) {
                    $args = ['quantity' => $quantity];
                    if ($variation_id) {
                        $args['variation_id'] = $variation_id;
                    }
                    $order->add_product($product, $quantity, $args);
                } else {
                    // Producto no encontrado — agregar como item genérico
                    $line_item = new WC_Order_Item_Product();
                    $line_item->set_name('Producto #' . $product_id);
                    $line_item->set_quantity($quantity);
                    $order->add_item($line_item);
                }
            }
        }

        // Fee lines (descuentos FC, membresía, cargo tarjeta)
        if (!empty($order_data['fee_lines']) && is_array($order_data['fee_lines'])) {
            foreach ($order_data['fee_lines'] as $fee) {
                $fee_item = new WC_Order_Item_Fee();
                $fee_item->set_name(sanitize_text_field($fee['name'] ?? 'Fee'));
                $fee_item->set_total(floatval($fee['total'] ?? 0));
                $fee_item->set_tax_status(sanitize_text_field($fee['tax_status'] ?? 'none'));
                $order->add_item($fee_item);
            }
        }

        // Shipping lines
        if (!empty($order_data['shipping_lines']) && is_array($order_data['shipping_lines'])) {
            foreach ($order_data['shipping_lines'] as $shipping) {
                $shipping_item = new WC_Order_Item_Shipping();
                $shipping_item->set_method_id(sanitize_text_field($shipping['method_id'] ?? ''));
                $shipping_item->set_method_title(sanitize_text_field($shipping['method_title'] ?? ''));
                $shipping_item->set_total(floatval($shipping['total'] ?? 0));
                $order->add_item($shipping_item);
            }
        }

        // Billing
        if (!empty($order_data['billing'])) {
            $b = $order_data['billing'];
            $order->set_billing_first_name(sanitize_text_field($b['first_name'] ?? ''));
            $order->set_billing_last_name(sanitize_text_field($b['last_name'] ?? ''));
            $order->set_billing_address_1(sanitize_text_field($b['address_1'] ?? ''));
            $order->set_billing_city(sanitize_text_field($b['city'] ?? ''));
            $order->set_billing_state(sanitize_text_field($b['state'] ?? ''));
            $order->set_billing_postcode(sanitize_text_field($b['postcode'] ?? ''));
            $order->set_billing_country(sanitize_text_field($b['country'] ?? 'CO'));
            $order->set_billing_email(sanitize_email($b['email'] ?? $user->user_email));
            $order->set_billing_phone(sanitize_text_field($b['phone'] ?? ''));
        }

        // Shipping address
        if (!empty($order_data['shipping'])) {
            $s = $order_data['shipping'];
            $order->set_shipping_first_name(sanitize_text_field($s['first_name'] ?? ''));
            $order->set_shipping_last_name(sanitize_text_field($s['last_name'] ?? ''));
            $order->set_shipping_address_1(sanitize_text_field($s['address_1'] ?? ''));
            $order->set_shipping_city(sanitize_text_field($s['city'] ?? ''));
            $order->set_shipping_state(sanitize_text_field($s['state'] ?? ''));
            $order->set_shipping_postcode(sanitize_text_field($s['postcode'] ?? ''));
            $order->set_shipping_country(sanitize_text_field($s['country'] ?? 'CO'));
        }

        // Customer note
        if (!empty($order_data['customer_note'])) {
            $order->set_customer_note(sanitize_textarea_field($order_data['customer_note']));
        }

        // Meta data del pedido original
        if (!empty($order_data['meta_data']) && is_array($order_data['meta_data'])) {
            foreach ($order_data['meta_data'] as $meta) {
                $key = sanitize_text_field($meta['key'] ?? '');
                $value = sanitize_text_field($meta['value'] ?? '');
                if (!empty($key)) {
                    $order->update_meta_data($key, $value);
                }
            }
        }

        // Metadatos Wompi
        $order->update_meta_data('_wompi_transaction_id', $transaction['id'] ?? '');
        $order->update_meta_data('_wompi_reference', $reference);
        $order->update_meta_data('_wompi_payment_method', $transaction['payment_method_type'] ?? 'CARD');
        $order->update_meta_data('_order_type', 'checkout_card_payment');
        $order->update_meta_data('_starter_card_payment_id', $payment->id);
        $order->update_meta_data('_starter_order_created_by', 'server_backup');

        // Método de pago
        $order->set_payment_method('wompi');
        $order->set_payment_method_title('Wompi - Tarjeta');
        $order->set_transaction_id($transaction['id'] ?? '');

        // Nota
        $order->add_order_note(sprintf(
            'Orden creada automáticamente por el servidor (backup). El frontend no completó la creación de la orden. Ref Wompi: %s. FC procesados: %s',
            $reference,
            number_format($payment->fc_for_order)
        ));

        $order->calculate_totals();
        $order->save();

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[Starter Card Payment WC Order] Orden WC creada (backup): #%d para usuario %d (Ref: %s)',
                $order->get_id(), $user_id, $reference
            ));
        }

        return $order->get_id();

    } catch (Exception $e) {
        error_log('[Starter Card Payment WC Order] Excepción: ' . $e->getMessage());
        return null;
    }
}

// ─── Declined / Voided ───────────────────────────────────────────────────────

/**
 * Procesar pago rechazado de Wompi para checkout con tarjeta
 */
function starter_process_checkout_card_payment_declined($reference, $transaction) {
    if (strpos($reference, 'CPY-') !== 0) {
        return;
    }
    starter_cancel_checkout_card_payment($reference, $transaction, 'declined', 'Pago rechazado por Wompi.');
}

/**
 * Procesar error de pago de Wompi para checkout con tarjeta
 * STATUS=ERROR indica un error técnico en el procesamiento (distinto a DECLINED por fondos/datos).
 */
function starter_process_checkout_card_payment_error($reference, $transaction) {
    if (strpos($reference, 'CPY-') !== 0) {
        return;
    }
    starter_cancel_checkout_card_payment($reference, $transaction, 'error', 'Error técnico de Wompi al procesar el pago.');
}

/**
 * Procesar pago anulado de Wompi para checkout con tarjeta
 */
function starter_process_checkout_card_payment_voided($reference, $transaction) {
    if (strpos($reference, 'CPY-') !== 0) {
        return;
    }
    starter_cancel_checkout_card_payment($reference, $transaction, 'voided', 'Pago anulado por Wompi.');
}

/**
 * Cancelar pago de checkout con tarjeta (usado por declined y voided)
 *
 * @param string $reference
 * @param array  $transaction
 * @param string $status      'declined' | 'voided'
 * @param string $reason      Texto para la nota de la orden
 */
function starter_cancel_checkout_card_payment($reference, $transaction, $status, $reason) {
    global $wpdb;
    $table       = starter_card_payment_table_name();
    $wompi_tx_id = $transaction['id'] ?? '';

    $payment = starter_card_payment_get_by_reference($reference);

    // Actualizar estado en la tabla de pagos
    $wpdb->update($table, [
        'status'               => $status,
        'wompi_transaction_id' => $wompi_tx_id,
        'processed_at'         => current_time('mysql'),
    ], ['reference' => $reference]);

    // Si hay orden vinculada, cancelarla
    if ($payment && $payment->order_id) {
        $order = wc_get_order($payment->order_id);
        if ($order && in_array($order->get_status(), ['wompi-verifying', 'pending', 'on-hold'])) {
            $order->set_payment_method('wompi');
            $order->set_payment_method_title('Wompi - Cancelado');
            if ($wompi_tx_id) {
                $order->set_transaction_id($wompi_tx_id);
            }
            delete_post_meta($payment->order_id, '_starter_card_payment_pending');
            $order->set_status('cancelled', $reason . ' Transacción: ' . $wompi_tx_id);
            $order->save();

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[Starter Card Payment] Orden #%d cancelada - %s - Ref: %s',
                    $payment->order_id, $status, $reference
                ));
            }
        }
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(sprintf('[Starter Card Payment] Pago %s - Ref: %s', $status, $reference));
    }
}
