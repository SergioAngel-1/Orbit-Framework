<?php
/**
 * Rotación diaria de debug.log
 * 
 * - Cada día mueve debug.log a /logs/debug-YYYY-MM-DD.log
 * - Mantiene solo los últimos 7 días de logs
 * - Se ejecuta via WP Cron una vez al día
 * - Los logs rotados se guardan en wp-content/logs/
 */

if (!defined('ABSPATH')) {
    exit;
}

// Número de días de logs a conservar
define('STARTER_LOG_RETENTION_DAYS', 7);

// Directorio donde se guardan los logs rotados
define('STARTER_LOG_DIR', WP_CONTENT_DIR . '/logs');

// Crear directorio de logs inmediatamente si no existe
if (!is_dir(STARTER_LOG_DIR)) {
    wp_mkdir_p(STARTER_LOG_DIR);

    // Proteger el directorio con .htaccess
    $htaccess = STARTER_LOG_DIR . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Order deny,allow\nDeny from all\n");
    }
}

/**
 * Registrar el evento cron al activar el tema
 */
function starter_schedule_log_rotation() {
    if (!wp_next_scheduled('starter_daily_log_rotation')) {
        wp_schedule_event(time(), 'daily', 'starter_daily_log_rotation');
    }
}
add_action('after_setup_theme', 'starter_schedule_log_rotation');

/**
 * Limpiar el evento cron al cambiar de tema
 */
function starter_unschedule_log_rotation() {
    $timestamp = wp_next_scheduled('starter_daily_log_rotation');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'starter_daily_log_rotation');
    }
}
add_action('switch_theme', 'starter_unschedule_log_rotation');

/**
 * Ejecutar la rotación de logs
 */
function starter_rotate_debug_log() {
    $log_file = STARTER_LOG_DIR . '/debug.log';

    // Si no existe o está vacío, no hacer nada
    if (!file_exists($log_file) || filesize($log_file) === 0) {
        return;
    }

    // Crear directorio de logs si no existe
    if (!is_dir(STARTER_LOG_DIR)) {
        wp_mkdir_p(STARTER_LOG_DIR);

        // Proteger el directorio con .htaccess
        $htaccess = STARTER_LOG_DIR . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Order deny,allow\nDeny from all\n");
        }
    }

    // Renombrar el archivo actual con la fecha de hoy
    $date_suffix = date('Y-m-d');
    $rotated_file = STARTER_LOG_DIR . '/debug-' . $date_suffix . '.log';

    // Si ya existe el archivo rotado de hoy, agregar contenido en vez de sobreescribir
    if (file_exists($rotated_file)) {
        $current_content = file_get_contents($log_file);
        if ($current_content !== false && $current_content !== '') {
            file_put_contents($rotated_file, $current_content, FILE_APPEND | LOCK_EX);
        }
    } else {
        rename($log_file, $rotated_file);
    }

    // Vaciar o recrear debug.log para que WordPress siga escribiendo ahí
    file_put_contents($log_file, '', LOCK_EX);

    // Eliminar logs antiguos (más de STARTER_LOG_RETENTION_DAYS días)
    starter_cleanup_old_logs();
}
add_action('starter_daily_log_rotation', 'starter_rotate_debug_log');

/**
 * Eliminar archivos de log más antiguos que el período de retención
 */
function starter_cleanup_old_logs() {
    $retention_seconds = STARTER_LOG_RETENTION_DAYS * DAY_IN_SECONDS;
    $now = time();

    $files = glob(STARTER_LOG_DIR . '/debug-*.log');
    if (!$files) {
        return;
    }

    foreach ($files as $file) {
        // Extraer la fecha del nombre del archivo
        $basename = basename($file, '.log');
        $date_part = str_replace('debug-', '', $basename);
        $file_time = strtotime($date_part);

        if ($file_time && ($now - $file_time) > $retention_seconds) {
            @unlink($file);
        }
    }
}
