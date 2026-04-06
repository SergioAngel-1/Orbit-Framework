<?php
/**
 * Handler para obtener secciones del home
 */

if (!defined('ABSPATH')) {
    exit;
}

class FIHS_Sections_Handler {
    
    private const MAX_MEMBERSHIP_LEVEL = 5;
    
    /**
     * Obtener secciones para el usuario actual
     * 
     * IMPORTANTE: Este endpoint es sensible a membresía - retorna datos diferentes
     * según el nivel del usuario. Se envían headers para prevenir caché del navegador.
     */
    public static function get_sections($request = null) {
        try {
            // Detect language
            $lang = 'es';
            $include_products = false;
            if ($request instanceof WP_REST_Request) {
                $lang = function_exists('starter_get_request_lang')
                    ? starter_get_request_lang($request->get_param('lang'))
                    : ($request->get_param('lang') ?: 'es');
                $include_products = (bool) $request->get_param('include_products');
            } elseif (function_exists('starter_get_request_lang')) {
                $lang = starter_get_request_lang();
            }
            
            $response = self::get_sections_internal($lang, $include_products);
            // Headers para prevenir caché del navegador - endpoint sensible a membresía
            $response->header('Cache-Control', 'no-cache, no-store, must-revalidate, private');
            $response->header('Pragma', 'no-cache');
            $response->header('Expires', '0');
            $response->header('X-Membership-Level', FIHS_Auth_Helper::get_user_membership_level());
            return $response;
        } catch (Exception $e) {
            error_log('Starter Home Sections Error: ' . $e->getMessage());
            $response = new WP_REST_Response(array(
                'error' => true,
                'message' => 'Error interno al obtener secciones',
                'debug' => WP_DEBUG ? $e->getMessage() : null,
            ), 500);
            $response->header('Cache-Control', 'no-cache, no-store, must-revalidate, private');
            return $response;
        }
    }
    
    /**
     * Lógica interna para obtener secciones
     */
    private static function get_sections_internal($lang = 'es', $include_products = false) {
        $user_level = FIHS_Auth_Helper::get_user_membership_level();
        $cache = FIHS_Cache_Manager::get_instance();
        
        // Verificar caché (key diferenciada cuando incluye productos)
        $cache_key = $include_products
            ? $cache->sections_with_products_key($user_level, $lang)
            : $cache->sections_key($user_level, $lang);
        $cached = $cache->get($cache_key);
        if ($cached !== false && is_array($cached) && !empty($cached)) {
            return new WP_REST_Response($cached, 200);
        }
        
        $plugin = Starter_Home_Sections::get_instance();
        $layout_types = $plugin->get_layout_types();
        $sections_by_level = get_option('starter_home_sections_by_level', array());
        
        // Verificar si hay secciones en el nuevo formato
        if (!self::has_new_format_sections($sections_by_level)) {
            $sections = $plugin->get_sections();
            if (!empty($sections)) {
                return self::get_legacy_sections($sections, $layout_types);
            }
            return new WP_REST_Response(array(), 200);
        }
        
        // Precargar categorías
        $category_map = FIHS_Category_Loader::preload_for_sections($sections_by_level);
        
        // Obtener exclusiones del usuario
        $excluded = get_option('starter_excluded_sections_level_' . $user_level, array());
        if (!is_array($excluded)) {
            $excluded = array();
        }
        
        $result = array();
        $seen_ids = array();
        
        // 1. Secciones propias del nivel del usuario
        self::add_level_sections(
            $result, $seen_ids, $sections_by_level, $user_level, 
            $category_map, $layout_types, $excluded, $user_level, $lang
        );
        
        // 2. Secciones heredadas de niveles superiores
        for ($level = $user_level + 1; $level <= self::MAX_MEMBERSHIP_LEVEL; $level++) {
            self::add_level_sections(
                $result, $seen_ids, $sections_by_level, $level,
                $category_map, $layout_types, $excluded, $user_level, $lang
            );
        }
        
        // Ordenar por zona y orden
        usort($result, function($a, $b) {
            $zone_order = array('top' => 1, 'middle' => 2, 'bottom' => 3);
            $zone_a = $zone_order[$a['zone']] ?? 99;
            $zone_b = $zone_order[$b['zone']] ?? 99;
            
            if ($zone_a != $zone_b) {
                return $zone_a - $zone_b;
            }
            return ($a['order'] ?? 0) - ($b['order'] ?? 0);
        });
        
        // Si se pidieron productos, cargarlos inline en cada sección
        if ($include_products && !empty($result)) {
            $result = self::embed_products($result, $user_level, $lang);
        }
        
        // Cachear solo si hay resultados
        if (!empty($result)) {
            // TTL más largo cuando incluye productos (respuesta completa, menos requests)
            $ttl = $include_products ? 300 : 600;
            $cache->set($cache_key, $result, $ttl);
        }
        
        return new WP_REST_Response($result, 200);
    }
    
