<?php
/**
 * Endpoints REST API para menús
 * 
 * SISTEMA DE CACHÉ:
 * - Usa transients (persistente en BD) en lugar de wp_cache (memoria)
 * - Caché por nivel de membresía para personalización
 * - Invalidación automática al modificar menús, items o categorías
 * - TTL de 5 minutos para balance entre rendimiento y frescura
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

// Constantes de configuración del caché
define('STARTER_MENU_CACHE_PREFIX', 'fi_menu_v3_');
define('STARTER_MENU_CACHE_TTL', 300); // 5 minutos
define('STARTER_MENU_MAX_LEVELS', 5);

/**
 * Invalidar cache del menú cuando se modifica
 * Limpia transients para todos los niveles de membresía (0-5) y todos los idiomas
 */
function starter_invalidate_menu_cache() {
    // Log para debugging
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[Starter Menu] Invalidando caché de menú...');
    }
    
    // Idiomas: español (default) + todos los idiomas de traducción de translation-fields.php
    $all_langs = array_merge(['es'], defined('STARTER_TRANSLATION_LANGS') ? STARTER_TRANSLATION_LANGS : ['en']);
    
    // Limpiar transients para todos los niveles de membresía y todos los idiomas
    foreach ($all_langs as $lang) {
        for ($level = 0; $level <= STARTER_MENU_MAX_LEVELS; $level++) {
            $cache_key = STARTER_MENU_CACHE_PREFIX . $lang . '_level_' . $level;
            delete_transient($cache_key);
        }
    }
    
    // También limpiar versiones anteriores por compatibilidad
    for ($level = 0; $level <= STARTER_MENU_MAX_LEVELS; $level++) {
        wp_cache_delete('starter_main_menu_v2_level_' . $level);
        // Limpiar formato anterior sin idioma
        delete_transient(STARTER_MENU_CACHE_PREFIX . 'level_' . $level);
    }
    wp_cache_delete('starter_main_menu_v1');
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[Starter Menu] Caché invalidado correctamente');
    }
}

// Hooks para invalidar cache cuando se modifica el menú
add_action('wp_update_nav_menu', 'starter_invalidate_menu_cache');
add_action('wp_create_nav_menu', 'starter_invalidate_menu_cache');
add_action('wp_delete_nav_menu', 'starter_invalidate_menu_cache');

// Hooks adicionales para invalidar cuando se modifican items del menú
add_action('wp_update_nav_menu_item', 'starter_invalidate_menu_cache');
add_action('wp_add_nav_menu_item', 'starter_invalidate_menu_cache');
add_action('delete_nav_menu_item', 'starter_invalidate_menu_cache');

// Hooks para invalidar cuando se modifican categorías de productos (afectan al menú)
add_action('edited_product_cat', 'starter_invalidate_menu_cache');
add_action('created_product_cat', 'starter_invalidate_menu_cache');
add_action('delete_product_cat', 'starter_invalidate_menu_cache');

// Hook para invalidar cuando se actualiza el meta de membresía de una categoría
add_action('updated_term_meta', function($meta_id, $object_id, $meta_key, $meta_value) {
    if ($meta_key === '_min_membership_level') {
        starter_invalidate_menu_cache();
    }
}, 10, 4);

/**
 * Registrar el endpoint REST API para obtener el menú principal
 */
function starter_register_menu_endpoints() {
    register_rest_route('starter/v1', '/menu', array(
        'methods' => 'GET',
        'callback' => 'starter_get_main_menu',
        'permission_callback' => function() {
            return true; // Permitir acceso público
        }
    ));
}
add_action('rest_api_init', 'starter_register_menu_endpoints');

