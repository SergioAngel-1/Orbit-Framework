<?php
/**
 * Handler: Beneficios de Seguridad
 * 
 * Gestiona acceso a servicios de seguridad y asesoría:
 * - Espacio seguro
 * - Asesoría jurídica (videollamada)
 * 
 * Configuración esperada:
 * - safe_space: bool - Acceso a espacio seguro
 * - legal_advice: bool - Acceso a asesoría jurídica
 * 
 * @package Starter_Memberships
 * @subpackage Benefits/Handlers
 * @since 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Starter_Security_Benefits_Handler extends Starter_Benefit_Handler_Base {
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->key = 'security_benefits';
        $this->name = 'Beneficios de Seguridad';
        $this->description = 'Acceso a servicios de seguridad y asesoría';
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
        // Hook para verificar acceso a espacio seguro
        add_filter('starter_can_access_safe_space', [$this, 'check_safe_space_access'], 10, 2);
        
        // Hook para verificar acceso a asesoría jurídica
        add_filter('starter_can_access_legal_advice', [$this, 'check_legal_advice_access'], 10, 2);
        
        // Hook para obtener servicios de seguridad disponibles
        add_filter('starter_available_security_services', [$this, 'get_available_services'], 10, 2);
    }
    
    /**
     * {@inheritdoc}
     */
    public function apply(int $user_id, array $context = []) {
        $config = $this->get_config_for_user($user_id);
        
        if (!$config) {
            return [
                'safe_space' => false,
                'legal_advice' => false
            ];
        }
        
        return [
            'safe_space' => !empty($config['safe_space']),
            'legal_advice' => !empty($config['legal_advice'])
        ];
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_display_value(int $user_id): string {
        $config = $this->get_config_for_user($user_id);
        
        if (!$config) {
            return '';
        }
        
        $services = [];
        
        if (!empty($config['safe_space'])) {
            $services[] = __('Espacio seguro', 'starter-memberships');
        }
        
        if (!empty($config['legal_advice'])) {
            $services[] = __('Asesoría jurídica', 'starter-memberships');
        }
        
        if (empty($services)) {
            return '';
        }
        
        return implode(' + ', $services);
    }
    
    /**
     * Verificar acceso a espacio seguro
     * 
     * @param bool $has_access
     * @param int $user_id
     * @return bool
     */
    public function check_safe_space_access(bool $has_access, int $user_id): bool {
        if ($has_access) {
            return true;
        }
        
        if (!$this->is_enabled_for_user($user_id)) {
            return false;
        }
        
        $config = $this->get_config_for_user($user_id);
        return !empty($config['safe_space']);
    }
    
    /**
     * Verificar acceso a asesoría jurídica
     * 
     * @param bool $has_access
     * @param int $user_id
     * @return bool
     */
    public function check_legal_advice_access(bool $has_access, int $user_id): bool {
        if ($has_access) {
            return true;
        }
        
        if (!$this->is_enabled_for_user($user_id)) {
            return false;
        }
        
        $config = $this->get_config_for_user($user_id);
        return !empty($config['legal_advice']);
    }
    
    /**
     * Obtener servicios de seguridad disponibles para el usuario
     * 
     * @param array $services
     * @param int $user_id
     * @return array
     */
    public function get_available_services(array $services, int $user_id): array {
        if (!$this->is_enabled_for_user($user_id)) {
            return $services;
        }
        
        $config = $this->get_config_for_user($user_id);
        
        if (!empty($config['safe_space'])) {
            $services['safe_space'] = [
                'name' => __('Espacio Seguro', 'starter-memberships'),
                'description' => __('Acceso a espacio seguro para consumo responsable', 'starter-memberships'),
                'icon' => '🛡️',
                'available' => true
            ];
        }
        
        if (!empty($config['legal_advice'])) {
            $services['legal_advice'] = [
                'name' => __('Asesoría Jurídica', 'starter-memberships'),
                'description' => __('Videollamada con asesor jurídico especializado', 'starter-memberships'),
                'icon' => '⚖️',
                'available' => true
            ];
        }
        
        return $services;
    }
}

// Registrar el handler inmediatamente
// (las clases core se cargan antes que los handlers en benefits/index.php)
starter_benefit_registry()->register(new Starter_Security_Benefits_Handler());
