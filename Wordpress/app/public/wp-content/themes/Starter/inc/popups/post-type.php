<?php
/**
 * Popups - Registro del Custom Post Type
 * 
 * Este archivo contiene la definición y registro del tipo de contenido personalizado
 * para los popups del sitio.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tipos de popup disponibles
 */
function starter_get_popup_types() {
    return array(
        'membership_legacy' => array(
            'label' => 'Membresía por Antigüedad',
            'description' => 'Popup OBLIGATORIO para migración al sistema de membresías',
            'icon' => '🏆',
            'unique' => true,
            'details' => array(
                'title' => 'Plan de Migración - Membresía por Antigüedad (OBLIGATORIO)',
                'conditions' => array(
                    'Usuario registrado ANTES de la fecha de lanzamiento del sistema de membresías',
                    'Usuario NO ha ACEPTADO la membresía (seguirá apareciendo hasta que acepte)',
                ),
                'behavior' => array(
                    'Se muestra SIEMPRE hasta que el usuario ACEPTE',
                    'NO se puede cerrar sin aceptar',
                    'Sin botón X, sin click fuera para cerrar',
                    'Requiere aceptar términos con checkbox',
                    'Es OBLIGATORIO para continuar usando la plataforma',
                ),
                'actions' => array(
                    'ACEPTA → Se le otorga Membresía de Antigüedad gratuita permanente',
                ),
            ),
        ),
        'membership_expiration' => array(
            'label' => 'Recordatorio de Expiración',
            'description' => 'Recordatorio cuando la membresía está por expirar',
            'icon' => '⏰',
            'unique' => true,
            'details' => array(
                'title' => 'Recordatorio de Expiración de Membresía',
                'conditions' => array(
                    'Usuario tiene membresía activa',
                    'Faltan 2 días o menos para que expire',
                ),
                'behavior' => array(
                    'Se muestra una vez al día mientras cumpla condiciones',
                    'Se puede cerrar',
                ),
                'actions' => array(
                    'RENOVAR → Navega a página de membresías',
                    'MÁS TARDE → Cierra el popup',
                ),
            ),
        ),
        'membership_expired' => array(
            'label' => 'Membresía Expirada',
            'description' => 'Aviso cuando la membresía ya expiró',
            'icon' => '⚠️',
            'unique' => true,
            'details' => array(
                'title' => 'Aviso de Membresía Expirada',
                'conditions' => array(
                    'Usuario tenía membresía que ya expiró',
                    'Expiró hace menos de 30 días',
                ),
                'behavior' => array(
                    'Se muestra una vez por semana mientras cumpla condiciones',
                    'Se puede cerrar',
                ),
                'actions' => array(
                    'RENOVAR → Navega a página de membresías',
                    'MÁS TARDE → Cierra el popup',
                ),
            ),
        ),
        'referral_bonus' => array(
            'label' => 'Bonificación por Referido',
            'description' => 'Notificación de membresía Plata por registro con código de referido',
            'icon' => '🎁',
            'unique' => true,
            'details' => array(
                'title' => 'Bonificación de Membresía por Referido',
                'conditions' => array(
                    'Usuario se registró con código de referido válido',
                    'Usuario aún no ha sido notificado del beneficio',
                ),
                'behavior' => array(
                    'Se muestra UNA SOLA VEZ al primer inicio de sesión',
                    'Se puede cerrar',
                ),
                'actions' => array(
                    'VER MI MEMBRESÍA → Navega a página de membresías',
                    'CERRAR → Cierra el popup',
                ),
                'recommended_frequency' => 'Solo una vez (nunca más)',
                'bonus' => '1 mes de membresía Plata gratis',
            ),
        ),
        'login_prompt' => array(
            'label' => 'Invitación a Iniciar Sesión',
            'description' => 'Popup para invitar a usuarios anónimos a registrarse/iniciar sesión',
            'icon' => '🔐',
            'unique' => true,
            'details' => array(
                'title' => 'Invitación a Iniciar Sesión',
                'conditions' => array(
                    'Usuario NO está autenticado (anónimo)',
                ),
                'behavior' => array(
                    'Se muestra una vez por sesión',
                    'Se puede cerrar con X o click fuera',
                    'Solo muestra la imagen (sin botones)',
                    'Soporta retraso configurable antes de aparecer',
                ),
                'actions' => array(
                    'CLICK EN IMAGEN → Redirige a /login',
                    'CERRAR → Cierra el popup',
                ),
                'recommended_frequency' => 'Una vez por sesión',
                'tip' => 'Usa el campo "Retraso" para evitar interrumpir al usuario inmediatamente',
            ),
        ),
        'general' => array(
            'label' => 'Popup General',
            'description' => 'Popup de propósito general con imagen y mensaje',
            'icon' => '📢',
            'unique' => false,
            'details' => array(
                'title' => 'Popup General',
                'conditions' => array(
                    'Según configuración de membresía mínima',
                    'Usuario cumple con nivel de membresía requerido',
                ),
                'behavior' => array(
                    'Frecuencia configurable (siempre, sesión, día, una vez)',
                    'Se puede cerrar',
                    'La imagen puede ser clickeable si tiene URL',
                ),
                'actions' => array(
                    'CERRAR → Cierra el popup',
                    'CLICK EN IMAGEN → Abre URL (si está configurada)',
                ),
            ),
        ),
    );
}

