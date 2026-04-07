<?php
/**
 * Panel administrativo para el sistema de referidos y puntos
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Inicializar panel administrativo
 */
function starter_rp_init_admin_panel() {
    // Registrar configuraciones
    add_action('admin_init', 'starter_rp_register_panel_settings');
    
    // HOOKS DE PERFIL DE USUARIO ELIMINADOS - CONSOLIDADOS EN admin-functions.php
    // La función starter_rp_add_user_profile_fields() en admin-functions.php
    // ya maneja toda la visualización y guardado de campos de perfil.
    // Esto elimina la duplicación de secciones "Referidos y Puntos" en user-edit.php
    
    // Columna de puntos en la tabla de usuarios
    add_filter('manage_users_columns', 'starter_rp_modify_user_table');
    add_filter('manage_users_custom_column', 'starter_rp_modify_user_table_row', 10, 3);
    add_filter('manage_users_sortable_columns', 'starter_rp_sortable_columns');
    
    // Columna de puntos en los pedidos
    add_filter('manage_edit-shop_order_columns', 'starter_rp_order_points_column');
    add_action('manage_shop_order_posts_custom_column', 'starter_rp_order_points_column_content', 10, 2);
}

/**
 * Registrar configuraciones del panel
 */
function starter_rp_register_panel_settings() {
    // Corregir el nombre de la opción para que coincida con el usado en el formulario
    register_setting('starter_rp_settings', 'starter_rp_settings');
    
    add_settings_section(
        'starter_rp_general',
        __('Configuración General', 'starter-rp'),
        'starter_rp_general_section_callback',
        'starter_rp_settings'
    );
    
    add_settings_section(
        'starter_rp_referrals',
        __('Configuración de Referidos', 'starter-rp'),
        'starter_rp_referrals_section_callback',
        'starter_rp_settings'
    );
    
    add_settings_section(
        'starter_rp_points',
        __('Configuración de Puntos', 'starter-rp'),
        'starter_rp_points_section_callback',
        'starter_rp_settings'
    );
    
    // Campos generales
    add_settings_field(
        'starter_rp_enabled',
        __('Estado del Sistema', 'starter-rp'),
        'starter_rp_field_enabled_callback',
        'starter_rp_settings',
        'starter_rp_general'
    );
    
    // Campos de referidos
    add_settings_field(
        'starter_rp_commission_level1',
        __('Comisión Nivel 1 (%)', 'starter-rp'),
        'starter_rp_field_commission_level1_callback',
        'starter_rp_settings',
        'starter_rp_referrals'
    );
    
    add_settings_field(
        'starter_rp_commission_level2',
        __('Comisión Nivel 2 (%)', 'starter-rp'),
        'starter_rp_field_commission_level2_callback',
        'starter_rp_settings',
        'starter_rp_referrals'
    );
    
    add_settings_field(
        'starter_rp_referral_signup_points',
        __('Puntos por Nuevo Referido', 'starter-rp'),
        'starter_rp_field_referral_signup_points_callback',
        'starter_rp_settings',
        'starter_rp_referrals'
    );
    
    // Campos de puntos
    add_settings_field(
        'starter_rp_points_per_currency',
        __('Puntos por Unidad de Moneda', 'starter-rp'),
        'starter_rp_field_points_per_currency_callback',
        'starter_rp_settings',
        'starter_rp_points'
    );
    
    add_settings_field(
        'starter_rp_points_conversion_rate',
        __('Tasa de Conversión (puntos por $1)', 'starter-rp'),
        'starter_rp_field_points_conversion_rate_callback',
        'starter_rp_settings',
        'starter_rp_points'
    );
    
    add_settings_field(
        'starter_rp_points_expiration_days',
        __('Días para Expiración de Puntos', 'starter-rp'),
        'starter_rp_field_points_expiration_days_callback',
        'starter_rp_settings',
        'starter_rp_points'
    );
}

/**
 * Callback para la sección general
 */
function starter_rp_general_section_callback() {
    echo '<p>' . __('Configuración general del sistema de referidos y puntos.', 'starter-rp') . '</p>';
}

/**
 * Callback para la sección de referidos
 */
function starter_rp_referrals_section_callback() {
    echo '<p>' . __('Configura el sistema de referidos y comisiones.', 'starter-rp') . '</p>';
}

/**
 * Callback para la sección de puntos
 */
function starter_rp_points_section_callback() {
    echo '<p>' . __('Configura el sistema de puntos y recompensas.', 'starter-rp') . '</p>';
}

/**
 * Callback para el campo de estado
 */
