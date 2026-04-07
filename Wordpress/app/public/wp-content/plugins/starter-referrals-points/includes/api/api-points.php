<?php
/**
 * API REST para el sistema de puntos
 * 
 * Funciones para los endpoints de la API REST relacionados con puntos.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Endpoint: Obtener Virtual Coins del usuario actual
 */
function starter_rp_get_current_user_points_endpoint() {
    $user_id = get_current_user_id();
    
    // Verificar si el usuario puede usar el sistema de puntos
    if (!starter_rp_can_user_use_points($user_id)) {
        return new WP_Error(
            'system_disabled', 
            'El sistema de puntos está deshabilitado o no tienes permisos para acceder.',
            ['status' => 403]
        );
    }
    
    $user_points = starter_rp_get_user_points($user_id);
    
    if (!$user_points) {
        $user_points = [
            'balance' => 0,
            'total_earned' => 0,
            'used' => 0
        ];
    }
    
    // Calcular valor monetario
    $options = Starter_RP()->get_options();
    $conversion_rate = (float) ($options['points_conversion_rate'] ?? 0.1);
    $points_percentage = $options['points_percentage'] ?? 5;
    
    // Calcular el valor monetario basado en conversion_rate
    // Si conversion_rate = 0.1, significa que cada punto vale 0.1 unidades monetarias
    // Por lo tanto, multiplicamos el balance por conversion_rate
    $points_value = $user_points['balance'] * $conversion_rate;
    
    $response = [
        'balance' => $user_points['balance'],
        'total_earned' => $user_points['total_earned'],
        'used' => $user_points['used'],
        'monetary_value' => round($points_value, 2),
        'conversion_rate' => $conversion_rate,
        'points_percentage' => $points_percentage
    ];
    
    return new WP_REST_Response($response, 200);
}

/**
 * Endpoint: Obtener transacciones de Virtual Coins del usuario actual
 */
