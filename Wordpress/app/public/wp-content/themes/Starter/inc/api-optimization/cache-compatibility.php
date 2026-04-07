<?php
/**
 * API Optimization - Compatibilidad con sistema de caché
 * 
 * Este archivo mantiene la compatibilidad con el código existente que utilizaba
 * el sistema de caché personalizado, ahora reemplazado por W3 Total Cache.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gestor de caché basado en transients de WordPress
 * 
 * Proporciona caché real para datos de plugins internos (referidos, puntos, etc.)
 * que antes era un stub NO-OP. Usa WordPress Transients API como backend,
 * compatible con object cache (Memcached/Redis) cuando está disponible.
 * 
 * Para productos/categorías/páginas, W3 Total Cache sigue siendo el responsable.
 * Esta clase maneja caché de datos personalizados por usuario/tipo.
 */
class API_Cache_Manager {
    private static $instance = null;
    
    const CACHE_PREFIX = 'fi_api_';
    const TRACKED_KEYS_OPTION = 'fi_api_cache_tracked_keys'; // Legacy, kept for cleanup
    
    /**
     * Flag to track if legacy wp_options keys have been cleaned up
     * @var bool
     */
    private static $legacy_cleaned = false;
    
    /**
     * TTL por tipo de contenido (en segundos)
     */
    private $ttl = [
        'points'          => 300,   // 5 minutos - balance de puntos del usuario
        'transactions'    => 300,   // 5 minutos - historial de transacciones
        'referrals'       => 600,   // 10 minutos - datos de referidos
        'referral'        => 600,   // 10 minutos - dato individual de referido
        'referral_signup' => 600,   // 10 minutos - datos de signup por referido
        'earned'          => 300,   // 5 minutos - puntos ganados
        'used'            => 300,   // 5 minutos - puntos usados
        'expired'         => 600,   // 10 minutos - puntos expirados
        'admin_add'       => 300,   // 5 minutos - adiciones por admin
        'admin_deduct'    => 300,   // 5 minutos - deducciones por admin
    ];
    
    private function __construct() {}
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Construye una clave de caché única
     * 
     * @param string $content_type Tipo de contenido
     * @param int|string|null $id ID del recurso (ej: user_id)
     * @param array $params Parámetros adicionales
     * @return string Clave de caché
     */
    public function build_cache_key($content_type, $id = null, $params = []) {
        // Encode content_type and id into the key prefix for efficient prefix-based invalidation
        // Keys look like: fi_api_<type>_u<id>_<md5> or fi_api_<type>__<md5> (no user)
        $user_part = $id !== null ? 'u' . $id : '';
        return self::CACHE_PREFIX . $content_type . '_' . $user_part . '_' . md5($content_type . '_' . ($id ?? '') . '_' . json_encode($params));
    }
    
    /**
     * Obtiene un valor del caché
     * 
     * @param string $content_type Tipo de contenido
     * @param int|string|null $id ID del recurso
     * @param array $params Parámetros adicionales
     * @return mixed|null Datos cacheados o null si no existe/expiró
     */
    public function get($content_type, $id = null, $params = []) {
        $key = $this->build_cache_key($content_type, $id, $params);
        $cached = get_transient($key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        return null;
    }
    
    /**
     * Guarda un valor en el caché
     * 
     * @param string $content_type Tipo de contenido
     * @param mixed $data Datos a cachear
     * @param int|string|null $id ID del recurso
     * @param array $params Parámetros adicionales
     * @param int|null $custom_ttl TTL personalizado en segundos (null = usar default del tipo)
     * @return bool True si se guardó correctamente
     */
    public function set($content_type, $data, $id = null, $params = [], $custom_ttl = null) {
        $key = $this->build_cache_key($content_type, $id, $params);
        $ttl = $custom_ttl !== null ? (int) $custom_ttl : $this->get_ttl_for_type($content_type);
        
        // No tracking needed: content_type and user_id are encoded in the key prefix
        return set_transient($key, $data, $ttl);
    }
    
    /**
     * Invalida un elemento específico del caché
     * 
     * @param string $content_type Tipo de contenido
     * @param int|string|null $id ID del recurso
     * @param array $params Parámetros adicionales
     * @return bool True si se invalidó
     */
    public function invalidate($content_type, $id = null, $params = []) {
        $key = $this->build_cache_key($content_type, $id, $params);
        return delete_transient($key);
    }
    
    /**
     * Invalida todos los elementos de un tipo específico
     * 
     * @param string $content_type Tipo de contenido
     * @return int Número de elementos invalidados
     */
    public function invalidate_by_type($content_type) {
        global $wpdb;
        
        // Clean up legacy tracked keys on first call
        self::cleanup_legacy_tracked_keys();
        
        // Delete transients by prefix: fi_api_<type>_
        $type_prefix = self::CACHE_PREFIX . $content_type . '_';
        $transient_prefix = '_transient_' . $type_prefix;
        $timeout_prefix = '_transient_timeout_' . $type_prefix;
        
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $transient_prefix . '%',
                $timeout_prefix . '%'
            )
        );
        
