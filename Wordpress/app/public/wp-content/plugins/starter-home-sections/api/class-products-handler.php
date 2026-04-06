<?php
/**
 * Handler para obtener productos de secciones
 */

if (!defined('ABSPATH')) {
    exit;
}

class FIHS_Products_Handler {
    
    /**
     * Obtener productos de una sección específica
     * 
     * IMPORTANTE: Este endpoint es sensible a membresía - retorna datos diferentes
     * según el nivel del usuario. Se envían headers para prevenir caché del navegador.
     */
    public static function get_section_products($request) {
        $section_id = $request->get_param('section_id');
        $section_data = self::find_section($section_id);
        $user_level = FIHS_Auth_Helper::get_user_membership_level();
        
        // Detect language for translations
        $lang = function_exists('starter_get_request_lang')
            ? starter_get_request_lang($request->get_param('lang'))
            : 'es';
        
        if (is_wp_error($section_data)) {
            $response = new WP_REST_Response($section_data->get_error_data(), $section_data->get_error_code() === 'not_found' ? 404 : 400);
            $response->header('Cache-Control', 'no-cache, no-store, must-revalidate, private');
            return $response;
        }
        
        $plugin = Starter_Home_Sections::get_instance();
        $layout_types = $plugin->get_layout_types();
        $layout_config = $layout_types[$section_data['layout_type']] ?? $layout_types['standard'];
        
        $per_page = $request->get_param('per_page');
        $limit = !empty($per_page) ? intval($per_page) : $layout_config['limit'];
        $random = $section_data['random'] ?? false;
        
        // Verificar caché (interno del servidor, no del navegador)
        $cache = FIHS_Cache_Manager::get_instance();
        $cache_key = $cache->products_key($section_id, $limit, $random, $user_level, $lang);
        $ttl = $random ? 300 : 900;
        
        $cached = $cache->get($cache_key);
        if ($cached !== false) {
            $response = new WP_REST_Response($cached, 200);
            // Headers para prevenir caché del navegador
            $response->header('Cache-Control', 'no-cache, no-store, must-revalidate, private');
            $response->header('Pragma', 'no-cache');
            $response->header('Expires', '0');
            $response->header('X-Membership-Level', $user_level);
            return $response;
        }
        
        // Obtener productos
        $category_id = $section_data['category_id'];
        $products = self::query_products($category_id, $limit, $random, $user_level);
        
        // Construir respuesta
        $category = get_term($category_id, 'product_cat');
        $category_min_level = intval(get_term_meta($category_id, '_min_membership_level', true));
        
        // Translate category name if needed
        $category_name = $category->name;
        if ($lang !== 'es' && function_exists('starter_translate_category')) {
            $translated_cat = starter_translate_category(
                array('id' => $category_id, 'name' => $category->name, 'description' => $category->description),
                $lang
            );
            $category_name = $translated_cat['name'];
        }
        
        // Translate each product
        if ($lang !== 'es' && function_exists('starter_translate_product')) {
            if (function_exists('starter_preload_category_translation_meta')) {
                starter_preload_category_translation_meta($products);
            }
            $products = array_map(function($p) use ($lang) {
                return starter_translate_product($p, $lang);
            }, $products);
        }
        
        // Determine title and subtitle based on language
        $title = !empty($section_data['title']) ? $section_data['title'] : $category_name;
        $subtitle = $section_data['subtitle'] ?? '';
        if ($lang !== 'es') {
            if (!empty($section_data['title_en'])) {
                $title = $section_data['title_en'];
            } elseif (empty($section_data['title'])) {
                // No custom title set — already using translated category_name
                $title = $category_name;
            }
            if (!empty($section_data['subtitle_en'])) {
                $subtitle = $section_data['subtitle_en'];
            }
        }
        
        $response_data = array(
            'id' => $section_id,
            'layout_type' => $section_data['layout_type'],
            'grid_type' => $layout_config['grid_type'],
            'zone' => $section_data['zone'],
            'category_id' => $category_id,
            'category_name' => $category_name,
            'category_slug' => $category->slug,
            'title' => $title,
            'subtitle' => $subtitle,
            'products' => $products,
            'limit' => $limit,
            'min_products' => $layout_config['min_products'],
            'min_membership_level' => $category_min_level,
        );
        
        // Agregar segunda categoría para compact_pair
        if ($section_data['layout_type'] === 'compact_pair' && !empty($section_data['category_id_2'])) {
            $response_data = self::add_second_category($response_data, $section_data, $limit, $random, $user_level, $lang);
        }
        
        $cache->set($cache_key, $response_data, $ttl);
        
        $response = new WP_REST_Response($response_data, 200);
        // Headers para prevenir caché del navegador
        $response->header('Cache-Control', 'no-cache, no-store, must-revalidate, private');
        $response->header('Pragma', 'no-cache');
        $response->header('Expires', '0');
        $response->header('X-Membership-Level', $user_level);
        return $response;
    }
    
