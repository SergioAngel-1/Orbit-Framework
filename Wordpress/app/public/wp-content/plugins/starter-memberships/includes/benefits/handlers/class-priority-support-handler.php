<?php
/**
 * Handler: Soporte Prioritario
 * 
 * Gestiona el beneficio de atención al cliente prioritaria.
 * Este es un beneficio de tipo "fixed" (on/off).
 * 
 * @package Starter_Memberships
 * @subpackage Benefits/Handlers
 * @since 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Starter_Priority_Support_Handler extends Starter_Benefit_Handler_Base {
    
    /**
     * Meta key para marcar usuarios con soporte prioritario
     */
    const META_PRIORITY_SUPPORT = '_starter_priority_support';
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->key = 'priority_support';
        $this->name = 'Soporte Prioritario';
        $this->description = 'Atención al cliente prioritaria';
        $this->requires_wc = false;
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_name(): string {
        return __($this->name, 'starter-memberships');
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_description(): string {
        return __($this->description, 'starter-memberships');
    }
    
    /**
     * {@inheritdoc}
     */
    public function register_hooks(): void {
        // Hook para verificar si el usuario tiene soporte prioritario
        add_filter('starter_has_priority_support', [$this, 'check_priority_support'], 10, 2);
        
        // Hook para obtener nivel de prioridad en tickets de soporte
        add_filter('starter_support_ticket_priority', [$this, 'get_ticket_priority'], 10, 2);
        
        // Hook cuando cambia la membresía para actualizar meta
        add_action('starter_membership_changed', [$this, 'update_support_status'], 10, 2);
    }
    
    /**
     * {@inheritdoc}
     */
    public function apply(int $user_id, array $context = []) {
        $has_support = $this->is_enabled_for_user($user_id);
        
        return [
            'has_priority_support' => $has_support,
            'support_level' => $has_support ? 'priority' : 'standard',
            'response_time' => $has_support ? '< 4 horas' : '< 24 horas'
        ];
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_display_value(int $user_id): string {
        if (!$this->is_enabled_for_user($user_id)) {
            return '';
        }
        
        return __('Atención prioritaria', 'starter-memberships');
    }
    
    /**
     * Verificar si el usuario tiene soporte prioritario
     * 
     * @param bool $has_support
     * @param int $user_id
     * @return bool
     */
    public function check_priority_support(bool $has_support, int $user_id): bool {
        if ($has_support) {
            return true;
        }
        
        return $this->is_enabled_for_user($user_id);
    }
    
    /**
     * Obtener prioridad para tickets de soporte
     * 
     * @param string $priority Prioridad por defecto
     * @param int $user_id
     * @return string
     */
    public function get_ticket_priority(string $priority, int $user_id): string {
        if (!$this->is_enabled_for_user($user_id)) {
            return $priority;
        }
        
        // Elevar prioridad si tiene soporte prioritario
        $priority_map = [
            'low' => 'normal',
            'normal' => 'high',
            'high' => 'urgent'
        ];
        
        return $priority_map[$priority] ?? 'high';
    }
    
    /**
     * Actualizar estado de soporte cuando cambia la membresía
     * 
     * @param int $user_id
     * @param int $new_level
     */
    public function update_support_status(int $user_id, int $new_level): void {
        $has_support = $this->is_enabled_for_user($user_id);
        
        if ($has_support) {
            update_user_meta($user_id, self::META_PRIORITY_SUPPORT, 'yes');
        } else {
            delete_user_meta($user_id, self::META_PRIORITY_SUPPORT);
        }
        
        $this->log("Estado de soporte actualizado", [
            'user_id' => $user_id,
            'level' => $new_level,
            'has_priority' => $has_support
        ]);
    }
}

// Registrar el handler inmediatamente
// (las clases core se cargan antes que los handlers en benefits/index.php)
starter_benefit_registry()->register(new Starter_Priority_Support_Handler());
