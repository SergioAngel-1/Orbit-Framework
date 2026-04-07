<?php
/**
 * Funciones helper para la página de administración de grillas por membresía
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Obtener grillas guardadas por nivel
 */
function fipg_get_grids_by_level() {
    return get_option('starter_promotional_grids_by_level', array());
}

/**
 * Guardar grillas por nivel
 */
function fipg_save_grids_by_level($grids) {
    update_option('starter_promotional_grids_by_level', $grids);
    
    // Invalidar caché de productos (las grillas afectan visualización de productos)
    if (class_exists('Starter_WC_Cache_Manager')) {
        Starter_WC_Cache_Manager::invalidate_by_route_type('products');
    }
}

/**
 * Obtener exclusiones para un nivel
 */
function fipg_get_excluded_grids($level) {
    return get_option('starter_excluded_grids_level_' . $level, array());
}

/**
 * Guardar exclusiones para un nivel
 */
function fipg_save_excluded_grids($level, $excluded) {
    update_option('starter_excluded_grids_level_' . $level, $excluded);
}

/**
 * Limpiar todas las exclusiones
 */
function fipg_clear_all_exclusions() {
    for ($lvl = 0; $lvl <= 5; $lvl++) {
        delete_option('starter_excluded_grids_level_' . $lvl);
    }
}

/**
 * Sincronizar grillas desde CPT al nuevo sistema
 * Importa todas las grillas del CPT que no existan ya en el nuevo sistema
 */
function fipg_sync_from_cpt() {
    $grids_by_level = fipg_get_grids_by_level();
    $count = 0;
    $existing_cpt_ids = array();
    
    // Recopilar IDs de CPT ya migrados
    foreach ($grids_by_level as $level => $grids) {
        if (!is_array($grids)) continue;
        foreach ($grids as $grid) {
            if (!empty($grid['migrated_from_cpt'])) {
                $existing_cpt_ids[] = intval($grid['migrated_from_cpt']);
            }
        }
    }
    
    $query = new WP_Query(array(
        'post_type' => 'promotional_grid',
        'posts_per_page' => -1,
        'post_status' => 'publish',
    ));
    
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            
            // Saltar si ya fue migrado
            if (in_array($post_id, $existing_cpt_ids)) {
                continue;
            }
            
            $is_active = get_post_meta($post_id, '_promotional_grid_active', true);
            $category_id = get_post_meta($post_id, '_promotional_grid_category', true);
            $min_membership = get_post_meta($post_id, '_promotional_grid_min_membership', true);
            $min_membership = $min_membership !== '' ? intval($min_membership) : 0;
            $membership_mode = get_post_meta($post_id, '_promotional_grid_membership_mode', true) ?: 'cascade';
            $products = get_post_meta($post_id, '_promotional_grid_products', true);
            
            $grid_data = array(
                'id' => 'grid_cpt_' . $post_id,
                'title' => get_the_title(),
                'type' => empty($category_id) ? 'default' : 'category',
                'category_id' => !empty($category_id) ? intval($category_id) : null,
                'products' => is_array($products) ? array_map('intval', array_filter($products)) : array(),
                'enabled' => !empty($is_active),
                'order' => 0,
                'visibility_mode' => $membership_mode,
                'migrated_from_cpt' => $post_id,
            );
            
            if (!isset($grids_by_level[$min_membership])) {
                $grids_by_level[$min_membership] = array();
            }
            $grids_by_level[$min_membership][] = $grid_data;
            $count++;
        }
        wp_reset_postdata();
    }
    
    // Guardar las grillas sincronizadas
    if ($count > 0) {
        fipg_save_grids_by_level($grids_by_level);
    }
    
    return array('status' => 'synced', 'count' => $count);
}

/**
 * Migrar grillas del CPT al nuevo formato (wrapper para compatibilidad)
 */
function fipg_migrate_from_cpt() {
    return fipg_sync_from_cpt();
}

/**
 * Obtener grillas heredadas para un nivel
 * Los niveles SUPERIORES heredan de los inferiores (solo si visibility_mode = cascade)
 * Ejemplo: Dorada (3) define grilla con cascade → Diamante (4) y Antigüedad (5) la heredan
 * 
 * Respeta el modo de visibilidad:
 * - cascade: Se hereda a niveles superiores
 * - exact: NO se hereda, solo visible en su nivel
 */
function fipg_get_inherited_grids($level, $grids_by_level) {
    $inherited = array();
    $seen_keys = array();
    
    // Iterar desde nivel 0 hacia arriba hasta el nivel actual (exclusivo)
    for ($lvl = 0; $lvl < $level; $lvl++) {
        if (isset($grids_by_level[$lvl]) && is_array($grids_by_level[$lvl])) {
            foreach ($grids_by_level[$lvl] as $grid) {
                // Solo heredar si el modo es cascade
                $visibility_mode = $grid['visibility_mode'] ?? 'cascade';
                if ($visibility_mode === 'exact') {
                    continue; // No heredar grillas con modo exacto
                }
                
                // Crear clave única basada en título y tipo para evitar duplicados
                $key = $grid['title'] . '_' . $grid['type'] . '_' . ($grid['category_id'] ?? '');
                
                if (in_array($key, $seen_keys)) {
                    continue; // Saltar duplicados
                }
                $seen_keys[] = $key;
                
                $grid['from_level'] = $lvl;
                $inherited[] = $grid;
            }
        }
    }
    
    return $inherited;
}

/**
 * Obtener exclusiones en cascada para un nivel
 * Las exclusiones de niveles inferiores se propagan hacia arriba
 * Ejemplo: Si Bronce excluye una grilla, Plateada, Dorada, etc. también la excluyen
 */
function fipg_get_cascade_exclusions($level) {
    $cascade_excluded = array();
    
    // Iterar desde nivel 0 hacia arriba hasta el nivel actual (exclusivo)
    for ($lower_lvl = 0; $lower_lvl < $level; $lower_lvl++) {
        $exc = fipg_get_excluded_grids($lower_lvl);
        if (is_array($exc)) {
            $cascade_excluded = array_merge($cascade_excluded, $exc);
        }
    }
    
    return array_unique($cascade_excluded);
}

/**
 * Filtrar grillas heredadas excluyendo las que no son accesibles
 */
function fipg_filter_inherited_grids($inherited_grids, $level, $cascade_excluded) {
    $filtered = array();
    
    foreach ($inherited_grids as $grid) {
        // Excluir si está en la lista de exclusiones en cascada
        if (in_array($grid['id'], $cascade_excluded)) {
            continue;
        }
        
        // Si es una grilla de categoría, verificar que la categoría sea accesible
        if ($grid['type'] === 'category' && !empty($grid['category_id'])) {
            $cat_min_level = intval(get_term_meta($grid['category_id'], '_min_membership_level', true));
            if ($cat_min_level > $level) {
                continue;
            }
        }
        
        $filtered[] = $grid;
    }
    
    return $filtered;
}

/**
 * Obtener nombre de producto por ID
 */
function fipg_get_product_name($product_id) {
    $product = wc_get_product($product_id);
    return $product ? $product->get_name() : 'Producto no encontrado';
}

/**
 * Obtener nombre de categoría por ID
 */
function fipg_get_category_name($category_id) {
    $term = get_term($category_id, 'product_cat');
    return (!is_wp_error($term) && $term) ? $term->name : 'Categoría no encontrada';
}
