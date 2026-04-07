<?php
/**
 * API REST para el sistema de referidos
 * 
 * Funciones para los endpoints de la API REST relacionados con referidos.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Endpoint: Obtener información de referidos del usuario actual
 */
function starter_rp_get_user_referrals_endpoint() {
    $user_id = get_current_user_id();
    
    // Verificar si el usuario puede usar el sistema de referidos
    if (!starter_rp_can_user_use_referrals($user_id)) {
        return new WP_Error(
            'system_disabled', 
            'El sistema de referidos está deshabilitado o no tienes permisos para acceder.',
            ['status' => 403]
        );
    }
    
    $referrals = starter_rp_get_user_referrals($user_id, 'all', true);
    
    // Agrupar por nivel
    $direct_referrals = array_filter($referrals, function($ref) {
        return $ref['level'] == 1;
    });
    
    $indirect_referrals = array_filter($referrals, function($ref) {
        return $ref['level'] == 2;
    });
    
    $response = [
        'direct' => array_values($direct_referrals),
        'indirect' => array_values($indirect_referrals),
        'total_count' => count($referrals)
    ];
    
    return new WP_REST_Response($response, 200);
}

/**
 * Endpoint: Obtener estadísticas de referidos del usuario actual
 */
function starter_rp_get_user_referral_stats_endpoint() {
    $user_id = get_current_user_id();
    
    // Verificar si el usuario puede usar el sistema de referidos
    if (!starter_rp_can_user_use_referrals($user_id)) {
        return new WP_Error(
            'system_disabled', 
            'El sistema de referidos está deshabilitado o no tienes permisos para acceder.',
            ['status' => 403]
        );
    }
    
    $stats = starter_rp_get_referral_stats($user_id);
    
    if (!$stats) {
        $stats = [
            'total_referrals' => 0,
            'total_earnings' => 0,
            'direct_referrals' => 0,
            'indirect_referrals' => 0
        ];
    }
    
    return new WP_REST_Response($stats, 200);
}

/**
 * Endpoint: Obtener el código y enlace de referido del usuario actual
 */
function starter_rp_get_user_referral_code_endpoint() {
    $user_id = get_current_user_id();
    
    // Verificar si el usuario puede usar el sistema de referidos
    if (!starter_rp_can_user_use_referrals($user_id)) {
        return new WP_Error(
            'system_disabled', 
            'El sistema de referidos está deshabilitado o no tienes permisos para acceder.',
            ['status' => 403]
        );
    }
    
    $code = starter_rp_get_user_referral_code($user_id);
    
    if (!$code) {
        // Si el usuario no tiene código, generar uno
        $code = starter_rp_generate_referral_code($user_id);
    }
    
    $referral_url = home_url('?ref=' . $code);
    
    return new WP_REST_Response([
        'code' => $code,
        'url' => $referral_url
    ], 200);
}

/**
 * Endpoint: Obtener la configuración del programa de referidos
 */
function starter_rp_get_referral_config_endpoint() {
    $options = Starter_RP()->get_options();
    
    // Obtener comisiones del usuario actual según su nivel de membresía
    $user_id = get_current_user_id();
    $user_level = 0;
    
    if ($user_id && function_exists('starter_get_user_membership_level')) {
        $user_level = starter_get_user_membership_level($user_id);
    }
    
    // Usar la función helper para obtener comisiones
    $commission_first = function_exists('starter_rp_get_commission_for_user') 
        ? starter_rp_get_commission_for_user($user_id, 'first', $options)
        : 10;
    $commission_subsequent = function_exists('starter_rp_get_commission_for_user') 
        ? starter_rp_get_commission_for_user($user_id, 'subsequent', $options)
        : 5;
    $commission_level2 = function_exists('starter_rp_get_commission_for_user') 
        ? starter_rp_get_commission_for_user($user_id, 'level2', $options)
        : 2;
    
    // Nivel 0 no tiene acceso al programa de referidos (requiere mínimo Bronce)
    $has_referral_access = $user_level >= 1;
    
    $config = [
        'signup_points' => isset($options['referral_signup_points']) ? intval($options['referral_signup_points']) : 0,
        'signup_points_level1' => isset($options['signup_points_level1']) ? intval($options['signup_points_level1']) : 100,
        'signup_points_level2' => isset($options['signup_points_level2']) ? intval($options['signup_points_level2']) : 50,
        // Acceso al programa de referidos
        'has_referral_access' => $has_referral_access,
        'min_level_required' => 1, // Bronce
        // Comisiones del usuario según su nivel de membresía (0 si no tiene acceso)
        'user_membership_level' => $user_level,
        'commission_first' => $has_referral_access ? $commission_first : 0,
        'commission_subsequent' => $has_referral_access ? $commission_subsequent : 0,
        'commission_level2' => $has_referral_access ? $commission_level2 : 0,
        // Otros
        'enable_second_level' => !empty($options['enable_second_level']),
        'commission_duration_days' => intval($options['referral_commission_duration'] ?? 365),
        'points_value' => isset($options['points_value']) ? floatval($options['points_value']) : 0,
        'points_percentage' => isset($options['points_percentage']) ? floatval($options['points_percentage']) : 5,
        'currency_symbol' => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$'
    ];
    
    return new WP_REST_Response($config, 200);
}