    /**
     * Buscar sección por ID
     */
    private static function find_section($section_id) {
        $sections_by_level = get_option('starter_home_sections_by_level', array());
        
        foreach ($sections_by_level as $level => $level_sections) {
            if (is_array($level_sections)) {
                foreach ($level_sections as $sec) {
                    if (isset($sec['id']) && $sec['id'] === $section_id) {
                        return $sec;
                    }
                }
            }
        }
        
        // Buscar en formato legacy
        $plugin = Starter_Home_Sections::get_instance();
        $legacy_sections = $plugin->get_sections();
        if (isset($legacy_sections[$section_id])) {
            return $legacy_sections[$section_id];
        }
        
        return new WP_Error('not_found', 'Sección no encontrada', array(
            'error' => 'Sección no encontrada',
            'section_id' => $section_id,
        ));
    }
    
    /**
     * Consultar productos de una categoría
     */
    public static function query_products($category_id, $limit, $random, $user_level, $full_data = true) {
        $search_limit = $limit * 4;
        
        // Buscar en categoría + subcategorías en una sola query
        // Esto evita la doble query (primero solo padre, luego padre+hijos)
        $args = array(
            'post_type'      => 'product',
            'posts_per_page' => $search_limit,
            'post_status'    => 'publish',
            'tax_query'      => array(
                array(
                    'taxonomy' => 'product_cat',
                    'field'    => 'term_id',
                    'terms'    => $category_id,
                    'include_children' => true,
                ),
            ),
            'meta_query'     => array(
                array(
                    'key'     => '_stock_status',
                    'value'   => 'instock',
                    'compare' => '=',
                ),
            ),
            'orderby'        => 'date',
            'order'          => 'DESC',
        );
        
        $query = new WP_Query($args);
        $products = array();
        
        if ($query->have_posts()) {
            // Precarga en batch: postmeta + termmeta para evitar N+1 queries individuales
            $post_ids = wp_list_pluck($query->posts, 'ID');
            update_postmeta_cache($post_ids);
            // Precargar términos de categoría para todos los productos
            wp_get_object_terms($post_ids, 'product_cat');
            // Precargar term_meta de categorías relevantes
            $all_cat_ids = array();
            foreach ($post_ids as $pid) {
                $cats = get_the_terms($pid, 'product_cat');
                if ($cats && !is_wp_error($cats)) {
                    foreach ($cats as $c) {
                        $all_cat_ids[] = $c->term_id;
                    }
                }
            }
            if (!empty($all_cat_ids)) {
                update_termmeta_cache(array_unique($all_cat_ids));
            }
            
            while ($query->have_posts() && count($products) < $limit) {
                $query->the_post();
                $product_data = self::process_product(get_the_ID(), $user_level, $full_data);
                if ($product_data) {
                    $products[] = $product_data;
                }
            }
            wp_reset_postdata();
        }
        
        // Aleatorizar en PHP en vez de ORDER BY RAND() en MySQL (más eficiente)
        if ($random) {
            shuffle($products);
        }
        
        return array_slice($products, 0, $limit);
    }
    
