<?php
/**
 * Banners - Columnas de Administración
 * 
 * Este archivo contiene la implementación de columnas personalizadas
 * para la lista de banners en el panel de administración.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Añadir columnas personalizadas a la lista de banners
 */
function banner_custom_columns($columns) {
    $new_columns = array();
    
    // Insertar columnas después del título
    foreach ($columns as $key => $value) {
        $new_columns[$key] = $value;
        
        if ($key === 'title') {
            $new_columns['banner_type'] = 'Tipo';
            $new_columns['banner_image'] = 'Imagen';
            $new_columns['banner_order'] = 'Orden';
        }
    }
    
    return $new_columns;
}
add_filter('manage_banner_posts_columns', 'banner_custom_columns');

/**
 * Mostrar contenido de columnas personalizadas
 */
function banner_custom_column_content($column, $post_id) {
    switch ($column) {
        case 'banner_type':
            $type = get_post_meta($post_id, '_banner_type', true);
            $type_labels = array(
                'main' => 'Principal (Carrusel)',
                'middle' => 'Intermedio',
                'bottom' => 'Inferior (Redes Sociales)',
                'landing_toures' => 'Landing Toures (Carrusel)',
                'experience_toures' => 'Experiencia Toures (Carrusel)',
            );
            
            echo isset($type_labels[$type]) ? esc_html($type_labels[$type]) : esc_html($type);
            break;
            
        case 'banner_image':
            $image = get_post_meta($post_id, '_banner_image', true);
            
            if (!empty($image)) {
                echo '<img src="' . esc_url($image) . '" alt="Banner" style="max-width: 100px; max-height: 60px;" />';
            } else if (has_post_thumbnail($post_id)) {
                echo get_the_post_thumbnail($post_id, array(100, 60));
            } else {
                echo '—';
            }
            break;
            
        case 'banner_order':
            $order = get_post_meta($post_id, '_banner_order', true);
            
            if ($order !== '') {
                echo esc_html($order);
            } else {
                echo '0';
            }
            break;
    }
}
add_action('manage_banner_posts_custom_column', 'banner_custom_column_content', 10, 2);

/**
 * Hacer las columnas ordenables
 */
function banner_sortable_columns($columns) {
    $columns['banner_type'] = 'banner_type';
    $columns['banner_order'] = 'banner_order';
    
    return $columns;
}
add_filter('manage_edit-banner_sortable_columns', 'banner_sortable_columns');

/**
 * Personalizar la consulta para ordenar por las columnas personalizadas
 */
function banner_custom_orderby($query) {
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }
    
    $orderby = $query->get('orderby');
    
    if ('banner_type' === $orderby) {
        $query->set('meta_key', '_banner_type');
        $query->set('orderby', 'meta_value');
    }
    
    if ('banner_order' === $orderby) {
        $query->set('meta_key', '_banner_order');
        $query->set('orderby', 'meta_value_num');
    }
}
add_action('pre_get_posts', 'banner_custom_orderby');
