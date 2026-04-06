<?php
/**
 * Página: Configurar Sidebar por Rol
 * 
 * Interfaz para configurar qué elementos del menú puede ver cada rol
 * 
 * @package Starter
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Página: Configurar Sidebar por Rol
 */
function starter_configure_sidebar_page() {
    // Procesar guardado de configuración
    if (isset($_POST['save_sidebar_config']) && check_admin_referer('starter_sidebar_config')) {
        $role_slug = sanitize_text_field($_POST['role_slug']);
        $allowed_menus = isset($_POST['allowed_menus']) ? array_map('sanitize_text_field', $_POST['allowed_menus']) : array();
        
        update_option('starter_sidebar_config_' . $role_slug, $allowed_menus);
        echo '<div class="notice notice-success is-dismissible"><p>Configuración guardada correctamente.</p></div>';
    }
    
    $selected_role = isset($_GET['role']) ? sanitize_text_field($_GET['role']) : 'shop_manager';
    
    global $wp_roles, $menu, $submenu;
    $all_roles = $wp_roles->roles;
    
    if (!did_action('admin_menu')) {
        do_action('admin_menu');
    }
    
    // Agregar menús conocidos que podrían no estar en $menu debido a restricciones
    // Estos son menús de plugins/temas que deben ser configurables
    $known_menus = array(
        array('Membresías', 'manage_options', 'starter-memberships', '', 'dashicons-id-alt'),
        array('Popups', 'edit_posts', 'edit.php?post_type=starter_popup', '', 'dashicons-megaphone'),
        array('Categorías Destacadas', 'manage_options', 'featured-categories', '', 'dashicons-star-filled'),
        array('Grillas Publicitarias', 'manage_options', 'promotional-grids', '', 'dashicons-grid-view'),
        array('Generar Sitemaps', 'manage_options', 'starter-sitemap-generator', '', 'dashicons-admin-site-alt3'),
        array('Reseñas', 'manage_woocommerce', 'starter-reviews', '', 'dashicons-star-filled'),
    );
    
    // Verificar si estos menús ya están en $menu, si no, agregarlos temporalmente
    foreach ($known_menus as $known_menu) {
        $menu_slug = $known_menu[2];
        $found = false;
        foreach ($menu as $menu_item) {
            if (!empty($menu_item[2]) && $menu_item[2] === $menu_slug) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $menu[] = $known_menu;
        }
    }
    
    $saved_config = get_option('starter_sidebar_config_' . $selected_role, array());
    
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Configurar Sidebar por Rol</h1>
        <a href="<?php echo admin_url('admin.php?page=starter-roles-permissions'); ?>" class="page-title-action">Volver a Roles</a>
        <hr class="wp-header-end">
        
        <p>Selecciona qué elementos del menú de administración puede ver cada rol.</p>
        
        <form method="get" action="" style="margin: 20px 0;">
            <input type="hidden" name="page" value="starter-configure-sidebar">
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="role_select">Seleccionar Rol</label></th>
                    <td>
                        <select id="role_select" name="role" onchange="this.form.submit()" class="regular-text">
                            <?php foreach ($all_roles as $role_slug => $role_data) : ?>
                                <option value="<?php echo esc_attr($role_slug); ?>" <?php selected($selected_role, $role_slug); ?>>
                                    <?php echo esc_html($role_data['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>
        </form>
        
        <form method="post" action="">
            <?php wp_nonce_field('starter_sidebar_config'); ?>
            <input type="hidden" name="role_slug" value="<?php echo esc_attr($selected_role); ?>">
            
            <div class="card" style="max-width: 100%; margin-top: 20px; padding: 20px;">
                <h2>Elementos del Menú Disponibles</h2>
                <p class="description">Marca los elementos que el rol <strong><?php echo esc_html($all_roles[$selected_role]['name']); ?></strong> podrá ver.</p>
                
                <div style="margin-top: 15px;">
                    <button type="button" class="button" onclick="document.querySelectorAll('input[name=\'allowed_menus[]\']').forEach(cb => cb.checked = true);">Seleccionar Todos</button>
                    <button type="button" class="button" onclick="document.querySelectorAll('input[name=\'allowed_menus[]\']').forEach(cb => cb.checked = false);">Deseleccionar Todos</button>
                </div>
                
                <div style="max-height: 600px; overflow-y: auto; padding: 10px; border: 1px solid #ddd; margin-top: 15px; background: #f9f9f9;">
                    <ul style="list-style-type: none; margin: 0; padding: 0;">
                        <?php 
                        foreach ($menu as $menu_item) {
                            if (empty($menu_item[0]) || empty($menu_item[2])) continue;
                            
                            $parent_slug = $menu_item[2];
                            $parent_label = strip_tags($menu_item[0]);
                            
                            if ($parent_label === '') continue;
                            ?>
                            <li style="margin-bottom: 15px; padding: 15px; background: white; border-left: 4px solid #0073aa;">
                                <label style="font-weight: 600; font-size: 14px;">
                                    <input type="checkbox" name="allowed_menus[]" value="<?php echo esc_attr($parent_slug); ?>" <?php checked(in_array($parent_slug, $saved_config)); ?> />
                                    <?php echo esc_html($parent_label); ?>
                                </label>
                                
                                <?php 
                                if (isset($submenu[$parent_slug]) && is_array($submenu[$parent_slug]) && !empty($submenu[$parent_slug])) {
                                    ?>
                                    <ul style="list-style-type: none; margin: 10px 0 0 25px; padding: 0;">
                                        <?php foreach ($submenu[$parent_slug] as $submenu_item) {
                                            if (empty($submenu_item[0]) || empty($submenu_item[2])) continue;
                                            
                                            $submenu_slug = $submenu_item[2];
                                            $submenu_label = strip_tags($submenu_item[0]);
                                            ?>
                                            <li style="margin: 8px 0;">
                                                <label style="font-size: 13px;">
                                                    <input type="checkbox" name="allowed_menus[]" value="<?php echo esc_attr($submenu_slug); ?>" <?php checked(in_array($submenu_slug, $saved_config)); ?> />
                                                    <?php echo esc_html($submenu_label); ?>
                                                </label>
                                            </li>
                                        <?php } ?>
                                    </ul>
                                <?php } ?>
                            </li>
                        <?php } ?>
                    </ul>
                </div>
            </div>
            
            <p class="submit">
                <button type="submit" name="save_sidebar_config" class="button button-primary">
                    <span class="dashicons dashicons-yes" style="margin-top: 3px;"></span> Guardar Configuración
                </button>
                <a href="<?php echo admin_url('admin.php?page=starter-roles-permissions'); ?>" class="button">Cancelar</a>
            </p>
        </form>
    </div>
    
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Función para sincronizar checkboxes padre-hijo
        function syncParentChildren() {
            // Para cada checkbox padre (menú principal)
            $('input[type="checkbox"][name="allowed_menus[]"]').each(function() {
                var $parent = $(this);
                var $container = $parent.closest('li');
                var $childrenContainer = $container.find('ul').first();
                
                // Solo procesar si tiene hijos
                if ($childrenContainer.length > 0) {
                    var $children = $childrenContainer.find('input[type="checkbox"][name="allowed_menus[]"]');
                    
                    // Evento cuando cambia el padre
                    $parent.off('change').on('change', function() {
                        if ($(this).is(':checked')) {
                            // Si el padre se marca: marcar y deshabilitar todos los hijos
                            $children.prop('checked', true).prop('disabled', true);
                        } else {
                            // Si el padre se desmarca: desmarcar y habilitar todos los hijos
                            $children.prop('checked', false).prop('disabled', false);
                        }
                    });
                    
                    // Aplicar estado inicial
                    if ($parent.is(':checked')) {
                        $children.prop('checked', true).prop('disabled', true);
                    } else {
                        $children.prop('disabled', false);
                    }
                }
            });
        }
        
        // Ejecutar al cargar la página
        syncParentChildren();
        
        // Modificar los botones de seleccionar/deseleccionar todo
        $('button[onclick*="allowed_menus"]').off('click').on('click', function(e) {
            e.preventDefault();
            var selectAll = $(this).text().includes('Seleccionar');
            
            // Cambiar estado de todos los checkboxes padre
            $('input[type="checkbox"][name="allowed_menus[]"]').each(function() {
                var $checkbox = $(this);
                var $container = $checkbox.closest('li');
                var $childrenContainer = $container.find('ul').first();
                
                // Solo cambiar padres (los que tienen hijos)
                if ($childrenContainer.length > 0 || $checkbox.closest('ul').length === 0) {
                    $checkbox.prop('checked', selectAll);
                    $checkbox.trigger('change');
                }
            });
        });
    });
    </script>
    <?php
}
