<?php
/**
 * Virtual Coins Purchase Endpoints — Registro de rutas REST y handlers
 * 
 * Endpoints:
 * - GET  /virtual-coins/packages                    → Listar paquetes disponibles
 * - POST /virtual-coins/pending-purchase             → Registrar compra pendiente
 * - GET  /virtual-coins/purchase-status/{reference}  → Consultar estado
 * - POST /virtual-coins/confirm-purchase             → Confirmar compra (frontend → backend)
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

    register_rest_route($namespace, '/virtual-coins/packages', [
        'methods'             => 'GET',
        'callback'            => 'starter_fc_endpoint_get_packages',
        'permission_callback' => 'is_user_logged_in',
    ]);

    register_rest_route($namespace, '/virtual-coins/pending-purchase', [
        'methods'             => 'POST',
        'callback'            => 'starter_fc_endpoint_register_pending',
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

    register_rest_route($namespace, '/virtual-coins/purchase-status/(?P<reference>[a-zA-Z0-9-]+)', [
        'methods'             => 'GET',
        'callback'            => 'starter_fc_endpoint_get_status',
        'permission_callback' => 'is_user_logged_in',
        'args'                => [
            'reference' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ],
    ]);

    register_rest_route($namespace, '/virtual-coins/confirm-purchase', [
        'methods'             => 'POST',
        'callback'            => 'starter_fc_endpoint_confirm',
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
 * Obtener todos los paquetes de Virtual Coins disponibles
 * Busca productos simples con meta '_is_virtual_coins_product' = 'yes'
 * Filtra por nivel de membresía del usuario actual (a menos que show_all=true)
 */
function starter_fc_endpoint_get_packages(WP_REST_Request $request) {
    $show_all = $request->get_param('show_all') === 'true' || $request->get_param('show_all') === '1';

    // Obtener nivel de membresía del usuario actual
    $user_membership_level = 0;
    $user_id = get_current_user_id();

    if ($user_id && function_exists('starter_get_user_membership_level')) {
        $user_membership_level = starter_get_user_membership_level($user_id);
    }

    $args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_query'     => [
            [
                'key'     => '_is_virtual_coins_product',
                'value'   => 'yes',
                'compare' => '=',
            ],
        ],
        'orderby'  => 'meta_value_num',
        'meta_key' => '_price',
        'order'    => 'ASC',
    ];

    $products = get_posts($args);
    $packages = [];

    foreach ($products as $post) {
        $product = wc_get_product($post->ID);
        if (!$product) {
            continue;
        }

        // Obtener restricciones de membresía
        $min_membership  = (int) get_post_meta($post->ID, '_virtual_coins_min_membership', true);
        $membership_mode = get_post_meta($post->ID, '_virtual_coins_membership_mode', true) ?: 'cascade';

        // Verificar acceso (solo si no se pide mostrar todos)
        if (!$show_all) {
            if ($membership_mode === 'direct') {
                $has_access = ($user_membership_level === $min_membership);
            } else {
                $has_access = ($user_membership_level >= $min_membership);
            }
            if (!$has_access) {
                continue;
            }
        }

        $coins_amount = (int) get_post_meta($post->ID, '_virtual_coins_amount', true);
        $coins_bonus  = (int) get_post_meta($post->ID, '_virtual_coins_bonus', true);
        $is_popular   = get_post_meta($post->ID, '_virtual_coins_popular', true) === 'yes';

        // Precios
        $regular_price = (float) $product->get_regular_price();
        $sale_price    = $product->get_sale_price();
        $current_price = (float) $product->get_price();
        $is_on_sale    = $product->is_on_sale();

        $packages[] = [
            'id'              => $post->ID,
            'name'            => $product->get_name(),
            'slug'            => $post->post_name,
            'price'           => $current_price,
            'regular_price'   => $regular_price,
            'sale_price'      => $is_on_sale ? (float) $sale_price : null,
            'is_on_sale'      => $is_on_sale,
            'coins'           => $coins_amount,
            'bonus'           => $coins_bonus,
            'total_coins'     => $coins_amount + $coins_bonus,
            'popular'         => $is_popular,
            'description'     => $product->get_short_description(),
            'image'           => wp_get_attachment_url($product->get_image_id()),
            'min_membership'  => $min_membership,
            'membership_mode' => $membership_mode,
        ];
    }

    return new WP_REST_Response([
        'success'               => true,
        'data'                  => $packages,
        'user_membership_level' => $user_membership_level,
    ], 200);
}

/**
 * Registrar compra pendiente de Virtual Coins
 * 
 * Valida producto FC, verifica duplicado y persiste el registro.
 * Se llama ANTES de abrir el widget de Wompi.
 */
