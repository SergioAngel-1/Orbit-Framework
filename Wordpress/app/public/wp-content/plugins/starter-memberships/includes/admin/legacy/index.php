<?php
/**
 * Módulo de Membresías por Antigüedad (Legacy)
 * 
 * Este archivo carga todos los componentes del módulo legacy y registra
 * los hooks necesarios. Reemplaza al monolítico legacy-membership-page.php.
 * 
 * Estructura modular:
 * - class-legacy-membership-service.php: Lógica de negocio
 * - class-legacy-ajax-handlers.php: Handlers AJAX
 * - class-legacy-page-renderer.php: Renderizado UI
 * 
 * @package Starter_Memberships
 * @subpackage Admin/Legacy
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Cargar clases del módulo
require_once __DIR__ . '/class-legacy-membership-service.php';
require_once __DIR__ . '/class-legacy-ajax-handlers.php';
require_once __DIR__ . '/class-legacy-page-renderer.php';

/**
 * Inicializar módulo legacy
 */
function starter_legacy_module_init() {
    // Registrar handlers AJAX
    Starter_Legacy_Ajax_Handlers::register();
    
    // Hook para asignación automática en lote (ejecutado por cron)
    add_action('starter_assign_legacy_memberships_batch', 'starter_run_legacy_membership_assignment');
}
add_action('init', 'starter_legacy_module_init');

/**
 * Función wrapper para asignación automática (compatibilidad)
 * 
 * @param int $batch_size Tamaño del lote
 * @return array Resultado
 */
function starter_run_legacy_membership_assignment($batch_size = 100) {
    return Starter_Legacy_Membership_Service::run_batch_assignment($batch_size);
}

/**
 * Función wrapper para ejecutar asignación masiva (compatibilidad)
 * 
 * @param string $mode Modo de asignación
 * @param int $duration Duración en días
 * @param int $batch_size Tamaño del lote
 * @param bool $reset Resetear progreso
 * @return array Resultado
 */
function starter_run_mass_assignment($mode, $duration, $batch_size = 100, $reset = false) {
    return Starter_Legacy_Membership_Service::run_mass_assignment($mode, $duration, $batch_size, $reset);
}

/**
 * Función wrapper para contar usuarios según modo (compatibilidad)
 * 
 * @param string $mode Modo
 * @return int Cantidad
 */
function starter_count_users_for_mode($mode) {
    // Esta función ya no es necesaria externamente, pero se mantiene por compatibilidad
    $stats = Starter_Legacy_Membership_Service::get_stats();
    
    if ($mode === 'new_only') {
        return $stats['eligible'];
    } elseif ($mode === 'update_existing') {
        return $stats['with_legacy'];
    } else {
        return $stats['eligible'] + $stats['with_legacy'];
    }
}

/**
 * Función wrapper para obtener lote de usuarios (compatibilidad)
 * 
 * @deprecated Usar Starter_Legacy_Membership_Service directamente
 */
function starter_get_users_batch_for_mode($mode, $batch_size, $exclude_ids = []) {
    _doing_it_wrong(__FUNCTION__, 'Usar Starter_Legacy_Membership_Service directamente', '1.1.0');
    return [];
}

/**
 * Renderizar página de membresías por antigüedad
 * 
 * Esta función es llamada desde el menú de admin.
 */
function starter_memberships_legacy_page() {
    Starter_Legacy_Page_Renderer::render();
}

/**
 * Obtener estadísticas de membresía por antigüedad
 * 
 * @return array
 */
function starter_get_legacy_membership_stats() {
    return Starter_Legacy_Membership_Service::get_stats();
}

/**
 * Obtener usuarios con membresía por antigüedad
 * 
 * @param int $limit Límite
 * @return array
 */
function starter_get_users_with_legacy_membership($limit = 20) {
    return Starter_Legacy_Membership_Service::get_users_with_legacy($limit);
}

/**
 * Asignar membresías por antigüedad a usuarios elegibles (legacy sync)
 * 
 * @deprecated Usar Starter_Legacy_Membership_Service::run_batch_assignment()
 * @return array
 */
function starter_assign_legacy_memberships() {
    global $wpdb;
    
    $table = $wpdb->prefix . 'starter_user_memberships';
    $duration = isset($_POST['legacy_duration']) ? intval($_POST['legacy_duration']) : 365;
    
    $eligible_users = $wpdb->get_col("
        SELECT DISTINCT u.ID 
        FROM {$wpdb->users} u
        INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
        LEFT JOIN $table m ON u.ID = m.user_id AND m.status = 'active'
        WHERE um.meta_key = '{$wpdb->prefix}capabilities'
        AND um.meta_value LIKE '%customer%'
        AND m.id IS NULL
    ");
    
    $assigned = 0;
    $skipped = 0;
    
    foreach ($eligible_users as $user_id) {
        $existing = starter_memberships_get_user_membership($user_id);
        
        if ($existing) {
            $skipped++;
            continue;
        }
        
        $result = starter_activate_user_membership($user_id, 5, $duration > 0 ? $duration : 36500);
        
        if ($result) {
            $assigned++;
        }
    }
    
    error_log(sprintf(
        'Starter Memberships: Asignación masiva de membresías por antigüedad - %d asignadas, %d omitidas',
        $assigned, $skipped
    ));
    
    return [
        'assigned' => $assigned,
        'skipped' => $skipped
    ];
}