function starter_rp_field_enabled_callback() {
    $options = Starter_RP()->get_options();
    $enabled = isset($options['enabled']) ? $options['enabled'] : true;
    
    echo '<label><input type="checkbox" name="starter_rp_options[enabled]" value="1" ' . checked(1, $enabled, false) . '/>';
    echo ' ' . __('Activar sistema de referidos y puntos', 'starter-rp') . '</label>';
}

/**
 * Callback para comisión nivel 1
 */
function starter_rp_field_commission_level1_callback() {
    $options = Starter_RP()->get_options();
    $commission = isset($options['referral_commission_level1']) ? $options['referral_commission_level1'] : 10;
    
    echo '<input type="number" min="0" max="100" step="1" name="starter_rp_options[referral_commission_level1]" value="' . esc_attr($commission) . '" class="small-text" />';
    echo ' <span class="description">' . __('Porcentaje de puntos que recibe el referidor directo.', 'starter-rp') . '</span>';
}

/**
 * Callback para comisión nivel 2
 */
function starter_rp_field_commission_level2_callback() {
    $options = Starter_RP()->get_options();
    $commission = isset($options['referral_commission_level2']) ? $options['referral_commission_level2'] : 5;
    
    echo '<input type="number" min="0" max="100" step="1" name="starter_rp_options[referral_commission_level2]" value="' . esc_attr($commission) . '" class="small-text" />';
    echo ' <span class="description">' . __('Porcentaje de puntos que recibe el referidor indirecto.', 'starter-rp') . '</span>';
}

/**
 * Callback para puntos por unidad de moneda
 */
function starter_rp_field_points_per_currency_callback() {
    $options = Starter_RP()->get_options();
    $points = isset($options['points_per_currency']) ? $options['points_per_currency'] : 10;
    
    echo '<input type="number" min="0.1" step="0.1" name="starter_referrals_points_options[points_per_currency]" value="' . esc_attr($points) . '" class="small-text" />';
    echo ' <span class="description">' . __('Cuántos puntos dar por cada unidad de moneda gastada.', 'starter-rp') . '</span>';
}

/**
 * Callback para tasa de conversión
 */
function starter_rp_field_points_conversion_rate_callback() {
    $options = Starter_RP()->get_options();
    $rate = isset($options['points_conversion_rate']) ? $options['points_conversion_rate'] : 0.1;
    
    echo '<input type="number" min="0.01" step="0.01" name="starter_referrals_points_options[points_conversion_rate]" value="' . esc_attr($rate) . '" class="small-text" />';
    $vc_name = function_exists('site_get_vc_name') ? site_get_vc_name() : 'Virtual Coin';
    $vc_short = function_exists('site_get_vc_short') ? site_get_vc_short() : 'VC';
    echo ' <span class="description">' . sprintf('¿Cuánto vale 1 %s en la moneda del sitio? Por ejemplo: 100 = 1 %s vale $100', esc_html($vc_name), esc_html($vc_short)) . '</span>';
}

/**
 * Callback para días de expiración
 */
function starter_rp_field_points_expiration_days_callback() {
    $options = Starter_RP()->get_options();
    $days = isset($options['points_expiration_days']) ? $options['points_expiration_days'] : 365;
    
    echo '<input type="number" min="0" step="1" name="starter_referrals_points_options[points_expiration_days]" value="' . esc_attr($days) . '" class="small-text" />';
    echo ' <span class="description">' . __('Días hasta que expiran los puntos (0 = sin expiración).', 'starter-rp') . '</span>';
}

/**
 * Callback para el campo de puntos por nuevo referido
 */
function starter_rp_field_referral_signup_points_callback() {
    $options = Starter_RP()->get_options();
    $value = isset($options['referral_signup_points']) ? $options['referral_signup_points'] : 100;
    ?>
    <input type="number" name="starter_rp_options[referral_signup_points]" value="<?php echo esc_attr($value); ?>" min="0" step="1" />
    <p class="description">
        <?php _e('Cantidad de puntos que recibe un usuario cuando uno de sus referidos es aprobado.', 'starter-rp'); ?>
    </p>
    <?php
}

/**
 * Página principal de administración
 */
