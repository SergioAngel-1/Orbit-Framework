<?php
/**
 * Endpoints de API para productos de membresía
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Obtener todos los productos de membresía disponibles
 */
function starter_api_get_membership_products($request) {
    $products = starter_get_membership_products();
    
    // Agregar información de acceso del usuario si está logueado
    $user_id = function_exists('starter_get_jwt_user_id') ? starter_get_jwt_user_id() : get_current_user_id();
    $user_level = $user_id ? starter_get_user_membership_level($user_id) : 0;
    
    foreach ($products as &$product) {
        $product['user_can_purchase'] = true;
        $product['is_current_level'] = $user_level === $product['membership_level'];
        $product['is_upgrade'] = $user_level > 0 && $product['membership_level'] > $user_level;
        $product['is_downgrade'] = $user_level > 0 && $product['membership_level'] < $user_level;
        
        // Verificar si puede comprar
        if ($user_id) {
            $can_purchase = starter_can_user_purchase_membership($user_id, $product['membership_level']);
            $product['user_can_purchase'] = $can_purchase['can_purchase'];
            $product['purchase_message'] = $can_purchase['reason'] ?? '';
        }
    }
    
    return new WP_REST_Response([
        'success' => true,
        'data' => [
            'products' => $products,
            'user_level' => $user_level,
            'is_logged_in' => $user_id > 0
        ]
    ], 200);
}

/**
 * Obtener niveles de membresía
 * Incluye icon_url para imágenes de iconos en el frontend
 * Incluye información de elegibilidad por antigüedad si el usuario está autenticado
 * Incluye has_product para indicar si existe un producto WC asociado al nivel
 */
