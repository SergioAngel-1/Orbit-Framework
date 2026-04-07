<?php
/**
 * Funciones de administración
 * 
 * Este archivo contiene todas las funciones relacionadas con la interfaz
 * de administración, como columnas personalizadas, campos de perfil, etc.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Añadir columnas personalizadas en la lista de usuarios del admin
 */
function add_custom_user_columns($columns) {
    $columns['pending_status'] = 'Estado';
    $columns['user_phone'] = 'Teléfono';
    return $columns;
}
add_filter('manage_users_columns', 'add_custom_user_columns');

/**
 * Mostrar el contenido de las columnas personalizadas
 */
function show_custom_user_columns_content($value, $column_name, $user_id) {
    if ($column_name === 'pending_status') {
        $pending = get_user_meta($user_id, 'pending_approval', true);
        $rejected = get_user_meta($user_id, 'rejected_status', true);
        
        if ($rejected) {
            return '<span style="color:orange; display:block;">Rechazado</span>';
        } else if ($pending && current_user_can('edit_users')) {
            $approve_link = admin_url("users.php?action=approve&user_id={$user_id}");
            $approve_nonce = wp_create_nonce("approve-user_{$user_id}");
            
            $reject_link = admin_url("users.php?action=reject&user_id={$user_id}");
            $reject_nonce = wp_create_nonce("reject-user_{$user_id}");
            
            return '<span style="color:red; display:block; margin-bottom:5px;">Pendiente</span>' .
                   '<a href="' . $approve_link . '&_wpnonce=' . $approve_nonce . '" ' .
                   'class="button button-primary" style="display:inline-block; margin-right:5px;">Aprobar</a>' .
                   '<a href="' . $reject_link . '&_wpnonce=' . $reject_nonce . '" ' .
                   'class="button button-secondary" style="display:inline-block; background-color:#dc3545; color:white; border-color:#dc3545;">Rechazar</a>';
        } else if ($pending) {
            return '<span style="color:red;">Pendiente</span>';
        } else {
            return '<span style="color:green;">Aprobado</span>';
        }
    } elseif ($column_name === 'user_phone') {
        // Buscar teléfono en múltiples ubicaciones como fallback
        $phone = get_user_meta($user_id, 'phone', true);
        
        // Si no hay teléfono en 'phone', buscar en billing_phone (WooCommerce)
        if (empty($phone)) {
            $phone = get_user_meta($user_id, 'billing_phone', true);
        }
        
        // Si no hay teléfono en billing_phone, buscar en shipping_phone (WooCommerce)
        if (empty($phone)) {
            $phone = get_user_meta($user_id, 'shipping_phone', true);
        }
        
        // Si no hay teléfono en los campos estándar, buscar en las direcciones del usuario
        if (empty($phone)) {
            $user_addresses = get_user_meta($user_id, 'user_addresses', true);
            if (!empty($user_addresses) && is_array($user_addresses)) {
                foreach ($user_addresses as $address) {
                    if (!empty($address['phone'])) {
                        $phone = $address['phone'];
                        break; // Usar el primer teléfono encontrado
                    }
                }
            }
        }
        
        if (!empty($phone)) {
            // Limpiar el número de teléfono (solo números)
            $clean_phone = preg_replace('/[^0-9]/', '', $phone);
            
            // Formatear el teléfono para mejor visualización
            $formatted_phone = $phone;
            $whatsapp_number = $clean_phone;
            
            // Si el teléfono limpio tiene 10 dígitos, asumir formato colombiano
            if (preg_match('/^\d{10}$/', $clean_phone)) {
                $formatted_phone = '+57 ' . substr($clean_phone, 0, 3) . ' ' . substr($clean_phone, 3, 3) . ' ' . substr($clean_phone, 6, 4);
                $whatsapp_number = '57' . $clean_phone; // Formato para WhatsApp SIN ESPACIOS
            } elseif (preg_match('/^57\d{10}$/', $clean_phone)) {
                // Si ya incluye el código de país 57
                $formatted_phone = '+' . substr($clean_phone, 0, 2) . ' ' . substr($clean_phone, 2, 3) . ' ' . substr($clean_phone, 5, 3) . ' ' . substr($clean_phone, 8, 4);
                $whatsapp_number = $clean_phone; // Ya tiene el formato correcto
            }
            
            $user_data = get_userdata($user_id);
            $user_name = $user_data->display_name ?: $user_data->user_login;
            
            // Crear el mensaje sin codificar primero
            $site_name_msg = function_exists('site_get_name') ? site_get_name() : 'Mi Tienda';
            $raw_message = "🌸 Hola {$user_name}, ¡gracias por registrarte en {$site_name_msg}! 🐰✨
Antes de darte acceso al jardín secreto 🌿, necesitamos conocerte un poquito más.

Por favor respóndenos a este mensaje con una breve info sobre ti:
– ¿Cómo nos conociste?
– ¿Qué te interesa de nuestra tienda?

Con eso podremos continuar tu aprobación y dejarte entrar al lado bonito de la magia verde 🌳💚";
            
            // Asegurar que el mensaje esté en UTF-8 y codificar de forma compatible con WhatsApp
            $utf8_message = mb_convert_encoding($raw_message, 'UTF-8', 'auto');
            
            // Usar urlencode que maneja mejor los emojis UTF-8 que rawurlencode
            $whatsapp_message = urlencode($utf8_message);
            
            // Fallback: si la URL es demasiado larga o problemática, usar versión sin emojis
            if (strlen($whatsapp_message) > 2000) {
                $fallback_message = "Hola {$user_name}, gracias por registrarte en {$site_name_msg}!
Antes de darte acceso, necesitamos conocerte un poquito más.

Por favor respóndenos:
- ¿Cómo nos conociste?
- ¿Qué te interesa de nuestra tienda?

Con eso podremos continuar tu aprobación.";
                $whatsapp_message = urlencode($fallback_message);
            }
            
            // Construir URL de WhatsApp - el número ya está limpio sin espacios
            $whatsapp_url = "https://wa.me/{$whatsapp_number}?text={$whatsapp_message}";
            
            return '<div style="display: flex; flex-direction: column; gap: 2px;">' .
                   '<span style="font-family: monospace; font-weight: bold;">' . esc_html($formatted_phone) . '</span>' .
                   '<a href="' . esc_attr($whatsapp_url) . '" target="_blank" ' .
                   'style="font-size: 11px; color: #25D366; text-decoration: none;" ' .
                   'title="Contactar por WhatsApp">' .
                   '📱 WhatsApp</a>' .
                   '</div>';
        } else {
            return '<span style="color: #999; font-style: italic;">No registrado</span>';
        }
    }
    
    return $value;
}
add_filter('manage_users_custom_column', 'show_custom_user_columns_content', 10, 3);