    /**
     * Procesar un producto individual
     */
    public static function process_product($product_id, $user_level, $full_data = true) {
        $product = wc_get_product($product_id);
        
        if (!$product || !$product->is_in_stock()) {
            return null;
        }
        
        // Obtener categorías una sola vez (reutilizar abajo)
        $product_categories = get_the_terms($product_id, 'product_cat');
        if (!$product_categories || is_wp_error($product_categories)) {
            $product_categories = array();
        }
        
        // Verificar acceso por membresía y calcular nivel requerido
        $product_min_level = 0;
        if (function_exists('starter_get_category_min_membership')) {
            foreach ($product_categories as $cat) {
                $cat_level = starter_get_category_min_membership($cat->term_id);
                if ($cat_level > $product_min_level) {
                    $product_min_level = $cat_level;
                }
            }
            if ($product_min_level > $user_level) {
                return null;
            }
        }
        
        $image_id = $product->get_image_id();
        $image_url = wp_get_attachment_image_url($image_id, $full_data ? 'full' : 'woocommerce_thumbnail');
        
        $data = array(
            'id' => $product_id,
            'name' => $product->get_name(),
            'slug' => $product->get_slug(),
            'permalink' => get_permalink($product_id),
            'price' => $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'on_sale' => $product->is_on_sale(),
            'stock_status' => 'instock',
            'categories' => array_map(function($term) {
                $cat_data = array(
                    'id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                );
                if (function_exists('starter_get_category_min_membership')) {
                    $cat_data['min_membership_level'] = starter_get_category_min_membership($term->term_id);
                }
                return $cat_data;
            }, $product_categories),
            'images' => array(
                array(
                    'id' => $image_id,
                    'src' => $image_url,
                    'alt' => get_post_meta($image_id, '_wp_attachment_image_alt', true),
                ),
            ),
            'membership_required' => $product_min_level,
            'type' => $product->get_type(),
            'stock_quantity' => $product->get_stock_quantity(),
            'average_rating' => $product->get_average_rating(),
            'rating_count' => $product->get_rating_count(),
        );
        
        // Agregar datos extendidos solo cuando se requieren (ej: endpoint individual)
        // Para el home, full_data=false reduce significativamente el payload
        if ($full_data) {
            $data = array_merge($data, array(
                'date_created' => $product->get_date_created() ? $product->get_date_created()->date('c') : '',
                'date_modified' => $product->get_date_modified() ? $product->get_date_modified()->date('c') : '',
                'status' => $product->get_status(),
                'featured' => $product->is_featured(),
                'catalog_visibility' => $product->get_catalog_visibility(),
                'description' => $product->get_description(),
                'short_description' => $product->get_short_description(),
                'sku' => $product->get_sku(),
                'purchasable' => $product->is_purchasable(),
                'virtual' => $product->is_virtual(),
                'downloadable' => $product->is_downloadable(),
                'manage_stock' => $product->get_manage_stock(),
                'backorders_allowed' => $product->backorders_allowed(),
                'weight' => $product->get_weight(),
                'dimensions' => array(
                    'length' => $product->get_length(),
                    'width' => $product->get_width(),
                    'height' => $product->get_height(),
                ),
                'shipping_required' => $product->needs_shipping(),
                'tags' => array_map(function($term) {
                    return array(
                        'id' => $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                    );
                }, get_the_terms(get_the_ID(), 'product_tag') ?: array()),
            ));
        }
        
        return $data;
    }
    
    /**
     * Buscar productos en subcategorías
     */
    private static function search_subcategories($products, $category_id, $limit, $random, $user_level) {
        $existing_ids = array_column($products, 'id');
        
        $args = array(
            'post_type'      => 'product',
            'posts_per_page' => $limit * 4,
            'post_status'    => 'publish',
            'post__not_in'   => $existing_ids,
            'tax_query'      => array(
                array(
                    'taxonomy' => 'product_cat',
                    'field'    => 'term_id',
                    'terms'    => $category_id,
                    'include_children' => true,
                ),
            ),
            'meta_query'     => array(
                array(
                    'key'     => '_stock_status',
                    'value'   => 'instock',
                    'compare' => '=',
                ),
            ),
            'orderby'        => 'date',
            'order'          => 'DESC',
        );
        
        $query = new WP_Query($args);
        
        if ($query->have_posts()) {
            while ($query->have_posts() && count($products) < $limit) {
                $query->the_post();
                $product_data = self::process_product(get_the_ID(), $user_level, false);
                if ($product_data) {
                    $products[] = $product_data;
                }
            }
            wp_reset_postdata();
        }
        
        return $products;
    }
    
    /**
     * Agregar segunda categoría para compact_pair
     */
    private static function add_second_category($response, $section_data, $limit, $random, $user_level, $lang = 'es') {
        $category_id_2 = intval($section_data['category_id_2']);
        $category_2 = get_term($category_id_2, 'product_cat');
        
        if (!$category_2 || is_wp_error($category_2)) {
            return $response;
        }
        
        // Translate second category name if needed
        $category_name_2 = $category_2->name;
        if ($lang !== 'es' && function_exists('starter_translate_category')) {
            $translated_cat = starter_translate_category(
                array('id' => $category_id_2, 'name' => $category_2->name, 'description' => $category_2->description),
                $lang
            );
            $category_name_2 = $translated_cat['name'];
        }
        
        $products_2 = self::query_products($category_id_2, $limit, $random, $user_level);
        
        // Translate products of second category
        if ($lang !== 'es' && function_exists('starter_translate_product')) {
            if (function_exists('starter_preload_category_translation_meta')) {
                starter_preload_category_translation_meta($products_2);
            }
            $products_2 = array_map(function($p) use ($lang) {
                return starter_translate_product($p, $lang);
            }, $products_2);
        }
        
        // Determine title_2 and subtitle_2 based on language
        $title_2 = !empty($section_data['title_2']) ? $section_data['title_2'] : $category_name_2;
        $subtitle_2 = $section_data['subtitle_2'] ?? '';
        if ($lang !== 'es') {
            if (!empty($section_data['title_2_en'])) {
                $title_2 = $section_data['title_2_en'];
            } elseif (empty($section_data['title_2'])) {
                $title_2 = $category_name_2;
            }
            if (!empty($section_data['subtitle_2_en'])) {
                $subtitle_2 = $section_data['subtitle_2_en'];
            }
        }
        
        $response['category_id_2'] = $category_id_2;
        $response['category_name_2'] = $category_name_2;
        $response['category_slug_2'] = $category_2->slug;
        $response['title_2'] = $title_2;
        $response['subtitle_2'] = $subtitle_2;
        $response['products_2'] = $products_2;
        
        return $response;
    }
}
