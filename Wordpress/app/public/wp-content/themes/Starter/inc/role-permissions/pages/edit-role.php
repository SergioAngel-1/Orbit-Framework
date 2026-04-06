<?php
/**
 * Página: Editar Rol
 * 
 * Interfaz para editar capacidades de un rol existente
 * 
 * @package Starter
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Página: Editar Rol
 */
function starter_edit_role_page() {
    // Verificar que se haya pasado un rol
    if (!isset($_GET['role'])) {
        echo '<div class="wrap"><h1>Error</h1><p>No se especificó un rol para editar.</p></div>';
        return;
    }
    
    $role_slug = sanitize_text_field($_GET['role']);
    $role = get_role($role_slug);
    
    if (!$role) {
        echo '<div class="wrap"><h1>Error</h1><p>El rol especificado no existe.</p></div>';
        return;
    }
    
    global $wp_roles;
    $role_name = isset($wp_roles->roles[$role_slug]['name']) ? $wp_roles->roles[$role_slug]['name'] : $role_slug;
    
    // Procesar guardado de capacidades
    if (isset($_POST['save_capabilities']) && check_admin_referer('starter_edit_role_' . $role_slug)) {
        $new_capabilities = isset($_POST['capabilities']) ? $_POST['capabilities'] : array();
        
        // Obtener capacidades actuales
        $current_capabilities = array_keys($role->capabilities);
        
        // Remover capacidades que ya no están seleccionadas
        foreach ($current_capabilities as $cap) {
            if (!in_array($cap, $new_capabilities)) {
                $role->remove_cap($cap);
            }
        }
        
        // Añadir capacidades nuevas
        foreach ($new_capabilities as $cap) {
            $cap = sanitize_text_field($cap);
            if (!$role->has_cap($cap)) {
                $role->add_cap($cap);
            }
        }
        
        echo '<div class="notice notice-success is-dismissible"><p>Capacidades actualizadas correctamente.</p></div>';
        
        // Recargar el rol desde la base de datos
        wp_cache_delete($role_slug, 'user_roles');
        $role = get_role($role_slug);
    }
    
    // Obtener todas las capacidades disponibles
    $all_capabilities = starter_get_all_capabilities();
    
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Editar Rol: <?php echo esc_html($role_name); ?></h1>
        <a href="<?php echo admin_url('admin.php?page=starter-roles-permissions'); ?>" class="page-title-action">Volver a Roles</a>
        <hr class="wp-header-end">
        
        <p>Selecciona las capacidades que tendrá el rol <strong><?php echo esc_html($role_name); ?></strong>.</p>
        
        <form method="post" action="">
            <?php wp_nonce_field('starter_edit_role_' . $role_slug); ?>
            
            <div class="card" style="max-width: 100%; margin-top: 20px; padding: 20px;">
                <h2>Capacidades del Rol</h2>
                <p class="description">Marca las capacidades que deseas asignar a este rol. Las capacidades controlan qué puede hacer un usuario en WordPress.</p>
                
                <div style="margin-top: 20px;">
                    <button type="button" class="button" onclick="document.querySelectorAll('input[name=\'capabilities[]\']').forEach(cb => cb.checked = true);">Seleccionar Todas</button>
                    <button type="button" class="button" onclick="document.querySelectorAll('input[name=\'capabilities[]\']').forEach(cb => cb.checked = false);">Deseleccionar Todas</button>
                </div>
                
                <div style="max-height: 600px; overflow-y: auto; padding: 20px 10px; border: 1px solid #ddd; margin-top: 15px; background: #f9f9f9;">
                    <?php foreach ($all_capabilities as $category => $caps) : ?>
                        <div style="margin-bottom: 30px; padding: 15px; background: white; border-left: 4px solid #0073aa;">
                            <h3 style="margin-top: 0; color: #0073aa;"><?php echo esc_html($category); ?></h3>
                            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 10px;">
                                <?php foreach ($caps as $cap) : 
                                    $has_cap = isset($role->capabilities[$cap]) && $role->capabilities[$cap];
                                ?>
                                    <label style="display: flex; align-items: center; padding: 5px;">
                                        <input type="checkbox" name="capabilities[]" value="<?php echo esc_attr($cap); ?>" <?php checked($has_cap); ?> style="margin-right: 8px;">
                                        <code style="font-size: 12px;"><?php echo esc_html($cap); ?></code>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <p class="submit">
                <button type="submit" name="save_capabilities" class="button button-primary">
                    <span class="dashicons dashicons-yes" style="margin-top: 3px;"></span> Guardar Capacidades
                </button>
                <a href="<?php echo admin_url('admin.php?page=starter-roles-permissions'); ?>" class="button">Cancelar</a>
            </p>
        </form>
    </div>
    <?php
}