/**
 * Hacer las columnas personalizadas ordenables
 */
function make_user_columns_sortable($columns) {
    $columns['user_phone'] = 'phone';
    $columns['pending_status'] = 'pending_approval';
    return $columns;
}
add_filter('manage_users_sortable_columns', 'make_user_columns_sortable');

/**
 * Manejar la ordenación de las columnas personalizadas
 */
function handle_user_columns_sorting($query) {
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }
    
    $orderby = $query->get('orderby');
    
    if ($orderby === 'phone') {
        $query->set('meta_key', 'phone');
        $query->set('orderby', 'meta_value');
    } elseif ($orderby === 'pending_approval') {
        $query->set('meta_key', 'pending_approval');
        $query->set('orderby', 'meta_value');
    }
}
add_action('pre_get_users', 'handle_user_columns_sorting');

/**
 * Añadir filtros adicionales en la página de usuarios
 */
function add_user_status_filters() {
    if (!is_admin() || !current_user_can('list_users')) {
        return;
    }
    
    $current_screen = get_current_screen();
    if ($current_screen->id !== 'users') {
        return;
    }
    
    // Obtener el filtro actual
    $current_filter = isset($_GET['user_status_filter']) ? $_GET['user_status_filter'] : '';
    
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Añadir el filtro después de los filtros existentes
        var filterHtml = '<select name="user_status_filter" id="user_status_filter">' +
            '<option value="">Todos los estados</option>' +
            '<option value="pending"<?php echo $current_filter === "pending" ? " selected" : ""; ?>>Solo pendientes</option>' +
            '<option value="approved"<?php echo $current_filter === "approved" ? " selected" : ""; ?>>Solo aprobados</option>' +
            '<option value="rejected"<?php echo $current_filter === "rejected" ? " selected" : ""; ?>>Solo rechazados</option>' +
            '</select>';
        
        $('.tablenav.top .alignleft.actions:first').append(filterHtml);
    });
    </script>
    <?php
}
add_action('admin_footer-users.php', 'add_user_status_filters');

/**
 * Procesar el filtro de estado de usuario
 */
