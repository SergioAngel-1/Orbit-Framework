<?php
/**
 * Membership Purchase Processor — Lógica central y hooks de webhook
 * 
 * Responsabilidades:
 * - Procesar compra aprobada: activar membresía + acreditar FC de bono
 * - Crear orden WC para trazabilidad
 * - Procesar pago rechazado/anulado
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

add_action('starter_wompi_payment_approved', 'starter_process_membership_purchase', 10, 2);
add_action('starter_wompi_payment_declined', 'starter_process_membership_purchase_declined', 10, 2);
add_action('starter_wompi_payment_voided', 'starter_process_membership_purchase_voided', 10, 2);
add_action('starter_wompi_payment_error', 'starter_process_membership_purchase_error', 10, 2);

// ─── Approved ────────────────────────────────────────────────────────────────

/**
 * Procesar compra de membresía después de pago aprobado
 * 
 * Flujo atómico:
 * 1. SELECT FOR UPDATE (bloqueo exclusivo)
 * 2. Marcar como 'processing'
 * 3. Validar monto
 * 4. Activar membresía
 * 5. Acreditar FC de bono
 * 6. Crear orden WC para trazabilidad
 * 7. Marcar como 'completed'
 *
 * @param string $reference    Referencia del pago (debe empezar con MB-)
 * @param array  $transaction  Datos de la transacción de Wompi
 */
