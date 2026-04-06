<?php
/**
 * Card Payment Endpoints — Registro de rutas REST y handlers
 * 
 * Endpoints:
 * - POST /checkout/card-payment/pending      → Registrar pago pendiente
 * - GET  /checkout/card-payment/status/{ref}  → Consultar estado
 * - POST /checkout/card-payment/confirm       → Confirmar pago (frontend → backend)
 * - POST /checkout/card-payment/link-order    → Vincular orden WC al pago
 * 
 * @package Starter
 * @since 1.1.0
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// ─── Registro de Rutas ──────────────────────────────────────────────────────

add_action('rest_api_init', function () {
    $namespace = 'starter/v1';

    register_rest_route($namespace, '/checkout/card-payment/pending', [
        'methods'             => 'POST',
        'callback'            => 'starter_card_payment_endpoint_register_pending',
        'permission_callback' => 'is_user_logged_in',
        'args'                => [
            'order_total' => [
                'required'    => true,
                'type'        => 'number',
                'description' => 'Monto total del pedido (sin el incremento del 5%)',
            ],
            'reference' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'order_id' => [
                'required'    => false,
                'type'        => 'integer',
                'description' => 'ID del pedido WooCommerce (opcional, se puede crear después)',
            ],
            'order_data' => [
                'required'    => false,
                'type'        => 'object',
                'description' => 'Datos del pedido WC (billing, shipping, line_items, etc.) para crear la orden como backup server-side',
            ],
        ],
    ]);

    register_rest_route($namespace, '/checkout/card-payment/status/(?P<reference>[a-zA-Z0-9-]+)', [
        'methods'             => 'GET',
        'callback'            => 'starter_card_payment_endpoint_get_status',
        'permission_callback' => 'is_user_logged_in',
        'args'                => [
            'reference' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ],
    ]);

    register_rest_route($namespace, '/checkout/card-payment/confirm', [
        'methods'             => 'POST',
        'callback'            => 'starter_card_payment_endpoint_confirm',
        'permission_callback' => 'is_user_logged_in',
        'args'                => [
            'reference' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'transaction_id' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ],
    ]);

    register_rest_route($namespace, '/checkout/card-payment/link-order', [
        'methods'             => 'POST',
        'callback'            => 'starter_card_payment_endpoint_link_order',
        'permission_callback' => 'is_user_logged_in',
        'args'                => [
            'reference' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'order_id' => [
                'required'    => true,
                'type'        => 'integer',
                'description' => 'ID del pedido WooCommerce',
            ],
        ],
    ]);
});

// ─── Endpoint Handlers ───────────────────────────────────────────────────────

/**
 * Registrar pago pendiente con tarjeta en checkout
 * 
 * Calcula el fee del 5%, redondea a múltiplos de 50 COP,
 * calcula FC equivalentes y persiste el registro.
 */
