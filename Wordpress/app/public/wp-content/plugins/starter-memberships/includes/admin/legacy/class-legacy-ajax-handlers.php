<?php
/**
 * Handlers AJAX para membresías por antigüedad
 * 
 * Contiene todos los handlers de peticiones AJAX relacionados con
 * la gestión de membresías legacy. Delega la lógica al servicio.
 * 
 * @package Starter_Memberships
 * @subpackage Admin/Legacy
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Starter_Legacy_Ajax_Handlers {
    
    /**
     * Registrar handlers AJAX
     */
    public static function register() {
        add_action('wp_ajax_starter_process_legacy_batch', [__CLASS__, 'process_legacy_batch']);
        add_action('wp_ajax_starter_mass_assign_legacy', [__CLASS__, 'mass_assign_legacy']);
        add_action('wp_ajax_starter_delete_all_memberships', [__CLASS__, 'delete_all_memberships']);
        add_action('wp_ajax_starter_freeze_memberships_batch', [__CLASS__, 'freeze_memberships_batch']);
        add_action('wp_ajax_starter_freeze_single_membership', [__CLASS__, 'freeze_single_membership']);
    }
    
    /**
     * Verificar permisos de administrador
     * 
     * @return bool
     */
    private static function verify_admin() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sin permisos']);
            return false;
        }
        return true;
    }
    
    /**
     * Verificar nonce
     * 
     * @param string $nonce_action Acción del nonce
     * @return bool
     */
    private static function verify_nonce($nonce_action) {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', $nonce_action)) {
            wp_send_json_error(['message' => 'Nonce inválido']);
            return false;
        }
        return true;
    }
    
    /**
     * Handler: Procesar lote de asignación automática
     */
    public static function process_legacy_batch() {
        if (!self::verify_admin()) return;
        if (!self::verify_nonce('starter_legacy_batch_nonce')) return;
        
        // Resetear progreso si es inicio
        if (isset($_POST['reset']) && $_POST['reset'] === '1') {
            delete_option('starter_legacy_assignment_progress');
            delete_option('starter_legacy_memberships_assigned');
        }
        
        $result = Starter_Legacy_Membership_Service::run_batch_assignment(100);
        
        wp_send_json_success($result);
    }
    
    /**
     * Handler: Asignación masiva con modos
     */
    public static function mass_assign_legacy() {
        if (!self::verify_admin()) return;
        if (!self::verify_nonce('starter_mass_assign_nonce')) return;
        
        $mode = sanitize_text_field($_POST['mode'] ?? 'new_only');
        $duration = intval($_POST['duration'] ?? 365);
        $is_reset = isset($_POST['reset']) && $_POST['reset'] === '1';
        
        $result = Starter_Legacy_Membership_Service::run_mass_assignment($mode, $duration, 100, $is_reset);
        
        wp_send_json_success($result);
    }
    
    /**
     * Handler: Eliminar todas las membresías
     */
    public static function delete_all_memberships() {
        if (!self::verify_admin()) return;
        if (!self::verify_nonce('starter_delete_all_memberships_nonce')) return;
        
        $reset = isset($_POST['reset']) && $_POST['reset'] === '1';
        
        $result = Starter_Legacy_Membership_Service::delete_memberships_batch($reset, 100);
        
        wp_send_json_success($result);
    }
    
    /**
     * Handler: Congelar/descongelar membresías en lotes
     */
    public static function freeze_memberships_batch() {
        if (!self::verify_admin()) return;
        if (!self::verify_nonce('starter_freeze_memberships_nonce')) return;
        
        $freeze_action = sanitize_text_field($_POST['freeze_action'] ?? 'freeze');
        $reset = isset($_POST['reset']) && $_POST['reset'] === '1';
        
        $result = Starter_Legacy_Membership_Service::freeze_memberships_batch($freeze_action, $reset, 100);
        
        wp_send_json_success($result);
    }
    
    /**
     * Handler: Congelar/descongelar membresía individual
     */
    public static function freeze_single_membership() {
        if (!self::verify_admin()) return;
        if (!self::verify_nonce('starter_freeze_memberships_nonce')) return;
        
        $user_id = absint($_POST['user_id'] ?? 0);
        $freeze_action = sanitize_text_field($_POST['freeze_action'] ?? 'freeze');
        
        if (!$user_id) {
            wp_send_json_error(['message' => 'Usuario no válido']);
            return;
        }
        
        $result = Starter_Legacy_Membership_Service::freeze_single_membership($user_id, $freeze_action);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } else {
            wp_send_json_success($result);
        }
    }
}