function starter_process_membership_purchase($reference, $transaction) {
    // Solo procesar compras de membresía
    if (strpos($reference, 'MB-') !== 0) {
        return;
    }

    global $wpdb;

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[Starter Membership Purchase] Procesando compra de membresía: ' . $reference);
    }

    // ── Paso 1: Bloqueo atómico ──────────────────────────────────────────────
    $wpdb->query('START TRANSACTION');

    $purchase = starter_membership_purchase_get_for_update($reference);

    if (!$purchase) {
        $wpdb->query('ROLLBACK');
        error_log('[Starter Membership Purchase] ERROR: No se encontró compra pendiente para referencia: ' . $reference);
        return;
    }

    // Ya procesada — liberar bloqueo y salir
    if ($purchase->status === 'completed') {
        $wpdb->query('ROLLBACK');
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Starter Membership Purchase] Compra ya procesada anteriormente: ' . $reference);
        }
        return;
    }

    // Si está en 'processing' por otro hilo, verificar timeout (60s)
    if ($purchase->status === 'processing') {
        $processing_since = strtotime($purchase->processed_at ?: $purchase->created_at);
        $elapsed = time() - $processing_since;
        if ($elapsed < 60) {
            $wpdb->query('ROLLBACK');
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Starter Membership Purchase] Compra en procesamiento por otro hilo: ' . $reference);
            }
            return;
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Starter Membership Purchase] Compra en processing por más de 60s, reintentando: ' . $reference);
        }
    }

    // Ignorar estados de error terminales
    if (in_array($purchase->status, ['amount_mismatch', 'error_activation'], true)) {
        $wpdb->query('ROLLBACK');
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Starter Membership Purchase] Compra con estado terminal de error: ' . $purchase->status . ' - ' . $reference);
        }
        return;
    }

    // ── Paso 2: Marcar como processing ───────────────────────────────────────
    starter_membership_purchase_update($purchase->id, [
        'status'       => 'processing',
        'processed_at' => current_time('mysql'),
    ]);
    $wpdb->query('COMMIT');

    // A partir de aquí no hay riesgo de race condition

    $wompi_tx_id = $transaction['id'] ?? '';

    // ── Paso 3: Validar monto ────────────────────────────────────────────────
    // El frontend aplica redondeo de moneda antes de enviar a Wompi
    $rounded_price   = function_exists('site_round_currency') ? site_round_currency($purchase->price) : ceil($purchase->price);
    $expected_cents  = (int) ($rounded_price * 100);
    $received_cents  = (int) ($transaction['amount_in_cents'] ?? 0);

    if ($expected_cents !== $received_cents) {
        error_log(sprintf(
            '[Starter Membership Purchase] ERROR: Monto no coincide. Esperado: %d (precio: %.2f, redondeado: %.2f), Recibido: %d',
            $expected_cents, $purchase->price, $rounded_price, $received_cents
        ));
        starter_membership_purchase_mark_error($purchase->id, 'amount_mismatch', $wompi_tx_id);
        return;
    }

    $user_id          = $purchase->user_id;
    $membership_level = $purchase->membership_level;
    $duration_days    = $purchase->duration_days;
    $monthly_points   = $purchase->monthly_points;

    // ── Paso 4: Activar membresía ────────────────────────────────────────────
    $membership_activated = false;

    if (function_exists('starter_activate_user_membership')) {
        $membership_id = starter_activate_user_membership($user_id, $membership_level, $duration_days, $purchase->product_id);
        $membership_activated = !empty($membership_id);

        if ($membership_activated) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[Starter Membership Purchase] Membresía activada: user=%d, level=%d, duration=%d días',
                    $user_id, $membership_level, $duration_days
                ));
            }

            // Registrar en historial
            if (function_exists('starter_memberships_log_action')) {
                starter_memberships_log_action(
                    $user_id,
                    'activation',
                    null,
                    $membership_level,
                    [
                        'source'         => 'wompi_purchase',
                        'reference'      => $reference,
                        'product_id'     => $purchase->product_id,
                        'transaction_id' => $wompi_tx_id,
                    ],
                    $membership_id
                );
            }
        } else {
            error_log('[Starter Membership Purchase] ERROR: No se pudo activar la membresía');
        }
    } else {
        error_log('[Starter Membership Purchase] ERROR: Función starter_activate_user_membership no disponible');
    }

    // ── Paso 5: Acreditar Virtual Coins de bono ───────────────────────────────
    if ($monthly_points > 0 && $membership_activated && function_exists('starter_rp_add_points')) {
        $level_name = starter_membership_purchase_get_level_name($membership_level);

        $description = sprintf(
            'Bono de membresía %s - %s FC (Ref: %s)',
            $level_name,
            number_format($monthly_points),
            $reference
        );

        // Obtener configuración de expiración de puntos
        $expiration_days = 365;
        if (function_exists('Starter_RP')) {
            $options = Starter_RP()->get_options();
            $expiration_days = $options['points_expiry_days'] ?? 365;
        }

        $points_added = starter_rp_add_points(
            $user_id,
            $monthly_points,
            'membership_bonus',
            $description,
            $purchase->id,
            $expiration_days
        );

        if ($points_added && defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[Starter Membership Purchase] Acreditados %d FC al usuario %d',
                $monthly_points, $user_id
            ));
        } elseif (!$points_added) {
            error_log('[Starter Membership Purchase] ERROR: No se pudieron acreditar los Virtual Coins');
        }
    }

    // ── Paso 6: Actualizar estado ────────────────────────────────────────────
    if ($membership_activated) {
        // Crear orden WC para trazabilidad en el admin
        $wc_order_id = starter_create_membership_wc_order($purchase, $transaction, $reference);

        starter_membership_purchase_update($purchase->id, [
            'status'               => 'completed',
            'wompi_transaction_id' => $wompi_tx_id,
            'wc_order_id'          => $wc_order_id,
            'processed_at'         => current_time('mysql'),
        ]);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[Starter Membership Purchase] ÉXITO: Membresía nivel %d activada para usuario %d (Ref: %s, WC Order: %d)',
                $membership_level, $user_id, $reference, $wc_order_id ?: 0
            ));
        }

        // Disparar acción para notificaciones u otros hooks
        do_action('starter_membership_purchase_completed', $user_id, $membership_level, $monthly_points, $purchase, $transaction);
    } else {
        starter_membership_purchase_mark_error($purchase->id, 'error_activation', $wompi_tx_id);
    }
}

// ─── WC Order Creation ───────────────────────────────────────────────────────

/**
 * Crear orden WooCommerce para compra de membresía
 * Permite trazabilidad en el admin de WP/WC
 * 
 * NOTA: Esta función es referenciada externamente por special-orders/ajax-handlers.php
 *
 * @param object $purchase    Datos de la compra desde la tabla starter_membership_purchases
 * @param array  $transaction Datos de la transacción de Wompi
 * @param string $reference   Referencia de la compra
 * @return int|null ID de la orden WC creada o null si falla
 */