/**
 * Endpoint para validar un código de referido
 * 
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function starter_rp_validate_referral_code_endpoint($request) {
    // Verificar si el sistema de referidos está habilitado
    if (!starter_rp_is_referrals_system_enabled()) {
        return new WP_Error(
            'system_disabled', 
            'El sistema de referidos está deshabilitado.',
            ['status' => 403]
        );
    }
    
    $params = $request->get_params();
    
    if (!isset($params['code'])) {
        return new WP_Error(
            'missing_code', 
            'Falta el parámetro de código de referido', 
            ['status' => 400]
        );
    }
    
    $code = sanitize_text_field($params['code']);
    $user_id = get_current_user_id();
    
    // Validar que el código existe (ahora solo se aceptan coincidencias exactas)
    $referrer_id = starter_rp_get_user_by_referral_code($code);
    if (!$referrer_id) {
        starter_rp_log("Código de referido no encontrado: $code");
        return new WP_Error(
            'invalid_code',
            'Código de referido inválido o no encontrado. Debe ingresar el código exacto (nombre de usuario + 4 números).',
            array('status' => 400)
        );
    }
    
    // Verificar si el referidor puede participar en el sistema de referidos
    if (!starter_rp_can_user_use_referrals($referrer_id)) {
        return new WP_Error(
            'referrer_not_allowed',
            'El propietario de este código de referido no puede participar en el programa.',
            array('status' => 400)
        );
    }
    
    // Verificar que no sea el propio código del usuario
    $user_code = starter_rp_get_user_referral_code($user_id);
    if ($user_code && $user_code === $code) {
        return new WP_Error(
            'own_code', 
            'No puedes usar tu propio código de referido', 
            ['status' => 400]
        );
    }
    
    // Verificar que el usuario no sea ya referido por otro usuario
    global $wpdb;
    $table = $wpdb->prefix . 'starter_referrals';
    $existing_referrer = $wpdb->get_var($wpdb->prepare("
        SELECT referrer_id FROM $table WHERE user_id = %d AND referrer_id IS NOT NULL
    ", $user_id));
    
    // Obtener información del referente
    $referrer = get_userdata($referrer_id);
    
    $response = [
        'valid' => true,
        'referrer' => [
            'id' => $referrer_id,
            'name' => $referrer ? $referrer->display_name : 'Usuario',
        ],
        'has_existing_referrer' => !empty($existing_referrer)
    ];
    
    return new WP_REST_Response($response, 200);
}

/**
 * Obtener estadísticas de referidos
 * 
 * OPTIMIZADO: Usa queries batch con GROUP BY en lugar de N+1 queries individuales.
 * Antes: 1 query por cada referido para calcular earnings (N+1 problem)
 * Ahora: 1 query para todos los earnings agrupados por reference_id
 * 
 * @param int $user_id ID del usuario
 * @return array Estadísticas de referidos
 */
