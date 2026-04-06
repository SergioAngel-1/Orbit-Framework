<?php
/**
 * Gestión de caché para Home Sections
 */

if (!defined('ABSPATH')) {
    exit;
}

class FIHS_Cache_Manager {
    
    private static $instance = null;
    private const CACHE_VERSION = 'v8';
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Hooks para invalidar caché automáticamente
        add_action('save_post_product', array($this, 'invalidate'));
        add_action('deleted_post', array($this, 'invalidate'));
        add_action('edited_terms', array($this, 'invalidate'));
        add_action('created_term', array($this, 'invalidate'));
        add_action('updated_postmeta', array($this, 'maybe_invalidate_on_product_meta'), 10, 4);
        add_action('starter_home_sections_changed', array($this, 'invalidate'));
    }
    
    /**
     * Solo invalidar caché cuando cambia meta de un producto
     * 
     * updated_postmeta se dispara para CUALQUIER post type. Sin este filtro,
     * editar una página, menú o cualquier CPT invalidaba el caché de home sections.
     * 
     * @param int $meta_id
     * @param int $object_id
     * @param string $meta_key
     * @param mixed $meta_value
     */
    public function maybe_invalidate_on_product_meta($meta_id, $object_id, $meta_key, $meta_value) {
        // Solo invalidar si es un producto
        if (get_post_type($object_id) !== 'product') {
            return;
        }
        
        // Solo invalidar para meta keys relevantes para home sections
        // (precio, stock, visibilidad, traducciones, imagen)
        $relevant_prefixes = [
            '_price', '_regular_price', '_sale_price',
            '_stock', '_stock_status', '_manage_stock',
            '_visibility', '_featured',
            '_thumbnail_id',
            '_name_', '_short_description_', '_description_',
        ];
        
        foreach ($relevant_prefixes as $prefix) {
            if (strpos($meta_key, $prefix) === 0 || $meta_key === $prefix) {
                $this->invalidate();
                return;
            }
        }
    }
    
    /**
     * Obtener valor del caché
     */
    public function get($key) {
        return get_transient($key);
    }
    
    /**
     * Guardar valor en caché
     */
    public function set($key, $value, $ttl = 600) {
        set_transient($key, $value, $ttl);
        $this->track_key($key);
    }
    
    /**
     * Registrar key para poder invalidar después
     */
    private function track_key($key) {
        $keys = get_option('fihs_cache_keys', array());
        if (!is_array($keys)) {
            $keys = array();
        }
        if (!in_array($key, $keys, true)) {
            $keys[] = $key;
            update_option('fihs_cache_keys', $keys, false);
        }
    }
    
    /**
     * Invalidar todo el caché
     */
    public function invalidate(...$args) {
        $keys = get_option('fihs_cache_keys', array());
        if (is_array($keys)) {
            foreach ($keys as $k) {
                delete_transient($k);
            }
        }
        update_option('fihs_cache_keys', array(), false);
    }
    
    /**
     * Generar key para secciones por nivel
     */
    public function sections_key($level, $lang = 'es') {
        return 'fihs_home_sections_' . self::CACHE_VERSION . '_level_' . $level . '_' . $lang;
    }
    
    /**
     * Generar key para secciones con productos embebidos por nivel
     */
    public function sections_with_products_key($level, $lang = 'es') {
        return 'fihs_home_full_' . self::CACHE_VERSION . '_level_' . $level . '_' . $lang;
    }
    
    /**
     * Generar key para productos de sección
     */
    public function products_key($section_id, $limit, $random, $level, $lang = 'es') {
        return 'fihs_section_products_v6_' . md5($section_id . '|' . $limit . '|' . ($random ? '1' : '0') . '|' . $level . '|' . $lang);
    }
}
