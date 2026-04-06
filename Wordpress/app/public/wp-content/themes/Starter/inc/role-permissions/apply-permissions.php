<?php
/**
 * Aplicar Permisos de Sidebar
 * 
 * Aplica la configuración guardada de sidebar para cada rol
 * 
 * @package Starter
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Aplicar configuración de sidebar guardada
 */
function starter_apply_saved_sidebar_config() {
    $user = wp_get_current_user();
    
    // Obtener el primer rol del usuario
    $user_role = !empty($user->roles) ? $user->roles[0] : '';
    
    if (empty($user_role)) {
        return;
    }
    
    // Obtener configuración guardada para este rol
    $allowed_menus = get_option('starter_sidebar_config_' . $user_role, array());
    
    // Si no hay configuración, no ocultar nada (comportamiento por defecto)
    if (empty($allowed_menus)) {
        return;
    }
    
    // Migrar slugs antiguos a nuevos (corrección de errores)
    $slug_migrations = array(
        'fipg-promotional-grids' => 'promotional-grids',
    );
    $needs_save = false;
    foreach ($slug_migrations as $old_slug => $new_slug) {
        $old_key = array_search($old_slug, $allowed_menus);
        if ($old_key !== false) {
            $allowed_menus[$old_key] = $new_slug;
            $needs_save = true;
        }
    }
    if ($needs_save) {
        update_option('starter_sidebar_config_' . $user_role, $allowed_menus);
    }
    
    global $menu, $submenu;
    
    // Identificar qué menús padre tienen al menos un submenú permitido
    $parents_with_allowed_children = array();
    if (is_array($submenu)) {
        foreach ($submenu as $parent_slug => $submenu_items) {
            $has_allowed = false;
            foreach ($submenu_items as $submenu_item) {
                if (!empty($submenu_item[2]) && in_array($submenu_item[2], $allowed_menus)) {
                    $has_allowed = true;
                    break;
                }
            }
            if ($has_allowed) {
                $parents_with_allowed_children[] = $parent_slug;
            }
        }
    }
    
    // Ocultar menús principales no permitidos
    foreach ($menu as $index => $menu_item) {
        if (!empty($menu_item[2])) {
            $menu_slug = $menu_item[2];
            
            // Siempre permitir el perfil
            if ($menu_slug === 'profile.php') {
                continue;
            }
            
            // No ocultar si el menú está explícitamente permitido O tiene hijos permitidos
            $is_allowed = in_array($menu_slug, $allowed_menus);
            $has_allowed_children = in_array($menu_slug, $parents_with_allowed_children);
            
            if (!$is_allowed && !$has_allowed_children) {
                remove_menu_page($menu_slug);
            }
        }
    }
    
    // Ocultar submenús no permitidos
    if (is_array($submenu)) {
        foreach ($submenu as $parent_slug => $submenu_items) {
            // Verificar si el padre está visible (permitido o tiene hijos permitidos)
            $parent_is_visible = in_array($parent_slug, $allowed_menus) || in_array($parent_slug, $parents_with_allowed_children);
            
            if ($parent_is_visible) {
                foreach ($submenu_items as $index => $submenu_item) {
                    if (!empty($submenu_item[2])) {
                        $submenu_slug = $submenu_item[2];
                        
                        // Si el padre está marcado, mostrar todos los hijos
                        if (in_array($parent_slug, $allowed_menus)) {
                            continue;
                        }
                        
                        // Si el padre NO está marcado, solo mostrar hijos específicamente permitidos
                        if (!in_array($submenu_slug, $allowed_menus)) {
                            remove_submenu_page($parent_slug, $submenu_slug);
                        }
                    }
                }
            }
        }
    }
}
add_action('admin_menu', 'starter_apply_saved_sidebar_config', 999);