/**
 * Registrar Custom Post Type para Popups
 */
function starter_register_popup_post_type() {
    $labels = array(
        'name'               => 'Popups',
        'singular_name'      => 'Popup',
        'menu_name'          => 'Popups',
        'name_admin_bar'     => 'Popup',
        'add_new'            => 'Añadir nuevo',
        'add_new_item'       => 'Añadir nuevo Popup',
        'new_item'           => 'Nuevo Popup',
        'edit_item'          => 'Editar Popup',
        'view_item'          => 'Ver Popup',
        'all_items'          => 'Todos los Popups',
        'search_items'       => 'Buscar Popups',
        'parent_item_colon'  => 'Popup padre:',
        'not_found'          => 'No se encontraron popups',
        'not_found_in_trash' => 'No se encontraron popups en la papelera'
    );

    $args = array(
        'labels'             => $labels,
        'public'             => false,
        'publicly_queryable' => false,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'query_var'          => true,
        'rewrite'            => false,
        'capability_type'    => 'post',
        'has_archive'        => false,
        'hierarchical'       => false,
        'menu_position'      => 21,
        'menu_icon'          => 'dashicons-megaphone',
        'supports'           => array('title'),
        'show_in_rest'       => true,
    );

    register_post_type('starter_popup', $args);
}
add_action('init', 'starter_register_popup_post_type');

/**
 * Verificar si ya existe un popup del tipo seleccionado (para tipos únicos)
 */
function starter_check_existing_popup_type() {
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'starter_popup') {
        return;
    }
    
    if (isset($_GET['post'])) {
        $post_id = intval($_GET['post']);
        $current_type = get_post_meta($post_id, '_popup_type', true);
        
        if (empty($current_type)) {
            return;
        }
        
        $popup_types = starter_get_popup_types();
        
        // Verificar si es un tipo único
        if (isset($popup_types[$current_type]) && $popup_types[$current_type]['unique']) {
            $args = array(
                'post_type' => 'starter_popup',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'meta_query' => array(
                    array(
                        'key' => '_popup_type',
                        'value' => $current_type,
                        'compare' => '=',
                    ),
                ),
                'post__not_in' => array($post_id),
            );
            
            $existing_popups = get_posts($args);
            
            if (!empty($existing_popups)) {
                add_action('admin_notices', function() use ($current_type, $popup_types) {
                    echo '<div class="notice notice-warning is-dismissible"><p>';
                    echo sprintf(
                        'Ya existe otro popup de tipo "%s" publicado. Solo uno puede estar activo a la vez.',
                        esc_html($popup_types[$current_type]['label'])
                    );
                    echo '</p></div>';
                });
            }
        }
    }
}
add_action('admin_init', 'starter_check_existing_popup_type');
