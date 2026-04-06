<?php
/**
 * API REST para administración
 * 
 * Funciones para los endpoints de la API REST relacionados con administración.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Endpoint: Obtener resumen de estadísticas para administradores
 */
function starter_rp_get_admin_stats_endpoint() {
    // Verificar permisos
    if (!current_user_can('manage_options')) {
        return new WP_Error(
            'permission_denied',
            'No tienes permisos para acceder a esta información',
            ['status' => 403]
        );
    }
    
    global $wpdb;
    $users_table = $wpdb->users;
    $points_table = $wpdb->prefix . 'starter_points_transactions';
    $referrals_table = $wpdb->prefix . 'starter_referrals';
    
    // Estadísticas de usuarios
    $total_users = $wpdb->get_var("SELECT COUNT(*) FROM $users_table");
    
    // Estadísticas de puntos
    $total_points_balance = $wpdb->get_var("
        SELECT SUM(points) FROM $points_table WHERE points > 0
    ");
    
    $total_points_used = $wpdb->get_var("
        SELECT ABS(SUM(points)) FROM $points_table WHERE points < 0
    ");
    
    $recent_transactions = $wpdb->get_results("
        SELECT * FROM $points_table ORDER BY created_at DESC LIMIT 10
    ", ARRAY_A);
    
    // Formatear transacciones recientes
    if ($recent_transactions) {
        foreach ($recent_transactions as &$transaction) {
            $user = get_userdata($transaction['user_id']);
            $transaction['user_name'] = $user ? $user->display_name : "Usuario #{$transaction['user_id']}";
        }
    }
    
    // Estadísticas de referidos
    $total_referrals = $wpdb->get_var("
        SELECT COUNT(*) FROM $referrals_table WHERE referrer_id IS NOT NULL
    ");
    
    // Usuarios con más referidos
    $top_referrers = $wpdb->get_results("
        SELECT referrer_id, COUNT(*) as referral_count 
        FROM $referrals_table 
        WHERE referrer_id IS NOT NULL 
        GROUP BY referrer_id 
        ORDER BY referral_count DESC 
        LIMIT 5
    ", ARRAY_A);
    
    // Formatear usuarios con más referidos
    if ($top_referrers) {
        foreach ($top_referrers as &$referrer) {
            $user = get_userdata($referrer['referrer_id']);
            $referrer['user_name'] = $user ? $user->display_name : "Usuario #{$referrer['referrer_id']}";
            $referrer['user_email'] = $user ? $user->user_email : "";
        }
    }
    
    // Preparar respuesta
    $response = [
        'users' => [
            'total' => intval($total_users)
        ],
        'points' => [
            'total_balance' => intval($total_points_balance),
            'total_used' => intval($total_points_used),
            'recent_transactions' => $recent_transactions ?: []
        ],
        'referrals' => [
            'total' => intval($total_referrals),
            'top_referrers' => $top_referrers ?: []
        ]
    ];
    
    return new WP_REST_Response($response, 200);
}
