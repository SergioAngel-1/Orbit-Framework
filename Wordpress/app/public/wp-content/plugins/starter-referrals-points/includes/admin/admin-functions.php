<?php
/**
 * Funciones administrativas para el plugin de Referidos y Puntos
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Inicializar funcionalidades de administración
 */
function starter_rp_init_admin() {
    add_action('admin_menu', 'starter_rp_register_admin_menu');
    // Registrar configuraciones del plugin
    add_action('admin_init', 'starter_rp_register_settings');
    add_action('admin_init', 'starter_rp_process_admin_points_assignment');
    add_action('admin_footer', 'starter_rp_admin_footer_scripts');
    
    // Cargar estilos CSS para el panel de administración
    add_action('admin_enqueue_scripts', 'starter_rp_admin_styles');
    
    // Filtros para añadir columna de puntos en la lista de usuarios
    add_filter('manage_users_columns', 'starter_rp_add_user_columns');
    add_filter('manage_users_custom_column', 'starter_rp_user_column_content', 10, 3);
    add_filter('manage_users_sortable_columns', 'starter_rp_user_sortable_columns');
    
    // Acciones para usuarios
    add_action('show_user_profile', 'starter_rp_add_user_profile_fields');
    add_action('edit_user_profile', 'starter_rp_add_user_profile_fields');
    add_action('personal_options_update', 'starter_rp_save_user_profile_fields');
    add_action('edit_user_profile_update', 'starter_rp_save_user_profile_fields');
}

/**
 * Cargar estilos CSS para el panel de administración
 */
function starter_rp_admin_styles($hook) {
    // Páginas donde cargar los estilos
    $pages = array(
        'toplevel_page_starter-rp-dashboard',
        'referidos-y-puntos_page_starter-rp-transactions',
        'referidos-y-puntos_page_starter-rp-network',
        'referidos-y-puntos_page_starter-rp-settings'
    );
    
    // Cargar estilos solo en las páginas del plugin
    if (in_array($hook, $pages)) {
        wp_enqueue_style(
            'starter-rp-admin-tabs', 
            plugins_url('/assets/css/starter-styles.css', dirname(dirname(__FILE__))),
            array(),
            STARTER_RP_VERSION
        );
    }
}

/**
 * Registrar menús de administración
 */
function starter_rp_register_admin_menu() {
    // Menú principal
    add_menu_page(
        __('Referidos y Puntos', 'starter-rp'),
        __('Referidos y Puntos', 'starter-rp'),
        'manage_woocommerce',
        'starter-rp-dashboard',
        'starter_rp_dashboard_page',
        'dashicons-chart-area',
        56
    );
    
    // Submenús
    add_submenu_page(
        'starter-rp-dashboard',
        __('Dashboard de Referidos y Puntos', 'starter-rp'),
        __('Dashboard', 'starter-rp'),
        'manage_woocommerce',
        'starter-rp-dashboard',
        'starter_rp_dashboard_page'
    );
    
    add_submenu_page(
        'starter-rp-dashboard',
        __('Transacciones de Puntos', 'starter-rp'),
        __('Transacciones', 'starter-rp'),
        'manage_woocommerce',
        'starter-rp-transactions',
        'starter_rp_transactions_page'
    );
    
    add_submenu_page(
        'starter-rp-dashboard',
        __('Red de Referidos', 'starter-rp'),
        __('Red de Referidos', 'starter-rp'),
        'manage_woocommerce',
        'starter-rp-network',
        'starter_rp_network_page'
    );
    
    add_submenu_page(
        'starter-rp-dashboard',
        __('Configuración de Referidos y Puntos', 'starter-rp'),
        __('Configuración', 'starter-rp'),
        'manage_options',
        'starter-rp-settings',
        'starter_rp_settings_page'
    );
    
    add_submenu_page(
        'starter-rp-dashboard',
        __('Recálculo de Comisiones', 'starter-rp'),
        __('Recálculo', 'starter-rp'),
        'manage_options',
        'starter-rp-recalculate',
        'starter_rp_recalculate_page'
    );
}

/**
 * Página de dashboard
 */
