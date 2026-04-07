<?php
/**
 * WooCommerce Proxy Orchestrator
 * 
 * Main orchestrator that coordinates all proxy components
 * 
 * @package Starter
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Starter_WC_Proxy_Orchestrator {
    
    /**
     * Handle proxy request
     * 
     * @param string $method HTTP method
     * @param string $route API route
     * @param WP_REST_Request $request Original request
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_request($method, $route, WP_REST_Request $request) {
        $method = strtoupper($method);
        
        // Get request params and remove route param
        $params = $request->get_params();
        unset($params['route']);
        
        // Extract lang from request params (injected by frontend interceptor)
        $lang = isset($params['lang']) ? sanitize_text_field($params['lang']) : null;
        
        // Check cache for GET requests
        // Skip cache for orders routes (user-specific sensitive data that changes frequently)
        $is_orders_route = strpos($route, 'orders') !== false;
        if ($method === 'GET' && !$is_orders_route) {
            $cache_params = $params;
            // Normalize lang in cache key to avoid duplicates (missing lang = 'es')
            if (empty($cache_params['lang'])) {
                $cache_params['lang'] = 'es';
            }
            if (strpos($route, 'products') !== false) {
                // CRÍTICO: Usar verificación JWT, no cookies de sesión
                $user_level = function_exists('starter_get_jwt_user_membership_level') 
                    ? starter_get_jwt_user_membership_level() 
                    : 0;
                $cache_params['_membership_level'] = $user_level;
            }
            $cache_response = self::handle_cache_get($route, $cache_params, $route);
            if ($cache_response) {
                return $cache_response;
            }
        }
        
        // Handle idempotency for POST /orders
        if (Starter_WC_Idempotency::is_idempotent_route($method, $route)) {
            $idemp_response = self::handle_idempotency($request, $route);
            if ($idemp_response) {
                return $idemp_response;
            }
        }
        
        // Execute HTTP request to WooCommerce
        $http_response = Starter_WC_HTTP_Client::request($method, $route, $request, $params);
        
        if (is_wp_error($http_response)) {
            return $http_response;
        }
        
        // Parse response
        $status = $http_response['status'];
        $raw_body = $http_response['body'];
        $headers = $http_response['headers'];
        $request_id = isset($http_response['request_id']) ? (string) $http_response['request_id'] : '';
        
        $data = json_decode($raw_body, true);
        if ($data === null && !empty($raw_body)) {
            $data = $raw_body;
        }
        
        // Filter products by membership level if this is a products request
        if ($method === 'GET' && $status >= 200 && $status < 300) {
            $data = self::filter_products_by_membership($route, $data);
            $data = self::filter_categories($route, $data);
            $data = self::apply_translations($route, $data, $lang);
        }
        
        // SEGURIDAD: Si filter_products_by_membership marcó acceso denegado,
        // retornar 404 en vez de filtrar datos sensibles del producto
        if (is_array($data) && isset($data['__access_denied']) && $data['__access_denied'] === true) {
            $status = 404;
            $data = [
                'code' => 'product_not_found',
                'message' => 'Producto no encontrado.',
                'data' => ['status' => 404]
            ];
        }
        
        // Create REST response
        $rest_response = new WP_REST_Response($data, $status);

        if ($request_id !== '') {
            $rest_response->header('X-FI-Proxy-Request-Id', $request_id);
        }
        
        // Propagate relevant headers
        $propagate_headers = Starter_WC_HTTP_Client::extract_propagate_headers($headers);
        foreach ($propagate_headers as $key => $value) {
            $rest_response->header($key, $value);
        }

        if ($status >= 500) {
            $snippet = is_string($raw_body) ? trim(substr($raw_body, 0, 300)) : '';
            error_log(sprintf(
                '[Starter WC Proxy] Upstream %d in %s %s (id=%s). Params=%s. Body=%s',
                intval($status),
                strtoupper($method),
                (string) $route,
                $request_id !== '' ? $request_id : 'n/a',
                wp_json_encode($params),
                $snippet
            ));

            $retry_after = '';
            if (isset($propagate_headers['retry-after'])) {
                $retry_after = (string) $propagate_headers['retry-after'];
            }

            $payload = [
                'code' => 'wc_upstream_unavailable',
                'message' => 'Servicio temporalmente no disponible. Por favor, intenta de nuevo más tarde.',
                'upstream_status' => intval($status),
                'route' => (string) $route,
                'request_id' => $request_id,
            ];

            if ($retry_after !== '') {
                $payload['retry_after'] = $retry_after;
            }

            $rest_response->set_data($payload);
            return $rest_response;
        }
        
        // Store in cache for GET requests (skip orders - user-specific sensitive data)
        // Include user membership level in cache key for products routes
        if ($method === 'GET' && $status >= 200 && $status < 300 && !$is_orders_route) {
            $cache_params = $params;
            // Normalize lang in cache key to match the GET path normalization
            if (empty($cache_params['lang'])) {
                $cache_params['lang'] = 'es';
            }
            if (strpos($route, 'products') !== false) {
                // CRÍTICO: Usar verificación JWT, no cookies de sesión
                $user_level = function_exists('starter_get_jwt_user_membership_level') 
                    ? starter_get_jwt_user_membership_level() 
                    : 0;
                $cache_params['_membership_level'] = $user_level;
            }
            self::handle_cache_set($route, $cache_params, $data, $status, $propagate_headers);
        }
        
        // Handle idempotency storage for successful POST /orders
        if (Starter_WC_Idempotency::is_idempotent_route($method, $route)) {
            self::handle_idempotency_storage($request, $data, $status, $propagate_headers);
        }
        
        return $rest_response;
    }
    
    /**
     * Handle cache retrieval for GET requests
     * 
     * @param string $route
     * @param array $params
     * @param string $filter_route Route to use for post-cache filtering
     * @return WP_REST_Response|null
     */
    private static function handle_cache_get($route, $params, $filter_route = '') {
        $cache_key = Starter_WC_Cache_Manager::generate_key($route, $params);
        $cached = Starter_WC_Cache_Manager::get($cache_key);
        
        if ($cached) {
            return Starter_WC_Cache_Manager::create_response_from_cache($cached, $filter_route);
        }
        
        return null;
    }
    
    /**
     * Handle cache storage for GET requests
     * 
     * @param string $route
     * @param array $params
     * @param mixed $data
     * @param int $status
     * @param array $headers
     */
    private static function handle_cache_set($route, $params, $data, $status, $headers) {
        $cache_key = Starter_WC_Cache_Manager::generate_key($route, $params);
        Starter_WC_Cache_Manager::set($cache_key, $data, $status, $headers, $params);
    }
    
    /**
     * Handle idempotency pre-check
     * 
     * @param WP_REST_Request $request
     * @param string $route
     * @return WP_REST_Response|null Response if should return early, null to continue
     */
    private static function handle_idempotency(WP_REST_Request $request, $route) {
        $idemp_key = Starter_WC_Idempotency::get_key_from_request($request);
        
        if (empty($idemp_key)) {
            return null; // No idempotency key, continue normally
        }
        
        // Validate key format
        $validation = Starter_WC_Idempotency::validate_key($idemp_key);
        if ($validation !== true) {
            return $validation; // Return error response
        }
        
        // Check for stored response
        $stored = Starter_WC_Idempotency::get_stored_response($idemp_key);
        if ($stored) {
            // Validate body matches
            $body_hash = Starter_WC_Idempotency::generate_body_hash($request);
            $match_check = Starter_WC_Idempotency::validate_body_match($stored, $body_hash, $idemp_key);
            
            if ($match_check !== true) {
                return $match_check; // Return conflict response
            }
            
            // Return replayed response
            return Starter_WC_Idempotency::create_replayed_response($stored, $idemp_key);
        }
        
        // Try to acquire lock
        if (!Starter_WC_Idempotency::acquire_lock($idemp_key)) {
            // Another process is handling this, wait for completion
            return Starter_WC_Idempotency::wait_for_completion($idemp_key);
        }
        
        // Lock acquired, store key in request for later cleanup
        $request->set_param('_idemp_key', $idemp_key);
        $request->set_param('_idemp_lock_acquired', true);
        
        return null; // Continue with request
    }
    
    /**
     * Handle idempotency storage after successful request
     * 
     * @param WP_REST_Request $request
     * @param mixed $data
     * @param int $status
     * @param array $headers
     */
    private static function handle_idempotency_storage(WP_REST_Request $request, $data, $status, $headers) {
        $idemp_key = $request->get_param('_idemp_key');
        $lock_acquired = $request->get_param('_idemp_lock_acquired');
        
        if (empty($idemp_key)) {
            return;
        }
        
        // Store successful response
        if ($status >= 200 && $status < 300) {
            $body_hash = Starter_WC_Idempotency::generate_body_hash($request);
            Starter_WC_Idempotency::store_response($idemp_key, $data, $status, $headers, $body_hash);
        }
        
        // Release lock if we acquired it
        if ($lock_acquired) {
            Starter_WC_Idempotency::release_lock($idemp_key);
        }
    }
    
    /**
     * Filter products by user membership level
     * 
     * OPTIMIZACIÓN v2: Precarga todos los term_meta de categorías en batch
     * para evitar N+1 queries. Antes: 1 query por categoría por producto.
     * Ahora: 1 query para precargar + lookup en memoria.
     * 
     * @param string $route API route
     * @param mixed $data Response data
     * @return mixed Filtered data
     */
    private static function filter_products_by_membership($route, $data) {
        $debug = defined('WP_DEBUG') && WP_DEBUG;
        
        // Only filter products routes
        if (strpos($route, 'products') === false) {
            return $data;
        }
        
        // Skip if not an array (single product or error)
        if (!is_array($data)) {
            return $data;
        }
        
        // Check if this is a list of products (has numeric keys or is sequential)
        $is_product_list = isset($data[0]) && is_array($data[0]) && isset($data[0]['id']);
        
        // CRÍTICO: Usar verificación JWT, no cookies de sesión
        $user_level = function_exists('starter_get_jwt_user_membership_level') 
            ? starter_get_jwt_user_membership_level() 
            : 0;
        
        if (!$is_product_list) {
            // Single product - check membership access
            if (isset($data['id']) && isset($data['categories'])) {
                // Precargar term_meta para las categorías del producto
                $category_levels = self::preload_category_membership_levels($data['categories']);
                
                // Find required membership level usando el mapa precargado
                $required_level = 0;
                foreach ($data['categories'] as $category) {
                    $category_id = isset($category['id']) ? intval($category['id']) : 0;
                    if ($category_id > 0 && isset($category_levels[$category_id])) {
                        $min_level = $category_levels[$category_id];
                        if ($min_level > $required_level) {
                            $required_level = $min_level;
                        }
                    }
                }
                
                $has_access = $user_level >= $required_level;
                
                if ($has_access) {
                    // Usuario con acceso: retornar producto completo con anotaciones
                    $data['membership_required'] = $required_level;
                    $data['user_has_access'] = true;
                } else {
                    // SEGURIDAD: Usuario SIN acceso → retornar 404
                    // NO enviar datos del producto al frontend para evitar leak de info
                    if ($debug) {
                        error_log("WC Proxy: Blocking single product ID {$data['id']} - requires level $required_level, user has level $user_level");
                    }
                    return [
                        '__access_denied' => true,
                        'membership_required' => $required_level,
                    ];
                }
            }
            return $data;
        }
        
        // =====================================================================
        // OPTIMIZACIÓN: Precargar todos los term_meta de categorías en batch
        // =====================================================================
        
        // 1. Recolectar todos los IDs de categorías de todos los productos
        $all_category_ids = [];
        foreach ($data as $product) {
            if (isset($product['categories']) && is_array($product['categories'])) {
                foreach ($product['categories'] as $category) {
                    $cat_id = isset($category['id']) ? intval($category['id']) : 0;
                    if ($cat_id > 0) {
                        $all_category_ids[] = $cat_id;
                    }
                }
            }
        }
        
        // 2. Precargar niveles de membresía de todas las categorías en una sola operación
        $category_levels = self::preload_category_membership_levels_by_ids($all_category_ids);
        
        // Detectar si es una búsqueda por slug o por IDs específicos (include)
        // En estos casos NO filtrar, solo anotar con membership info para que el frontend decida
        $is_slug_search = isset($_GET['slug']);
        $is_include_search = isset($_GET['include']);
        $annotate_only = $is_slug_search || $is_include_search;
        
        if ($debug) {
            error_log("WC Proxy: Processing " . count($data) . " products for user level $user_level, preloaded " . count($category_levels) . " category levels" . ($annotate_only ? " (annotate-only mode: slug=$is_slug_search, include=$is_include_search)" : ""));
        }
        
        $processed = [];
        $filtered_count = 0;
        
        foreach ($data as $product) {
            // Excluir productos de membresía del listado principal
            // (siempre filtrar estos, incluso en annotate-only mode)
            $product_id = isset($product['id']) ? intval($product['id']) : 0;
            if ($product_id > 0 && function_exists('starter_is_membership_product') && starter_is_membership_product($product_id)) {
                $filtered_count++;
                continue;
            }
            
            if (!isset($product['categories'])) {
                $product['membership_required'] = 0;
                $product['user_has_access'] = true;
                $processed[] = $product;
                continue;
            }
            
            // Find required membership level usando el mapa precargado (sin queries adicionales)
            // También enriquecer cada categoría con su min_membership_level para el frontend
            $required_level = 0;
            $blocking_category = '';
            
            foreach ($product['categories'] as &$category) {
                $category_id = isset($category['id']) ? intval($category['id']) : 0;
                if ($category_id > 0 && isset($category_levels[$category_id])) {
                    $min_level = $category_levels[$category_id];
                    // Enriquecer la categoría con su nivel de membresía
                    $category['min_membership_level'] = $min_level;
                    if ($min_level > $required_level) {
                        $required_level = $min_level;
                        $blocking_category = isset($category['name']) ? $category['name'] : "ID:$category_id";
                    }
                } else {
                    // Categoría sin restricción de membresía
                    $category['min_membership_level'] = 0;
                }
            }
            unset($category); // Romper referencia del foreach
            
            $has_access = $user_level >= $required_level;
            
            // Anotar siempre con membership info
            $product['membership_required'] = $required_level;
            $product['user_has_access'] = $has_access;
            
            if (!$has_access) {
                // SEGURIDAD: Filtrar productos sin acceso tanto en listado normal
                // como en slug/include search. No enviar datos al frontend.
                $filtered_count++;
            } else {
                $processed[] = $product;
            }
        }
        
        if ($debug) {
            error_log("WC Proxy: Returned " . count($processed) . " products, filtered out $filtered_count" . ($annotate_only ? " (annotate-only)" : ""));
        }
        return $processed;
    }
    
    /**
     * Precargar niveles de membresía de categorías desde un array de categorías
     * 
     * @param array $categories Array de categorías con 'id'
     * @return array Mapa de category_id => min_membership_level
     */
    private static function preload_category_membership_levels($categories) {
        $category_ids = [];
        foreach ($categories as $category) {
            $cat_id = isset($category['id']) ? intval($category['id']) : 0;
            if ($cat_id > 0) {
                $category_ids[] = $cat_id;
            }
        }
        return self::preload_category_membership_levels_by_ids($category_ids);
    }
    
    /**
     * Precargar niveles de membresía de categorías por IDs
     * 
     * OPTIMIZACIÓN: En lugar de hacer N queries (una por categoría),
     * precargamos todos los term_meta en 1-2 queries y luego hacemos
     * lookup en memoria.
     * 
     * @param array $category_ids Array de IDs de categorías
     * @return array Mapa de category_id => min_membership_level
     */
    private static function preload_category_membership_levels_by_ids($category_ids) {
        if (empty($category_ids)) {
            return [];
        }
        
        // Eliminar duplicados y reindexar
        $category_ids = array_values(array_unique(array_filter($category_ids)));
        
        if (empty($category_ids)) {
            return [];
        }
        
        // Precargar todos los term_meta en el cache de WordPress
        // Esto hace UNA sola query para todos los IDs
        if (function_exists('update_term_meta_cache')) {
            update_term_meta_cache($category_ids);
        }
        
        // Ahora get_term_meta() usará el cache en memoria (sin queries)
        $levels = [];
        foreach ($category_ids as $cat_id) {
            $min_level = get_term_meta($cat_id, '_min_membership_level', true);
            $levels[$cat_id] = $min_level !== '' ? intval($min_level) : 0;
        }
        
        return $levels;
    }
    
    /**
     * Apply translations to product/category data based on ?lang parameter
     * 
     * @param string $route API route
     * @param mixed $data Response data
     * @return mixed Translated data
     */
    private static function apply_translations($route, $data, $request_lang = null) {
        if (!function_exists('starter_get_request_lang')) {
            return $data;
        }

        $lang = starter_get_request_lang($request_lang);
        if ($lang === 'es') {
            return $data;
        }

        if (!is_array($data)) {
            return $data;
        }

        // Products routes
        if (strpos($route, 'products') !== false && strpos($route, 'products/categories') === false) {
            $is_product_list = isset($data[0]) && is_array($data[0]) && isset($data[0]['id']);
            if ($is_product_list) {
                if (function_exists('starter_preload_category_translation_meta')) {
                    starter_preload_category_translation_meta($data);
                }
                foreach ($data as &$product) {
                    $product = starter_translate_product($product, $lang);
                }
                unset($product);
            } elseif (isset($data['id'])) {
                $data = starter_translate_product($data, $lang);
            }
            return $data;
        }

        // Categories routes
        if (strpos($route, 'products/categories') !== false) {
            $is_category_list = isset($data[0]) && is_array($data[0]) && isset($data[0]['id']);
            if ($is_category_list) {
                foreach ($data as &$category) {
                    $category = starter_translate_category($category, $lang);
                }
                unset($category);
            } elseif (isset($data['id'])) {
                $data = starter_translate_category($data, $lang);
            }
            return $data;
        }

        return $data;
    }

    /**
     * Check if user can access a product based on its categories
     * 
     * @param array $product Product data
     * @param int|null $user_level User membership level (optional, will be fetched if not provided)
     * @return bool True if user can access
     */
    private static function user_can_access_product($product, $user_level = null) {
        // If no categories, allow access
        if (!isset($product['categories']) || empty($product['categories'])) {
            return true;
        }
        
        // Get user level if not provided
        // CRÍTICO: Usar verificación JWT, no cookies de sesión
        if ($user_level === null) {
            $user_level = function_exists('starter_get_jwt_user_membership_level') 
                ? starter_get_jwt_user_membership_level() 
                : 0;
        }
        
        // Find the highest required level among all categories
        $highest_required_level = 0;
        
        foreach ($product['categories'] as $category) {
            $category_id = isset($category['id']) ? intval($category['id']) : 0;
            if ($category_id > 0) {
                $min_level = starter_get_category_min_membership($category_id);
                if ($min_level > $highest_required_level) {
                    $highest_required_level = $min_level;
                }
            }
        }
        
        // User must have access to the highest required level
        return $user_level >= $highest_required_level;
    }
    
    /**
     * Filter categories to exclude internal/system categories
     * 
     * @param string $route API route
     * @param mixed $data Response data
     * @return mixed Filtered data
     */
    private static function filter_categories($route, $data) {
        // Only filter categories routes
        if (strpos($route, 'products/categories') === false) {
            return $data;
        }
        
        // Skip if not an array
        if (!is_array($data)) {
            return $data;
        }
        
        // Slugs de categorías a excluir del listado público
        $excluded_slugs = ['membresias', 'memberships'];
        
        // CRÍTICO: Obtener nivel de membresía del usuario para filtrar categorías exclusivas
        $user_level = function_exists('starter_get_jwt_user_membership_level') 
            ? starter_get_jwt_user_membership_level() 
            : 0;
        
        // Check if this is a list of categories (has numeric keys or is sequential)
        $is_category_list = isset($data[0]) && is_array($data[0]) && isset($data[0]['id']);
        
        if (!$is_category_list) {
            // Single category request - verificar acceso por membresía
            if (isset($data['id'])) {
                $cat_id = intval($data['id']);
                if (function_exists('starter_get_category_min_membership')) {
                    $min_level = starter_get_category_min_membership($cat_id);
                    if ($min_level > 0 && $user_level < $min_level) {
                        return new WP_Error(
                            'rest_forbidden',
                            'No tienes acceso a esta categoría.',
                            ['status' => 403]
                        );
                    }
                }
            }
            return $data;
        }
        
        // Precargar niveles de membresía de todas las categorías
        $category_ids = [];
        foreach ($data as $category) {
            $cat_id = isset($category['id']) ? intval($category['id']) : 0;
            if ($cat_id > 0) {
                $category_ids[] = $cat_id;
            }
        }
        $category_levels = self::preload_category_membership_levels_by_ids($category_ids);
        
        // Filter out excluded categories AND categories that require higher membership
        $filtered = array_filter($data, function($category) use ($excluded_slugs, $user_level, $category_levels) {
            $slug = isset($category['slug']) ? strtolower($category['slug']) : '';
            if (in_array($slug, $excluded_slugs)) {
                return false;
            }
            
            // Filtrar por nivel de membresía
            $cat_id = isset($category['id']) ? intval($category['id']) : 0;
            if ($cat_id > 0 && isset($category_levels[$cat_id])) {
                $min_level = $category_levels[$cat_id];
                if ($min_level > 0 && $user_level < $min_level) {
                    return false;
                }
            }
            
            return true;
        });
        
        // Anotar cada categoría con su nivel mínimo de membresía requerido
        foreach ($filtered as &$category) {
            $cat_id = intval($category['id'] ?? 0);
            $category['min_membership_level'] = isset($category_levels[$cat_id]) ? $category_levels[$cat_id] : 0;
        }
        unset($category);

        // Re-index array to avoid gaps
        return array_values($filtered);
    }
}
