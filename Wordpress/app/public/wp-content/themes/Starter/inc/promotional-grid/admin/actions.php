<?php
/**
 * Procesamiento de acciones del formulario de administración de grillas
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Procesar acciones POST del formulario
 */
function fipg_process_admin_actions() {
    if (!isset($_POST['action']) || !current_user_can('manage_options')) {
        return;
    }
    
    // Verificar nonce
    if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'starter_promotional_grids_action')) {
        return;
    }
    
    $action = sanitize_text_field($_POST['action']);
    
    switch ($action) {
        case 'save_grids_by_level':
            fipg_handle_save_grids();
            break;
            
        case 'migrate_from_cpt':
            fipg_handle_migration();
            break;
            
        case 'cascade_update':
            fipg_handle_cascade_update();
            break;
            
        case 'remove_duplicates':
            fipg_handle_remove_duplicates();
            break;
            
        case 'refresh_grids':
            fipg_handle_refresh_grids();
            break;
            
        case 'sync_from_cpt':
            fipg_handle_sync_from_cpt();
            break;
    }
}

/**
 * Guardar grillas por nivel
 */
function fipg_handle_save_grids() {
    $grids_by_level = array();
    
    // Procesar grillas enviadas
    if (isset($_POST['grids_by_level']) && is_array($_POST['grids_by_level'])) {
        foreach ($_POST['grids_by_level'] as $level => $grids) {
            $level = intval($level);
            $grids_by_level[$level] = array();
            
            if (is_array($grids)) {
                foreach ($grids as $grid) {
                    $grid_data = array(
                        'id' => sanitize_text_field($grid['id'] ?? fipg_generate_grid_id()),
                        'title' => sanitize_text_field($grid['title'] ?? ''),
                        'type' => sanitize_text_field($grid['type'] ?? 'default'),
                        'category_id' => !empty($grid['category_id']) ? intval($grid['category_id']) : null,
                        'products' => isset($grid['products']) ? array_map('intval', array_filter((array)$grid['products'])) : array(),
                        'enabled' => !empty($grid['enabled']),
                        'order' => intval($grid['order'] ?? 0),
                        'visibility_mode' => sanitize_text_field($grid['visibility_mode'] ?? 'cascade'),
                    );
                    
                    // Solo guardar grillas con al menos un producto
                    if (!empty($grid_data['products'])) {
                        $grids_by_level[$level][] = $grid_data;
                    }
                }
            }
        }
    }
    
    // Procesar exclusiones - ahora elimina las grillas de su nivel original
    if (isset($_POST['excluded_grids']) && is_array($_POST['excluded_grids'])) {
        foreach ($_POST['excluded_grids'] as $level => $excluded_ids) {
            $excluded_ids = array_map('sanitize_text_field', (array)$excluded_ids);
            
            // Buscar y eliminar cada grilla excluida de su nivel original
            foreach ($excluded_ids as $grid_id) {
                // Buscar en qué nivel está esta grilla
                foreach ($grids_by_level as $lvl => &$grids) {
                    foreach ($grids as $idx => $grid) {
                        if ($grid['id'] === $grid_id) {
                            unset($grids_by_level[$lvl][$idx]);
                            // Reindexar array
                            $grids_by_level[$lvl] = array_values($grids_by_level[$lvl]);
                            break 2;
                        }
                    }
                }
            }
        }
    }
    
    // Limpiar exclusiones antiguas (ya no se usan)
    fipg_clear_all_exclusions();
    
    fipg_save_grids_by_level($grids_by_level);
    
    add_settings_error(
        'starter_promotional_grids',
        'grids_saved',
        'Grillas guardadas correctamente.',
        'success'
    );
}

/**
 * Manejar migración desde CPT
 */
function fipg_handle_migration() {
    $result = fipg_migrate_from_cpt();
    
    if ($result['status'] === 'migrated') {
        add_settings_error(
            'starter_promotional_grids',
            'migration_success',
            sprintf('Se migraron %d grillas del sistema anterior.', $result['count']),
            'success'
        );
    } else {
        add_settings_error(
            'starter_promotional_grids',
            'migration_skipped',
            'Las grillas ya fueron migradas anteriormente.',
            'info'
        );
    }
}

/**
 * Eliminar grillas duplicadas de todos los niveles
 * Mantiene solo la grilla del nivel más bajo (original)
 */
