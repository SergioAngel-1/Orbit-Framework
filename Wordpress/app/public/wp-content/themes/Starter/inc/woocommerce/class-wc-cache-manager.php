<?php
/**
 * WooCommerce Proxy Cache Manager
 * 
 * Handles caching of WooCommerce API responses with intelligent invalidation
 * 
 * @package Starter
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Starter_WC_Cache_Manager {
    
    const CACHE_PREFIX = 'fi_wc_proxy_';
    const CACHE_KEYS_OPTION = 'fi_wc_proxy_cache_keys'; // Legacy, kept for cleanup
    const DEFAULT_TTL = 900; // 15 minutes
    const SEARCH_TTL = 300;  // 5 minutes
    
    /**
     * Last route used for key generation (used for tracking metadata)
     * @var string
     */
    private static $last_route = '';
    
    /**
     * Flag to track if legacy wp_options keys have been cleaned up
     * @var bool
     */
    private static $legacy_cleaned = false;
    
    /**
     * Generate cache key for a request
     * 
     * @param string $route API route
     * @param array $params Query parameters
     * @return string
     */
    public static function generate_key($route, $params = []) {
        self::$last_route = $route;
        
        // Encode route type into key prefix for efficient prefix-based invalidation
        // Keys look like: fi_wc_proxy_products_<md5>, fi_wc_proxy_categories_<md5>, etc.
        $route_type = self::get_route_type($route);
        return self::CACHE_PREFIX . $route_type . '_' . md5($route . '|' . http_build_query($params));
    }
    
    /**
     * Get cached response
     * 
     * @param string $cache_key Cache key
     * @return array|false Cached data or false if not found
     */
    public static function get($cache_key) {
        $cached = get_transient($cache_key);
        
        if ($cached !== false && is_array($cached) && isset($cached['data'], $cached['status'])) {
            $status = intval($cached['status']);
            if ($status >= 500) {
                delete_transient($cache_key);
                return false;
            }
            return $cached;
        }
        
        return false;
    }
    
    /**
     * Store response in cache
     * 
     * @param string $cache_key Cache key
     * @param mixed $data Response data
     * @param int $status HTTP status code
     * @param array $headers Response headers to cache
     * @param array $params Request params (to determine TTL)
     * @return bool
     */
    public static function set($cache_key, $data, $status, $headers = [], $params = []) {
        $payload = [
            'data'    => $data,
            'status'  => $status,
            'headers' => $headers,
        ];
        
        // Determine TTL based on request type
        $ttl = (isset($params['search']) && $params['search'] !== '') 
            ? self::SEARCH_TTL 
            : self::DEFAULT_TTL;
        
        // Store in cache
        // No tracking needed: route type is encoded in the key prefix for direct invalidation
        return set_transient($cache_key, $payload, $ttl);
    }
    
    /**
     * Cleanup legacy tracked keys from wp_options (one-time migration)
     * 
     * Previous versions tracked cache keys in wp_options which caused O(n) overhead.
     * Now route type is encoded in the key prefix, making tracking unnecessary.
     * This method cleans up the legacy option on first invalidation call.
     */
    private static function cleanup_legacy_tracked_keys() {
        if (self::$legacy_cleaned) {
            return;
        }
        self::$legacy_cleaned = true;
        
        // Delete any remaining legacy-tracked transients
        $keys = get_option(self::CACHE_KEYS_OPTION, []);
        if (!empty($keys) && is_array($keys)) {
            foreach ($keys as $entry) {
                $key = is_string($entry) ? $entry : (isset($entry['key']) ? $entry['key'] : null);
                if ($key) {
                    delete_transient($key);
                }
            }
            delete_option(self::CACHE_KEYS_OPTION);
        }
    }
    
    /**
     * Classify a WC API route into a type for granular invalidation
     * 
     * @param string $route e.g. 'products', 'products/categories', 'products/123', 'orders'
     * @return string Route type: 'products', 'categories', 'orders', 'search', 'unknown'
     */
    private static function get_route_type($route) {
        if (empty($route)) {
            return 'unknown';
        }
        
        if (strpos($route, 'products/categories') !== false) {
            return 'categories';
        }
        
        if (strpos($route, 'products/reviews') !== false) {
            return 'reviews';
        }
        
        if (strpos($route, 'products') !== false) {
            return 'products';
        }
        
        if (strpos($route, 'orders') !== false) {
            return 'orders';
        }
        
        return 'unknown';
    }
    
    /**
     * Invalidate all cached responses (safety valve)
     */
    public static function invalidate_all() {
        global $wpdb;
        
        // Clean up legacy tracked keys if any remain
        self::cleanup_legacy_tracked_keys();
        
        // Delete all transients with our prefix using a single DB query
        // Transients in wp_options use key: _transient_<name> and _transient_timeout_<name>
        $prefix = '_transient_' . self::CACHE_PREFIX;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $prefix . '%',
                '_transient_timeout_' . self::CACHE_PREFIX . '%'
            )
        );
        
        // Clear the alloptions cache so WordPress doesn't serve stale data
        wp_cache_delete('alloptions', 'options');
    }
    
    /**
     * Invalidate cached responses by route type
     * 
     * @param string|array $route_types One or more route types: 'products', 'categories', 'orders'
     * @return int Number of keys invalidated
     */
    public static function invalidate_by_route_type($route_types) {
        global $wpdb;
        
        if (!is_array($route_types)) {
            $route_types = [$route_types];
        }
        
        // Clean up legacy tracked keys on first call
        self::cleanup_legacy_tracked_keys();
        
        // Delete transients by prefix for each route type using direct DB queries
        // This is O(1) per route type instead of O(n) over all tracked keys
        $total_deleted = 0;
        
        foreach ($route_types as $route_type) {
            $type_prefix = self::CACHE_PREFIX . $route_type . '_';
            $transient_prefix = '_transient_' . $type_prefix;
            $timeout_prefix = '_transient_timeout_' . $type_prefix;
            
            $deleted = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                    $transient_prefix . '%',
                    $timeout_prefix . '%'
                )
            );
            
            if ($deleted !== false) {
                $total_deleted += $deleted;
            }
        }
        
        if ($total_deleted > 0) {
            // Clear the alloptions cache so WordPress doesn't serve stale data
            wp_cache_delete('alloptions', 'options');
        }
        
        return $total_deleted;
    }
    
    /**
     * Create WP_REST_Response from cached data
     * 
     * @param array $cached Cached data
     * @param string $route Optional route for post-cache filtering
     * @return WP_REST_Response
     */
    public static function create_response_from_cache($cached, $route = '') {
        $data = $cached['data'];
        
        // Apply post-cache filters if route is provided
        if (!empty($route) && is_array($data)) {
            // DEFENSIVO: Re-aplicar filtros de membresía al servir datos cacheados.
            // Aunque la cache key incluye el nivel de membresía, si la resolución JWT
            // fue intermitentemente incorrecta al crear la cache entry, los datos
            // podrían corresponder a un nivel equivocado.
            if (strpos($route, 'products') !== false && strpos($route, 'products/categories') === false) {
                $data = self::refilter_products_from_cache($data);
            }
            
            // Filter categories to exclude internal ones and re-apply membership filter
            if (strpos($route, 'products/categories') !== false) {
                $data = self::refilter_categories_from_cache($data);
            }
        }
        
        // SEGURIDAD: Si el re-filtro de membresía marcó acceso denegado,
        // retornar 404 en vez de enviar datos sensibles del producto
        if (is_array($data) && isset($data['__access_denied']) && $data['__access_denied'] === true) {
            $data = [
                'code' => 'product_not_found',
                'message' => 'Producto no encontrado.',
                'data' => ['status' => 404]
            ];
            $response = new WP_REST_Response($data, 404);
            $response->header('X-Cache', 'HIT');
            return $response;
        }
        
        $response = new WP_REST_Response($data, intval($cached['status']));
        
        if (!empty($cached['headers']) && is_array($cached['headers'])) {
            foreach ($cached['headers'] as $key => $value) {
                $response->header($key, $value);
            }
        }
        
        $response->header('X-Cache', 'HIT');
        
        return $response;
    }
    
    /**
     * Re-filter cached products by current user's membership level
     * 
     * DEFENSIVO: Verifica que los productos cacheados siguen siendo accesibles
     * para el usuario actual. No filtra en modo slug/include (annotate-only).
     * 
     * @param mixed $data Cached product data
     * @return mixed Filtered data
     */
    private static function refilter_products_from_cache($data) {
        if (!is_array($data)) {
            return $data;
        }
        
        // Detectar si es lista de productos
        $is_product_list = isset($data[0]) && is_array($data[0]) && isset($data[0]['id']);
        
        $user_level = function_exists('starter_get_jwt_user_membership_level') 
            ? starter_get_jwt_user_membership_level() 
            : 0;
        
        if (!$is_product_list) {
            // Producto individual: verificar acceso
            if (isset($data['id']) && isset($data['membership_required'])) {
                $has_access = $user_level >= intval($data['membership_required']);
                
                if (!$has_access) {
                    // SEGURIDAD: No enviar datos del producto al frontend
                    return [
                        '__access_denied' => true,
                        'membership_required' => intval($data['membership_required']),
                    ];
                }
                
                $data['user_has_access'] = true;
                
                // Enriquecer categorías con min_membership_level si no lo tienen
                $data = self::enrich_product_categories($data);
            }
            return $data;
        }
        
        // Lista de productos: filtrar los que no tienen acceso
        // SEGURIDAD: No enviar datos de productos restringidos al frontend
        $filtered = [];
        foreach ($data as $product) {
            if (isset($product['membership_required'])) {
                $has_access = $user_level >= intval($product['membership_required']);
                
                if (!$has_access) {
                    continue;
                }
                
                $product['user_has_access'] = true;
            }
            // Enriquecer categorías con min_membership_level si no lo tienen
            $product = self::enrich_product_categories($product);
            $filtered[] = $product;
        }
        
        return $filtered;
    }
    
    /**
     * Enriquecer las categorías de un producto con min_membership_level
     * Útil para datos cacheados que no tienen este campo
     * 
     * @param array $product Datos del producto
     * @return array Producto con categorías enriquecidas
     */
    private static function enrich_product_categories($product) {
        if (!isset($product['categories']) || !is_array($product['categories'])) {
            return $product;
        }
        
        // Verificar si ya están enriquecidas (primera categoría tiene min_membership_level)
        if (isset($product['categories'][0]['min_membership_level'])) {
            return $product;
        }
        
        // Enriquecer cada categoría
        foreach ($product['categories'] as &$category) {
            $category_id = isset($category['id']) ? intval($category['id']) : 0;
            if ($category_id > 0 && function_exists('starter_get_category_min_membership')) {
                $category['min_membership_level'] = starter_get_category_min_membership($category_id);
            } else {
                $category['min_membership_level'] = 0;
            }
        }
        unset($category);
        
        return $product;
    }
    
    /**
     * Re-filter cached categories by current user's membership level
     * Also excludes internal categories (membresias/memberships)
     * 
     * @param mixed $data Cached category data
     * @return mixed Filtered data
     */
    private static function refilter_categories_from_cache($data) {
        if (!is_array($data)) {
            return $data;
        }
        
        $excluded_slugs = ['membresias', 'memberships'];
        $is_category_list = isset($data[0]) && is_array($data[0]) && isset($data[0]['id']);
        
        if (!$is_category_list) {
            // Single category: check membership access
            if (isset($data['id']) && function_exists('starter_get_category_min_membership')) {
                $user_level = function_exists('starter_get_jwt_user_membership_level') 
                    ? starter_get_jwt_user_membership_level() 
                    : 0;
                $cat_id = intval($data['id']);
                $min_level = starter_get_category_min_membership($cat_id);
                if ($min_level > 0 && $user_level < $min_level) {
                    // Use __access_denied pattern consistent with product filtering
                    // create_response_from_cache() handles this and returns a proper 404
                    return [
                        '__access_denied' => true,
                        'category_id' => $cat_id,
                        'required_level' => $min_level,
                    ];
                }
            }
            return $data;
        }
        
        // Category list: filter by slug exclusions + membership level
        $user_level = function_exists('starter_get_jwt_user_membership_level') 
            ? starter_get_jwt_user_membership_level() 
            : 0;
        
        $data = array_values(array_filter($data, function($category) use ($excluded_slugs, $user_level) {
            $slug = isset($category['slug']) ? strtolower($category['slug']) : '';
            if (in_array($slug, $excluded_slugs)) {
                return false;
            }
            
            // Re-check membership level if min_membership_level is annotated
            if (isset($category['min_membership_level'])) {
                $min_level = intval($category['min_membership_level']);
                if ($min_level > 0 && $user_level < $min_level) {
                    return false;
                }
            }
            
            return true;
        }));
        
        return $data;
    }
    
    /**
     * Register cache invalidation hooks
     * 
     * Usa invalidación granular por tipo de ruta en lugar de invalidar todo el caché.
     * Cada cambio solo invalida las entradas de caché del tipo afectado.
     */
    public static function register_invalidation_hooks() {
        // Cambios en productos → solo invalidan caché de productos
        add_action('save_post_product', [__CLASS__, 'on_product_change']);
        add_action('woocommerce_update_product_variation', [__CLASS__, 'on_product_change']);
        add_action('woocommerce_new_product_variation', [__CLASS__, 'on_product_change']);
        add_action('woocommerce_delete_product_variation', [__CLASS__, 'on_product_change']);
        
        // Eliminación de posts → invalidar productos (deleted_post aplica a products)
        add_action('deleted_post', [__CLASS__, 'on_post_deleted']);
        
        // Cambios en términos/categorías → invalidan caché de categorías + productos
        add_action('edited_terms', [__CLASS__, 'on_category_change']);
        add_action('created_term', [__CLASS__, 'on_category_change']);
        
        // Cambios en órdenes → solo invalidan caché de órdenes
        add_action('woocommerce_order_status_changed', [__CLASS__, 'on_order_change'], 10, 4);
        
        // Cambios en meta de traducciones → solo invalidan productos
        add_action('updated_postmeta', [__CLASS__, 'on_translation_meta_change'], 10, 4);
        add_action('added_post_meta', [__CLASS__, 'on_translation_meta_change'], 10, 4);
        
        // Cambios en reseñas/comentarios de productos → invalidan caché de reviews
        add_action('comment_post', [__CLASS__, 'on_review_change'], 10, 3);
        add_action('edit_comment', [__CLASS__, 'on_review_change'], 10, 2);
        add_action('wp_set_comment_status', [__CLASS__, 'on_review_status_change'], 10, 2);
    }
    
    /**
     * Invalidate product cache on product changes
     */
    public static function on_product_change() {
        self::invalidate_by_route_type('products');
    }
    
    /**
     * Invalidate on post deletion - only if it's a product
     * 
     * @param int $post_id
     */
    public static function on_post_deleted($post_id) {
        if (get_post_type($post_id) === 'product' || get_post_type($post_id) === 'product_variation') {
            self::invalidate_by_route_type('products');
        }
    }
    
    /**
     * Invalidate category + product cache on term changes
     * (products may embed category data, so both need invalidation)
     */
    public static function on_category_change() {
        self::invalidate_by_route_type(['categories', 'products']);
    }
    
    /**
     * Invalidate order cache on order status changes
     */
    public static function on_order_change() {
        self::invalidate_by_route_type('orders');
    }
    
    /**
     * Invalidate reviews cache when a product review is created or edited
     * 
     * @param int $comment_id
     */
    public static function on_review_change($comment_id) {
        $comment = get_comment($comment_id);
        if (!$comment) {
            return;
        }
        // Solo invalidar si es una reseña de producto
        if ($comment->comment_type === 'review' || get_post_type($comment->comment_post_ID) === 'product') {
            self::invalidate_by_route_type('reviews');
        }
    }
    
    /**
     * Invalidate reviews cache when a review status changes (approve/unapprove)
     * 
     * @param int $comment_id
     * @param string $status
     */
    public static function on_review_status_change($comment_id, $status) {
        self::on_review_change($comment_id);
    }
    
    /**
     * Conditionally invalidate product cache when translation meta changes
     * Only triggers for product posts and translation-related meta keys
     */
    public static function on_translation_meta_change($meta_id, $object_id, $meta_key, $meta_value = '') {
        $translation_prefixes = ['_name_', '_short_description_', '_description_'];
        $is_translation_meta = false;
        foreach ($translation_prefixes as $prefix) {
            if (strpos($meta_key, $prefix) === 0) {
                $is_translation_meta = true;
                break;
            }
        }
        
        if ($is_translation_meta && get_post_type($object_id) === 'product') {
            self::invalidate_by_route_type('products');
        }
    }
}