    /**
     * Cargar productos inline en cada sección para evitar N+1 requests HTTP
     * Reutiliza FIHS_Products_Handler::query_products() que ya maneja subcategorías y membresía
     */
    private static function embed_products($sections, $user_level, $lang = 'es') {
        $plugin = Starter_Home_Sections::get_instance();
        $layout_types = $plugin->get_layout_types();
        
        foreach ($sections as &$section) {
            $layout_config = $layout_types[$section['layout_type']] ?? $layout_types['standard'];
            $limit = $layout_config['limit'];
            $random = $section['random'] ?? false;
            $category_id = intval($section['category_id']);
            
            // Cargar productos de la categoría principal
            // full_data=false: payload ligero para el home (sin description, sku, weight, etc.)
            $products = FIHS_Products_Handler::query_products($category_id, $limit, $random, $user_level, false);
            
            // Traducir productos si es necesario
            if ($lang !== 'es' && function_exists('starter_translate_product')) {
                if (function_exists('starter_preload_category_translation_meta')) {
                    starter_preload_category_translation_meta($products);
                }
                $products = array_map(function($p) use ($lang) {
                    return starter_translate_product($p, $lang);
                }, $products);
            }
            
            $section['products'] = $products;
            
            // Cargar productos de la segunda categoría para compact_pair
            if ($section['layout_type'] === 'compact_pair' && !empty($section['category_id_2'])) {
                $cat_id_2 = intval($section['category_id_2']);
                $products_2 = FIHS_Products_Handler::query_products($cat_id_2, $limit, $random, $user_level, false);
                
                if ($lang !== 'es' && function_exists('starter_translate_product')) {
                    if (function_exists('starter_preload_category_translation_meta')) {
                        starter_preload_category_translation_meta($products_2);
                    }
                    $products_2 = array_map(function($p) use ($lang) {
                        return starter_translate_product($p, $lang);
                    }, $products_2);
                }
                
                $section['products_2'] = $products_2;
            }
        }
        unset($section);
        
        return $sections;
    }
    
