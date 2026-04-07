<?php
/**
 * Popups - Columnas de Administración
 * 
 * Este archivo contiene la personalización de las columnas
 * en el listado de popups del panel de administración.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Añadir columnas personalizadas al listado de popups
 */
function starter_popup_add_columns($columns) {
    $new_columns = array();
    
    foreach ($columns as $key => $value) {
        $new_columns[$key] = $value;
        
        // Añadir columnas después del título
        if ($key === 'title') {
            $new_columns['popup_type'] = 'Tipo';
            $new_columns['popup_active'] = 'Estado';
            $new_columns['popup_priority'] = 'Prioridad';
        }
    }
    
    return $new_columns;
}
add_filter('manage_starter_popup_posts_columns', 'starter_popup_add_columns');

/**
 * Mostrar contenido de las columnas personalizadas
 */
function starter_popup_column_content($column, $post_id) {
    switch ($column) {
        case 'popup_type':
            $type = get_post_meta($post_id, '_popup_type', true);
            $popup_types = starter_get_popup_types();
            
            if (isset($popup_types[$type])) {
                echo '<span style="display: inline-flex; align-items: center; gap: 5px;">';
                echo '<span style="font-size: 16px;">' . esc_html($popup_types[$type]['icon']) . '</span>';
                echo '<span>' . esc_html($popup_types[$type]['label']) . '</span>';
                echo '</span>';
            } else {
                echo '<span style="color: #999;">Sin tipo</span>';
            }
            break;
            
        case 'popup_active':
            $active = get_post_meta($post_id, '_popup_active', true);
            
            if ($active === '1' || $active === 'yes') {
                echo '<span style="color: #46b450; font-weight: bold;">✓ Activo</span>';
            } else {
                echo '<span style="color: #999;">Inactivo</span>';
            }
            break;
            
        case 'popup_priority':
            $priority = get_post_meta($post_id, '_popup_priority', true) ?: 10;
            echo '<span style="background: #f0f0f0; padding: 2px 8px; border-radius: 3px;">' . esc_html($priority) . '</span>';
            break;
    }
}
add_action('manage_starter_popup_posts_custom_column', 'starter_popup_column_content', 10, 2);

/**
 * Hacer las columnas ordenables
 */
function starter_popup_sortable_columns($columns) {
    $columns['popup_type'] = 'popup_type';
    $columns['popup_active'] = 'popup_active';
    $columns['popup_priority'] = 'popup_priority';
    return $columns;
}
add_filter('manage_edit-starter_popup_sortable_columns', 'starter_popup_sortable_columns');

/**
 * Ordenar por las columnas personalizadas
 */
function starter_popup_orderby($query) {
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }
    
    if ($query->get('post_type') !== 'starter_popup') {
        return;
    }
    
    $orderby = $query->get('orderby');
    
    switch ($orderby) {
        case 'popup_type':
            $query->set('meta_key', '_popup_type');
            $query->set('orderby', 'meta_value');
            break;
            
        case 'popup_active':
            $query->set('meta_key', '_popup_active');
            $query->set('orderby', 'meta_value');
            break;
            
        case 'popup_priority':
            $query->set('meta_key', '_popup_priority');
            $query->set('orderby', 'meta_value_num');
            break;
    }
}
add_action('pre_get_posts', 'starter_popup_orderby');

/**
 * Añadir filtro por tipo de popup
 */
function starter_popup_add_type_filter() {
    global $typenow;
    
    if ($typenow !== 'starter_popup') {
        return;
    }
    
    $popup_types = starter_get_popup_types();
    $selected = isset($_GET['popup_type_filter']) ? sanitize_text_field($_GET['popup_type_filter']) : '';
    
    echo '<select name="popup_type_filter">';
    echo '<option value="">Todos los tipos</option>';
    
    foreach ($popup_types as $type_key => $type_info) {
        printf(
            '<option value="%s" %s>%s %s</option>',
            esc_attr($type_key),
            selected($selected, $type_key, false),
            esc_html($type_info['icon']),
            esc_html($type_info['label'])
        );
    }
    
    echo '</select>';
}
add_action('restrict_manage_posts', 'starter_popup_add_type_filter');

/**
 * Filtrar por tipo de popup
 */
function starter_popup_filter_by_type($query) {
    global $pagenow, $typenow;
    
    if ($pagenow !== 'edit.php' || $typenow !== 'starter_popup') {
        return;
    }
    
    if (!isset($_GET['popup_type_filter']) || empty($_GET['popup_type_filter'])) {
        return;
    }
    
    $type = sanitize_text_field($_GET['popup_type_filter']);
    
    $meta_query = $query->get('meta_query') ?: array();
    $meta_query[] = array(
        'key' => '_popup_type',
        'value' => $type,
        'compare' => '=',
    );
    
    $query->set('meta_query', $meta_query);
}
add_action('pre_get_posts', 'starter_popup_filter_by_type');
