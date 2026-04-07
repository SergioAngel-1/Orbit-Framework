<?php
/**
 * Servicio de lógica de negocio para membresías por antigüedad
 * 
 * Contiene toda la lógica de asignación, eliminación y gestión de membresías legacy.
 * Separado del renderizado UI y handlers AJAX para mejor mantenibilidad.
 * 
 * @package Starter_Memberships
 * @subpackage Admin/Legacy
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Starter_Legacy_Membership_Service {
    
    /**
     * Nivel de membresía por antigüedad
     */
    const LEGACY_LEVEL = 5;
    
    /**
     * Tamaño de lote por defecto
     */
    const DEFAULT_BATCH_SIZE = 100;
    
    /**
     * Duración por defecto en días
     */
    const DEFAULT_DURATION = 365;
    
    /**
     * Obtener nombre de tabla de membresías
     * 
     * @return string
     */
    private static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'starter_user_memberships';
    }
    
    /**
     * Ejecutar asignación automática de membresías por antigüedad (paginada)
     * 
     * @param int $batch_size Número de usuarios por lote
     * @return array Resultado de la asignación
     */
    public static function run_batch_assignment($batch_size = self::DEFAULT_BATCH_SIZE) {
        global $wpdb;
        
        $table = self::get_table_name();
        $duration = self::DEFAULT_DURATION;
        
        $progress = self::get_assignment_progress();
        
        // Si es la primera ejecución, contar total
        if ($progress['total'] === 0) {
            $progress['total'] = self::count_eligible_users();
            self::save_assignment_progress($progress);
        }
        
        // Obtener lote de usuarios elegibles
        $eligible_users = self::get_eligible_users_batch($batch_size);
        
        $batch_assigned = 0;
        $batch_skipped = 0;
        
        foreach ($eligible_users as $user_id) {
            $existing = starter_memberships_get_user_membership($user_id);
            
            if ($existing) {
                $batch_skipped++;
                continue;
            }
            
            $result = starter_activate_user_membership($user_id, self::LEGACY_LEVEL, $duration, null, null);
            
            if ($result) {
                $batch_assigned++;
                
                starter_memberships_log_action(
                    $user_id,
                    'legacy_auto_assignment',
                    0,
                    self::LEGACY_LEVEL,
                    ['reason' => 'Asignación automática por antigüedad'],
                    $result
                );
            } else {
                $batch_skipped++;
            }
        }
        
        // Actualizar progreso
        $progress['processed'] += count($eligible_users);
        $progress['assigned'] += $batch_assigned;
        $progress['skipped'] += $batch_skipped;
        
        $is_complete = count($eligible_users) < $batch_size;
        
        if ($is_complete) {
            self::mark_assignment_complete($progress);
        } else {
            self::save_assignment_progress($progress);
        }
        
        return [
            'batch_assigned' => $batch_assigned,
            'batch_skipped' => $batch_skipped,
            'batch_size' => count($eligible_users),
            'total_assigned' => $progress['assigned'],
            'total_skipped' => $progress['skipped'],
            'total_processed' => $progress['processed'],
            'total' => $progress['total'],
            'is_complete' => $is_complete,
            'remaining' => $is_complete ? 0 : ($progress['total'] - $progress['processed'])
        ];
    }
    
    /**
     * Ejecutar asignación masiva con diferentes modos
     * 
     * @param string $mode 'new_only', 'update_existing', 'all'
     * @param int $duration Duración en días
     * @param int $batch_size Tamaño del lote
     * @param bool $reset Resetear progreso
     * @return array Resultado
     */
    public static function run_mass_assignment($mode, $duration, $batch_size = self::DEFAULT_BATCH_SIZE, $reset = false) {
        global $wpdb;
        
        $table = self::get_table_name();
        $progress_key = 'starter_mass_assign_progress_' . $mode;
        $processed_key = 'starter_mass_assign_processed_ids_' . $mode;
        
        if ($reset) {
            delete_option($progress_key);
            delete_option($processed_key);
        }
        
        $progress = get_option($progress_key, [
            'processed' => 0,
            'assigned' => 0,
            'updated' => 0,
            'skipped' => 0,
            'total' => 0,
            'mode' => $mode,
            'duration' => $duration,
            'started_at' => current_time('mysql')
        ]);
        
        $processed_ids = get_option($processed_key, []);
        
        if ($progress['total'] === 0) {
            $progress['total'] = self::count_users_for_mode($mode);
            update_option($progress_key, $progress);
        }
        
        $users = self::get_users_batch_for_mode($mode, $batch_size, $processed_ids);
        
        $batch_assigned = 0;
        $batch_updated = 0;
        $batch_skipped = 0;
        
        foreach ($users as $user_id) {
            $processed_ids[] = $user_id;
            $existing = starter_memberships_get_user_membership($user_id);
            
            if ($mode === 'new_only' && $existing) {
                $batch_skipped++;
                continue;
            }
            
            if ($mode === 'update_existing') {
                if (!$existing || $existing->membership_level != self::LEGACY_LEVEL) {
                    $batch_skipped++;
                    continue;
                }
                
                self::extend_membership($existing->id, $user_id, $duration);
                $batch_updated++;
                continue;
            }
            
            // Modo 'all' o 'new_only' sin membresía existente
            if ($existing && $existing->membership_level == self::LEGACY_LEVEL) {
                self::extend_membership($existing->id, $user_id, $duration);
                $batch_updated++;
            } elseif (!$existing) {
                $result = starter_activate_user_membership($user_id, self::LEGACY_LEVEL, $duration, null, null);
                if ($result) {
                    $batch_assigned++;
                } else {
                    $batch_skipped++;
                }
            } else {
                $batch_skipped++;
            }
        }
        
        $progress['processed'] += count($users);
        $progress['assigned'] += $batch_assigned;
        $progress['updated'] += $batch_updated;
        $progress['skipped'] += $batch_skipped;
        
        update_option($processed_key, $processed_ids);
        
        $is_complete = count($users) < $batch_size;
        
        if ($is_complete) {
            $progress['completed_at'] = current_time('mysql');
            delete_option($progress_key);
            delete_option($processed_key);
            
            error_log(sprintf(
                'Starter Memberships: Asignación masiva COMPLETADA (modo: %s) - Asignados: %d, Actualizados: %d, Omitidos: %d',
                $mode, $progress['assigned'], $progress['updated'], $progress['skipped']
            ));
        } else {
            update_option($progress_key, $progress);
        }
        
        return [
            'batch_assigned' => $batch_assigned,
            'batch_updated' => $batch_updated,
            'batch_skipped' => $batch_skipped,
            'batch_size' => count($users),
            'total_assigned' => $progress['assigned'],
            'total_updated' => $progress['updated'],
            'total_skipped' => $progress['skipped'],
            'total_processed' => $progress['processed'],
            'total' => $progress['total'],
            'is_complete' => $is_complete,
            'mode' => $mode
        ];
    }
    
    /**
     * Eliminar membresías en lotes (excepto admins)
     * 
     * @param bool $reset Resetear progreso
     * @param int $batch_size Tamaño del lote
     * @return array Resultado
     */
    public static function delete_memberships_batch($reset = false, $batch_size = self::DEFAULT_BATCH_SIZE) {
        global $wpdb;
        
        $table = self::get_table_name();
        $admin_ids = self::get_admin_ids();
        
        if ($reset) {
            $total = self::count_non_admin_memberships($admin_ids);
            update_option('starter_delete_progress', [
                'total' => $total,
                'deleted' => 0
            ]);
        }
        
        $progress = get_option('starter_delete_progress', ['total' => 0, 'deleted' => 0]);
        
        $membership_ids = self::get_non_admin_membership_ids($admin_ids, $batch_size);
        
        $deleted_this_batch = 0;
        if (!empty($membership_ids)) {
            $user_ids = $wpdb->get_col(
                "SELECT DISTINCT user_id FROM $table WHERE id IN (" . implode(',', array_map('intval', $membership_ids)) . ")"
            );
            
            $placeholders = implode(',', array_fill(0, count($membership_ids), '%d'));
            $deleted_this_batch = $wpdb->query($wpdb->prepare(
                "DELETE FROM $table WHERE id IN ($placeholders)",
                $membership_ids
            ));
            
            if (!empty($user_ids)) {
                $user_placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->usermeta} WHERE user_id IN ($user_placeholders) AND meta_key IN ('_membership_level', '_membership_end_date')",
                    $user_ids
                ));
            }
        }
        
        $progress['deleted'] += $deleted_this_batch;
        update_option('starter_delete_progress', $progress);
        
        $is_complete = empty($membership_ids) || count($membership_ids) < $batch_size;
        
        if ($is_complete) {
            delete_option('starter_delete_progress');
            delete_option('starter_legacy_assignment_progress');
            delete_option('starter_legacy_memberships_assigned');
            
            error_log(sprintf('Starter Memberships: Eliminadas %d membresías (excepto %d admins)', $progress['deleted'], count($admin_ids)));
        }
        
        return [
            'deleted_this_batch' => $deleted_this_batch,
            'total_deleted' => $progress['deleted'],
            'total' => $progress['total'],
            'is_complete' => $is_complete,
            'admins_preserved' => count($admin_ids)
        ];
    }
    
    /**
     * Congelar/descongelar membresías en lotes
     * 
     * @param string $action 'freeze' o 'unfreeze'
     * @param bool $reset Resetear progreso
     * @param int $batch_size Tamaño del lote
     * @return array Resultado
     */
    public static function freeze_memberships_batch($action, $reset = false, $batch_size = self::DEFAULT_BATCH_SIZE) {
        global $wpdb;
        
        $table = self::get_table_name();
        $admin_ids = self::get_admin_ids();
        
        $from_status = $action === 'freeze' ? 'active' : 'frozen';
        $to_status = $action === 'freeze' ? 'frozen' : 'active';
        
        if ($reset) {
            $total = self::count_memberships_by_status($from_status, $admin_ids);
            update_option('starter_freeze_progress', ['total' => $total, 'processed' => 0]);
        }
        
        $progress = get_option('starter_freeze_progress', ['total' => 0, 'processed' => 0]);
        
        $membership_ids = self::get_membership_ids_by_status($from_status, $admin_ids, $batch_size);
        
        $processed_this_batch = 0;
        if (!empty($membership_ids)) {
            $memberships = $wpdb->get_results(
                "SELECT id, user_id, membership_level FROM $table WHERE id IN (" . implode(',', array_map('intval', $membership_ids)) . ")"
            );
            
            $placeholders = implode(',', array_fill(0, count($membership_ids), '%d'));
            $processed_this_batch = $wpdb->query($wpdb->prepare(
                "UPDATE $table SET status = %s, updated_at = %s WHERE id IN ($placeholders)",
                array_merge([$to_status, current_time('mysql')], $membership_ids)
            ));
            
            foreach ($memberships as $m) {
                if ($action === 'freeze') {
                    update_user_meta($m->user_id, '_membership_level', 0);
                } else {
                    update_user_meta($m->user_id, '_membership_level', $m->membership_level);
                }
            }
        }
        
        $progress['processed'] += $processed_this_batch;
        update_option('starter_freeze_progress', $progress);
        
        $is_complete = empty($membership_ids) || count($membership_ids) < $batch_size;
        
        if ($is_complete) {
            delete_option('starter_freeze_progress');
            error_log(sprintf('Starter Memberships: %s %d membresías', $action === 'freeze' ? 'Congeladas' : 'Descongeladas', $progress['processed']));
        }
        
        return [
            'processed' => $progress['processed'],
            'total' => $progress['total'],
            'is_complete' => $is_complete
        ];
    }
    
    /**
     * Congelar/descongelar membresía individual
     * 
     * @param int $user_id ID del usuario
     * @param string $action 'freeze' o 'unfreeze'
     * @return array|WP_Error Resultado o error
     */
    public static function freeze_single_membership($user_id, $action) {
        global $wpdb;
        
        $table = self::get_table_name();
        $to_status = $action === 'freeze' ? 'frozen' : 'active';
        
        $membership = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d ORDER BY id DESC LIMIT 1",
            $user_id
        ));
        
        if (!$membership) {
            return new WP_Error('not_found', 'No se encontró membresía para este usuario');
        }
        
        if ($action === 'freeze' && $membership->status === 'frozen') {
            return new WP_Error('already_frozen', 'La membresía ya está congelada');
        }
        
        if ($action === 'unfreeze' && $membership->status === 'active') {
            return new WP_Error('already_active', 'La membresía ya está activa');
        }
        
        $updated = $wpdb->update(
            $table,
            ['status' => $to_status, 'updated_at' => current_time('mysql')],
            ['id' => $membership->id],
            ['%s', '%s'],
            ['%d']
        );
        
        if ($updated !== false) {
            if ($action === 'freeze') {
                update_user_meta($user_id, '_membership_level', 0);
            } else {
                update_user_meta($user_id, '_membership_level', $membership->membership_level);
            }
            
            return ['success' => true, 'message' => 'Membresía actualizada'];
        }
        
        return new WP_Error('update_failed', 'Error al actualizar membresía');
    }
    
    /**
     * Asignar membresía individual
     * 
     * @param int $user_id ID del usuario
     * @param int $duration Duración en días
     * @return bool Éxito
     */
    public static function assign_single($user_id, $duration = self::DEFAULT_DURATION) {
        if (!$user_id || !get_user_by('ID', $user_id)) {
            return false;
        }
        
        $result = starter_activate_user_membership($user_id, self::LEGACY_LEVEL, $duration, null, null);
        return (bool) $result;
    }
    
    /**
     * Obtener estadísticas de membresía por antigüedad
     * 
     * @return array
     */
    public static function get_stats() {
        global $wpdb;
        
        $table = self::get_table_name();
        
        $with_legacy = $wpdb->get_var(
            "SELECT COUNT(*) FROM $table WHERE membership_level = " . self::LEGACY_LEVEL . " AND status = 'active'"
        );
        
        $eligible = self::count_eligible_users();
        
        return [
            'with_legacy' => intval($with_legacy),
            'eligible' => intval($eligible)
        ];
    }
    
    /**
     * Obtener usuarios con membresía por antigüedad
     * 
     * @param int $limit Límite de resultados
     * @return array
     */
    public static function get_users_with_legacy($limit = 20) {
        global $wpdb;
        
        $table = self::get_table_name();
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table 
             WHERE membership_level = %d AND status = 'active'
             ORDER BY created_at DESC
             LIMIT %d",
            self::LEGACY_LEVEL,
            $limit
        ));
    }
    
    // =========================================================================
    // MÉTODOS PRIVADOS DE AYUDA
    // =========================================================================
    
    /**
     * Obtener progreso de asignación
     */
    private static function get_assignment_progress() {
        return get_option('starter_legacy_assignment_progress', [
            'processed' => 0,
            'assigned' => 0,
            'skipped' => 0,
            'total' => 0,
            'started_at' => current_time('mysql')
        ]);
    }
    
    /**
     * Guardar progreso de asignación
     */
    private static function save_assignment_progress($progress) {
        update_option('starter_legacy_assignment_progress', $progress);
    }
    
    /**
     * Marcar asignación como completada
     */
    private static function mark_assignment_complete($progress) {
        update_option('starter_legacy_memberships_assigned', 'yes');
        delete_option('starter_assign_legacy_on_init');
        $progress['completed_at'] = current_time('mysql');
        
        error_log(sprintf(
            'Starter Memberships: Asignación automática COMPLETADA - %d asignadas, %d omitidas de %d total',
            $progress['assigned'],
            $progress['skipped'],
            $progress['total']
        ));
        
        self::save_assignment_progress($progress);
    }
    
    /**
     * Contar usuarios elegibles
     */
    private static function count_eligible_users() {
        global $wpdb;
        $table = self::get_table_name();
        
        return $wpdb->get_var("
            SELECT COUNT(DISTINCT u.ID) 
            FROM {$wpdb->users} u
            INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
            LEFT JOIN $table m ON u.ID = m.user_id AND m.status = 'active'
            WHERE um.meta_key = '{$wpdb->prefix}capabilities'
            AND (um.meta_value LIKE '%customer%' OR um.meta_value LIKE '%subscriber%')
            AND m.id IS NULL
        ");
    }
    
    /**
     * Obtener lote de usuarios elegibles
     */
    private static function get_eligible_users_batch($batch_size) {
        global $wpdb;
        $table = self::get_table_name();
        
        return $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT u.ID 
            FROM {$wpdb->users} u
            INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
            LEFT JOIN $table m ON u.ID = m.user_id AND m.status = 'active'
            WHERE um.meta_key = '{$wpdb->prefix}capabilities'
            AND (um.meta_value LIKE '%%customer%%' OR um.meta_value LIKE '%%subscriber%%')
            AND m.id IS NULL
            LIMIT %d
        ", $batch_size));
    }
    
    /**
     * Contar usuarios según el modo
     */
    private static function count_users_for_mode($mode) {
        global $wpdb;
        $table = self::get_table_name();
        
        if ($mode === 'new_only') {
            return self::count_eligible_users();
        } elseif ($mode === 'update_existing') {
            return $wpdb->get_var("
                SELECT COUNT(*) FROM $table 
                WHERE membership_level = " . self::LEGACY_LEVEL . " AND status = 'active'
            ");
        } else {
            $new = self::count_eligible_users();
            $existing = $wpdb->get_var("
                SELECT COUNT(*) FROM $table 
                WHERE membership_level = " . self::LEGACY_LEVEL . " AND status = 'active'
            ");
            return intval($new) + intval($existing);
        }
    }
    
    /**
     * Obtener lote de usuarios según el modo
     */
    private static function get_users_batch_for_mode($mode, $batch_size, $exclude_ids = []) {
        global $wpdb;
        $table = self::get_table_name();
        
        $exclude_clause = '';
        if (!empty($exclude_ids)) {
            $exclude_ids_str = implode(',', array_map('intval', $exclude_ids));
            $exclude_clause = " AND u.ID NOT IN ($exclude_ids_str)";
        }
        
        if ($mode === 'new_only') {
            return $wpdb->get_col($wpdb->prepare("
                SELECT DISTINCT u.ID 
                FROM {$wpdb->users} u
                INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
                LEFT JOIN $table m ON u.ID = m.user_id AND m.status = 'active'
                WHERE um.meta_key = '{$wpdb->prefix}capabilities'
                AND (um.meta_value LIKE '%%customer%%' OR um.meta_value LIKE '%%subscriber%%')
                AND m.id IS NULL
                $exclude_clause
                LIMIT %d
            ", $batch_size));
        } elseif ($mode === 'update_existing') {
            $exclude_clause_m = '';
            if (!empty($exclude_ids)) {
                $exclude_ids_str = implode(',', array_map('intval', $exclude_ids));
                $exclude_clause_m = " AND user_id NOT IN ($exclude_ids_str)";
            }
            return $wpdb->get_col($wpdb->prepare("
                SELECT user_id FROM $table 
                WHERE membership_level = %d AND status = 'active'
                $exclude_clause_m
                LIMIT %d
            ", self::LEGACY_LEVEL, $batch_size));
        } else {
            $new_users = $wpdb->get_col($wpdb->prepare("
                SELECT DISTINCT u.ID 
                FROM {$wpdb->users} u
                INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
                LEFT JOIN $table m ON u.ID = m.user_id AND m.status = 'active'
                WHERE um.meta_key = '{$wpdb->prefix}capabilities'
                AND (um.meta_value LIKE '%%customer%%' OR um.meta_value LIKE '%%subscriber%%')
                AND m.id IS NULL
                $exclude_clause
                LIMIT %d
            ", $batch_size));
            
            if (count($new_users) < $batch_size) {
                $remaining = $batch_size - count($new_users);
                $all_exclude = array_merge($exclude_ids, $new_users);
                $exclude_clause_m = '';
                if (!empty($all_exclude)) {
                    $exclude_ids_str = implode(',', array_map('intval', $all_exclude));
                    $exclude_clause_m = " AND user_id NOT IN ($exclude_ids_str)";
                }
                $existing_users = $wpdb->get_col($wpdb->prepare("
                    SELECT user_id FROM $table 
                    WHERE membership_level = %d AND status = 'active'
                    $exclude_clause_m
                    LIMIT %d
                ", self::LEGACY_LEVEL, $remaining));
                return array_merge($new_users, $existing_users);
            }
            
            return $new_users;
        }
    }
    
    /**
     * Extender membresía existente
     */
    private static function extend_membership($membership_id, $user_id, $duration) {
        global $wpdb;
        $table = self::get_table_name();
        
        $new_end_date = date('Y-m-d H:i:s', strtotime("+{$duration} days"));
        $wpdb->update(
            $table,
            ['end_date' => $new_end_date, 'updated_at' => current_time('mysql')],
            ['id' => $membership_id],
            ['%s', '%s'],
            ['%d']
        );
        update_user_meta($user_id, '_membership_end_date', $new_end_date);
    }
    
    /**
     * Obtener IDs de administradores
     */
    private static function get_admin_ids() {
        return get_users([
            'role' => 'administrator',
            'fields' => 'ID'
        ]);
    }
    
    /**
     * Contar membresías no-admin
     */
    private static function count_non_admin_memberships($admin_ids) {
        global $wpdb;
        $table = self::get_table_name();
        
        $exclude_clause = '';
        if (!empty($admin_ids)) {
            $placeholders = implode(',', array_fill(0, count($admin_ids), '%d'));
            $exclude_clause = $wpdb->prepare(" WHERE user_id NOT IN ($placeholders)", $admin_ids);
        }
        
        return $wpdb->get_var("SELECT COUNT(*) FROM $table $exclude_clause");
    }
    
    /**
     * Obtener IDs de membresías no-admin
     */
    private static function get_non_admin_membership_ids($admin_ids, $batch_size) {
        global $wpdb;
        $table = self::get_table_name();
        
        $exclude_clause = '';
        if (!empty($admin_ids)) {
            $placeholders = implode(',', array_fill(0, count($admin_ids), '%d'));
            $exclude_clause = $wpdb->prepare("WHERE user_id NOT IN ($placeholders)", $admin_ids);
        }
        
        return $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $table $exclude_clause LIMIT %d",
            $batch_size
        ));
    }
    
    /**
     * Contar membresías por estado
     */
    private static function count_memberships_by_status($status, $admin_ids) {
        global $wpdb;
        $table = self::get_table_name();
        
        $exclude_clause = '';
        if (!empty($admin_ids)) {
            $placeholders = implode(',', array_fill(0, count($admin_ids), '%d'));
            $exclude_clause = $wpdb->prepare(" AND user_id NOT IN ($placeholders)", $admin_ids);
        }
        
        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE status = %s $exclude_clause",
            $status
        ));
    }
    
    /**
     * Obtener IDs de membresías por estado
     */
    private static function get_membership_ids_by_status($status, $admin_ids, $batch_size) {
        global $wpdb;
        $table = self::get_table_name();
        
        $exclude_clause = '';
        if (!empty($admin_ids)) {
            $placeholders = implode(',', array_fill(0, count($admin_ids), '%d'));
            $exclude_clause = $wpdb->prepare("AND user_id NOT IN ($placeholders)", $admin_ids);
        }
        
        return $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $table WHERE status = %s $exclude_clause LIMIT %d",
            $status, $batch_size
        ));
    }
}