function fipg_remove_duplicate_grids() {
    $grids_by_level = fipg_get_grids_by_level();
    $seen_keys = array();
    $removed_count = 0;
    
    // Iterar desde nivel 0 hacia arriba
    // Mantener la primera ocurrencia (nivel más bajo)
    for ($level = 0; $level <= 5; $level++) {
        if (!isset($grids_by_level[$level]) || !is_array($grids_by_level[$level])) {
            continue;
        }
        
        $cleaned = array();
        foreach ($grids_by_level[$level] as $grid) {
            $key = $grid['title'] . '_' . $grid['type'] . '_' . ($grid['category_id'] ?? '');
            
            if (in_array($key, $seen_keys)) {
                $removed_count++;
                continue; // Duplicado, saltar
            }
            
            $seen_keys[] = $key;
            $cleaned[] = $grid;
        }
        
        $grids_by_level[$level] = $cleaned;
    }
    
    if ($removed_count > 0) {
        fipg_save_grids_by_level($grids_by_level);
    }
    
    return $removed_count;
}

/**
 * Actualizar grillas en cascada
 * Propaga grillas desde niveles SUPERIORES hacia INFERIORES
 * Solo propaga si TODOS los productos de la grilla están disponibles para el nivel destino
 */
function fipg_handle_cascade_update() {
    $grids_by_level = fipg_get_grids_by_level();
    $propagated_count = 0;
    $debug_info = array();
    
    error_log('=== INICIO CASCADE UPDATE ===');
    error_log('Grillas por nivel: ' . print_r(array_map('count', $grids_by_level), true));
    
    // Recopilar grillas ORIGINALES (no propagadas) por nivel
    $original_grids = array();
    for ($lvl = 0; $lvl <= 5; $lvl++) {
        $original_grids[$lvl] = array();
        if (isset($grids_by_level[$lvl]) && is_array($grids_by_level[$lvl])) {
            foreach ($grids_by_level[$lvl] as $grid) {
                // Solo considerar grillas originales (no propagadas)
                if (empty($grid['cascaded_from'])) {
                    $original_grids[$lvl][] = $grid;
                    error_log("Nivel $lvl - Grilla original: " . $grid['title'] . " (productos: " . count($grid['products'] ?? array()) . ")");
                }
            }
        }
    }
    
    // Reconstruir grillas empezando solo con las originales
    $new_grids = array();
    for ($lvl = 0; $lvl <= 5; $lvl++) {
        $new_grids[$lvl] = $original_grids[$lvl];
    }
    
    // Propagar desde niveles SUPERIORES hacia INFERIORES
    // Nivel 5 (Antigüedad) → Nivel 0 (Zanahoria)
    for ($source_level = 5; $source_level >= 1; $source_level--) {
        foreach ($original_grids[$source_level] as $grid) {
            // Solo propagar grillas con modo cascade
            $visibility_mode = $grid['visibility_mode'] ?? 'cascade';
            if ($visibility_mode === 'exact') {
                continue;
            }
            
            $products = $grid['products'] ?? array();
            if (empty($products)) {
                continue;
            }
            
            // Propagar a niveles INFERIORES
            for ($target_level = $source_level - 1; $target_level >= 0; $target_level--) {
                error_log("  Intentando propagar '{$grid['title']}' de nivel $source_level a nivel $target_level");
                
                // Verificar si TODOS los productos están disponibles para este nivel
                $all_products_available = true;
                foreach ($products as $product_id) {
                    $is_available = fipg_is_product_available_for_level($product_id, $target_level);
                    error_log("    Producto $product_id disponible para nivel $target_level: " . ($is_available ? 'SI' : 'NO'));
                    if (!$is_available) {
                        $all_products_available = false;
                        break;
                    }
                }
                
                if (!$all_products_available) {
                    error_log("    SALTANDO: No todos los productos disponibles para nivel $target_level");
                    continue; // No propagar si algún producto no está disponible
                }
                
                // Verificar si ya existe una grilla con el mismo título en el nivel destino
                $grid_exists = false;
                foreach ($new_grids[$target_level] as $existing) {
                    if ($existing['title'] === $grid['title']) {
                        $grid_exists = true;
                        error_log("    SALTANDO: Ya existe grilla con título '{$grid['title']}' en nivel $target_level");
                        break;
                    }
                }
                
                if (!$grid_exists) {
                    $new_grid = $grid;
                    $new_grid['id'] = $grid['id'] . '_cascade_' . $target_level;
                    $new_grid['cascaded_from'] = $source_level;
                    $new_grids[$target_level][] = $new_grid;
                    $propagated_count++;
                    error_log("    PROPAGADA: Grilla '{$grid['title']}' agregada a nivel $target_level");
                }
            }
        }
    }
    
    error_log('=== FIN CASCADE UPDATE - Propagadas: ' . $propagated_count . ' ===');
    
    // Guardar las grillas actualizadas
    fipg_save_grids_by_level($new_grids);
    
    if ($propagated_count > 0) {
        add_settings_error(
            'starter_promotional_grids',
            'cascade_success',
            sprintf('Se propagaron %d grillas en cascada a los niveles inferiores.', $propagated_count),
            'success'
        );
    } else {
        add_settings_error(
            'starter_promotional_grids',
            'cascade_none',
            'No hay grillas nuevas para propagar. Las grillas ya están sincronizadas o los productos no están disponibles para niveles inferiores.',
            'info'
        );
    }
}