function starter_rp_get_user_transactions_endpoint(WP_REST_Request $request) {
    $user_id = get_current_user_id();
    
    // Verificar si el usuario puede usar el sistema de puntos
    if (!starter_rp_can_user_use_points($user_id)) {
        return new WP_Error(
            'system_disabled', 
            'El sistema de puntos está deshabilitado o no tienes permisos para acceder.',
            ['status' => 403]
        );
    }
    
    $page = isset($request['page']) ? absint($request['page']) : 1;
    $per_page = isset($request['per_page']) ? absint($request['per_page']) : 20;
    // Limitar per_page entre 1 y 100 para evitar consultas muy grandes
    $per_page = max(1, min(100, $per_page));
    $offset = ($page - 1) * $per_page;
    
    global $wpdb;
    $transactions_table = $wpdb->prefix . 'starter_points_transactions';
    
    // Obtener transacciones
    $transactions = $wpdb->get_results($wpdb->prepare("
        SELECT * FROM $transactions_table WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d
    ", $user_id, $per_page, $offset));
    $total_transactions = starter_rp_count_user_transactions($user_id);
    
    if (!$transactions) {
        $transactions = [];
    }
    
    // Formatear para la respuesta
    $formatted_transactions = [];
    foreach ($transactions as $transaction) {
        $formatted_transactions[] = [
            'id' => $transaction->id,
            'date' => $transaction->created_at,
            'type' => $transaction->type,
            'points' => $transaction->points,
            'description' => $transaction->description,
            'expires_at' => $transaction->expiration_date
        ];
    }
    
    $response = [
        'transactions' => $formatted_transactions,
        'total' => $total_transactions,
        'pages' => ceil($total_transactions / $per_page)
    ];
    
    return new WP_REST_Response($response, 200);
}

/**
 * Endpoint: Obtener información de Virtual Coins de un producto
 */
function starter_rp_get_product_points_endpoint($request) {
    $product_id = $request['id'];
    $product = wc_get_product($product_id);
    
    if (!$product) {
        return new WP_Error('product_not_found', 'Producto no encontrado', ['status' => 404]);
    }
    
    $points = starter_rp_calculate_product_points($product_id);
    
    return new WP_REST_Response([
        'id' => $product_id,
        'name' => $product->get_name(),
        'points' => $points
    ], 200);
}

/**
 * Endpoint para transferir Virtual Coins a otro usuario usando código de referido
 * 
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function starter_rp_transfer_points_endpoint($request) {
    $user_id = get_current_user_id();
    
    // Verificar si el usuario puede usar el sistema de puntos
    if (!starter_rp_can_user_use_points($user_id)) {
        return new WP_Error(
            'system_disabled', 
            'El sistema de puntos está deshabilitado o no tienes permisos para acceder.',
            ['status' => 403]
        );
    }
    
    $params = $request->get_json_params();
    
    // Verificar parámetros requeridos
    if (!isset($params['points']) || !isset($params['recipient_code'])) {
        return new WP_Error(
            'missing_parameters', 
            'Faltan parámetros requeridos: points, recipient_code', 
            ['status' => 400]
        );
    }
    
    $points = absint($params['points']);
    $recipient_code = sanitize_text_field($params['recipient_code']);
    $description = isset($params['description']) ? sanitize_text_field($params['description']) : '';
    
    // Verificar cantidad mínima de puntos
    if ($points < 100) {
        return new WP_Error(
            'invalid_points', 
            'La cantidad mínima para transferir es de 100 puntos', 
            ['status' => 400]
        );
    }
    
    // Verificar si el usuario tiene suficientes puntos
    $user_points = starter_rp_get_user_points($user_id);
    if (!$user_points || $user_points['balance'] < $points) {
        return new WP_Error(
            'insufficient_points', 
            'No tienes suficientes puntos para realizar esta transferencia', 
            ['status' => 400]
        );
    }
    
    // Obtener ID del usuario receptor por código de referido
    $recipient_id = starter_rp_get_user_by_referral_code($recipient_code);
    
    if (!$recipient_id) {
        return new WP_Error(
            'invalid_recipient', 
            'Código de referido no válido o usuario no encontrado', 
            ['status' => 400]
        );
    }
    
    // Verificar que no sea el mismo usuario
    if ($recipient_id == $user_id) {
        return new WP_Error(
            'self_transfer', 
            'No puedes transferirte puntos a ti mismo', 
            ['status' => 400]
        );
    }
    
    // Obtener datos de los usuarios
    $sender = get_userdata($user_id);
    $recipient = get_userdata($recipient_id);
    
    if (!$sender || !$recipient) {
        return new WP_Error(
            'user_error', 
            'Error al obtener información de usuario', 
            ['status' => 500]
        );
    }
    
    // Preparar descripciones
    if (empty($description)) {
        $description = 'Transferencia de puntos';
    }
    
    $sender_description = sprintf(
        'Transferencia a %s: %s', 
        $recipient->display_name, 
        $description
    );
    
    $recipient_description = sprintf(
        'Recibido de %s: %s', 
        $sender->display_name, 
        $description
    );
    
    // Restar puntos al remitente
    $sender_result = starter_rp_use_points(
        $user_id, 
        $points, 
        $sender_description, 
        $recipient_id
    );
    
    if (!$sender_result) {
        return new WP_Error(
            'sender_transaction_error', 
            'Error al procesar la transacción del remitente', 
            ['status' => 500]
        );
    }
    
    // Aplicar comisión si está configurada
    $options = Starter_RP()->get_options();
    $commission_percentage = isset($options['transfer_commission_percentage']) 
        ? floatval($options['transfer_commission_percentage']) 
        : 0;
    
    $commission_points = 0;
    $points_to_add = $points;
    
    if ($commission_percentage > 0) {
        $commission_points = ceil(($points * $commission_percentage) / 100);
        $points_to_add = $points - $commission_points;
    }
    
    // Añadir puntos al destinatario
    $recipient_result = starter_rp_add_points(
        $recipient_id, 
        $points_to_add, 
        'received', 
        $recipient_description, 
        $user_id, 
        isset($options['points_expiration_days']) ? $options['points_expiration_days'] : 0
    );
    
    if (!$recipient_result) {
        // Revertir la transacción del remitente
        starter_rp_add_points(
            $user_id, 
            $points, 
            'refund', 
            'Reembolso por error en transferencia', 
            $recipient_id
        );
        
        return new WP_Error(
            'recipient_transaction_error', 
            'Error al procesar la transacción del destinatario', 
            ['status' => 500]
        );
    }
    
    // Respuesta exitosa
    return new WP_REST_Response([
        'success' => true,
        'points_sent' => $points,
        'points_received' => $points_to_add,
        'commission' => $commission_points,
        'recipient' => [
            'id' => $recipient_id,
            'name' => $recipient->display_name
        ]
    ], 200);
}

/**
 * Contar transacciones de un usuario
 */
function starter_rp_count_user_transactions($user_id) {
    global $wpdb;
    $transactions_table = $wpdb->prefix . 'starter_points_transactions';
    
    return $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) FROM $transactions_table WHERE user_id = %d
    ", $user_id));
}
