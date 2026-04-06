<?php
/**
 * Manejador de base de datos para tokens de restablecimiento
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Crea la tabla de tokens de restablecimiento si no existe
 */
function starter_create_reset_tokens_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'starter_reset_tokens';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        token_hash varchar(64) NOT NULL,
        user_id bigint(20) NOT NULL,
        user_login varchar(60) NOT NULL,
        is_valid tinyint(1) NOT NULL DEFAULT 1,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY token_hash (token_hash),
        KEY idx_token_lookup (token_hash, user_login, is_valid, expires_at),
        KEY idx_cleanup (is_valid, expires_at)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

/**
 * Registra un token de restablecimiento en la base de datos
 * 
 * @param string $token_hash Hash del token
 * @param int $user_id ID del usuario
 * @param string $user_login Login del usuario
 * @param int $expiry_hours Horas de validez del token
 * @return bool Resultado de la operación
 */
function starter_register_reset_token($token_hash, $user_id, $user_login, $expiry_hours = 24) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'starter_reset_tokens';
    
    // Calcular fecha de expiración
    $expires_at = date('Y-m-d H:i:s', strtotime("+{$expiry_hours} hours"));
    
    // Insertar el token
    $result = $wpdb->insert(
        $table_name,
        array(
            'token_hash' => $token_hash,
            'user_id' => $user_id,
            'user_login' => $user_login,
            'is_valid' => 1,
            'expires_at' => $expires_at
        ),
        array('%s', '%d', '%s', '%d', '%s')
    );
    
    return $result !== false;
}

/**
 * Verifica si un token de restablecimiento es válido
 * 
 * @param string $token_hash Hash del token
 * @param string $user_login Login del usuario
 * @return bool|WP_Error True si es válido, WP_Error si no
 */
function starter_verify_reset_token($token_hash, $user_login) {
    global $wpdb;
    
    // Registrar intento de verificación (sin datos sensibles)
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("Verificando token de reset para usuario: {$user_login}");
    }
    
    // Asegurarse de que la tabla existe
    starter_create_reset_tokens_table();
    
    $table_name = $wpdb->prefix . 'starter_reset_tokens';
    
    // Buscar el token
    $query = $wpdb->prepare(
        "SELECT * FROM $table_name WHERE token_hash = %s AND user_login = %s AND is_valid = 1 AND expires_at > NOW()",
        $token_hash, $user_login
    );
    
    $token = $wpdb->get_row($query);
    
    if (!$token) {
        // Verificar si existe pero expiró
        $expired_query = $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE token_hash = %s AND user_login = %s AND expires_at <= NOW()",
            $token_hash, $user_login
        );
        
        $expired = $wpdb->get_var($expired_query);
        
        if ($expired) {
            return new WP_Error('expired_key', 'El token ha expirado');
        }
        
        // Verificar si existe pero fue invalidado
        $invalidated_query = $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE token_hash = %s AND user_login = %s AND is_valid = 0",
            $token_hash, $user_login
        );
        
        $invalidated = $wpdb->get_var($invalidated_query);
        
        if ($invalidated) {
            return new WP_Error('invalid_key', 'Token ya utilizado');
        }
        
        return new WP_Error('invalid_key', 'Token inválido');
    }
    
    return true;
}

/**
 * Invalida un token de restablecimiento después de usarlo
 * 
 * @param string $token_hash Hash del token
 * @param string $user_login Login del usuario
 * @return bool Resultado de la operación
 */
function starter_invalidate_reset_token($token_hash, $user_login) {
    global $wpdb;
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("Invalidando token para usuario: {$user_login}");
    }
    
    $table_name = $wpdb->prefix . 'starter_reset_tokens';
    
    // Verificar primero si el token existe
    $token_exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE token_hash = %s AND user_login = %s",
        $token_hash, $user_login
    ));
    
    if (!$token_exists) {
        return false;
    }
    
    // Actualizar el token a no válido
    $result = $wpdb->update(
        $table_name,
        array('is_valid' => 0),
        array('token_hash' => $token_hash, 'user_login' => $user_login),
        array('%d'),
        array('%s', '%s')
    );
    
    if ($result === false) {
        error_log("Error al invalidar token de reset para usuario: {$user_login}");
    }
    
    return $result !== false;
}

/**
 * Limpia tokens expirados o no válidos
 */
function starter_cleanup_reset_tokens() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'starter_reset_tokens';
    
    // Eliminar tokens expirados o no válidos con más de 48 horas
    $wpdb->query(
        "DELETE FROM $table_name WHERE is_valid = 0 OR expires_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)"
    );
}

// Programar limpieza diaria de tokens
add_action('wp', function() {
    if (!wp_next_scheduled('starter_daily_token_cleanup')) {
        wp_schedule_event(time(), 'daily', 'starter_daily_token_cleanup');
    }
});

add_action('starter_daily_token_cleanup', 'starter_cleanup_reset_tokens');

// Crear tabla al activar el tema
add_action('after_switch_theme', 'starter_create_reset_tokens_table');

// También crear la tabla si no existe cuando se carga este archivo
starter_create_reset_tokens_table();
