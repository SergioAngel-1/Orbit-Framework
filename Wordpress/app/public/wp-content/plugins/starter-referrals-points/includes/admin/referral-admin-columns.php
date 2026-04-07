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
 * Añadir columna de referidor a la tabla de usuarios
 */
function starter_rp_add_referrer_column($columns) {
    $columns['referrer'] = 'Referido por';
    return $columns;
}
add_filter('manage_users_columns', 'starter_rp_add_referrer_column', 15);

/**
 * Mostrar el contenido de la columna de referidor
 */
function starter_rp_show_referrer_column($value, $column_name, $user_id) {
    if ($column_name === 'referrer') {
        // Usar la función mejorada para obtener el referidor
        $referrer = starter_rp_get_user_referrer($user_id);
        
        // Si encontramos un referidor, mostrar su nombre
        if ($referrer && isset($referrer['id'])) {
            // Determinar el estado y el color
            $status_text = '';
            $status_color = '';
            
            if ($referrer['status'] === 'pending') {
                $status_text = 'Pendiente de aprobación';
                $status_color = 'orange';
            } elseif ($referrer['status'] === 'pending_points') {
                $status_text = 'Pendiente de puntos';
                $status_color = 'blue';
            } else {
                $status_text = 'Aprobado';
                $status_color = 'green';
            }
            
            $status_label = $status_text ? sprintf(' <span style="color: %s;">(%s)</span>', $status_color, $status_text) : '';
            
            return sprintf(
                '<a href="%s">%s</a>%s',
                esc_url(add_query_arg('user_id', $referrer['id'], admin_url('user-edit.php'))),
                esc_html($referrer['name']),
                $status_label
            );
        }
        
        return '—';
    }
    
    return $value;
}
add_action('manage_users_custom_column', 'starter_rp_show_referrer_column', 10, 3);

/**
 * Hacer que la columna sea ordenable
 */
function starter_rp_make_referrer_column_sortable($columns) {
    $columns['referrer'] = 'referrer';
    return $columns;
}
add_filter('manage_users_sortable_columns', 'starter_rp_make_referrer_column_sortable');

/**
 * Ordenar por referidor
 */
function starter_rp_sort_by_referrer($query) {
    if (!is_admin()) {
        return;
    }
    
    $orderby = $query->get('orderby');
    
    if ($orderby === 'referrer') {
        $query->set('meta_key', '_starter_pending_referral_points');
        $query->set('orderby', 'meta_value_num');
    }
}
add_action('pre_get_users', 'starter_rp_sort_by_referrer');

/**
 * Añadir campo de código de referido al formulario de registro de WordPress
 */
function starter_rp_add_wp_register_referral_field() {
    // Verificar si hay un código de referido en la URL
    $ref_from_url = isset($_GET['ref']) ? sanitize_text_field($_GET['ref']) : '';
    
    // Verificar si hay un código de referido en la cookie
    $ref_from_cookie = isset($_COOKIE['starter_referral']) ? sanitize_text_field($_COOKIE['starter_referral']) : '';
    
    // Usar el código de la URL si existe, de lo contrario usar el de la cookie
    $ref_code = !empty($ref_from_url) ? $ref_from_url : $ref_from_cookie;
    
    // El campo solo debe ser de solo lectura si el código viene de la URL
    $readonly = !empty($ref_from_url) ? 'readonly="readonly"' : '';
    
    // Añadir un campo oculto para saber si el código vino de la URL
    $from_url = !empty($ref_from_url) ? '1' : '0';
    ?>
    <p>
        <label for="referral_code"><?php _e('Código de referido (opcional)', 'starter-rp'); ?></label>
        <input type="text" name="referral_code" id="referral_code" class="input" value="<?php echo esc_attr($ref_code); ?>" size="25" <?php echo $readonly; ?> />
        <input type="hidden" name="referral_code_from_url" value="<?php echo $from_url; ?>" />
    </p>
    <?php
    
    // Si el código viene de la URL, añadir un script para evitar que se modifique
    if (!empty($ref_from_url)) {
        ?>
        <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            var refField = document.getElementById('referral_code');
            if (refField) {
                refField.setAttribute('readonly', 'readonly');
                refField.style.backgroundColor = '#f0f0f0';
            }
        });
        </script>
        <?php
    }
}
add_action('register_form', 'starter_rp_add_wp_register_referral_field');

/**
 * Procesar el código de referido durante el registro de usuario
 */
function starter_rp_process_user_registration_referral($user_id) {
    // Verificar si hay un código de referido en el formulario de registro
    if (isset($_POST['referral_code']) && !empty($_POST['referral_code'])) {
        $referral_code = sanitize_text_field($_POST['referral_code']);
        
        // Buscar el ID del referidor por el código
        global $wpdb;
        $referrals_table = $wpdb->prefix . 'starter_referrals';
        $referrer_id = $wpdb->get_var($wpdb->prepare("
            SELECT user_id FROM $referrals_table WHERE referral_code = %s
        ", $referral_code));
        
        if ($referrer_id && $referrer_id != $user_id) {
            // Guardar el ID del referidor en el meta del usuario
            update_user_meta($user_id, '_starter_pending_referral_points', $referrer_id);
            
            // Registrar para depuración
            starter_rp_log("Usuario ID {$user_id} registrado con código de referido {$referral_code} del usuario ID {$referrer_id}");
        }
    }
}
add_action('user_register', 'starter_rp_process_user_registration_referral');