    /**
     * Verificar si hay secciones en el nuevo formato
     */
    private static function has_new_format_sections($sections_by_level) {
        if (empty($sections_by_level)) {
            return false;
        }
        foreach ($sections_by_level as $level_sections) {
            if (is_array($level_sections) && !empty($level_sections)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Agregar secciones de un nivel específico al resultado
     */
    private static function add_level_sections(
        &$result, &$seen_ids, $sections_by_level, $level,
        $category_map, $layout_types, $excluded, $user_level, $lang = 'es'
    ) {
        if (!isset($sections_by_level[$level]) || !is_array($sections_by_level[$level])) {
            return;
        }
        
        foreach ($sections_by_level[$level] as $section_data) {
            $section_id = $section_data['id'] ?? '';
            
            // Validaciones básicas
            if (empty($section_id) || in_array($section_id, $seen_ids) || in_array($section_id, $excluded)) {
                continue;
            }
            if (isset($section_data['enabled']) && !$section_data['enabled']) {
                continue;
            }
            if (empty($section_data['category_id'])) {
                continue;
            }
            
            // Verificar categoría
            $cat_id = intval($section_data['category_id']);
            if (!isset($category_map[$cat_id])) {
                continue;
            }
            $cat_data = $category_map[$cat_id];
            
            // Verificar acceso a la categoría (para secciones heredadas)
            if ($cat_data['min_membership_level'] > $user_level) {
                continue;
            }
            
            $seen_ids[] = $section_id;
            $layout_config = $layout_types[$section_data['layout_type']] ?? $layout_types['standard'];
            
            // Translate category name if needed
            $cat_name = $cat_data['name'];
            if ($lang !== 'es' && function_exists('starter_translate_category')) {
                $translated_cat = starter_translate_category(
                    array('id' => $cat_id, 'name' => $cat_data['name'], 'description' => ''),
                    $lang
                );
                $cat_name = $translated_cat['name'];
            }
            
            // Determine title/subtitle based on language
            $title = !empty($section_data['title']) ? $section_data['title'] : $cat_name;
            $subtitle = $section_data['subtitle'] ?? '';
            if ($lang !== 'es') {
                if (!empty($section_data['title_en'])) {
                    $title = $section_data['title_en'];
                } elseif (empty($section_data['title'])) {
                    $title = $cat_name;
                }
                if (!empty($section_data['subtitle_en'])) {
                    $subtitle = $section_data['subtitle_en'];
                }
            }
            
            $section_result = array(
                'id' => $section_id,
                'layout_type' => $section_data['layout_type'],
                'grid_type' => $layout_config['grid_type'],
                'zone' => $section_data['zone'],
                'category_id' => $section_data['category_id'],
                'category_name' => $cat_name,
                'category_slug' => $cat_data['slug'],
                'title' => $title,
                'subtitle' => $subtitle,
                'random' => $section_data['random'] ?? false,
                'limit' => $layout_config['limit'],
                'min_products' => $layout_config['min_products'],
                'order' => $section_data['order'] ?? 0,
                'membership_level' => $level,
                'min_membership_level' => $cat_data['min_membership_level'],
            );
            
            // Agregar segunda categoría para compact_pair
            if ($section_data['layout_type'] === 'compact_pair' && !empty($section_data['category_id_2'])) {
                $cat_id_2 = intval($section_data['category_id_2']);
                if (isset($category_map[$cat_id_2])) {
                    $cat_data_2 = $category_map[$cat_id_2];
                    
                    // Translate category 2 name if needed
                    $cat_name_2 = $cat_data_2['name'];
                    if ($lang !== 'es' && function_exists('starter_translate_category')) {
                        $translated_cat_2 = starter_translate_category(
                            array('id' => $cat_id_2, 'name' => $cat_data_2['name'], 'description' => ''),
                            $lang
                        );
                        $cat_name_2 = $translated_cat_2['name'];
                    }
                    
                    // Determine title_2/subtitle_2 based on language
                    $title_2 = !empty($section_data['title_2']) ? $section_data['title_2'] : $cat_name_2;
                    $subtitle_2 = $section_data['subtitle_2'] ?? '';
                    if ($lang !== 'es') {
                        if (!empty($section_data['title_2_en'])) {
                            $title_2 = $section_data['title_2_en'];
                        } elseif (empty($section_data['title_2'])) {
                            $title_2 = $cat_name_2;
                        }
                        if (!empty($section_data['subtitle_2_en'])) {
                            $subtitle_2 = $section_data['subtitle_2_en'];
                        }
                    }
                    
                    $section_result['category_id_2'] = $section_data['category_id_2'];
                    $section_result['category_name_2'] = $cat_name_2;
                    $section_result['category_slug_2'] = $cat_data_2['slug'];
                    $section_result['title_2'] = $title_2;
                    $section_result['subtitle_2'] = $subtitle_2;
                }
            }
            
            $result[] = $section_result;
        }
    }
    
    /**
     * Obtener secciones en formato legacy
     */
    private static function get_legacy_sections($sections, $layout_types) {
        $category_ids = array();
        foreach ($sections as $section_data) {
            if (!empty($section_data['category_id'])) {
                $category_ids[] = intval($section_data['category_id']);
            }
        }
        
        $category_map = FIHS_Category_Loader::preload_from_ids($category_ids);
        $result = array();
        
        foreach ($sections as $section_id => $section_data) {
            if (isset($section_data['enabled']) && !$section_data['enabled']) {
                continue;
            }
            if (empty($section_data['category_id'])) {
                continue;
            }
            
            $cat_id = intval($section_data['category_id']);
            if (!isset($category_map[$cat_id])) {
                continue;
            }
            $cat_data = $category_map[$cat_id];
            $layout_config = $layout_types[$section_data['layout_type']] ?? $layout_types['standard'];
            
            $result[] = array(
                'id' => $section_id,
                'layout_type' => $section_data['layout_type'],
                'grid_type' => $layout_config['grid_type'],
                'zone' => $section_data['zone'],
                'category_id' => $section_data['category_id'],
                'category_name' => $cat_data['name'],
                'category_slug' => $cat_data['slug'],
                'title' => !empty($section_data['title']) ? $section_data['title'] : $cat_data['name'],
                'subtitle' => $section_data['subtitle'] ?? '',
                'random' => $section_data['random'] ?? false,
                'limit' => $layout_config['limit'],
                'min_products' => $layout_config['min_products'],
                'order' => $section_data['order'] ?? 0,
            );
        }
        
        usort($result, function($a, $b) {
            return $a['order'] - $b['order'];
        });
        
        return new WP_REST_Response($result, 200);
    }
}