function starter_create_membership_wc_order($purchase, $transaction, $reference) {
    if (!function_exists('wc_create_order')) {
        error_log('[Starter Membership WC Order] WooCommerce no disponible');
        return null;
    }

    try {
        $user_id          = $purchase->user_id;
        $product_id       = $purchase->product_id;
        $price            = $purchase->price;
        $membership_level = $purchase->membership_level;
        $duration_days    = $purchase->duration_days;
        $monthly_points   = $purchase->monthly_points;

        $user = get_userdata($user_id);
        if (!$user) {
            error_log('[Starter Membership WC Order] Usuario no encontrado: ' . $user_id);
            return null;
        }

        $level_name = starter_membership_purchase_get_level_name($membership_level);

        // Crear la orden
        $order = wc_create_order([
            'customer_id' => $user_id,
            'status'      => 'completed',
        ]);

        if (is_wp_error($order)) {
            error_log('[Starter Membership WC Order] Error al crear orden: ' . $order->get_error_message());
            return null;
        }

        // Agregar el producto
        $product = wc_get_product($product_id);
        if ($product) {
            $order->add_product($product, 1, [
                'subtotal' => $price,
                'total'    => $price,
            ]);
        } else {
            $order->add_item(new WC_Order_Item_Product([
                'name'     => sprintf('Membresía %s - %d días', $level_name, $duration_days),
                'quantity' => 1,
                'subtotal' => $price,
                'total'    => $price,
            ]));
        }

        // Datos de facturación
        $order->set_billing_first_name($user->first_name ?: $user->display_name);
        $order->set_billing_last_name($user->last_name ?: '');
        $order->set_billing_email($user->user_email);

        // Metadatos de la transacción Wompi
        $order->update_meta_data('_wompi_transaction_id', $transaction['id'] ?? '');
        $order->update_meta_data('_wompi_reference', $reference);
        $order->update_meta_data('_wompi_payment_method', $transaction['payment_method_type'] ?? 'CARD');
        $order->update_meta_data('_starter_membership_purchase_id', $purchase->id);
        $order->update_meta_data('_starter_membership_level', $membership_level);
        $order->update_meta_data('_starter_membership_duration_days', $duration_days);
        $order->update_meta_data('_starter_membership_monthly_points', $monthly_points);
        $order->update_meta_data('_order_type', 'membership_purchase');

        // Método de pago
        $order->set_payment_method('wompi');
        $order->set_payment_method_title('Wompi - Tarjeta');

        // Nota
        $order->add_order_note(sprintf(
            'Compra de membresía procesada automáticamente. Nivel: %s. Duración: %d días. FC bono: %s. Referencia Wompi: %s',
            $level_name,
            $duration_days,
            number_format($monthly_points),
            $reference
        ));

        $order->calculate_totals();
        $order->save();

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[Starter Membership WC Order] Orden WC creada: #%d para usuario %d (Ref: %s)',
                $order->get_id(), $user_id, $reference
            ));
        }

        return $order->get_id();

    } catch (Exception $e) {
        error_log('[Starter Membership WC Order] Excepción: ' . $e->getMessage());
        return null;
    }
}

// ─── Declined / Voided ───────────────────────────────────────────────────────

/**
 * Procesar pago rechazado de membresía
 */
function starter_process_membership_purchase_declined($reference, $transaction) {
    if (strpos($reference, 'MB-') !== 0) {
        return;
    }
    starter_cancel_membership_purchase($reference, $transaction, 'declined');
}

/**
 * Procesar pago anulado de membresía
 */
function starter_process_membership_purchase_voided($reference, $transaction) {
    if (strpos($reference, 'MB-') !== 0) {
        return;
    }
    starter_cancel_membership_purchase($reference, $transaction, 'voided');
}

/**
 * Procesar error técnico de pago de membresía
 * STATUS=ERROR indica un error técnico en el procesamiento (distinto a DECLINED).
 */
function starter_process_membership_purchase_error($reference, $transaction) {
    if (strpos($reference, 'MB-') !== 0) {
        return;
    }
    starter_cancel_membership_purchase($reference, $transaction, 'error');
}

/**
 * Cancelar compra de membresía (usado por declined, voided y error)
 *
 * @param string $reference
 * @param array  $transaction
 * @param string $status  'declined' | 'voided'
 */
function starter_cancel_membership_purchase($reference, $transaction, $status) {
    global $wpdb;
    $table = starter_membership_purchase_table_name();

    $wpdb->update($table, [
        'status'               => $status,
        'wompi_transaction_id' => $transaction['id'] ?? '',
        'processed_at'         => current_time('mysql'),
    ], ['reference' => $reference]);

    error_log(sprintf('[Starter Membership] Compra %s - Ref: %s', $status, $reference));
}

// ─── Helpers internos ────────────────────────────────────────────────────────

/**
 * Obtener nombre legible del nivel de membresía
 *
 * @param int $membership_level
 * @return string
 */
function starter_membership_purchase_get_level_name($membership_level) {
    if (class_exists('Starter_Memberships')) {
        $level_info = Starter_Memberships::get_membership_level($membership_level);
        if ($level_info) {
            return $level_info['name'];
        }
    }
    return "Nivel $membership_level";
}
