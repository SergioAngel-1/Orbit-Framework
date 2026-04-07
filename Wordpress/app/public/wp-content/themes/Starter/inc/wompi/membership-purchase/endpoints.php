<?php
/**
 * Membership Purchase Endpoints — Registro de rutas REST y handlers
 * 
 * Endpoints:
 * - POST /membership/pending-purchase            → Registrar compra pendiente
 * - GET  /membership/purchase-status/{reference}  → Consultar estado
 * - POST /membership/confirm-purchase             → Confirmar compra (frontend → backend)
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

    register_rest_route($namespace, '/membership/pending-purchase', [
        'methods'             => 'POST',
        'callback'            => 'starter_membership_endpoint_register_pending',
        'permission_callback' => 'is_user_logged_in',
        'args'                => [
            'product_id' => [
                'required'          => true,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
            ],
            'reference' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ],
    ]);

    register_rest_route($namespace, '/membership/purchase-status/(?P<reference>[a-zA-Z0-9-]+)', [
        'methods'             => 'GET',
        'callback'            => 'starter_membership_endpoint_get_status',
        'permission_callback' => 'is_user_logged_in',
        'args'                => [
            'reference' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ],
    ]);

    register_rest_route($namespace, '/membership/confirm-purchase', [
        'methods'             => 'POST',
        'callback'            => 'starter_membership_endpoint_confirm',
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
});

// ─── Endpoint Handlers ───────────────────────────────────────────────────────

/**
 * Registrar compra pendiente de membresía
 * 
 * Valida producto, nivel, downgrade y persiste el registro.
 * Se llama ANTES de abrir el widget de Wompi.
 */
function starter_membership_endpoint_register_pending(WP_REST_Request $request) {
    global $wpdb;

    $user_id    = get_current_user_id();
    $product_id = $request->get_param('product_id');
    $reference  = $request->get_param('reference');

    // Validar prefijo de referencia
    if (strpos($reference, 'MB-') !== 0) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Referencia inválida para compra de membresía',
        ], 400);
    }

    // Verificar que el producto existe y es de tipo membresía
    $product = wc_get_product($product_id);
    if (!$product) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Producto no encontrado',
        ], 404);
    }

    $is_membership = get_post_meta($product_id, '_is_membership_product', true) === 'yes';
    if (!$is_membership) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'El producto no es una membresía válida',
        ], 400);
    }

    // Obtener datos del producto de membresía
    $membership_level = (int) get_post_meta($product_id, '_membership_level', true);
    $duration_days    = (int) get_post_meta($product_id, '_membership_duration_days', true) ?: 30;
    $monthly_points   = (int) get_post_meta($product_id, '_membership_monthly_points', true) ?: 0;
    $price            = (float) $product->get_price();

    // Validar nivel (1-4)
    if ($membership_level < 1 || $membership_level > 4) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Nivel de membresía no válido para compra',
        ], 400);
    }

    // Verificar nivel actual del usuario — no permitir downgrade
    $current_level = 0;
    if (function_exists('starter_get_user_membership_level')) {
        $current_level = starter_get_user_membership_level($user_id);
    }

    if ($membership_level <= $current_level && $current_level !== 5) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'No puedes adquirir una membresía de nivel igual o inferior al actual',
        ], 400);
    }

    // Verificar duplicado de referencia
    $existing = starter_membership_purchase_get_by_reference($reference);
    if ($existing) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Ya existe una compra con esta referencia',
        ], 409);
    }

    // Insertar registro
    $table  = starter_membership_purchase_table_name();
    $result = $wpdb->insert($table, [
        'user_id'          => $user_id,
        'product_id'       => $product_id,
        'membership_level' => $membership_level,
        'reference'        => $reference,
        'price'            => $price,
        'duration_days'    => $duration_days,
        'monthly_points'   => $monthly_points,
        'status'           => 'pending',
        'created_at'       => current_time('mysql'),
    ]);

    if (!$result) {
        error_log('[Starter Membership] Error al registrar compra pendiente: ' . $wpdb->last_error);
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Error al registrar la compra pendiente',
        ], 500);
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(sprintf(
            '[Starter Membership] Compra pendiente registrada: ref=%s, user=%d, level=%d, price=%.2f',
            $reference, $user_id, $membership_level, $price
        ));
    }

    return new WP_REST_Response([
        'success' => true,
        'data'    => [
            'id'               => $wpdb->insert_id,
            'reference'        => $reference,
            'membership_level' => $membership_level,
            'duration_days'    => $duration_days,
            'monthly_points'   => $monthly_points,
            'price'            => $price,
        ],
    ], 201);
}