        if ($deleted > 0) {
            wp_cache_delete('alloptions', 'options');
        }
        
        return $deleted !== false ? $deleted : 0;
    }
    
    /**
     * Invalida todas las entradas de caché de un usuario específico
     * 
     * @param int $user_id ID del usuario
     * @return int Número de elementos invalidados
     */
    public function invalidate_by_user($user_id) {
        global $wpdb;
        
        // Clean up legacy tracked keys on first call
        self::cleanup_legacy_tracked_keys();
        
        $user_id = (int) $user_id;
        
        // Delete transients containing user ID segment: fi_api_<type>_u<id>_
        // Match pattern: _transient_fi_api_%_u<id>_%
        $user_pattern = self::CACHE_PREFIX . '%' . '_u' . $user_id . '_%';
        $transient_prefix = '_transient_' . $user_pattern;
        $timeout_prefix = '_transient_timeout_' . $user_pattern;
        
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $transient_prefix,
                $timeout_prefix
            )
        );
        
        if ($deleted > 0) {
            wp_cache_delete('alloptions', 'options');
        }
        
        return $deleted !== false ? $deleted : 0;
    }
    
    /**
     * Determina si una ruta REST debe excluirse del caché
     * 
     * @param string $route Ruta REST
     * @return bool True si debe excluirse
     */
    public function should_exclude_route($route) {
        return false;
    }
    
    /**
     * Obtiene el TTL configurado para un tipo de contenido
     * 
     * @param string $content_type Tipo de contenido
     * @return int TTL en segundos
     */
    public function get_ttl_for_type($content_type) {
        if (isset($this->ttl[$content_type])) {
            return $this->ttl[$content_type];
        }
        
        // Si es un tipo singular que no existe pero su plural sí
        $plural = $content_type . 's';
        if (isset($this->ttl[$plural])) {
            return $this->ttl[$plural];
        }
        
        // Si es un tipo plural que no existe pero su singular sí
        if (substr($content_type, -1) === 's') {
            $singular = substr($content_type, 0, -1);
            if (isset($this->ttl[$singular])) {
                return $this->ttl[$singular];
            }
        }
        
        return 300; // 5 minutos por defecto
    }
    
    /**
     * Cleanup legacy tracked keys from wp_options (one-time migration)
     * 
     * Previous versions tracked cache keys in wp_options which caused O(n) overhead.
     * Now content_type and user_id are encoded in the key prefix, making tracking unnecessary.
     */
    private static function cleanup_legacy_tracked_keys() {
        if (self::$legacy_cleaned) {
            return;
        }
        self::$legacy_cleaned = true;
        
        $tracked = get_option(self::TRACKED_KEYS_OPTION, []);
        if (!empty($tracked) && is_array($tracked)) {
            foreach ($tracked as $entry) {
                if (isset($entry['key'])) {
                    delete_transient($entry['key']);
                }
            }
            delete_option(self::TRACKED_KEYS_OPTION);
        }
    }
}

/**
 * Función para mantener compatibilidad con código existente
 * 
 * @return API_Cache_Manager Instancia del gestor de caché
 */
function starter_api_cache() {
    return API_Cache_Manager::instance();
}

/**
 * Funciones vacías para mantener compatibilidad con código existente
 */

// Función vacía para mantener compatibilidad
function invalidate_product_cache($post_id) {
    // No hace nada - W3 Total Cache se encargará de esto
}
add_action('save_post_product', 'invalidate_product_cache');
add_action('woocommerce_update_product', 'invalidate_product_cache');
add_action('woocommerce_delete_product', 'invalidate_product_cache');

// Función vacía para mantener compatibilidad
function invalidate_category_cache($term_id, $tt_id, $taxonomy) {
    // No hace nada - W3 Total Cache se encargará de esto
}
add_action('create_term', 'invalidate_category_cache', 10, 3);
add_action('edit_term', 'invalidate_category_cache', 10, 3);
add_action('delete_term', 'invalidate_category_cache', 10, 3);

// Función vacía para mantener compatibilidad
function invalidate_user_cache($user_id) {
    // No hace nada - W3 Total Cache se encargará de esto
}
add_action('profile_update', 'invalidate_user_cache');
add_action('user_register', 'invalidate_user_cache');
add_action('deleted_user', 'invalidate_user_cache');

// Función vacía para mantener compatibilidad
function invalidate_home_sections_cache() {
    // No hace nada - W3 Total Cache se encargará de esto
}

// Mantener el hook para compatibilidad
add_action('update_option_starter_home_sections_options', 'invalidate_home_sections_cache');
