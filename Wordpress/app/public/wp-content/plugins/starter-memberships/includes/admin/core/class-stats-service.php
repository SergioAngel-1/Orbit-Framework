<?php
/**
 * Servicio de estadísticas de membresías
 * 
 * @package Starter_Memberships
 * @subpackage Admin/Core
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Starter_Stats_Service {
    
    /**
     * Obtener estadísticas generales de membresías
     * 
     * @return array
     */
    public static function get_stats() {
        global $wpdb;
        
        $memberships_table = $wpdb->prefix . 'starter_user_memberships';
        $points_table = $wpdb->prefix . 'starter_membership_points';
        
        return [
            'active_memberships' => self::get_active_count($memberships_table),
            'by_level' => self::get_count_by_level($memberships_table),
            'frozen_count' => self::get_frozen_count($memberships_table),
            'total_points_awarded' => self::get_monthly_points($points_table),
            'expiring_soon' => self::get_expiring_soon_count($memberships_table),
            'expired_count' => self::get_expired_count($memberships_table)
        ];
    }
    
    /**
     * Obtener cantidad de membresías activas
     * 
     * @param string $table Nombre de la tabla
     * @return int
     */
    private static function get_active_count($table) {
        global $wpdb;
        
        $count = $wpdb->get_var(
            "SELECT COUNT(*) FROM $table WHERE status = 'active' AND end_date > NOW()"
        );
        
        return intval($count);
    }
    
    /**
     * Obtener cantidad por nivel
     * 
     * @param string $table Nombre de la tabla
     * @return array
     */
    private static function get_count_by_level($table) {
        global $wpdb;
        
        $by_level = [];
        $levels_data = $wpdb->get_results(
            "SELECT membership_level, COUNT(*) as count FROM $table 
             WHERE status = 'active' AND end_date > NOW()
             GROUP BY membership_level"
        );
        
        foreach ($levels_data as $row) {
            $by_level[$row->membership_level] = intval($row->count);
        }
        
        return $by_level;
    }
    
    /**
     * Obtener cantidad de membresías congeladas
     * 
     * @param string $table Nombre de la tabla
     * @return int
     */
    private static function get_frozen_count($table) {
        global $wpdb;
        
        $count = $wpdb->get_var(
            "SELECT COUNT(*) FROM $table WHERE status = 'frozen'"
        );
        
        return intval($count);
    }
    
    /**
     * Obtener puntos otorgados este mes
     * 
     * @param string $table Nombre de la tabla
     * @return int
     */
    private static function get_monthly_points($table) {
        global $wpdb;
        
        $current_month = date('n');
        $current_year = date('Y');
        
        $total_points = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(points_amount) FROM $table 
             WHERE period_month = %d AND period_year = %d",
            $current_month,
            $current_year
        ));
        
        return intval($total_points);
    }
    
    /**
     * Obtener cantidad de membresías próximas a expirar
     * 
     * @param string $table Nombre de la tabla
     * @param int $days Días para considerar "próximo"
     * @return int
     */
    private static function get_expiring_soon_count($table, $days = 7) {
        global $wpdb;
        
        // Solo la membresía más reciente de cada usuario
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table m
             INNER JOIN (
                SELECT user_id, MAX(id) AS max_id
                FROM $table
                GROUP BY user_id
             ) latest ON m.id = latest.max_id
             WHERE m.status = 'active' 
             AND m.end_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL %d DAY)",
            $days
        ));
        
        return intval($count);
    }
    
    /**
     * Obtener cantidad de membresías expiradas
     * (status 'active' pero end_date ya pasó, o status 'expired')
     * 
     * @param string $table Nombre de la tabla
     * @return int
     */
    private static function get_expired_count($table) {
        global $wpdb;
        
        // Solo contar la membresía más reciente de cada usuario
        // para evitar capturar filas antiguas de usuarios que renovaron
        $count = $wpdb->get_var(
            "SELECT COUNT(*) FROM $table m
             INNER JOIN (
                SELECT user_id, MAX(id) AS max_id
                FROM $table
                GROUP BY user_id
             ) latest ON m.id = latest.max_id
             WHERE (m.status = 'active' AND m.end_date <= NOW())
                OR m.status = 'expired'"
        );
        
        return intval($count);
    }
}