/**
 * Obtener todas las capacidades disponibles organizadas por categoría
 */
function starter_get_all_capabilities() {
    return array(
        'Generales' => array(
            'read',
            'edit_dashboard',
            'manage_options',
        ),
        'Posts' => array(
            'edit_posts',
            'edit_others_posts',
            'edit_published_posts',
            'edit_private_posts',
            'publish_posts',
            'read_private_posts',
            'delete_posts',
            'delete_others_posts',
            'delete_published_posts',
            'delete_private_posts',
        ),
        'Páginas' => array(
            'edit_pages',
            'edit_others_pages',
            'edit_published_pages',
            'edit_private_pages',
            'publish_pages',
            'read_private_pages',
            'delete_pages',
            'delete_others_pages',
            'delete_published_pages',
            'delete_private_pages',
        ),
        'Medios' => array(
            'upload_files',
            'edit_files',
        ),
        'Categorías y Etiquetas' => array(
            'manage_categories',
            'edit_categories',
            'delete_categories',
            'assign_categories',
        ),
        'Usuarios' => array(
            'list_users',
            'create_users',
            'edit_users',
            'delete_users',
            'promote_users',
            'remove_users',
        ),
        'Temas y Plugins' => array(
            'switch_themes',
            'edit_themes',
            'edit_theme_options',
            'install_themes',
            'update_themes',
            'delete_themes',
            'install_plugins',
            'activate_plugins',
            'edit_plugins',
            'update_plugins',
            'delete_plugins',
        ),
        'Sistema' => array(
            'update_core',
            'export',
            'import',
            'unfiltered_html',
            'unfiltered_upload',
        ),
        'WooCommerce - General' => array(
            'manage_woocommerce',
            'view_woocommerce_reports',
        ),
        'WooCommerce - Productos' => array(
            'edit_product',
            'read_product',
            'delete_product',
            'edit_products',
            'edit_others_products',
            'publish_products',
            'read_private_products',
            'delete_products',
            'delete_private_products',
            'delete_published_products',
            'delete_others_products',
            'edit_private_products',
            'edit_published_products',
            'manage_product_terms',
            'edit_product_terms',
            'delete_product_terms',
            'assign_product_terms',
        ),
        'WooCommerce - Pedidos' => array(
            'edit_shop_order',
            'read_shop_order',
            'delete_shop_order',
            'edit_shop_orders',
            'edit_others_shop_orders',
            'publish_shop_orders',
            'read_private_shop_orders',
            'delete_shop_orders',
            'delete_private_shop_orders',
            'delete_published_shop_orders',
            'delete_others_shop_orders',
            'edit_private_shop_orders',
            'edit_published_shop_orders',
            'manage_shop_order_terms',
            'edit_shop_order_terms',
            'delete_shop_order_terms',
            'assign_shop_order_terms',
        ),
        'WooCommerce - Cupones' => array(
            'edit_shop_coupon',
            'read_shop_coupon',
            'delete_shop_coupon',
            'edit_shop_coupons',
            'edit_others_shop_coupons',
            'publish_shop_coupons',
            'read_private_shop_coupons',
            'delete_shop_coupons',
            'delete_private_shop_coupons',
            'delete_published_shop_coupons',
            'delete_others_shop_coupons',
            'edit_private_shop_coupons',
            'edit_published_shop_coupons',
        ),
    );
}
