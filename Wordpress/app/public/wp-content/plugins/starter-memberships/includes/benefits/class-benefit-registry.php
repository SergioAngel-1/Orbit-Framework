<?php
/**
 * Registro central de handlers de beneficios
 * 
 * Gestiona el registro, inicialización y acceso a todos los handlers de beneficios.
 * Implementa el patrón Singleton para acceso global.
 * 
 * @package Starter_Memberships
 * @subpackage Benefits
 * @since 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Starter_Benefit_Registry {
    
    /**
     * Instancia singleton
     * @var Starter_Benefit_Registry|null
     */
    private static $instance = null;
    
    /**
     * Handlers registrados
     * @var array<string, Starter_Benefit_Handler_Interface>
     */
    private $handlers = [];
    
    /**
     * Si los hooks ya fueron registrados
     * @var bool
     */
    private $hooks_registered = false;
    
    /**
     * Constructor privado (Singleton)
     */
    private function __construct() {
        // Registrar hook para limpiar caché cuando cambia la membresía
        add_action('starter_membership_changed', [$this, 'on_membership_changed'], 10, 2);
        add_action('starter_benefits_updated', [$this, 'on_benefits_updated'], 10, 1);
    }
    
    /**
     * Obtener instancia singleton
     * 
     * @return Starter_Benefit_Registry
     */
    public static function get_instance(): Starter_Benefit_Registry {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Registrar un handler de beneficio
     * 
     * @param Starter_Benefit_Handler_Interface $handler
     * @return bool True si se registró correctamente
     */
    public function register(Starter_Benefit_Handler_Interface $handler): bool {
        $key = $handler->get_key();
        
        if (empty($key)) {
            $this->log('Intento de registrar handler sin key');
            return false;
        }
        
        if (isset($this->handlers[$key])) {
            $this->log("Handler ya registrado: {$key}");
            return false;
        }
        
        // Verificar dependencia de WooCommerce
        if ($handler->requires_woocommerce() && !class_exists('WooCommerce')) {
            $this->log("Handler {$key} requiere WooCommerce pero no está activo");
            return false;
        }
        
        $this->handlers[$key] = $handler;
        
        return true;
    }
    
    /**
     * Obtener un handler por su key
     * 
     * @param string $key
     * @return Starter_Benefit_Handler_Interface|null
     */
    public function get(string $key): ?Starter_Benefit_Handler_Interface {
        return $this->handlers[$key] ?? null;
    }
    
    /**
     * Obtener todos los handlers registrados
     * 
     * @return array<string, Starter_Benefit_Handler_Interface>
     */
    public function get_all(): array {
        return $this->handlers;
    }
    
    /**
     * Verificar si un handler está registrado
     * 
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool {
        return isset($this->handlers[$key]);
    }
    
    /**
     * Registrar los hooks de todos los handlers
     * Se debe llamar después de que todos los handlers estén registrados
     */
    public function register_all_hooks(): void {
        if ($this->hooks_registered) {
            return;
        }
        
        foreach ($this->handlers as $key => $handler) {
            try {
                $handler->register_hooks();
            } catch (Exception $e) {
                $this->log("Error registrando hooks para {$key}: " . $e->getMessage());
            }
        }
        
        $this->hooks_registered = true;
    }
    
    /**
     * Obtener todos los beneficios activos para un usuario
     * 
     * @param int $user_id
     * @return array
     */
    public function get_active_benefits_for_user(int $user_id): array {
        $active = [];
        
        foreach ($this->handlers as $key => $handler) {
            if ($handler->is_enabled_for_user($user_id)) {
                $active[$key] = [
                    'key' => $key,
                    'name' => $handler->get_name(),
                    'description' => $handler->get_description(),
                    'display_value' => $handler->get_display_value($user_id),
                    'config' => $handler->get_config_for_user($user_id)
                ];
            }
        }
        
        return $active;
    }
    
    /**
     * Verificar si un usuario tiene un beneficio específico
     * 
     * @param int $user_id
     * @param string $benefit_key
     * @return bool
     */
    public function user_has_benefit(int $user_id, string $benefit_key): bool {
        $handler = $this->get($benefit_key);
        
        if (!$handler) {
            return false;
        }
        
        return $handler->is_enabled_for_user($user_id);
    }
    
    /**
     * Aplicar un beneficio específico
     * 
     * @param string $benefit_key
     * @param int $user_id
     * @param array $context
     * @return mixed
     */
    public function apply_benefit(string $benefit_key, int $user_id, array $context = []) {
        $handler = $this->get($benefit_key);
        
        if (!$handler) {
            return null;
        }
        
        if (!$handler->is_enabled_for_user($user_id)) {
            return null;
        }
        
        return $handler->apply($user_id, $context);
    }
    
    /**
     * Callback cuando cambia la membresía de un usuario
     * 
     * @param int $user_id
     * @param int $new_level
     */
    public function on_membership_changed(int $user_id, int $new_level): void {
        // Limpiar caché del usuario
        Starter_Benefit_Handler_Base::clear_cache($user_id);
        $this->log("Caché limpiado para usuario {$user_id} (nuevo nivel: {$new_level})");
    }
    
    /**
     * Callback cuando se actualizan los beneficios de un nivel
     * 
     * @param int $level
     */
    public function on_benefits_updated(int $level): void {
        // Limpiar toda la caché ya que los beneficios del nivel cambiaron
        Starter_Benefit_Handler_Base::clear_cache();
        $this->log("Caché global limpiado (beneficios nivel {$level} actualizados)");
    }
    
    /**
     * Obtener estadísticas del registro
     * 
     * @return array
     */
    public function get_stats(): array {
        $stats = [
            'total_handlers' => count($this->handlers),
            'hooks_registered' => $this->hooks_registered,
            'handlers' => []
        ];
        
        foreach ($this->handlers as $key => $handler) {
            $stats['handlers'][$key] = [
                'name' => $handler->get_name(),
                'requires_wc' => $handler->requires_woocommerce()
            ];
        }
        
        return $stats;
    }
    
    /**
     * Log interno
     * 
     * @param string $message
     */
    private function log(string $message): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Starter Benefit Registry] ' . $message);
        }
    }
}

/**
 * Función helper para acceder al registro
 * 
 * @return Starter_Benefit_Registry
 */
function starter_benefit_registry(): Starter_Benefit_Registry {
    return Starter_Benefit_Registry::get_instance();
}
