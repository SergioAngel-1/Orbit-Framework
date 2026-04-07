<?php
/**
 * Columnas personalizadas para la tabla de usuarios en el admin
 * 
 * Este archivo añade columnas personalizadas a la tabla de usuarios en el admin
 * para mostrar información sobre referidos y referidores.
 */

if (!defined('ABSPATH')) {
    exit; // Acceso directo no permitido
}

/**
 * Extender las columnas de usuario existentes
 * 
 * Esta función se ejecuta después de la función original para asegurarse de que
 * no haya conflictos con las columnas existentes.
 */
function starter_rp_extend_user_columns($columns) {
    // Verificar si la columna ya existe para evitar duplicados
    if (!isset($columns['starter_referral_code'])) {
        $columns['referrer'] = 'Referido por';
    }
    return $columns;
}
add_filter('manage_users_columns', 'starter_rp_extend_user_columns', 20); // Prioridad 20 para ejecutar después de la función original

/**
 * Mostrar el contenido de la columna de referidor
 */
function starter_rp_show_user_columns($value, $column_name, $user_id) {
    if ($column_name === 'referrer' || $column_name === 'starter_referral_code') {
        // Primero verificar si hay un referidor pendiente
        $referrer_id = get_user_meta($user_id, '_starter_pending_referral_points', true);
        $is_pending_referral = !empty($referrer_id);
        
        // Si no hay referidor pendiente, buscar en la tabla de referidos
        if (!$referrer_id) {
            global $wpdb;
            $referrals_table = $wpdb->prefix . 'starter_referrals';
            $referrer_id = $wpdb->get_var($wpdb->prepare("
                SELECT referrer_id FROM $referrals_table WHERE user_id = %d AND referrer_id IS NOT NULL
            ", $user_id));
        }
        
        // Si encontramos un referidor, mostrar su nombre
        if ($referrer_id) {
            $referrer = get_userdata($referrer_id);
            if ($referrer) {
                $status = get_user_meta($user_id, 'wp_user_approval_status', true);
                
                // Determinar el estado y el color
                $status_text = '';
                $status_color = '';
                
                if ($status === 'pending') {
                    $status_text = 'Pendiente de aprobación';
                    $status_color = 'orange';
                } elseif ($is_pending_referral) {
                    $status_text = 'Pendiente de puntos';
                    $status_color = 'blue';
                } else {
                    $status_text = 'Aprobado';
                    $status_color = 'green';
                }
                
                $status_label = $status_text ? sprintf(' <span style="color: %s;">(%s)</span>', $status_color, $status_text) : '';
                
                return sprintf(
                    '<a href="%s">%s</a>%s',
                    esc_url(add_query_arg('user_id', $referrer_id, admin_url('user-edit.php'))),
                    esc_html($referrer->display_name),
                    $status_label
                );
            }
        }
        
        return '—';
    }
    
    return $value;
}
add_action('manage_users_custom_column', 'starter_rp_show_user_columns', 10, 3);

/**
 * Extender las columnas ordenables
 */
function starter_rp_extend_user_sortable_columns($columns) {
    $columns['referrer'] = 'referrer';
    return $columns;
}
add_filter('manage_users_sortable_columns', 'starter_rp_extend_user_sortable_columns', 20);

/**
 * Ordenar por referidor
 */
function starter_rp_sort_by_referrer_column($query) {
    if (!is_admin()) {
        return;
    }
    
    $orderby = $query->get('orderby');
    
    if ($orderby === 'referrer') {
        $query->set('meta_key', '_starter_pending_referral_points');
        $query->set('orderby', 'meta_value_num');
    }
}
add_action('pre_get_users', 'starter_rp_sort_by_referrer_column', 20);