function starter_card_payment_endpoint_register_pending(WP_REST_Request $request) {
    $user_id     = get_current_user_id();
    $order_total = floatval($request->get_param('order_total'));
    $reference   = $request->get_param('reference');
    $order_id    = $request->get_param('order_id');
    $order_data  = $request->get_param('order_data');

    // Validaciones
    if ($order_total <= 0) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'El monto del pedido debe ser mayor a 0',
        ], 400);
    }

    // Redondear al múltiplo de moneda configurado (hacia arriba)
    $round_fn = function_exists('site_round_currency') ? 'site_round_currency' : function($a) { return ceil($a); };
    $order_total = $round_fn($order_total);

    // Calcular incremento del 5%
    $fee_percentage = STARTER_CARD_PAYMENT_FEE_PERCENTAGE;
    $fee_amount     = $round_fn($order_total * ($fee_percentage / 100));
    $total_with_fee = $round_fn($order_total + $fee_amount);

    // Calcular FC equivalentes usando tasa de conversión del sistema
    $conversion_rate = function_exists('site_get_option') ? floatval(site_get_option('virtual_currency_conversion_rate', 0.1)) : 0.1;
    if (function_exists('Starter_RP')) {
        $options         = Starter_RP()->get_options();
        $conversion_rate = floatval($options['points_conversion_rate'] ?? 0.1);
    }
    $fc_for_order = $conversion_rate > 0 ? round($order_total / $conversion_rate) : 0;

    // Verificar duplicado
    $existing = starter_card_payment_get_by_reference($reference);
    if ($existing) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Ya existe un pago pendiente con esta referencia',
        ], 400);
    }

    // Insertar registro
    global $wpdb;
    $table  = starter_card_payment_table_name();
    // Sanitizar y serializar order_data como JSON
    $order_data_json = null;
    if (!empty($order_data) && is_array($order_data)) {
        // Forzar customer_id al usuario autenticado (prevenir IDOR)
        $order_data['customer_id'] = $user_id;
        $order_data_json = wp_json_encode($order_data);
    }

    $result = $wpdb->insert($table, [
        'user_id'        => $user_id,
        'reference'      => $reference,
        'order_id'       => $order_id,
        'order_total'    => $order_total,
        'fee_percentage' => $fee_percentage,
        'fee_amount'     => $fee_amount,
        'total_with_fee' => $total_with_fee,
        'fc_for_order'   => $fc_for_order,
        'order_data'     => $order_data_json,
        'status'         => 'pending',
        'created_at'     => current_time('mysql'),
    ], ['%d', '%s', '%d', '%f', '%f', '%f', '%f', '%d', '%s', '%s', '%s']);

    if ($result === false) {
        error_log('[Starter Card Payment] Error al registrar pago pendiente: ' . $wpdb->last_error);
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Error al registrar el pago pendiente',
        ], 500);
    }

    $insert_id = $wpdb->insert_id;

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(sprintf(
            '[Starter Card Payment] Pago pendiente registrado - ID: %d, Ref: %s, User: %d, Total: %s, TotalConFee: %s, FC: %d',
            $insert_id, $reference, $user_id, $order_total, $total_with_fee, $fc_for_order
        ));
    }

    return new WP_REST_Response([
        'success' => true,
        'data'    => [
            'id'              => $insert_id,
            'reference'       => $reference,
            'order_total'     => $order_total,
            'fee_percentage'  => $fee_percentage,
            'fee_amount'      => $fee_amount,
            'total_with_fee'  => $total_with_fee,
            'fc_for_order'    => $fc_for_order,
            'amount_in_cents' => round($total_with_fee * 100),
        ],
    ], 200);
}

/**
 * Obtener estado de pago con tarjeta
 */
function starter_card_payment_endpoint_get_status(WP_REST_Request $request) {
    $user_id   = get_current_user_id();
    $reference = $request->get_param('reference');

    $payment = starter_card_payment_get_by_reference_and_user($reference, $user_id);

    if (!$payment) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Pago no encontrado',
        ], 404);
    }

    return new WP_REST_Response([
        'success' => true,
        'data'    => [
            'reference'              => $payment->reference,
            'status'                 => $payment->status,
            'order_total'            => floatval($payment->order_total),
            'total_with_fee'         => floatval($payment->total_with_fee),
            'fc_for_order'           => intval($payment->fc_for_order),
            'fc_transaction_id'      => $payment->fc_purchase_transaction_id,
            'fc_payment_transaction_id' => $payment->fc_payment_transaction_id,
            'wompi_transaction_id'   => $payment->wompi_transaction_id,
            'order_id'               => $payment->order_id ? intval($payment->order_id) : null,
        ],
    ], 200);
}

/**
 * Confirmar pago con tarjeta después de APPROVED en el widget
 * 
 * Verifica la transacción directamente con la API de Wompi
 * y luego delega al procesador central.
 * No depende del webhook asíncrono.
 */
