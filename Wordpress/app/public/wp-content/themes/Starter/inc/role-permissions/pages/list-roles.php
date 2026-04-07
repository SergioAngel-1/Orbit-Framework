<?php
/**
 * Página: Gestionar Roles
 * 
 * Lista todos los roles existentes con opciones para editar y eliminar
 * 
 * @package Starter
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Página principal: Gestionar Roles
 */
function starter_roles_permissions_page() {
    // Procesar eliminación de rol
    if (isset($_POST['delete_role']) && check_admin_referer('starter_delete_role')) {
        $role_slug = sanitize_text_field($_POST['role_slug']);
        
        $protected_roles = array('administrator', 'editor', 'author', 'contributor', 'subscriber');
        
        if (!in_array($role_slug, $protected_roles)) {
            remove_role($role_slug);
            echo '<div class="notice notice-success is-dismissible"><p>Rol eliminado correctamente.</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>No se puede eliminar un rol predeterminado de WordPress.</p></div>';
        }
    }
    
    global $wp_roles;
    $all_roles = $wp_roles->roles;
    
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Roles y Permisos</h1>
        <a href="<?php echo admin_url('admin.php?page=starter-create-role'); ?>" class="page-title-action">Crear Nuevo Rol</a>
        <hr class="wp-header-end">
        
        <p>Gestiona los roles de usuario y sus capacidades en el sistema.</p>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 25%;">Nombre del Rol</th>
                    <th style="width: 20%;">Slug</th>
                    <th style="width: 15%;">Usuarios</th>
                    <th style="width: 15%;">Capacidades</th>
                    <th style="width: 25%;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($all_roles as $role_slug => $role_data) : 
                    $user_count = count(get_users(array('role' => $role_slug)));
                    $capabilities_count = count($role_data['capabilities']);
                    $is_protected = in_array($role_slug, array('administrator', 'editor', 'author', 'contributor', 'subscriber'));
                ?>
                <tr>
                    <td><strong><?php echo esc_html($role_data['name']); ?></strong></td>
                    <td><code><?php echo esc_html($role_slug); ?></code></td>
                    <td><?php echo $user_count; ?> usuario(s)</td>
                    <td><?php echo $capabilities_count; ?> capacidad(es)</td>
                    <td>
                        <a href="<?php echo admin_url('admin.php?page=starter-edit-role&role=' . urlencode($role_slug)); ?>" class="button button-small">
                            <span class="dashicons dashicons-edit" style="margin-top: 3px;"></span> Editar
                        </a>
                        <?php if (!$is_protected) : ?>
                            <form method="post" style="display: inline;">
                                <?php wp_nonce_field('starter_delete_role'); ?>
                                <input type="hidden" name="role_slug" value="<?php echo esc_attr($role_slug); ?>">
                                <button type="submit" name="delete_role" class="button button-small button-link-delete" onclick="return confirm('¿Estás seguro de eliminar este rol? Los usuarios con este rol se quedarán sin rol asignado.');" style="color: #b32d2e;">
                                    <span class="dashicons dashicons-trash" style="margin-top: 3px;"></span> Eliminar
                                </button>
                            </form>
                        <?php else : ?>
                            <span class="description" style="color: #999;">Rol protegido</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="card" style="max-width: 100%; margin-top: 20px; padding: 20px;">
            <h2>Acciones Rápidas</h2>
            <p>
                <a href="<?php echo admin_url('admin.php?page=starter-create-role'); ?>" class="button button-primary">
                    <span class="dashicons dashicons-plus-alt" style="margin-top: 3px;"></span> Crear Nuevo Rol
                </a>
                <a href="<?php echo admin_url('admin.php?page=starter-configure-sidebar'); ?>" class="button">
                    <span class="dashicons dashicons-menu" style="margin-top: 3px;"></span> Configurar Sidebar por Rol
                </a>
            </p>
        </div>
    </div>
    <?php
}