function starter_rp_admin_page() {
    global $wpdb;
    
    // Estadísticas
    $users_table = $wpdb->prefix . 'users';
    $points_table = $wpdb->prefix . 'starter_user_points';
    $transactions_table = $wpdb->prefix . 'starter_points_transactions';
    $referrals_table = $wpdb->prefix . 'starter_referrals';
    
    // Total de usuarios con puntos
    $users_with_points = $wpdb->get_var("
        SELECT COUNT(DISTINCT user_id) FROM $points_table
    ");
    
    // Total de puntos activos
    $total_active_points = $wpdb->get_var("
        SELECT SUM(balance) FROM $points_table
    ");
    
    // Total de transacciones
    $total_transactions = $wpdb->get_var("
        SELECT COUNT(*) FROM $transactions_table
    ");
    
    // Total de referidos
    $total_referrals = $wpdb->get_var("
        SELECT COUNT(*) FROM $referrals_table WHERE referrer_id IS NOT NULL
    ");
    
    // Últimas transacciones
    $recent_transactions = $wpdb->get_results("
        SELECT t.*, u.display_name 
        FROM $transactions_table t
        JOIN $users_table u ON t.user_id = u.ID
        ORDER BY t.created_at DESC
        LIMIT 10
    ");
    
    // Top usuarios por puntos
    $top_users = $wpdb->get_results("
        SELECT p.user_id, p.balance, u.display_name, u.user_email
        FROM $points_table p
        JOIN $users_table u ON p.user_id = u.ID
        ORDER BY p.balance DESC
        LIMIT 10
    ");
    
    // Top referidores
    $top_referrers = $wpdb->get_results("
        SELECT r.referrer_id, COUNT(*) as total_referrals, u.display_name
        FROM $referrals_table r
        JOIN $users_table u ON r.referrer_id = u.ID
        WHERE r.referrer_id IS NOT NULL
        GROUP BY r.referrer_id
        ORDER BY total_referrals DESC
        LIMIT 10
    ");
    
    // Incluir vista
    include(plugin_dir_path(__FILE__) . 'views/admin-dashboard.php');
}

/**
 * Página de configuración del panel
 */
function starter_rp_panel_settings_page() {
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('starter_rp_settings');
            do_settings_sections('starter_rp_settings');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

/**
 * Página de transacciones del panel
 */
function starter_rp_panel_transactions_page() {
    // Incluir clase de listado de tabla WP
    if (!class_exists('WP_List_Table')) {
        require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
    }
    
    // Incluir clase de tabla de transacciones
    require_once(plugin_dir_path(__FILE__) . 'class-transactions-table.php');
    
    // Crear instancia de la tabla
    $transactions_table = new Starter_RP_Transactions_Table();
    
    // Preparar y mostrar la tabla
    $transactions_table->prepare_items();
    
    ?>
    <div class="wrap">
        <h1><?php _e('Transacciones de Puntos', 'starter-rp'); ?></h1>
        
        <form method="get">
            <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />
            <?php $transactions_table->search_box(__('Buscar', 'starter-rp'), 'starter-search'); ?>
            <?php $transactions_table->display(); ?>
        </form>
    </div>
    <?php
}

/**
 * Añadir columna de puntos a la tabla de usuarios
 */
function starter_rp_modify_user_table($columns) {
    $columns['starter_points'] = __('Puntos', 'starter-rp');
    return $columns;
}

/**
 * Mostrar datos de puntos en la columna de usuarios
 */
function starter_rp_modify_user_table_row($val, $column_name, $user_id) {
    if ($column_name === 'starter_points') {
        $user_points = starter_rp_get_user_points($user_id);
        
        if ($user_points) {
            return '<strong>' . $user_points['balance'] . '</strong>';
        } else {
            return '0';
        }
    }
    
    return $val;
}

/**
 * Hacer la columna de puntos ordenable
 */
function starter_rp_sortable_columns($columns) {
    $columns['starter_points'] = 'starter_points';
    return $columns;
}

/**
 * Añadir columna de puntos a la tabla de pedidos
 */
function starter_rp_order_points_column($columns) {
    $new_columns = [];
    
    foreach ($columns as $key => $column) {
        $new_columns[$key] = $column;
        
        if ($key === 'order_status') {
            $new_columns['starter_order_points'] = __('Puntos', 'starter-rp');
        }
    }
    
    return $new_columns;
}

/**
 * Mostrar datos de puntos en la columna de pedidos
 */
function starter_rp_order_points_column_content($column, $order_id) {
    if ($column === 'starter_order_points') {
        $points_earned = get_post_meta($order_id, '_starter_points_earned', true);
        $points_used = get_post_meta($order_id, '_starter_points_used', true);
        
        if ($points_earned) {
            echo '<span style="color: green;">+' . $points_earned . '</span>';
        }
        
        if ($points_used) {
            echo '<br><span style="color: red;">-' . $points_used . '</span>';
        }
        
        if (!$points_earned && !$points_used) {
            echo '-';
        }
    }
}