/**
 * Obtener la estructura del menú principal
 * 
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function starter_get_main_menu($request) {
    // CRÍTICO: Usar verificación JWT, no cookies de sesión
    // Esto asegura que usuarios sin token Bearer sean tratados como anónimos
    $user_membership_level = function_exists('starter_get_jwt_user_membership_level') 
        ? starter_get_jwt_user_membership_level() 
        : 0;
    $user_id = function_exists('starter_get_jwt_user_id') 
        ? starter_get_jwt_user_id() 
        : 0;
    
    // Obtener idioma solicitado usando el helper centralizado de translation-fields.php
    $lang = function_exists('starter_get_request_lang') 
        ? starter_get_request_lang($request->get_param('lang')) 
        : 'es';
    
    // Parámetro para forzar refresh del caché (útil para debugging)
    $force_refresh = $request->get_param('refresh') === '1';
    
    // Usar transients (persistente en BD) en lugar de wp_cache (memoria)
    // Cache key incluye idioma para servir menú traducido correctamente
    $cache_key = STARTER_MENU_CACHE_PREFIX . $lang . '_level_' . $user_membership_level;
    
    if (!$force_refresh) {
        $cached_menu = get_transient($cache_key);
        
        if ($cached_menu !== false && is_array($cached_menu)) {
            $response = new WP_REST_Response($cached_menu, 200);
            // Headers para indicar que es respuesta cacheada
            $response->header('X-Menu-Cache', 'HIT');
            $response->header('X-Menu-Level', $user_membership_level);
            $response->header('X-Menu-User', $user_id ?: 'anonymous');
            // NO cachear en CDN/proxy - cada usuario puede ver menú diferente
            $response->header('Cache-Control', 'private, no-store, max-age=0');
            $response->header('Vary', 'Authorization');
            return $response;
        }
    } else {
        // Limpiar caché si se solicita refresh
        delete_transient($cache_key);
    }
    
    // Obtener la ubicación del menú principal
    $locations = get_nav_menu_locations();
    
    if (!isset($locations['main-menu'])) {
        $error_response = [
            'success' => false,
            'message' => 'Menú principal no encontrado',
            'menu' => [
                'id' => null,
                'name' => 'Menú no disponible',
                'items' => []
            ]
        ];
        
        // NO cachear errores - queremos que se reintente
        $response = new WP_REST_Response($error_response, 200);
        $response->header('X-Menu-Cache', 'ERROR');
        $response->header('Cache-Control', 'no-store, max-age=0');
        return $response;
    }
    
    $menu_id = $locations['main-menu'];
    $menu_object = wp_get_nav_menu_object($menu_id);
    
    if (!$menu_object) {
        $error_response = [
            'success' => false,
            'message' => 'Objeto de menú no encontrado',
            'menu' => [
                'id' => $menu_id,
                'name' => 'Menú no disponible',
                'items' => []
            ]
        ];
        
        $response = new WP_REST_Response($error_response, 200);
        $response->header('X-Menu-Cache', 'ERROR');
        $response->header('Cache-Control', 'no-store, max-age=0');
        return $response;
    }
    
    // Obtener los elementos del menú
    $menu_items = wp_get_nav_menu_items($menu_id);
    
    if (!$menu_items) {
        $error_response = [
            'success' => false,
            'message' => 'No se encontraron elementos en el menú',
            'menu' => [
                'id' => $menu_id,
                'name' => $menu_object->name,
                'items' => []
            ]
        ];
        
        $response = new WP_REST_Response($error_response, 200);
        $response->header('X-Menu-Cache', 'ERROR');
        $response->header('Cache-Control', 'no-store, max-age=0');
        return $response;
    }
    
    // Procesar los elementos del menú para crear una estructura jerárquica
    // (user_membership_level ya se obtuvo al inicio para el cache key)
    $menu_tree = starter_build_menu_tree($menu_items, 0, $user_membership_level, $lang);
    
    $response_data = [
        'success' => true,
        'menu' => [
            'id' => $menu_id,
            'name' => $menu_object->name,
            'items' => $menu_tree
        ],
        'meta' => [
            'user_id' => $user_id,
            'membership_level' => $user_membership_level,
            'lang' => $lang,
            'cached' => false,
            'generated_at' => current_time('mysql'),
            'cache_ttl' => STARTER_MENU_CACHE_TTL
        ]
    ];
    
    // Cachear la respuesta exitosa usando transients (persistente en BD)
    set_transient($cache_key, $response_data, STARTER_MENU_CACHE_TTL);
    
    $response = new WP_REST_Response($response_data, 200);
    // Headers para indicar respuesta fresca y nivel de membresía
    $response->header('X-Menu-Cache', 'MISS');
    $response->header('X-Menu-Level', $user_membership_level);
    $response->header('X-Menu-User', $user_id ?: 'anonymous');
    // NO cachear en CDN/proxy - cada usuario puede ver menú diferente
    $response->header('Cache-Control', 'private, no-store, max-age=0');
    $response->header('Vary', 'Authorization');
    
    return $response;
}

/**
 * Construir una estructura jerárquica de menú a partir de elementos planos
 * Optimizada para mejor rendimiento y filtrado por membresía
 * 
 * @param array $menu_items
 * @param int $parent_id
 * @param int $user_membership_level Nivel de membresía del usuario
 * @return array
 */