function starter_card_payment_endpoint_confirm(WP_REST_Request $request) {
    $user_id        = get_current_user_id();
    $reference      = $request->get_param('reference');
    $transaction_id = $request->get_param('transaction_id');

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("[Starter Card Payment] Confirmando pago: ref=$reference, tx=$transaction_id, user=$user_id");
    }

    // Validar prefijo
    if (strpos($reference, 'CPY-') !== 0) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Referencia inválida',
        ], 400);
    }

    // Buscar pago del usuario
    $payment = starter_card_payment_get_by_reference_and_user($reference, $user_id);

    if (!$payment) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Pago no encontrado',
        ], 404);
    }

    // Si ya completado, retornar éxito (idempotente)
    if ($payment->status === 'completed') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[Starter Card Payment] Pago ya procesado anteriormente: $reference");
        }
        return new WP_REST_Response([
            'success' => true,
            'message' => 'Pago ya procesado',
            'data'    => [
                'reference'         => $payment->reference,
                'status'            => 'completed',
                'already_processed' => true,
            ],
        ], 200);
    }

    // ── Verificar transacción con Wompi API ──────────────────────────────────
    $transaction = starter_wompi_verify_transaction($transaction_id);

    if (is_wp_error($transaction)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => $transaction->get_error_message(),
        ], 400);
    }

    // Validar referencia
    if (($transaction['reference'] ?? '') !== $reference) {
        error_log("[Starter Card Payment] Referencia no coincide: esperada=$reference, recibida=" . ($transaction['reference'] ?? 'null'));
        return new WP_REST_Response([
            'success' => false,
            'message' => 'La transacción no corresponde a este pago',
        ], 400);
    }

    // Validar estado APPROVED
    if (($transaction['status'] ?? '') !== 'APPROVED') {
        error_log("[Starter Card Payment] Transacción no aprobada: status=" . ($transaction['status'] ?? 'null'));
        return new WP_REST_Response([
            'success' => false,
            'message' => 'La transacción no está aprobada',
            'data'    => ['transaction_status' => $transaction['status'] ?? 'unknown'],
        ], 400);
    }

    // Validar monto
    $expected_cents = (int) round($payment->total_with_fee * 100);
    $received_cents = (int) ($transaction['amount_in_cents'] ?? 0);

    if ($expected_cents !== $received_cents) {
        error_log("[Starter Card Payment] Monto no coincide: esperado=$expected_cents, recibido=$received_cents");
        starter_card_payment_mark_error($payment->id, 'amount_mismatch', $transaction_id);
        return new WP_REST_Response([
            'success' => false,
            'message' => 'El monto de la transacción no coincide',
        ], 400);
    }

    // ── Delegar al procesador central ────────────────────────────────────────
    // El procesador ya tiene su propio bloqueo atómico y validación de estado.
    // No se repite la validación de monto dentro del procesador porque
    // el endpoint confirm ya la hizo aquí contra la API de Wompi.
    starter_process_checkout_card_payment($reference, $transaction);

    // Verificar resultado
    $updated = starter_card_payment_get_by_reference($reference);

    if ($updated && $updated->status === 'completed') {
        return new WP_REST_Response([
            'success' => true,
            'message' => 'Pago procesado exitosamente',
            'data'    => [
                'reference' => $updated->reference,
                'status'    => 'completed',
                'order_id'  => $updated->order_id ? intval($updated->order_id) : null,
            ],
        ], 200);
    }

    return new WP_REST_Response([
        'success' => false,
        'message' => 'Error al procesar el pago',
        'data'    => ['status' => $updated ? $updated->status : 'unknown'],
    ], 500);
}

/**
 * Vincular order_id a un pago después de crear la orden WC
 * 
 * Actualiza el registro con el ID de la orden, marca metadatos en la orden
 * y si el pago ya fue procesado, sincroniza las descripciones de FC y
 * actualiza el estado de la orden.
 */
