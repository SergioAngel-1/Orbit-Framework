<?php
/**
 * Utilidad para desbloquear IPs del Rate Limiting
 * 
 * Proporciona funciones para administradores para desbloquear IPs
 * que han sido bloqueadas por el sistema de rate limiting.
 * 
 * @package Starter
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Desbloquear una IP específica para una acción
 * 
 * @param string $ip IP a desbloquear
 * @param string $action Acción específica ('login', 'register', etc.) o 'all' para todas
 * @return bool True si se desbloqueó exitosamente
 */
function starter_unblock_ip($ip, $action = 'all') {
    // Validar IP
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        error_log("Starter: IP inválida para desbloquear: {$ip}");
        return false;
    }
    
    $actions_to_clear = array();
    
    if ($action === 'all') {
        // Desbloquear para todas las acciones
        $actions_to_clear = array(
            'login',
            'register',
            'password_request',
            'password_reset',
            'contact',
            'order_email',
            'points_transfer',
            'referral_validate'
        );
    } else {
        $actions_to_clear = array($action);
    }
    
    $cleared_count = 0;
    
    foreach ($actions_to_clear as $act) {
        // Eliminar bloqueo
        $block_key = 'starter_blocked_' . $act . '_' . md5($ip);
        if (delete_transient($block_key)) {
            $cleared_count++;
            error_log("Starter: Eliminado bloqueo de {$ip} para acción: {$act}");
        }
        
        // Eliminar contador de intentos
        $attempts_key = 'starter_attempts_' . $act . '_' . md5($ip);
        if (delete_transient($attempts_key)) {
            error_log("Starter: Eliminado contador de intentos de {$ip} para acción: {$act}");
        }
    }
    
    if ($cleared_count > 0) {
        error_log("Starter: IP {$ip} desbloqueada exitosamente ({$cleared_count} acciones)");
        return true;
    }
    
    error_log("Starter: No se encontraron bloqueos para IP {$ip}");
    return false;
}

/**
 * Endpoint REST API para desbloquear IPs (solo administradores)
 */
add_action('rest_api_init', function() {
    register_rest_route('starter/v1', '/admin/unblock-ip', array(
        'methods' => 'POST',
        'callback' => 'starter_unblock_ip_endpoint',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ));
});

function starter_unblock_ip_endpoint($request) {
    $ip = $request->get_param('ip');
    $action = $request->get_param('action') ?: 'all';
    
    if (empty($ip)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'IP es requerida'
        ], 400);
    }
    
    $result = starter_unblock_ip($ip, $action);
    
    if ($result) {
        return new WP_REST_Response([
            'success' => true,
            'message' => "IP {$ip} desbloqueada exitosamente",
            'ip' => $ip,
            'action' => $action
        ], 200);
    } else {
        return new WP_REST_Response([
            'success' => false,
            'message' => "No se pudo desbloquear la IP {$ip}. Verifica que la IP sea válida o que esté bloqueada.",
            'ip' => $ip
        ], 404);
    }
}

/**
 * Comando WP-CLI para desbloquear IPs
 * 
 * Uso: wp starter unblock-ip <ip> [--action=<action>]
 * Ejemplo: wp starter unblock-ip 192.168.1.100
 * Ejemplo: wp starter unblock-ip 192.168.1.100 --action=login
 */
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('starter unblock-ip', function($args, $assoc_args) {
        if (empty($args[0])) {
            WP_CLI::error('Debes proporcionar una IP. Uso: wp starter unblock-ip <ip>');
            return;
        }
        
        $ip = $args[0];
        $action = isset($assoc_args['action']) ? $assoc_args['action'] : 'all';
        
        WP_CLI::log("Desbloqueando IP: {$ip} para acción: {$action}...");
        
        $result = starter_unblock_ip($ip, $action);
        
        if ($result) {
            WP_CLI::success("IP {$ip} desbloqueada exitosamente");
        } else {
            WP_CLI::warning("No se encontraron bloqueos para la IP {$ip}");
        }
    });
}
