<?php
/**
 * Clase base abstracta para handlers de beneficios
 * 
 * Proporciona implementaciones comunes para evitar duplicación de código.
 * Los handlers específicos deben extender esta clase.
 * 
 * @package Starter_Memberships
 * @subpackage Benefits/Handlers
 * @since 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class Starter_Benefit_Handler_Base implements Starter_Benefit_Handler_Interface {
    
    /**
     * Clave única del beneficio
     * @var string
     */
    protected $key = '';
    
    /**
     * Nombre del beneficio
     * @var string
     */
    protected $name = '';
    
    /**
     * Descripción del beneficio
     * @var string
     */
    protected $description = '';
    
    /**
     * Si requiere WooCommerce
     * @var bool
     */
    protected $requires_wc = false;
    
    /**
     * Caché de configuraciones por usuario
     * @var array
     */
    protected static $config_cache = [];
    
    /**
     * {@inheritdoc}
     */
    public function get_key(): string {
        return $this->key;
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_name(): string {
        return $this->name;
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_description(): string {
        return $this->description;
    }
    
    /**
     * {@inheritdoc}
     */
    public function requires_woocommerce(): bool {
        return $this->requires_wc;
    }
    
    /**
     * {@inheritdoc}
     */
    public function is_enabled_for_user(int $user_id): bool {
        $config = $this->get_config_for_user($user_id);
        return $config !== null && !empty($config['enabled']);
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_config_for_user(int $user_id): ?array {
        $cache_key = $this->key . '_' . $user_id;
        
        // Verificar caché
        if (isset(self::$config_cache[$cache_key])) {
            return self::$config_cache[$cache_key];
        }
        
        // Obtener nivel del usuario
        if (!function_exists('starter_get_user_membership_level')) {
            return null;
        }
        
        $level = starter_get_user_membership_level($user_id);
        
        // Obtener beneficios del nivel
        if (!function_exists('starter_get_level_benefits')) {
            return null;
        }
        
        $benefits = starter_get_level_benefits($level);
        
        if (!isset($benefits[$this->key])) {
            self::$config_cache[$cache_key] = null;
            return null;
        }
        
        $config = $benefits[$this->key];
        
        // Verificar si está habilitado
        if (empty($config['enabled'])) {
            self::$config_cache[$cache_key] = null;
            return null;
        }
        
        self::$config_cache[$cache_key] = $config;
        return $config;
    }
    
    /**
     * Limpiar caché de un usuario específico o de todos
     * 
     * @param int|null $user_id ID del usuario o null para limpiar todo
     */
    public static function clear_cache(?int $user_id = null): void {
        if ($user_id === null) {
            self::$config_cache = [];
        } else {
            foreach (self::$config_cache as $key => $value) {
                if (strpos($key, '_' . $user_id) !== false) {
                    unset(self::$config_cache[$key]);
                }
            }
        }
    }
    
    /**
     * Obtener el usuario actual si no se especifica
     * 
     * @param int|null $user_id
     * @return int
     */
    protected function get_user_id(?int $user_id = null): int {
        if ($user_id !== null) {
            return $user_id;
        }
        return get_current_user_id();
    }
    
    /**
     * Log de debug para el beneficio
     * 
     * @param string $message
     * @param array $context
     */
    protected function log(string $message, array $context = []): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[Starter Benefit: %s] %s | Context: %s',
                $this->key,
                $message,
                wp_json_encode($context)
            ));
        }
    }
    
    /**
     * Implementación por defecto de register_hooks
     * Los handlers específicos deben sobrescribir si necesitan hooks
     */
    public function register_hooks(): void {
        // Implementación vacía por defecto
        // Los handlers específicos sobrescriben este método
    }
    
    /**
     * Implementación por defecto de apply
     * Los handlers específicos deben sobrescribir
     */
    public function apply(int $user_id, array $context = []) {
        // Implementación por defecto retorna null
        // Los handlers específicos sobrescriben este método
        return null;
    }
    
    /**
     * Implementación por defecto de get_display_value
     * Los handlers específicos deben sobrescribir
     */
    public function get_display_value(int $user_id): string {
        $config = $this->get_config_for_user($user_id);
        
        if (!$config) {
            return '';
        }
        
        return __('Activo', 'starter-memberships');
    }
}