function starter_card_payment_endpoint_link_order(WP_REST_Request $request) {
    $user_id   = get_current_user_id();
    $reference = $request->get_param('reference');
    $order_id  = intval($request->get_param('order_id'));

    $payment = starter_card_payment_get_by_reference_and_user($reference, $user_id);

    if (!$payment) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Pago no encontrado',
        ], 404);
    }

    // Si el processor ya creó una orden backup (order_id != NULL),
    // cancelar la orden duplicada que el frontend acaba de crear y usar la existente.
    if (!empty($payment->order_id) && $payment->order_id != $order_id) {
        $existing_order_id = intval($payment->order_id);
        $duplicate_order = wc_get_order($order_id);
        if ($duplicate_order && !in_array($duplicate_order->get_status(), ['completed', 'processing'])) {
            $duplicate_order->set_status('cancelled', 'Orden duplicada cancelada automáticamente. La orden principal es #' . $existing_order_id);
            $duplicate_order->save();
        } elseif ($duplicate_order) {
            // La orden del frontend ya está en un estado avanzado; mantenerla y actualizar el registro
            $existing_order_id = $order_id;
            starter_card_payment_update($payment->id, ['order_id' => $order_id]);
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[Starter Card Payment] Link-order: orden backup ya existe (#%d). Orden del frontend (#%d) %s.',
                $payment->order_id, $order_id,
                $existing_order_id === $order_id ? 'mantenida' : 'cancelada'
            ));
        }

        return new WP_REST_Response([
            'success'  => true,
            'message'  => 'Orden ya vinculada (creada por el servidor)',
            'data'     => [
                'reference' => $reference,
                'order_id'  => $existing_order_id,
                'backup'    => true,
            ],
        ], 200);
    }

    // Verificar que la orden existe
    $order = wc_get_order($order_id);
    if (!$order) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Orden no encontrada',
        ], 404);
    }

    // Actualizar order_id en el registro de pago
    $result = starter_card_payment_update($payment->id, ['order_id' => $order_id]);

    if ($result === false) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Error al vincular la orden',
        ], 500);
    }

    // Marcar el pedido como "pago con tarjeta pendiente de FC"
    // Esto evita que order-fc-transactions.php cree transacciones duplicadas
    update_post_meta($order_id, '_starter_card_payment_reference', $reference);
    update_post_meta($order_id, '_starter_card_payment_pending', 'yes');

    // Si el pago ya fue procesado, sincronizar la orden
    if ($payment->status === 'completed' && $payment->fc_purchase_transaction_id) {
        starter_card_payment_update_order_descriptions($payment, $order_id);
        starter_card_payment_sync_order_meta(
            $order_id,
            $payment->fc_for_order,
            $payment->fc_purchase_transaction_id,
            $payment->fc_payment_transaction_id,
            $payment->wompi_transaction_id
        );
    } elseif ($payment->status === 'pending' || $payment->status === 'processing') {
        // Pago aún pendiente (PSE, Nequi, etc.) — agregar nota informativa
        $order->add_order_note(sprintf(
            'Pago con tarjeta pendiente de confirmación (Ref: %s). Las transacciones de FC se crearán cuando Wompi confirme el pago.',
            $reference
        ));
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(sprintf(
            '[Starter Card Payment] Order vinculada - Ref: %s, OrderID: %d',
            $reference, $order_id
        ));
    }

    return new WP_REST_Response([
        'success' => true,
        'message' => 'Orden vinculada correctamente',
        'data'    => [
            'reference' => $reference,
            'order_id'  => $order_id,
        ],
    ], 200);
}

// ─── Helpers internos del módulo endpoints ───────────────────────────────────

/**
 * Actualizar descripciones de transacciones FC cuando se vincula la orden
 * (las transacciones se crearon antes de tener el order_id)
 *
 * @param object $payment   Registro de pago de la DB
 * @param int    $order_id
 */
function starter_card_payment_update_order_descriptions($payment, $order_id) {
    global $wpdb;
    $tx_table = $wpdb->prefix . 'starter_points_transactions';

    // Actualizar descripción de compra de FC
    $wpdb->update(
        $tx_table,
        ['description' => sprintf('Compra de %s Virtual Coins para aporte #%d', number_format($payment->fc_for_order), $order_id)],
        ['id' => $payment->fc_purchase_transaction_id],
        ['%s'],
        ['%d']
    );

    // Actualizar descripción de uso de FC
    if ($payment->fc_payment_transaction_id) {
        $wpdb->update(
            $tx_table,
            ['description' => sprintf('Aporte #%d pagado con %s Virtual Coins', $order_id, number_format($payment->fc_for_order))],
            ['id' => $payment->fc_payment_transaction_id],
            ['%s'],
            ['%d']
        );
    }
}