function starter_api_get_membership_levels($request) {
    $levels = Starter_Memberships::get_all_membership_levels();
    
    // Detectar idioma solicitado
    $lang = function_exists('starter_get_request_lang')
        ? starter_get_request_lang($request->get_param('lang'))
        : 'es';
    
    // Mapeo de niveles a URLs de iconos (imágenes en el frontend)
    $icon_urls = starter_get_membership_icon_urls();
    
    // Obtener días de antigüedad del usuario actual (si está autenticado)
    $user_id = function_exists('starter_get_jwt_user_id') ? starter_get_jwt_user_id() : get_current_user_id();
    $user_registration_days = $user_id ? starter_get_user_registration_days($user_id) : 0;
    
    // Obtener productos de membresía existentes para saber qué niveles tienen producto
    $membership_products = starter_get_membership_products();
    $levels_with_products = [];
    $product_info_by_level = [];
    foreach ($membership_products as $product) {
        $product_id = $product['id'];
        $levels_with_products[] = $product['membership_level'];
        
        // Obtener campos adicionales del producto
        $product_min_registration_days = intval(get_post_meta($product_id, '_membership_min_registration_days', true));
        $product_renewal_period = get_post_meta($product_id, '_membership_renewal_period', true) ?: 'monthly';
        
        // Obtener imagen del producto WC
        $product_image_url = $product['image'] ?? '';
        
        // Traducir nombre del producto si es necesario
        $translated_product_name = $product['name'];
        if ($lang !== 'es') {
            $name_translated = get_post_meta($product_id, "_name_{$lang}", true);
            if (!empty($name_translated)) {
                $translated_product_name = $name_translated;
            }
        }
        
        $product_info_by_level[$product['membership_level']] = [
            'product_id' => $product_id,
            'product_name' => $translated_product_name,
            'product_price' => floatval($product['price']),
            'product_regular_price' => floatval($product['regular_price']),
            'product_sale_price' => ($product['sale_price'] !== '' && $product['sale_price'] !== null) ? floatval($product['sale_price']) : null,
            'product_permalink' => $product['permalink'],
            'product_image' => $product_image_url,
            'monthly_points' => intval($product['monthly_points']),
            'min_registration_days' => $product_min_registration_days,
            'renewal_period' => $product_renewal_period,
            'duration_days' => $product['duration_days']
        ];
    }
    
    $formatted_levels = [];
    foreach ($levels as $level_id => $level) {
        $has_product = in_array($level_id, $levels_with_products);
        
        // Usar antigüedad del producto si existe, sino usar la del nivel
        $min_registration_days = $level['min_registration_days'] ?? 0;
        if ($has_product && isset($product_info_by_level[$level_id]['min_registration_days'])) {
            $min_registration_days = $product_info_by_level[$level_id]['min_registration_days'];
        }
        $meets_seniority = $user_registration_days >= $min_registration_days;
        
        // Usar imagen del producto WC si existe, sino usar el fallback estático
        $icon_url = $icon_urls[$level_id] ?? '';
        if ($has_product && !empty($product_info_by_level[$level_id]['product_image'])) {
            $icon_url = $product_info_by_level[$level_id]['product_image'];
        }
        
        // Traducir nombre y descripción del nivel si es necesario
        // El name viene del nombre del producto WC, así que usamos su _name_{lang} meta
        $level_name = $level['name'];
        $level_description = $level['description'];
        if ($lang !== 'es' && !empty($level['product_id'])) {
            $name_translated = get_post_meta($level['product_id'], "_name_{$lang}", true);
            if (!empty($name_translated)) {
                $level_name = $name_translated;
            }
            $desc_translated = get_post_meta($level['product_id'], "_short_description_{$lang}", true);
            if (empty($desc_translated)) {
                $desc_translated = get_post_meta($level['product_id'], "_description_{$lang}", true);
            }
            if (!empty($desc_translated)) {
                $level_description = $desc_translated;
            }
        }
        
        $level_data = [
            'id' => $level_id,
            'level' => $level_id,
            'name' => $level_name,
            'slug' => $level['slug'],
            'slug_en' => $level['slug_en'] ?? '',
            'icon' => $level['icon'],
            'icon_url' => $icon_url,
            'color' => $level['color'],
            'price_min' => $level['price_min'],
            'price_max' => $level['price_max'],
            'monthly_points' => $level['monthly_points'],
            'description' => $level_description,
            'is_free' => $level_id === 0,
            'purchasable' => $level['purchasable'] ?? ($level_id > 0 && $level_id < 5),
            'admin_only' => $level['admin_only'] ?? false,
            'min_registration_days' => $min_registration_days,
            'user_meets_seniority' => $meets_seniority,
            'user_days_until_eligible' => $meets_seniority ? 0 : max(0, $min_registration_days - $user_registration_days),
            'has_product' => $has_product
        ];
        
        // Agregar info del producto si existe
        if ($has_product && isset($product_info_by_level[$level_id])) {
            $level_data['product_info'] = $product_info_by_level[$level_id];
        }
        
        $formatted_levels[] = $level_data;
    }
    
    return new WP_REST_Response([
        'success' => true,
        'data' => $formatted_levels,
        'user_registration_days' => $user_registration_days
    ], 200);
}

/**
 * Obtener URLs de iconos de membresía
 * Centraliza la configuración de iconos para consistencia
 * 
 * @return array Mapeo de nivel => URL del icono
 */
function starter_get_membership_icon_urls() {
    // Base path para los iconos (relativo al frontend)
    $base_path = '/assets/images/Iconos de Membresía/';
    
    // Mapeo correcto según niveles de membresía:
    // 0 = Zanahoria (gratis), 1 = Bronce, 2 = Plateada, 3 = Dorada/Oro, 4 = Diamante, 5 = Antigüedad
    return [
        0 => $base_path . 'Membresía Zanahoria.webp',
        1 => $base_path . 'Membresía Zanahoria Bronce.webp',
        2 => $base_path . 'Membresía Zanahoria Plateada.webp',
        3 => $base_path . 'Mebresía Zanahoria Oro .webp',
        4 => $base_path . 'Membresía Zanahoria Diamante.webp',
        5 => $base_path . 'Membresía Zanahoria Antiguedad.webp',
    ];
}