/**
 * Verificar si un producto está disponible para un nivel de membresía
 * Un producto está disponible si su categoría tiene nivel mínimo <= al nivel consultado
 */
function fipg_is_product_available_for_level($product_id, $level) {
    $product = wc_get_product($product_id);
    if (!$product) {
        return false;
    }
    
    // Obtener categorías del producto
    $category_ids = $product->get_category_ids();
    if (empty($category_ids)) {
        return true; // Sin categoría = disponible para todos
    }
    
    // Verificar el nivel mínimo de cada categoría
    foreach ($category_ids as $cat_id) {
        $cat_min_level = intval(get_term_meta($cat_id, '_min_membership_level', true));
        // Si alguna categoría requiere un nivel mayor, el producto no está disponible
        if ($cat_min_level > $level) {
            return false;
        }
    }
    
    return true;
}

/**
 * Handler para eliminar duplicados
 */
function fipg_handle_remove_duplicates() {
    $removed = fipg_remove_duplicate_grids();
    
    if ($removed > 0) {
        add_settings_error(
            'starter_promotional_grids',
            'duplicates_removed',
            sprintf('Se eliminaron %d grillas duplicadas.', $removed),
            'success'
        );
    } else {
        add_settings_error(
            'starter_promotional_grids',
            'no_duplicates',
            'No se encontraron grillas duplicadas.',
            'info'
        );
    }
}

/**
 * Actualizar y normalizar todas las grillas
 * - Asegura que todas tengan visibility_mode
 * - Elimina duplicados
 * - Invalida cachés
 */
function fipg_handle_refresh_grids() {
    $grids_by_level = fipg_get_grids_by_level();
    $updated_count = 0;
    
    // Normalizar todas las grillas
    for ($level = 0; $level <= 5; $level++) {
        if (!isset($grids_by_level[$level]) || !is_array($grids_by_level[$level])) {
            continue;
        }
        
        foreach ($grids_by_level[$level] as &$grid) {
            // Asegurar que visibility_mode existe
            if (!isset($grid['visibility_mode'])) {
                $grid['visibility_mode'] = 'cascade';
                $updated_count++;
            }
            
            // Asegurar que enabled existe
            if (!isset($grid['enabled'])) {
                $grid['enabled'] = true;
                $updated_count++;
            }
        }
    }
    
    // Guardar cambios
    fipg_save_grids_by_level($grids_by_level);
    
    // Limpiar duplicados
    $removed = fipg_remove_duplicate_grids();
    
    // Invalidar caché de productos (las grillas afectan visualización de productos)
    if (class_exists('Starter_WC_Cache_Manager')) {
        Starter_WC_Cache_Manager::invalidate_by_route_type('products');
    }
    
    add_settings_error(
        'starter_promotional_grids',
        'refresh_success',
        sprintf('Grillas actualizadas. %d campos normalizados, %d duplicados eliminados.', $updated_count, $removed),
        'success'
    );
}

/**
 * Sincronizar grillas desde el CPT al nuevo sistema
 */
function fipg_handle_sync_from_cpt() {
    $result = fipg_sync_from_cpt();
    
    if ($result['count'] > 0) {
        add_settings_error(
            'starter_promotional_grids',
            'sync_success',
            sprintf('Se importaron %d grillas desde el sistema CPT.', $result['count']),
            'success'
        );
    } else {
        add_settings_error(
            'starter_promotional_grids',
            'sync_none',
            'No hay grillas nuevas para importar desde el CPT.',
            'info'
        );
    }
}