function starter_rp_dashboard_page() {
    // Verificar permisos
    if (!current_user_can('manage_woocommerce')) {
        wp_die(__('No tienes permisos para acceder a esta página.', 'starter-rp'));
    }
    
    // Obtener datos para el dashboard
    global $wpdb;
    
    // Estadísticas generales
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
        SELECT SUM(points) FROM $points_table
    ");
    if (!$total_active_points) $total_active_points = 0;
    
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
        SELECT u.ID, u.display_name, u.user_email, p.points
        FROM $users_table u
        JOIN $points_table p ON u.ID = p.user_id
        ORDER BY p.points DESC
        LIMIT 10
    ");
    
    // Top referidores
    $top_referrers = $wpdb->get_results("
        SELECT 
            u.ID, 
            u.display_name, 
            COUNT(r.user_id) as total_referrals
        FROM 
            $users_table u
        JOIN 
            $referrals_table r ON u.ID = r.referrer_id
        GROUP BY 
            r.referrer_id
        ORDER BY 
            total_referrals DESC
        LIMIT 5
    ");
    
    // Iniciar el contenido de la página con las pestañas
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        
        <div class="starter-rp-tabs">
            <a href="<?php echo admin_url('admin.php?page=starter-rp-dashboard'); ?>" class="tab active">
                <?php _e('Dashboard', 'starter-rp'); ?>
            </a>
            <a href="<?php echo admin_url('admin.php?page=starter-rp-transactions'); ?>" class="tab">
                <?php _e('Transacciones', 'starter-rp'); ?>
            </a>
            <a href="<?php echo admin_url('admin.php?page=starter-rp-network'); ?>" class="tab">
                <?php _e('Red de Referidos', 'starter-rp'); ?>
            </a>
            <a href="<?php echo admin_url('admin.php?page=starter-rp-settings'); ?>" class="tab">
                <?php _e('Configuración', 'starter-rp'); ?>
            </a>
        </div>
    <?php
    
    // Incluir vista
    include_once plugin_dir_path(__FILE__) . 'views/admin-dashboard.php';
    
    // Cerrar el div.wrap
    echo '</div>';
    
    // Los estilos de las pestañas ahora se cargan desde starter-styles.css
}

/**
 * Página de transacciones
 */
function starter_rp_transactions_page() {
    // Verificar permisos
    if (!current_user_can('manage_woocommerce')) {
        wp_die(__('No tienes permisos para acceder a esta página.', 'starter-rp'));
    }
    
    // Incluir clase de la tabla si no está incluida
    if (!class_exists('Starter_RP_Transactions_Table')) {
        require_once plugin_dir_path(__FILE__) . 'class-transactions-table.php';
    }
    
    // Renderizar la tabla
    $transactions_table = new Starter_RP_Transactions_Table();
    $transactions_table->prepare_items();
    
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        
        <div class="starter-rp-tabs">
            <a href="<?php echo admin_url('admin.php?page=starter-rp-dashboard'); ?>" class="tab">
                <?php _e('Dashboard', 'starter-rp'); ?>
            </a>
            <a href="<?php echo admin_url('admin.php?page=starter-rp-transactions'); ?>" class="tab active">
                <?php _e('Transacciones', 'starter-rp'); ?>
            </a>
            <a href="<?php echo admin_url('admin.php?page=starter-rp-network'); ?>" class="tab">
                <?php _e('Red de Referidos', 'starter-rp'); ?>
            </a>
            <a href="<?php echo admin_url('admin.php?page=starter-rp-settings'); ?>" class="tab">
                <?php _e('Configuración', 'starter-rp'); ?>
            </a>
        </div>
        
        <form id="transactions-filter" method="get">
            <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />
            <?php $transactions_table->display(); ?>
        </form>
    </div>
    <?php
}

/**
 * Página de red de referidos
 */
function starter_rp_network_page() {
    // Verificar permisos
    if (!current_user_can('manage_woocommerce')) {
        wp_die(__('No tienes permisos para acceder a esta página.', 'starter-rp'));
    }
    
    // Procesar asignación masiva de códigos si se envió el formulario
    if (isset($_POST['starter_assign_codes_nonce']) && 
        wp_verify_nonce($_POST['starter_assign_codes_nonce'], 'starter_assign_codes_action')) {
        
        if (current_user_can('manage_options')) {
            $result = starter_rp_assign_missing_referral_codes();
            
            if ($result['success']) {
                echo '<div class="notice notice-success is-dismissible"><p>';
                printf(
                    __('✅ Se asignaron %d códigos de referido correctamente. %d usuarios ya tenían código. %d errores.', 'starter-rp'),
                    $result['assigned'],
                    $result['skipped'],
                    $result['errors']
                );
                echo '</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>';
                echo esc_html($result['message']);
                echo '</p></div>';
            }
        }
    }
    
    // Incluir clase de la tabla si no está incluida
    if (!class_exists('Starter_RP_Referrals_Table')) {
        require_once plugin_dir_path(__FILE__) . 'class-referrals-table.php';
    }
    
    global $wpdb;
    
    // Procesar búsqueda de usuario
    $search_user_id = null;
    $search_user_info = null;
    $search_error = '';
    
    if (isset($_GET['search_user']) && !empty($_GET['search_user'])) {
        $search_term = sanitize_text_field($_GET['search_user']);
        
        // Intentar buscar por ID
        if (is_numeric($search_term)) {
            $search_user_id = intval($search_term);
            
            // Verificar si el usuario existe
            $user = get_user_by('id', $search_user_id);
            if ($user) {
                $search_user_info = [
                    'id' => $user->ID,
                    'name' => $user->display_name,
                    'email' => $user->user_email,
                    'exists' => true
                ];
            } else {
                // Usuario no existe, pero verificar si tiene referidos en la tabla
                $has_referrals = $wpdb->get_var($wpdb->prepare("
                    SELECT COUNT(*) FROM {$wpdb->prefix}starter_referrals 
                    WHERE referrer_id = %d
                ", $search_user_id));
                
                if ($has_referrals > 0) {
                    $search_user_info = [
                        'id' => $search_user_id,
                        'name' => __('Usuario eliminado', 'starter-rp'),
                        'email' => __('N/A', 'starter-rp'),
                        'exists' => false
                    ];
                } else {
                    $search_error = __('No se encontraron referidos para este ID de usuario.', 'starter-rp');
                }
            }
        } else {
            // Buscar por email o nombre
            $user = get_user_by('email', $search_term);
            
            if (!$user) {
                // Buscar por nombre de usuario
                $user = get_user_by('login', $search_term);
            }
            
            if (!$user) {
                // Buscar por nombre para mostrar (display_name)
                $users = get_users([
                    'search' => '*' . $search_term . '*',
                    'search_columns' => ['display_name'],
                    'number' => 1
                ]);
                
                if (!empty($users)) {
                    $user = $users[0];
                }
            }
            
            if ($user) {
                $search_user_id = $user->ID;
                $search_user_info = [
                    'id' => $user->ID,
                    'name' => $user->display_name,
                    'email' => $user->user_email,
                    'exists' => true
                ];
            } else {
                $search_error = __('No se encontró ningún usuario con ese criterio de búsqueda.', 'starter-rp');
            }
        }
    }
    
    // Obtener estadísticas
    $total_users = count_users()['total_users'];
    
    $users_with_referrer = $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->prefix}starter_referrals
        WHERE referrer_id > 0
    ");
    
    $referral_rate = $total_users > 0 ? round(($users_with_referrer / $total_users) * 100, 2) : 0;
    
    // Contar usuarios sin código
    $users_without_code = $wpdb->get_var("
        SELECT COUNT(u.ID) 
        FROM {$wpdb->users} u
        LEFT JOIN {$wpdb->prefix}starter_referrals r ON u.ID = r.user_id
        WHERE r.referral_code IS NULL
    ");
    
    // Renderizar la tabla
    $referrals_table = new Starter_RP_Referrals_Table();
    $referrals_table->prepare_items();
    
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        
        <div class="starter-rp-tabs">
            <a href="<?php echo admin_url('admin.php?page=starter-rp-dashboard'); ?>" class="tab">
                <?php _e('Dashboard', 'starter-rp'); ?>
            </a>
            <a href="<?php echo admin_url('admin.php?page=starter-rp-transactions'); ?>" class="tab">
                <?php _e('Transacciones', 'starter-rp'); ?>
            </a>
            <a href="<?php echo admin_url('admin.php?page=starter-rp-network'); ?>" class="tab active">
                <?php _e('Red de Referidos', 'starter-rp'); ?>
            </a>
            <a href="<?php echo admin_url('admin.php?page=starter-rp-settings'); ?>" class="tab">
                <?php _e('Configuración', 'starter-rp'); ?>
            </a>
        </div>
        
        <!-- Buscador de Usuario -->
        <div class="starter-user-search-section" style="background: #fff; padding: 15px; margin: 20px 0; border-left: 4px solid #00a32a; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <h2 style="margin-top: 0;">
                <span class="dashicons dashicons-search" style="font-size: 20px; margin-right: 5px;"></span>
                <?php _e('Buscar Referidos de un Usuario', 'starter-rp'); ?>
            </h2>
            <p class="description">
                <?php _e('Busca por ID de usuario, email o nombre para ver todos sus referidos. Funciona incluso si el usuario ha sido eliminado.', 'starter-rp'); ?>
            </p>
            
            <form method="get" style="margin-top: 15px;">
                <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <input type="text" 
                           name="search_user" 
                           id="starter_search_user" 
                           value="<?php echo isset($_GET['search_user']) ? esc_attr($_GET['search_user']) : ''; ?>" 
                           placeholder="<?php _e('ID, email o nombre de usuario...', 'starter-rp'); ?>"
                           style="width: 300px;" 
                           class="regular-text" />
                    <button type="submit" class="button button-primary">
                        <span class="dashicons dashicons-search" style="margin-top: 3px;"></span>
                        <?php _e('Buscar Referidos', 'starter-rp'); ?>
                    </button>
                    <?php if (isset($_GET['search_user']) && !empty($_GET['search_user'])) : ?>
                    <a href="<?php echo admin_url('admin.php?page=starter-rp-network'); ?>" class="button">
                        <?php _e('Limpiar Búsqueda', 'starter-rp'); ?>
                    </a>
                    <?php endif; ?>
                </div>
            </form>
            
            <?php if (!empty($search_error)) : ?>
            <div class="notice notice-warning inline" style="margin-top: 15px; margin-left: 0;">
                <p><?php echo esc_html($search_error); ?></p>
            </div>
            <?php endif; ?>
            
            <?php if ($search_user_info) : ?>
            <div class="notice notice-success inline" style="margin-top: 15px; margin-left: 0;">
                <p>
                    <strong><?php _e('Usuario encontrado:', 'starter-rp'); ?></strong><br>
                    <?php if ($search_user_info['exists']) : ?>
                        <span class="dashicons dashicons-admin-users" style="color: #00a32a;"></span>
                        <?php echo esc_html($search_user_info['name']); ?> 
                        (<?php echo esc_html($search_user_info['email']); ?>) 
                        - ID: <?php echo $search_user_info['id']; ?>
                        <br>
                        <a href="<?php echo get_edit_user_link($search_user_info['id']); ?>" class="button button-small" style="margin-top: 5px;">
                            <?php _e('Ver Perfil', 'starter-rp'); ?>
                        </a>
                    <?php else : ?>
                        <span class="dashicons dashicons-warning" style="color: #dba617;"></span>
                        <?php echo esc_html($search_user_info['name']); ?> - ID: <?php echo $search_user_info['id']; ?>
                        <br>
                        <em><?php _e('Este usuario ya no existe en el sistema, pero sus referidos siguen registrados.', 'starter-rp'); ?></em>
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if ($users_without_code > 0) : ?>
        <div class="starter-assign-codes-section" style="background: #fff; padding: 20px; margin: 20px 0; border-left: 4px solid #2271b1; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <h2 style="margin-top: 0; display: flex; align-items: center;">
                <span class="dashicons dashicons-admin-users" style="font-size: 24px; margin-right: 10px;"></span>
                <?php _e('Asignación Masiva de Códigos de Referido', 'starter-rp'); ?>
            </h2>
            <p style="font-size: 14px; margin-bottom: 15px;">
                <?php 
                printf(
                    __('Hay <strong style="color: #d63638; font-size: 18px;">%d usuarios</strong> sin código de referido asignado.', 'starter-rp'),
                    $users_without_code
                );
                ?>
            </p>
            <form method="post" onsubmit="return confirm('<?php echo esc_js(__('¿Estás seguro de que deseas asignar códigos a todos los usuarios que no tienen uno?\n\nEsta acción:\n- Generará códigos únicos para cada usuario\n- No se puede deshacer\n- Puede tardar unos segundos\n\n¿Continuar?', 'starter-rp')); ?>');">
                <?php wp_nonce_field('starter_assign_codes_action', 'starter_assign_codes_nonce'); ?>
                <button type="submit" class="button button-primary button-large" style="height: 40px; font-size: 14px;">
                    <span class="dashicons dashicons-update" style="margin-top: 6px;"></span>
                    <?php _e('Asignar Códigos Restantes', 'starter-rp'); ?>
                </button>
                <p class="description" style="margin-top: 10px;">
                    <?php _e('💡 Esta acción generará códigos únicos basados en el nombre de usuario + 4 números aleatorios para todos los usuarios que actualmente no tienen uno asignado.', 'starter-rp'); ?>
                </p>
            </form>
        </div>
        <?php endif; ?>
        
        <div class="starter-rp-network-stats">
            <div class="stat-box">
                <span class="stat-label"><?php _e('Total de Usuarios', 'starter-rp'); ?></span>
                <span class="stat-value"><?php echo number_format($total_users); ?></span>
            </div>
            <div class="stat-box">
                <span class="stat-label"><?php _e('Usuarios con Referidor', 'starter-rp'); ?></span>
                <span class="stat-value"><?php echo number_format($users_with_referrer); ?></span>
            </div>
            <div class="stat-box">
                <span class="stat-label"><?php _e('Tasa de Referidos', 'starter-rp'); ?></span>
                <span class="stat-value"><?php echo $referral_rate; ?>%</span>
            </div>
        </div>
        
        <form id="referrals-filter" method="get">
            <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />
            <?php if ($search_user_id) : ?>
            <input type="hidden" name="referrer" value="<?php echo esc_attr($search_user_id); ?>" />
            <?php endif; ?>
            <?php $referrals_table->display(); ?>
        </form>
    </div>
    <?php
}

/**
 * Página de configuración
 */
function starter_rp_settings_page() {
    // Verificar permisos (mantener esta página solo para administradores)
    if (!current_user_can('manage_options')) {
        wp_die(__('No tienes permisos para acceder a esta página.', 'starter-rp'));
    }
    
    // Opciones
    $options = get_option('starter_rp_settings', []);
    
    // Incluir vista
    include_once plugin_dir_path(__FILE__) . 'views/admin-settings.php';
}

/**
 * Registrar ajustes
 */
function starter_rp_register_settings() {
    register_setting(
        'starter_rp_settings',
        'starter_rp_settings',
        [
            'sanitize_callback' => 'starter_rp_sanitize_settings',
            'default' => [
                // Opciones generales
                'enable_points' => 1,
                'enable_referrals' => 1,
                'allowed_roles' => ['customer'],
                
                // Opciones de puntos
                'points_conversion_rate' => 0.1,
                'points_percentage' => 5,
                'min_points_redemption' => 100,
                'max_points_per_order' => 1000,
                'points_expiry_days' => 365,
                'point_triggers' => ['purchase'],
                'points_registration' => 50,
                'points_review' => 10,
                'points_birthday' => 25,
                
                // Opciones de referidos
                'referral_commission_first' => 10,
                'referral_commission_subsequent' => 5,
                'referral_commission_duration' => 12,
                'enable_second_level' => 0,
                'second_level_commission' => 2,
                'points_per_referral' => 100,
                
                // Opciones de visualización
                'display_points_checkout' => 1,
            ]
        ]
    );
}

/**
 * Sanitizar ajustes
 */
function starter_rp_sanitize_settings($input) {
    $sanitized = [];
    
    // Checkboxes
    $sanitized['enable_points'] = isset($input['enable_points']) ? 1 : 0;
    $sanitized['enable_referrals'] = isset($input['enable_referrals']) ? 1 : 0;
    $sanitized['enable_second_level'] = isset($input['enable_second_level']) ? 1 : 0;
    $sanitized['display_points_checkout'] = isset($input['display_points_checkout']) ? 1 : 0;
    
    // Roles permitidos
    $sanitized['allowed_roles'] = isset($input['allowed_roles']) && is_array($input['allowed_roles']) 
        ? array_map('sanitize_text_field', $input['allowed_roles']) 
        : ['customer'];
    
    // Puntos por eventos
    $sanitized['point_triggers'] = isset($input['point_triggers']) && is_array($input['point_triggers']) 
        ? array_map('sanitize_text_field', $input['point_triggers']) 
        : ['purchase'];
    
    // Configuración numérica
    $numeric_fields = [
        'points_conversion_rate', 'points_percentage', 'min_points_redemption',
        'max_points_per_order', 'points_expiry_days', 'points_registration',
        'points_review', 'points_birthday', 'referral_commission_first',
        'referral_commission_subsequent', 'referral_commission_duration',
        'second_level_commission', 'signup_points_level1', 'signup_points_level2'
    ];
    
    foreach ($numeric_fields as $field) {
        $sanitized[$field] = isset($input[$field]) 
            ? floatval($input[$field]) 
            : 0;
    }
    
    // Textos
    $text_fields = [
        'redeem_points_text', 'insufficient_points_text', 'discount_applied_text'
    ];
    
    foreach ($text_fields as $field) {
        $sanitized[$field] = isset($input[$field]) 
            ? wp_kses_post($input[$field]) 
            : '';
    }
    
    // Comisiones por nivel de membresía
    if (isset($input['membership_commissions']) && is_array($input['membership_commissions'])) {
        $sanitized['membership_commissions'] = [];
        foreach ($input['membership_commissions'] as $level_id => $level_config) {
            $level_id = intval($level_id);
            $sanitized['membership_commissions'][$level_id] = [
                'first_commission' => isset($level_config['first_commission']) ? floatval($level_config['first_commission']) : 0,
                'subsequent_commission' => isset($level_config['subsequent_commission']) ? floatval($level_config['subsequent_commission']) : 0,
                'level2_commission' => isset($level_config['level2_commission']) ? floatval($level_config['level2_commission']) : 0,
            ];
        }
    }
    
    return $sanitized;
}

/**
 * Agregar campo de puntos a la tabla de usuarios
 */
function starter_rp_add_user_columns($columns) {
    $columns['starter_points'] = __('Puntos', 'starter-rp');
    // Eliminamos la columna de referido para evitar duplicados con referral-admin-columns.php
    return $columns;
}

/**
 * Contenido de las columnas personalizadas de usuarios
 */
function starter_rp_user_column_content($content, $column_name, $user_id) {
    switch ($column_name) {
        case 'starter_points':
            $points = starter_rp_get_user_points($user_id);
            return $points ? $points['balance'] : 0;
            
        case 'starter_referral_code':
            // Obtener el referidor del usuario usando la función implementada
            $referrer = starter_rp_get_user_referrer($user_id);
            
            if ($referrer && isset($referrer['id'])) {
                // Mostrar el nombre del referidor con enlace a su perfil
                return '<a href="' . get_edit_user_link($referrer['id']) . '">' . 
                       esc_html($referrer['name']) . '</a>';
            }
            
            return '—';
    }
    
    return $content;
}

/**
 * Hacer columnas ordenables
 */
function starter_rp_user_sortable_columns($columns) {
    $columns['starter_points'] = 'starter_points';
    $columns['starter_referral_code'] = 'starter_referral_code';
    return $columns;
}

/**
 * Añadir campos al perfil de usuario
 */
function starter_rp_add_user_profile_fields($user) {
    // Verificar permisos
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    
    // Procesar automáticamente los referidos pendientes
    $pending_referrer_id = get_user_meta($user->ID, '_starter_pending_referral_points', true);
    if (!empty($pending_referrer_id)) {
        // Actualizar el referidor en la tabla de referidos
        starter_rp_update_user_referrer($user->ID, $pending_referrer_id);
        starter_rp_log("Referidor pendiente procesado automáticamente para usuario ID {$user->ID}: {$pending_referrer_id}");
    }
    
    $points = starter_rp_get_user_points($user->ID);
    $referral_code = starter_rp_get_user_referral_code($user->ID);
    $referrer = starter_rp_get_user_referrer($user->ID);
    
    ?>
    <h2><?php _e('Referidos y Puntos', 'starter-rp'); ?></h2>
    
    <table class="form-table">
        <tr>
            <th><label for="starter_points"><?php _e('Puntos', 'starter-rp'); ?></label></th>
            <td>
                <input type="number" name="starter_points" id="starter_points" 
                    value="<?php echo esc_attr($points ? $points['balance'] : 0); ?>" 
                    class="regular-text" />
                <p class="description">
                    <?php _e('Saldo actual de puntos del usuario.', 'starter-rp'); ?>
                </p>
            </td>
        </tr>
        
        <tr>
            <th><label for="starter_referral_code"><?php _e('Código de Referido', 'starter-rp'); ?></label></th>
            <td>
                <input type="text" name="starter_referral_code" id="starter_referral_code" 
                    value="<?php echo esc_attr($referral_code); ?>" 
                    class="regular-text" />
                <p class="description">
                    <?php _e('Código de referido único del usuario.', 'starter-rp'); ?>
                </p>
            </td>
        </tr>
        
        <tr>
            <th><label for="starter_referrer_id"><?php _e('Referido por', 'starter-rp'); ?></label></th>
            <td>
                <select name="starter_referrer_id" id="starter_referrer_id">
                    <option value=""><?php _e('Ninguno', 'starter-rp'); ?></option>
                    <?php
                    $users = get_users([
                        'exclude' => [$user->ID],
                        'fields' => ['ID', 'display_name', 'user_email']
                    ]);
                    
                    foreach ($users as $u) {
                        $selected = $referrer && $referrer['id'] == $u->ID ? 'selected' : '';
                        echo '<option value="' . esc_attr($u->ID) . '" ' . $selected . '>';
                        echo esc_html($u->display_name) . ' (' . esc_html($u->user_email) . ')';
                        echo '</option>';
                    }
                    ?>
                </select>
                <p class="description">
                    <?php _e('Usuario que refirió a este usuario.', 'starter-rp'); ?>
                </p>
            </td>
        </tr>
    </table>
    <?php
}

/**
 * Guardar campos del perfil de usuario
 */
function starter_rp_save_user_profile_fields($user_id) {
    // Verificar permisos
    if (!current_user_can('manage_woocommerce')) {
        return false;
    }
    
    // Guardar puntos
    if (isset($_POST['starter_points'])) {
        $old_points = starter_rp_get_user_points($user_id);
        $new_points = intval($_POST['starter_points']);
        
        if ($old_points && $old_points['balance'] != $new_points) {
            $difference = $new_points - $old_points['balance'];
            
            if ($difference > 0) {
                // Añadir puntos
                starter_rp_add_points(
                    $user_id,
                    $difference,
                    'admin_add',
                    __('Ajuste manual por administrador', 'starter-rp'),
                    null,
                    null // Sin fecha de expiración para puntos asignados por admin
                );
            } else if ($difference < 0) {
                // Deducir puntos
                starter_rp_use_points(
                    $user_id,
                    abs($difference),
                    __('Ajuste manual por administrador', 'starter-rp'),
                    null
                );
            }
        }
    }
    
    // Guardar código de referido
    if (isset($_POST['starter_referral_code'])) {
        $new_code = sanitize_text_field($_POST['starter_referral_code']);
        starter_rp_update_user_referral_code($user_id, $new_code);
    }
    
    // Guardar referidor
    if (isset($_POST['starter_referrer_id'])) {
        $referrer_id = intval($_POST['starter_referrer_id']);
        starter_rp_update_user_referrer($user_id, $referrer_id);
    }
}

/**
 * Scripts para el footer del admin
 */
function starter_rp_admin_footer_scripts() {
    $screen = get_current_screen();
    
    // Solo en pantallas relevantes
    if (!$screen || !in_array($screen->id, [
        'starter-rp-dashboard',
        'starter-rp-settings',
        'starter-rp-transactions',
        'starter-rp-network'
    ])) {
        return;
    }
    
    ?>
    <script type="text/javascript">
        // Scripts específicos para el admin
        jQuery(document).ready(function($) {
            // Inicializar datepickers si existen
            if ($.fn.datepicker) {
                $('.starter-date-picker').datepicker({
                    dateFormat: 'yy-mm-dd'
                });
            }
        });
    </script>
    <?php
}

/**
 * Procesar la asignación de puntos por el administrador
 */
function starter_rp_process_admin_points_assignment() {
    // Verificar permisos
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    
    // Verificar nonce
    if (!isset($_POST['starter_rp_admin_points_nonce']) || 
        !wp_verify_nonce($_POST['starter_rp_admin_points_nonce'], 'starter_rp_admin_points_action')) {
        return;
    }
    
    // Obtener datos del formulario
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $points = isset($_POST['points']) ? intval($_POST['points']) : 0;
    $description = isset($_POST['description']) ? sanitize_text_field($_POST['description']) : '';
    $action_type = isset($_POST['action_type']) ? sanitize_text_field($_POST['action_type']) : 'add';
    
    // Validar datos
    if ($user_id <= 0 || $points <= 0 || empty($description)) {
        add_settings_error(
            'starter_rp_admin_points',
            'invalid_data',
            __('Por favor, complete todos los campos correctamente.', 'starter-rp'),
            'error'
        );
        return;
    }
    
    // Verificar que el usuario existe
    $user = get_user_by('id', $user_id);
    if (!$user) {
        add_settings_error(
            'starter_rp_admin_points',
            'invalid_user',
            __('El usuario seleccionado no existe.', 'starter-rp'),
            'error'
        );
        return;
    }
    
    $result = false;
    $error_details = '';
    
    // Procesar según el tipo de acción
    if ($action_type === 'add') {
        // Añadir puntos
        $result = starter_rp_add_points(
            $user_id,
            $points,
            'admin_add',
            $description,
            null,
            null // Sin fecha de expiración para puntos asignados por admin
        );
        
        if (!$result) {
            $error_details = sprintf(
                __('Falló al añadir %d puntos al usuario ID %d. Verifica que las tablas de la base de datos existan y que el usuario tenga un registro en la tabla de puntos.', 'starter-rp'),
                $points,
                $user_id
            );
            starter_rp_log("Starter R&P: Error añadiendo puntos - Usuario: {$user_id}, Puntos: {$points}, Descripción: {$description}");
        }
    } else if ($action_type === 'deduct') {
        // Verificar si el usuario tiene suficientes puntos
        $user_points = starter_rp_get_user_points($user_id);
        
        if (!$user_points || !isset($user_points['balance'])) {
            add_settings_error(
                'starter_rp_admin_points',
                'no_points_record',
                sprintf(
                    __('El usuario %s no tiene un registro de puntos. Primero añade puntos antes de deducir.', 'starter-rp'),
                    $user->display_name
                ),
                'error'
            );
            starter_rp_log("Starter R&P: Usuario {$user_id} no tiene registro de puntos");
            return;
        }
        
        if ($user_points['balance'] < $points) {
            add_settings_error(
                'starter_rp_admin_points',
                'insufficient_points',
                sprintf(
                    __('El usuario %s solo tiene %d puntos disponibles. No se pueden deducir %d puntos.', 'starter-rp'),
                    $user->display_name,
                    $user_points['balance'],
                    $points
                ),
                'error'
            );
            return;
        }
        
        // Deducir puntos
        $result = starter_rp_use_points(
            $user_id,
            $points,
            $description,
            null
        );
        
        if (!$result) {
            $error_details = sprintf(
                __('Falló al deducir %d puntos al usuario ID %d (balance actual: %d). Verifica los logs del servidor para más detalles.', 'starter-rp'),
                $points,
                $user_id,
                $user_points['balance']
            );
            starter_rp_log("Starter R&P: Error deduciendo puntos - Usuario: {$user_id}, Puntos a deducir: {$points}, Balance actual: {$user_points['balance']}");
        }
    }
    
    // Mostrar mensaje de resultado
    if ($result) {
        $action_text = $action_type === 'add' ? 'añadidos' : 'deducidos';
        add_settings_error(
            'starter_rp_admin_points',
            'success',
            sprintf(
                __('Se han %s %d puntos al usuario %s correctamente.', 'starter-rp'),
                $action_text,
                $points,
                $user->display_name
            ),
            'success'
        );
    } else {
        $error_message = __('Ha ocurrido un error al procesar los puntos.', 'starter-rp');
        if (!empty($error_details)) {
            $error_message .= ' ' . $error_details;
        }
        
        add_settings_error(
            'starter_rp_admin_points',
            'error',
            $error_message,
            'error'
        );
    }
}