/**
 * Obtener estado de una compra de membresía
 */
function starter_membership_endpoint_get_status(WP_REST_Request $request) {
    $user_id   = get_current_user_id();
    $reference = $request->get_param('reference');

    $purchase = starter_membership_purchase_get_by_reference_and_user($reference, $user_id);

    if (!$purchase) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Compra no encontrada',
        ], 404);
    }

    return new WP_REST_Response([
        'success' => true,
        'data'    => [
            'reference'        => $purchase->reference,
            'status'           => $purchase->status,
            'membership_level' => (int) $purchase->membership_level,
            'duration_days'    => (int) $purchase->duration_days,
            'monthly_points'   => (int) $purchase->monthly_points,
            'price'            => (float) $purchase->price,
            'created_at'       => $purchase->created_at,
            'processed_at'     => $purchase->processed_at,
        ],
    ], 200);
}

/**
 * Confirmar compra de membresía después de APPROVED en el widget
 * 
 * Verifica la transacción con la API de Wompi y delega al procesador central.
 * No depende del webhook asíncrono.
 */
function starter_membership_endpoint_confirm(WP_REST_Request $request) {
    $user_id        = get_current_user_id();
    $reference      = $request->get_param('reference');
    $transaction_id = $request->get_param('transaction_id');

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("[Starter Membership] Confirmando compra: ref=$reference, tx=$transaction_id, user=$user_id");
    }

    // Validar prefijo
    if (strpos($reference, 'MB-') !== 0) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Referencia inválida',
        ], 400);
    }

    // Buscar compra del usuario
    $purchase = starter_membership_purchase_get_by_reference_and_user($reference, $user_id);

    if (!$purchase) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Compra no encontrada',
        ], 404);
    }

    // Si ya completada, retornar éxito (idempotente)
    if ($purchase->status === 'completed') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[Starter Membership] Compra ya procesada anteriormente: $reference");
        }
        return new WP_REST_Response([
            'success' => true,
            'message' => 'Membresía ya activada',
            'data'    => [
                'reference'         => $purchase->reference,
                'status'            => 'completed',
                'membership_level'  => (int) $purchase->membership_level,
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
        error_log("[Starter Membership] Referencia no coincide: esperada=$reference, recibida=" . ($transaction['reference'] ?? 'null'));
        return new WP_REST_Response([
            'success' => false,
            'message' => 'La transacción no corresponde a esta compra',
        ], 400);
    }

    // Validar estado APPROVED
    if (($transaction['status'] ?? '') !== 'APPROVED') {
        error_log("[Starter Membership] Transacción no aprobada: status=" . ($transaction['status'] ?? 'null'));
        return new WP_REST_Response([
            'success' => false,
            'message' => 'La transacción no está aprobada',
            'data'    => ['transaction_status' => $transaction['status'] ?? 'unknown'],
        ], 400);
    }

    // Validar monto (redondeo configurable)
    $rounded_price  = function_exists('site_round_currency') ? site_round_currency($purchase->price) : ceil($purchase->price);
    $expected_cents = (int) ($rounded_price * 100);
    $received_cents = (int) ($transaction['amount_in_cents'] ?? 0);

    if ($expected_cents !== $received_cents) {
        error_log("[Starter Membership] Monto no coincide: esperado=$expected_cents (precio: {$purchase->price}, redondeado: $rounded_price), recibido=$received_cents");
        starter_membership_purchase_mark_error($purchase->id, 'amount_mismatch', $transaction_id);
        return new WP_REST_Response([
            'success' => false,
            'message' => 'El monto de la transacción no coincide',
        ], 400);
    }

    // ── Delegar al procesador central ────────────────────────────────────────
    starter_process_membership_purchase($reference, $transaction);

    // Verificar resultado
    $updated = starter_membership_purchase_get_by_reference($reference);

    if ($updated && $updated->status === 'completed') {
        return new WP_REST_Response([
            'success' => true,
            'message' => '¡Membresía activada exitosamente!',
            'data'    => [
                'reference'        => $updated->reference,
                'status'           => 'completed',
                'membership_level' => (int) $updated->membership_level,
                'duration_days'    => (int) $updated->duration_days,
                'monthly_points'   => (int) $updated->monthly_points,
            ],
        ], 200);
    }

    return new WP_REST_Response([
        'success' => false,
        'message' => 'Error al activar la membresía',
        'data'    => ['status' => $updated ? $updated->status : 'unknown'],
    ], 500);
}

// ─── Helpers internos del módulo endpoints ───────────────────────────────────