function starter_fc_endpoint_register_pending(WP_REST_Request $request) {
    global $wpdb;

    $user_id    = get_current_user_id();
    $product_id = $request->get_param('product_id');
    $reference  = $request->get_param('reference');

    // Validar prefijo
    if (strpos($reference, 'FC-') !== 0) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Referencia inválida para compra de Virtual Coins',
        ], 400);
    }

    // Verificar producto FC
    if (!starter_is_virtual_coins_product($product_id)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'El producto no es un paquete de Virtual Coins válido',
        ], 400);
    }

    $product = wc_get_product($product_id);
    if (!$product) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Producto no encontrado',
        ], 404);
    }

    $coins_amount = starter_get_product_coins_amount($product_id);
    $coins_bonus  = starter_get_product_coins_bonus($product_id);
    $total_coins  = $coins_amount + $coins_bonus;
    $price        = (float) $product->get_price();

    // Verificar duplicado
    $existing = starter_fc_purchase_get_by_reference($reference);
    if ($existing) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Ya existe una compra con esta referencia',
        ], 409);
    }

    // Insertar registro
    $table  = starter_fc_purchase_table_name();
    $result = $wpdb->insert($table, [
        'user_id'      => $user_id,
        'product_id'   => $product_id,
        'reference'    => $reference,
        'coins_amount' => $coins_amount,
        'coins_bonus'  => $coins_bonus,
        'total_coins'  => $total_coins,
        'price'        => $price,
        'status'       => 'pending',
        'created_at'   => current_time('mysql'),
    ]);

    if (!$result) {
        error_log('[Starter FC] Error al registrar compra pendiente: ' . $wpdb->last_error);
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Error al registrar la compra pendiente',
        ], 500);
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(sprintf(
            '[Starter FC] Compra pendiente registrada: ref=%s, user=%d, product=%d, coins=%d',
            $reference, $user_id, $product_id, $total_coins
        ));
    }

    return new WP_REST_Response([
        'success' => true,
        'data'    => [
            'id'          => $wpdb->insert_id,
            'reference'   => $reference,
            'total_coins' => $total_coins,
            'price'       => $price,
        ],
    ], 201);
}

/**
 * Obtener estado de una compra de FC
 */
function starter_fc_endpoint_get_status(WP_REST_Request $request) {
    $user_id   = get_current_user_id();
    $reference = $request->get_param('reference');

    $purchase = starter_fc_purchase_get_by_reference_and_user($reference, $user_id);

    if (!$purchase) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Compra no encontrada',
        ], 404);
    }

    return new WP_REST_Response([
        'success' => true,
        'data'    => [
            'reference'    => $purchase->reference,
            'status'       => $purchase->status,
            'total_coins'  => (int) $purchase->total_coins,
            'price'        => (float) $purchase->price,
            'created_at'   => $purchase->created_at,
            'processed_at' => $purchase->processed_at,
        ],
    ], 200);
}

/**
 * Confirmar compra de FC después de APPROVED en el widget
 * 
 * Verifica la transacción con la API de Wompi y delega al procesador central.
 * No depende del webhook asíncrono.
 */
function starter_fc_endpoint_confirm(WP_REST_Request $request) {
    $user_id        = get_current_user_id();
    $reference      = $request->get_param('reference');
    $transaction_id = $request->get_param('transaction_id');

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("[Starter FC] Confirmando compra: ref=$reference, tx=$transaction_id, user=$user_id");
    }

    // Validar prefijo
    if (strpos($reference, 'FC-') !== 0) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Referencia inválida',
        ], 400);
    }

    // Buscar compra del usuario
    $purchase = starter_fc_purchase_get_by_reference_and_user($reference, $user_id);

    if (!$purchase) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Compra no encontrada',
        ], 404);
    }

    // Si ya completada, retornar éxito (idempotente)
    if ($purchase->status === 'completed') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[Starter FC] Compra ya procesada anteriormente: $reference");
        }
        return new WP_REST_Response([
            'success' => true,
            'message' => 'Virtual Coins ya acreditados',
            'data'    => [
                'reference'         => $purchase->reference,
                'status'            => 'completed',
                'total_coins'       => (int) $purchase->total_coins,
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
        error_log("[Starter FC] Referencia no coincide: esperada=$reference, recibida=" . ($transaction['reference'] ?? 'null'));
        return new WP_REST_Response([
            'success' => false,
            'message' => 'La transacción no corresponde a esta compra',
        ], 400);
    }

    // Validar estado APPROVED
    if (($transaction['status'] ?? '') !== 'APPROVED') {
        error_log("[Starter FC] Transacción no aprobada: status=" . ($transaction['status'] ?? 'null'));
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
        error_log("[Starter FC] Monto no coincide: esperado=$expected_cents (precio: {$purchase->price}, redondeado: $rounded_price), recibido=$received_cents");
        starter_fc_purchase_mark_error($purchase->id, 'amount_mismatch', $transaction_id);
        return new WP_REST_Response([
            'success' => false,
            'message' => 'El monto de la transacción no coincide',
        ], 400);
    }

    // ── Delegar al procesador central ────────────────────────────────────────
    starter_process_virtual_coins_purchase($reference, $transaction);

    // Verificar resultado
    $updated = starter_fc_purchase_get_by_reference($reference);

    if ($updated && $updated->status === 'completed') {
        return new WP_REST_Response([
            'success' => true,
            'message' => '¡Virtual Coins acreditados exitosamente!',
            'data'    => [
                'reference'   => $updated->reference,
                'status'      => 'completed',
                'total_coins' => (int) $updated->total_coins,
            ],
        ], 200);
    }

    return new WP_REST_Response([
        'success' => false,
        'message' => 'Error al acreditar los Virtual Coins',
        'data'    => ['status' => $updated ? $updated->status : 'unknown'],
    ], 500);
}

// ─── Helpers internos del módulo endpoints ───────────────────────────────────

