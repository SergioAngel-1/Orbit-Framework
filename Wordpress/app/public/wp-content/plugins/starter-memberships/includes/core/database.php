<?php
/**
 * Funciones de base de datos para Starter Memberships
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Crear tablas necesarias para el plugin
 */
function starter_memberships_create_tables() {
    global $wpdb;
    
    $charset_collate = $wpdb->get_charset_collate();
    
    // Tabla de membresías de usuarios
    $table_user_memberships = $wpdb->prefix . 'starter_user_memberships';
    
    $sql_user_memberships = "CREATE TABLE IF NOT EXISTS $table_user_memberships (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        membership_level int(11) NOT NULL DEFAULT 0,
        product_id bigint(20) unsigned DEFAULT NULL,
        order_id bigint(20) unsigned DEFAULT NULL,
        start_date datetime NOT NULL,
        end_date datetime DEFAULT NULL,
        status varchar(20) NOT NULL DEFAULT 'active',
        auto_renew tinyint(1) NOT NULL DEFAULT 0,
        points_awarded bigint(20) NOT NULL DEFAULT 0,
        granted_by_admin bigint(20) unsigned DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY membership_level (membership_level),
        KEY status (status),
        KEY end_date (end_date)
    ) $charset_collate;";
    
    // Agregar columna granted_by_admin si no existe (para actualizaciones)
    $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_user_memberships LIKE 'granted_by_admin'");
    if (empty($column_exists)) {
        $wpdb->query("ALTER TABLE $table_user_memberships ADD COLUMN granted_by_admin bigint(20) unsigned DEFAULT NULL AFTER points_awarded");
    }
    
    // LEGACY: Columna next_points_date ya no se usa (los FC se otorgan una única vez al activar)
    // Se mantiene por compatibilidad con datos existentes
    $next_points_col = $wpdb->get_results("SHOW COLUMNS FROM $table_user_memberships LIKE 'next_points_date'");
    if (empty($next_points_col)) {
        $wpdb->query("ALTER TABLE $table_user_memberships ADD COLUMN next_points_date date DEFAULT NULL AFTER end_date");
    }
    
    // Columna is_referral_bonus para marcar membresías otorgadas por bono de referido
    // Estas membresías NO otorgan Virtual Coins
    $referral_bonus_col = $wpdb->get_results("SHOW COLUMNS FROM $table_user_memberships LIKE 'is_referral_bonus'");
    if (empty($referral_bonus_col)) {
        $wpdb->query("ALTER TABLE $table_user_memberships ADD COLUMN is_referral_bonus tinyint(1) NOT NULL DEFAULT 0 AFTER next_points_date");
    }
    
    // Tabla de historial de membresías
    $table_membership_history = $wpdb->prefix . 'starter_membership_history';
    
    $sql_membership_history = "CREATE TABLE IF NOT EXISTS $table_membership_history (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        membership_id bigint(20) unsigned DEFAULT NULL,
        action varchar(50) NOT NULL,
        old_level int(11) DEFAULT NULL,
        new_level int(11) DEFAULT NULL,
        details text DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY membership_id (membership_id),
        KEY action (action)
    ) $charset_collate;";
    
    // Tabla de puntos por periodo otorgados
    $table_monthly_points = $wpdb->prefix . 'starter_membership_points';
    
    $sql_monthly_points = "CREATE TABLE IF NOT EXISTS $table_monthly_points (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        membership_id bigint(20) unsigned NOT NULL,
        points_amount bigint(20) NOT NULL,
        period_month int(2) NOT NULL,
        period_year int(4) NOT NULL,
        awarded_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        UNIQUE KEY user_period (user_id, period_month, period_year),
        KEY membership_id (membership_id),
        KEY awarded_at (awarded_at)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    
    dbDelta($sql_user_memberships);
    dbDelta($sql_membership_history);
    dbDelta($sql_monthly_points);
    
    // Agregar índices faltantes en tabla de puntos (para bases de datos existentes)
    $table_points = $wpdb->prefix . 'starter_membership_points';
    
    // Verificar si el índice user_id existe
    $user_id_index = $wpdb->get_results("SHOW INDEX FROM $table_points WHERE Key_name = 'user_id'");
    if (empty($user_id_index)) {
        $wpdb->query("ALTER TABLE $table_points ADD INDEX user_id (user_id)");
    }
    
    // Verificar si el índice awarded_at existe
    $awarded_at_index = $wpdb->get_results("SHOW INDEX FROM $table_points WHERE Key_name = 'awarded_at'");
    if (empty($awarded_at_index)) {
        $wpdb->query("ALTER TABLE $table_points ADD INDEX awarded_at (awarded_at)");
    }
    
    // Guardar versión de la base de datos
    update_option('starter_memberships_db_version', '1.1.0');
    
    error_log('Starter Memberships: Tablas de base de datos creadas/actualizadas');
}

/**
 * Obtener membresía activa o congelada de un usuario
 */
function starter_memberships_get_user_membership($user_id) {
    global $wpdb;
    
    $table = $wpdb->prefix . 'starter_user_memberships';
    
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table 
         WHERE user_id = %d 
         AND status IN ('active', 'frozen') 
         AND (end_date > NOW() OR end_date IS NULL)
         ORDER BY FIELD(status, 'frozen', 'active'), membership_level DESC, end_date DESC
         LIMIT 1",
        $user_id
    ));
}

/**
 * Obtener historial de membresías de un usuario
 */
function starter_memberships_get_user_history($user_id, $limit = 10) {
    global $wpdb;
    
    $table = $wpdb->prefix . 'starter_membership_history';
    
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table 
         WHERE user_id = %d 
         ORDER BY created_at DESC
         LIMIT %d",
        $user_id,
        $limit
    ));
}

/**
 * Registrar acción en historial
 */
function starter_memberships_log_action($user_id, $action, $old_level = null, $new_level = null, $details = null, $membership_id = null) {
    global $wpdb;
    
    $table = $wpdb->prefix . 'starter_membership_history';
    
    return $wpdb->insert($table, [
        'user_id' => $user_id,
        'membership_id' => $membership_id,
        'action' => $action,
        'old_level' => $old_level,
        'new_level' => $new_level,
        'details' => is_array($details) ? wp_json_encode($details) : $details,
        'created_at' => current_time('mysql')
    ], ['%d', '%d', '%s', '%d', '%d', '%s', '%s']);
}