function filter_users_by_status($query) {
    global $pagenow;
    
    if (!is_admin() || $pagenow !== 'users.php' || !current_user_can('list_users')) {
        return;
    }
    
    $filter = isset($_GET['user_status_filter']) ? $_GET['user_status_filter'] : '';
    
    if (empty($filter)) {
        return;
    }
    
    $meta_query = $query->get('meta_query') ?: array();
    
    switch ($filter) {
        case 'pending':
            $meta_query[] = array(
                'key' => 'pending_approval',
                'value' => '1',
                'compare' => '='
            );
            break;
            
        case 'approved':
            $meta_query[] = array(
                'relation' => 'AND',
                array(
                    'key' => 'pending_approval',
                    'compare' => 'NOT EXISTS'
                ),
                array(
                    'key' => 'rejected_status',
                    'compare' => 'NOT EXISTS'
                )
            );
            break;
            
        case 'rejected':
            $meta_query[] = array(
                'key' => 'rejected_status',
                'value' => '1',
                'compare' => '='
            );
            break;
    }
    
    if (!empty($meta_query)) {
        $query->set('meta_query', $meta_query);
    }
}
add_action('pre_get_users', 'filter_users_by_status');

/**
 * Añadir un botón de aprobación en la administración de usuarios
 */
function add_approve_user_button($actions, $user_object) {
    // Verificar si el usuario tiene el meta pending_approval
    $pending = get_user_meta($user_object->ID, 'pending_approval', true);
    $role = $user_object->roles[0] ?? 'no-role';
    
    // Mostrar solo el botón de aprobación si el usuario está pendiente (el de rechazo solo en la columna Estado)
    if (current_user_can('edit_users') && $pending) {
        // Botón de aprobar
        $approve_link = admin_url("users.php?action=approve&user_id={$user_object->ID}");
        $approve_nonce = wp_create_nonce("approve-user_{$user_object->ID}");
        $actions['approve'] = "<a href='{$approve_link}&_wpnonce={$approve_nonce}' class='button button-primary' style='color: white; font-weight: bold;'>Aprobar</a>";
    }
    
    return $actions;
}
add_filter('user_row_actions', 'add_approve_user_button', 10, 2);

/**
 * FUNCIÓN ELIMINADA - CONSOLIDADA EN user-status.php
 * 
 * La visualización y gestión del estado de usuario ahora se maneja completamente en:
 * themes/Starter/inc/user-management/user-status.php
 * 
 * Esto elimina la duplicación de código y centraliza toda la lógica de estado en un solo lugar.
 * 
 * NOTA: El campo de teléfono también fue eliminado porque:
 * - Era solo de lectura (no editable)
 * - WooCommerce ya muestra estos datos en sus propias secciones (Billing/Shipping)
 * - Causaba confusión al duplicar información
 */

/**
 * Añadir el rol "Gestor de la tienda" al filtro de roles en la lista de usuarios
 */
function starter_add_shop_manager_to_role_filter($role_links) {
    global $wp_roles;
    
    if (!isset($wp_roles)) {
        $wp_roles = new WP_Roles();
    }
    
    $role_name = 'shop_manager';
    
    if (isset($wp_roles->roles[$role_name])) {
        $role = $wp_roles->roles[$role_name];
        $role_display_name = translate_user_role($role['name']);
        
        $users_of_role = count_users();
        $count = isset($users_of_role['avail_roles'][$role_name]) ? $users_of_role['avail_roles'][$role_name] : 0;
        
        $current_role = isset($_GET['role']) ? $_GET['role'] : '';
        $class = ($current_role === $role_name) ? 'current' : '';
        
        $role_links[$role_name] = sprintf(
            '<a href="%s"%s>%s <span class="count">(%s)</span></a>',
            add_query_arg('role', $role_name, 'users.php'),
            $class ? ' class="' . $class . '"' : '',
            $role_display_name,
            number_format_i18n($count)
        );
    }
    
    return $role_links;
}
add_filter('views_users', 'starter_add_shop_manager_to_role_filter');

/**
 * Añadir acceso directo a cache-test.php en la barra de administración
 */
function starter_add_cache_test_link($wp_admin_bar) {
    // Solo mostrar para administradores
    if (!current_user_can('administrator')) {
        return;
    }
    
    $args = array(
        'id'    => 'cache-test',
        'title' => 'Prueba de Caché',
        'href'  => site_url('/wp-content/themes/Starter/cache-test.php'),
        'meta'  => array(
            'class' => 'cache-test-link',
            'title' => 'Acceder a la prueba de caché de API'
        )
    );
    $wp_admin_bar->add_node($args);
}
add_action('admin_bar_menu', 'starter_add_cache_test_link', 999);
