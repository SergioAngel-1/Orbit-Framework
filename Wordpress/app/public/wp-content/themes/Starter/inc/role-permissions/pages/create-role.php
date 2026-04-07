<?php
/**
 * Página: Crear Nuevo Rol
 * 
 * Formulario para crear un nuevo rol basado en un rol existente
 * 
 * @package Starter
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Página: Crear Nuevo Rol
 */
function starter_create_role_page() {
    // Procesar creación de rol
    if (isset($_POST['create_role']) && check_admin_referer('starter_create_role')) {
        $role_slug = sanitize_title($_POST['role_slug']);
        $role_name = sanitize_text_field($_POST['role_name']);
        $base_role = sanitize_text_field($_POST['base_role']);
        
        if (get_role($role_slug)) {
            echo '<div class="notice notice-error is-dismissible"><p>Ya existe un rol con ese slug.</p></div>';
        } else {
            $base_role_obj = get_role($base_role);
            $capabilities = $base_role_obj ? $base_role_obj->capabilities : array('read' => true);
            
            add_role($role_slug, $role_name, $capabilities);
            
            echo '<div class="notice notice-success is-dismissible"><p>Rol creado correctamente. <a href="' . admin_url('admin.php?page=starter-edit-role&role=' . urlencode($role_slug)) . '">Editar capacidades</a></p></div>';
        }
    }
    
    global $wp_roles;
    $all_roles = $wp_roles->roles;
    
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Crear Nuevo Rol</h1>
        <a href="<?php echo admin_url('admin.php?page=starter-roles-permissions'); ?>" class="page-title-action">Volver a Roles</a>
        <hr class="wp-header-end">
        
        <p>Crea un nuevo rol de usuario basado en un rol existente.</p>
        
        <form method="post" action="">
            <?php wp_nonce_field('starter_create_role'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="role_name">Nombre del Rol <span class="description">(requerido)</span></label></th>
                    <td>
                        <input type="text" id="role_name" name="role_name" class="regular-text" required>
                        <p class="description">Nombre visible del rol (ej: "Gestor de Ventas")</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="role_slug">Slug del Rol <span class="description">(requerido)</span></label></th>
                    <td>
                        <input type="text" id="role_slug" name="role_slug" class="regular-text" required pattern="[a-z0-9_-]+" title="Solo letras minúsculas, números, guiones y guiones bajos">
                        <p class="description">Identificador único (ej: "gestor_ventas"). Solo letras minúsculas, números, guiones y guiones bajos.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="base_role">Basado en Rol</label></th>
                    <td>
                        <select id="base_role" name="base_role" class="regular-text">
                            <?php foreach ($all_roles as $role_slug => $role_data) : ?>
                                <option value="<?php echo esc_attr($role_slug); ?>" <?php selected($role_slug, 'shop_manager'); ?>>
                                    <?php echo esc_html($role_data['name']); ?> (<?php echo count($role_data['capabilities']); ?> capacidades)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">El nuevo rol heredará las capacidades del rol seleccionado.</p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <button type="submit" name="create_role" class="button button-primary">
                    <span class="dashicons dashicons-plus-alt" style="margin-top: 3px;"></span> Crear Rol
                </button>
                <a href="<?php echo admin_url('admin.php?page=starter-roles-permissions'); ?>" class="button">Cancelar</a>
            </p>
        </form>
    </div>
    <?php
}
