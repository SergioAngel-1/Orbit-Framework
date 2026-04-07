<?php
/**
 * Banners - Registro del Custom Post Type
 * 
 * Este archivo contiene la definición y registro del tipo de contenido personalizado
 * para los banners del sitio.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registrar Custom Post Type para Banners
 */
function starter_register_banner_post_type() {
    $labels = array(
        'name'               => 'Banners',
        'singular_name'      => 'Banner',
        'menu_name'          => 'Banners',
        'name_admin_bar'     => 'Banner',
        'add_new'            => 'Añadir nuevo',
        'add_new_item'       => 'Añadir nuevo Banner',
        'new_item'           => 'Nuevo Banner',
        'edit_item'          => 'Editar Banner',
        'view_item'          => 'Ver Banner',
        'all_items'          => 'Todos los Banners',
        'search_items'       => 'Buscar Banners',
        'parent_item_colon'  => 'Banner padre:',
        'not_found'          => 'No se encontraron banners',
        'not_found_in_trash' => 'No se encontraron banners en la papelera'
    );

    $args = array(
        'labels'             => $labels,
        'public'             => true,
        'publicly_queryable' => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'query_var'          => true,
        'rewrite'            => array('slug' => 'banner'),
        'capability_type'    => 'post',
        'has_archive'        => false,
        'hierarchical'       => false,
        'menu_position'      => 20,
        'menu_icon'          => 'dashicons-images-alt2',
        'supports'           => array('title'), 
        'show_in_rest'       => true,
    );

    register_post_type('banner', $args);
}
add_action('init', 'starter_register_banner_post_type');

/**
 * Verificar si ya existe un banner del tipo seleccionado
 */
function check_existing_banner_type() {
    // Solo ejecutar en la pantalla de edición de banners
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'banner') {
        return;
    }
    
    // Verificar si se está editando un banner existente
    if (isset($_GET['post'])) {
        $post_id = intval($_GET['post']);
        $current_type = get_post_meta($post_id, '_banner_type', true);
        
        // Si no tiene tipo asignado, no hacer nada
        if (empty($current_type)) {
            return;
        }
        
        // Mostrar aviso si es un tipo único
        if ($current_type === 'middle' || $current_type === 'bottom' || $current_type === 'landing_toures' || $current_type === 'experience_toures') {
            // Contar cuántos banners hay de este tipo
            $args = array(
                'post_type' => 'banner',
                'posts_per_page' => -1,
                'meta_query' => array(
                    array(
                        'key' => '_banner_type',
                        'value' => $current_type,
                        'compare' => '=',
                    ),
                ),
                'post__not_in' => array($post_id), // Excluir el banner actual
            );
            
            $existing_banners = get_posts($args);
            
            if (!empty($existing_banners)) {
                add_action('admin_notices', function() use ($current_type) {
                    echo '<div class="notice notice-warning is-dismissible"><p>';
                    echo sprintf('Ya existe otro banner de tipo "%s". Al guardar este banner, el otro podría quedar inactivo.', esc_html($current_type));
                    echo '</p></div>';
                });
            }
        }
    }
}
add_action('admin_init', 'check_existing_banner_type');