function starter_rp_get_referral_stats($user_id) {
    if (!$user_id) {
        return false;
    }
    
    global $wpdb;
    $referrals_table = $wpdb->prefix . 'starter_referrals';
    $transactions_table = $wpdb->prefix . 'starter_points_transactions';
    
    // Obtener número de referidos directos
    $direct_referrals = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) FROM $referrals_table WHERE referrer_id = %d
    ", $user_id));
    
    // Obtener referidos de segundo nivel (indirectos)
    $direct_users = $wpdb->get_col($wpdb->prepare("
        SELECT user_id FROM $referrals_table WHERE referrer_id = %d
    ", $user_id));
    
    $indirect_referrals = 0;
    if (!empty($direct_users)) {
        $safe_ids = array_map('intval', $direct_users);
        $placeholders = implode(',', array_fill(0, count($safe_ids), '%d'));
        $indirect_referrals = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM $referrals_table WHERE referrer_id IN ($placeholders)
        ", ...$safe_ids));
    }
    
    // Obtener ganancias totales por referidos (incluye todos los tipos de comisiones de referidos)
    $total_earnings = $wpdb->get_var($wpdb->prepare("
        SELECT SUM(points) FROM $transactions_table 
        WHERE user_id = %d 
        AND type IN ('referral', 'referral_signup', 'referral_commission', 'referral_commission_level2', 'referral_signup_level1', 'referral_signup_level2')
    ", $user_id));
    
    // Obtener información de referidos directos (máximo 10 recientes)
    $direct_referrals_data = $wpdb->get_results($wpdb->prepare("
        SELECT r.*, u.display_name, u.user_email, u.user_registered
        FROM $referrals_table r
        JOIN {$wpdb->users} u ON r.user_id = u.ID
        WHERE r.referrer_id = %d
        ORDER BY r.signup_date DESC
        LIMIT 10
    ", $user_id), ARRAY_A);
    
    // OPTIMIZACIÓN: Obtener earnings de TODOS los referidos directos en UNA sola query
    $direct_earnings_map = [];
    if ($direct_referrals_data) {
        $direct_user_ids = array_column($direct_referrals_data, 'user_id');
        
        $direct_placeholders = implode(',', array_fill(0, count($direct_user_ids), '%d'));
        $direct_earnings_results = $wpdb->get_results($wpdb->prepare("
            SELECT reference_id, SUM(points) as total_earnings
            FROM $transactions_table 
            WHERE user_id = %d 
            AND type IN ('referral', 'referral_signup', 'referral_commission', 'referral_signup_level1')
            AND reference_id IN ($direct_placeholders)
            GROUP BY reference_id
        ", array_merge(array($user_id), array_map('intval', $direct_user_ids))), ARRAY_A);
        
        foreach ($direct_earnings_results as $row) {
            $direct_earnings_map[$row['reference_id']] = intval($row['total_earnings']);
        }
    }
    
    // OPTIMIZACIÓN: Obtener estados de aprobación de TODOS los referidos en UNA sola query
    $pending_status_map = [];
    if ($direct_referrals_data) {
        $direct_user_ids = array_column($direct_referrals_data, 'user_id');
        
        $pending_placeholders = implode(',', array_fill(0, count($direct_user_ids), '%d'));
        $pending_users = $wpdb->get_col($wpdb->prepare("
            SELECT user_id FROM {$wpdb->usermeta} 
            WHERE meta_key = 'wp_user_approval_status' 
            AND meta_value = 'pending'
            AND user_id IN ($pending_placeholders)
        ", ...array_map('intval', $direct_user_ids)));
        
        foreach ($pending_users as $pending_user_id) {
            $pending_status_map[$pending_user_id] = true;
        }
    }
    
    // Formatear datos de referidos directos usando los mapas precargados
    $active_referrals = 0;
    if ($direct_referrals_data) {
        foreach ($direct_referrals_data as &$ref) {
            // Usar mapa de earnings en lugar de query individual
            $ref['earnings'] = isset($direct_earnings_map[$ref['user_id']]) ? $direct_earnings_map[$ref['user_id']] : 0;
            
            // Usar mapa de estados en lugar de get_user_meta individual
            $is_pending = isset($pending_status_map[$ref['user_id']]);
            $ref['status'] = $is_pending ? 'pending' : 'active';
            
            if (!$is_pending) {
                $active_referrals++;
            }
            
            $ref['first_purchase'] = null;
            $ref['timestamp'] = strtotime($ref['signup_date']);
        }
    }
    
    // Obtener referidos pendientes de aprobación (meta diferente)
    $pending_referrals = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) FROM {$wpdb->usermeta} 
        WHERE meta_key = '_starter_pending_referral_points' 
        AND meta_value = %d
    ", $user_id));
    
    // Obtener información de referidos indirectos (máximo 10 recientes)
    $indirect_referrals_data = [];
    if (!empty($direct_users)) {
        $safe_direct = array_map('intval', $direct_users);
        $indirect_placeholders = implode(',', array_fill(0, count($safe_direct), '%d'));
        $indirect_referrals_data = $wpdb->get_results($wpdb->prepare("
            SELECT r.*, u.display_name, u.user_email, u.user_registered,
                   r2.user_id as via_user_id, u2.display_name as via_name
            FROM $referrals_table r
            JOIN {$wpdb->users} u ON r.user_id = u.ID
            JOIN $referrals_table r2 ON r.referrer_id = r2.user_id
            JOIN {$wpdb->users} u2 ON r2.user_id = u2.ID
            WHERE r.referrer_id IN ($indirect_placeholders)
            ORDER BY r.signup_date DESC
            LIMIT 10
        ", ...$safe_direct), ARRAY_A);
        
        // OPTIMIZACIÓN: Obtener earnings de TODOS los referidos indirectos en UNA sola query
        if ($indirect_referrals_data) {
            $indirect_user_ids = array_column($indirect_referrals_data, 'user_id');
            $indirect_earnings_map = [];
            $ie_placeholders = implode(',', array_fill(0, count($indirect_user_ids), '%d'));
            $indirect_earnings_results = $wpdb->get_results($wpdb->prepare("
                SELECT reference_id, SUM(points) as total_earnings
                FROM $transactions_table 
                WHERE user_id = %d 
                AND type IN ('referral', 'referral_commission_level2', 'referral_signup_level2')
                AND reference_id IN ($ie_placeholders)
                GROUP BY reference_id
            ", array_merge(array($user_id), array_map('intval', $indirect_user_ids))), ARRAY_A);
            
            foreach ($indirect_earnings_results as $row) {
                $indirect_earnings_map[$row['reference_id']] = intval($row['total_earnings']);
            }
            
            // Formatear datos usando el mapa
            foreach ($indirect_referrals_data as &$ref) {
                $ref['earnings'] = isset($indirect_earnings_map[$ref['user_id']]) ? $indirect_earnings_map[$ref['user_id']] : 0;
                $ref['status'] = 'active';
                $ref['timestamp'] = strtotime($ref['signup_date']);
            }
        }
    }
    
    // Preparar respuesta
    $response = [
        'total_referrals' => intval($direct_referrals) + intval($indirect_referrals),
        'direct_referrals' => intval($direct_referrals),
        'indirect_referrals' => intval($indirect_referrals),
        'pending_referrals' => intval($pending_referrals),
        'total_earnings' => $total_earnings ? intval($total_earnings) : 0,
        'total_points_generated' => $total_earnings ? intval($total_earnings) : 0,
        'direct_referrals_data' => $direct_referrals_data,
        'indirect_referrals_data' => $indirect_referrals_data
    ];
    
    return $response;
}

/**
 * Endpoint: Obtener el referidor del usuario actual (quién me refirió)
 * 
 * @return WP_REST_Response|WP_Error
 */
function starter_rp_get_my_referrer_endpoint() {
    $user_id = get_current_user_id();
    
    if (!$user_id) {
        return new WP_Error(
            'not_logged_in', 
            'Debes iniciar sesión para ver esta información.',
            ['status' => 401]
        );
    }
    
    // Verificar si el usuario puede usar el sistema de referidos
    if (!starter_rp_can_user_use_referrals($user_id)) {
        return new WP_Error(
            'system_disabled', 
            'El sistema de referidos está deshabilitado o no tienes permisos para acceder.',
            ['status' => 403]
        );
    }
    
    // Obtener información del referidor
    $referrer = starter_rp_get_user_referrer($user_id);
    
    if (!$referrer) {
        return new WP_REST_Response([
            'has_referrer' => false,
            'referrer' => null
        ], 200);
    }
    
    return new WP_REST_Response([
        'has_referrer' => true,
        'referrer' => [
            'id' => $referrer['id'],
            'name' => $referrer['name'],
            'status' => $referrer['status']
        ]
    ], 200);
}