function starter_build_menu_tree($menu_items, $parent_id = 0, $user_membership_level = 0, $lang = 'es') {
    $tree = [];
    $items_by_parent = [];
    
    // Organizar elementos por padre para mejor rendimiento
    foreach ($menu_items as $item) {
        $parent = (int)$item->menu_item_parent;
        if (!isset($items_by_parent[$parent])) {
            $items_by_parent[$parent] = [];
        }
        $items_by_parent[$parent][] = $item;
    }
    
    // Función recursiva optimizada con filtrado por membresía
    $build_tree_recursive = function($parent_id) use (&$build_tree_recursive, $items_by_parent, $user_membership_level, $lang) {
        $tree = [];
        
        if (!isset($items_by_parent[$parent_id])) {
            return $tree;
        }
        
        foreach ($items_by_parent[$parent_id] as $item) {
            // Verificar membresía requerida del item del menú
            $min_membership = get_post_meta($item->ID, '_menu_item_min_membership', true);
            $min_membership = $min_membership !== '' ? intval($min_membership) : 0;
            
            // Si es una categoría de producto, también verificar el nivel de membresía de la categoría
            if ($item->object === 'product_cat' && $item->object_id) {
                $cat_min_level = get_term_meta($item->object_id, '_min_membership_level', true);
                if ($cat_min_level !== '' && intval($cat_min_level) > $min_membership) {
                    // Usar el nivel más restrictivo entre el item del menú y la categoría
                    $min_membership = intval($cat_min_level);
                }
            }
            
            // Obtener modo de visibilidad (cascade o exact)
            $membership_mode = get_post_meta($item->ID, '_menu_item_membership_mode', true);
            $membership_mode = $membership_mode ?: 'cascade'; // Por defecto: cascada
            
            // Filtrar según el modo de visibilidad
            $can_view = false;
            
            if ($min_membership === 0) {
                // Nivel público: todos pueden ver
                $can_view = true;
            } elseif ($membership_mode === 'exact') {
                // Modo exacto: solo el nivel específico puede ver
                $can_view = ($user_membership_level === $min_membership);
            } else {
                // Modo cascada (por defecto): este nivel y superiores
                $can_view = ($user_membership_level >= $min_membership);
            }
            
            if (!$can_view) {
                continue; // Saltar este item
            }
            
            // Obtener título traducido si el idioma no es español
            $translated_title = $item->title;
            if ($lang !== 'es') {
                // Intentar obtener traducción del título del item de menú
                $item_title_translated = get_post_meta($item->ID, '_menu_item_title_' . $lang, true);
                if (!empty($item_title_translated)) {
                    $translated_title = $item_title_translated;
                }
                // Si es categoría de producto, intentar traducción del term
                if ($item->object === 'product_cat' && $item->object_id) {
                    $term_name_translated = get_term_meta($item->object_id, '_name_' . $lang, true);
                    if (!empty($term_name_translated)) {
                        $translated_title = $term_name_translated;
                    }
                }
            }
            
            $menu_item = [
                'id' => $item->ID,
                'title' => $translated_title,
                'url' => $item->url,
                'target' => $item->target ?: '_self',
                'type' => $item->type,
                'object_id' => $item->object_id,
                'object' => $item->object,
                'minMembership' => $min_membership,
                'membershipMode' => $membership_mode,
            ];
            
            // Agregar información de membresía si está disponible
            if ($min_membership > 0 && class_exists('Starter_Memberships')) {
                $level_info = Starter_Memberships::get_membership_level($min_membership);
                $menu_item['membershipInfo'] = [
                    'level' => $min_membership,
                    'name' => $level_info['name'],
                    'icon' => $level_info['icon'],
                    'color' => $level_info['color'],
                    'mode' => $membership_mode
                ];
            }
            
            // Obtener hijos (también filtrados por membresía)
            $children = $build_tree_recursive($item->ID);
            if (!empty($children)) {
                $menu_item['children'] = $children;
            }
            
            // Si es una categoría de producto, agregar información básica
            if ($item->object === 'product_cat') {
                $term = get_term($item->object_id, 'product_cat');
                if ($term && !is_wp_error($term)) {
                    // Obtener nombre traducido del term
                    $term_display_name = $term->name;
                    if ($lang !== 'es') {
                        $term_name_translated = get_term_meta($term->term_id, '_name_' . $lang, true);
                        if (!empty($term_name_translated)) {
                            $term_display_name = $term_name_translated;
                        }
                    }
                    
                    $menu_item['term'] = [
                        'id' => $term->term_id,
                        'name' => $term_display_name,
                        'slug' => $term->slug,
                        'count' => $term->count,
                    ];
                    
                    // Agregar información de membresía de la categoría si existe
                    $cat_min_membership = get_term_meta($term->term_id, '_min_membership_level', true);
                    if ($cat_min_membership !== '' && intval($cat_min_membership) > 0) {
                        $menu_item['term']['minMembership'] = intval($cat_min_membership);
                    }
                }
            }
            
            $tree[] = $menu_item;
        }
        
        return $tree;
    };
    
    return $build_tree_recursive($parent_id);
}