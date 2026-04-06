<?php
/**
 * Campos personalizados para items de menú
 * Agrega campo de membresía mínima requerida a cada item del menú
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Agregar campos de membresía a items de menú
 * - Nivel de membresía requerido
 * - Modo de visibilidad (cascada o exacto)
 */
function starter_menu_item_membership_field($item_id, $item, $depth, $args) {
    // Obtener valores actuales
    $min_membership = get_post_meta($item_id, '_menu_item_min_membership', true);
    $min_membership = $min_membership !== '' ? intval($min_membership) : 0;
    
    $membership_mode = get_post_meta($item_id, '_menu_item_membership_mode', true);
    $membership_mode = $membership_mode ?: 'cascade'; // Por defecto: cascada (comportamiento anterior)
    
    // Obtener niveles de membresía si el plugin está activo
    $membership_levels = [];
    if (class_exists('Starter_Memberships')) {
        $membership_levels = Starter_Memberships::get_all_membership_levels();
    }
    
    if (empty($membership_levels)) {
        return; // No mostrar el campo si no hay niveles de membresía
    }
    
    // Si es una categoría de producto, obtener el nivel mínimo de la categoría
    $category_min_level = 0;
    $category_name = '';
    if ($item->object === 'product_cat' && $item->object_id) {
        $cat_min = get_term_meta($item->object_id, '_min_membership_level', true);
        if ($cat_min !== '' && intval($cat_min) > 0) {
            $category_min_level = intval($cat_min);
            $term = get_term($item->object_id, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $category_name = $term->name;
            }
        }
        // Si el valor guardado es menor que el nivel de la categoría, actualizarlo
        if ($min_membership < $category_min_level) {
            $min_membership = $category_min_level;
            update_post_meta($item_id, '_menu_item_min_membership', $min_membership);
        }
    }
    ?>
    <p class="field-min-membership description description-wide">
        <label for="edit-menu-item-min-membership-<?php echo $item_id; ?>">
            <?php _e('Membresía Requerida', 'starter'); ?><br />
            <select 
                id="edit-menu-item-min-membership-<?php echo $item_id; ?>" 
                class="widefat edit-menu-item-min-membership" 
                name="menu-item-min-membership[<?php echo $item_id; ?>]"
            >
                <?php foreach ($membership_levels as $level_id => $level) : 
                    // Si es categoría de producto, solo mostrar niveles >= al nivel de la categoría
                    if ($category_min_level > 0 && $level_id < $category_min_level) {
                        continue;
                    }
                ?>
                    <option value="<?php echo esc_attr($level_id); ?>" <?php selected($min_membership, $level_id); ?>>
                        <?php echo esc_html($level['icon'] . ' ' . $level['name']); ?>
                        <?php if ($level_id === 0) : ?>
                            (Público - Todos pueden ver)
                        <?php endif; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($category_min_level > 0) : ?>
                <span class="description" style="color: #d63638; display: block; margin-top: 5px;">
                    ⚠️ La categoría "<?php echo esc_html($category_name); ?>" requiere nivel <?php echo esc_html($membership_levels[$category_min_level]['name']); ?> o superior.
                </span>
            <?php endif; ?>
        </label>
    </p>
    
    <p class="field-membership-mode description description-wide">
        <label for="edit-menu-item-membership-mode-<?php echo $item_id; ?>">
            <?php _e('Modo de Visibilidad', 'starter'); ?><br />
            <select 
                id="edit-menu-item-membership-mode-<?php echo $item_id; ?>" 
                class="widefat edit-menu-item-membership-mode" 
                name="menu-item-membership-mode[<?php echo $item_id; ?>]"
            >
                <option value="cascade" <?php selected($membership_mode, 'cascade'); ?>>
                    📈 Cascada (este nivel y superiores)
                </option>
                <option value="exact" <?php selected($membership_mode, 'exact'); ?>>
                    🎯 Exacto (solo este nivel específico)
                </option>
            </select>
        </label>
        <span class="description">
            <?php if ($min_membership === 0) : ?>
                <?php _e('Para nivel público, el modo no aplica - todos pueden ver.', 'starter'); ?>
            <?php else : ?>
                <?php _e('<strong>Cascada:</strong> Visible para este nivel y superiores. <strong>Exacto:</strong> Solo visible para este nivel específico.', 'starter'); ?>
            <?php endif; ?>
        </span>
    </p>
    <?php
}
add_action('wp_nav_menu_item_custom_fields', 'starter_menu_item_membership_field', 10, 4);

/**
 * Guardar los campos de membresía
 */
function starter_save_menu_item_membership_field($menu_id, $menu_item_db_id, $args) {
    // Guardar nivel de membresía
    if (isset($_POST['menu-item-min-membership'][$menu_item_db_id])) {
        $min_membership = absint($_POST['menu-item-min-membership'][$menu_item_db_id]);
        update_post_meta($menu_item_db_id, '_menu_item_min_membership', $min_membership);
    } else {
        // Si no se envía, establecer en 0 (público)
        update_post_meta($menu_item_db_id, '_menu_item_min_membership', 0);
    }
    
    // Guardar modo de visibilidad
    if (isset($_POST['menu-item-membership-mode'][$menu_item_db_id])) {
        $mode = sanitize_text_field($_POST['menu-item-membership-mode'][$menu_item_db_id]);
        // Validar que sea un valor permitido
        $mode = in_array($mode, ['cascade', 'exact']) ? $mode : 'cascade';
        update_post_meta($menu_item_db_id, '_menu_item_membership_mode', $mode);
    } else {
        // Por defecto: cascada
        update_post_meta($menu_item_db_id, '_menu_item_membership_mode', 'cascade');
    }
}
add_action('wp_update_nav_menu_item', 'starter_save_menu_item_membership_field', 10, 3);

/**
 * Agregar estilos CSS para el campo en el admin
 */
function starter_menu_item_membership_field_styles() {
    ?>
    <style>
        .field-min-membership {
            margin: 5px 0;
        }
        .field-min-membership label {
            display: block;
            margin-bottom: 5px;
        }
        .field-min-membership select {
            width: 100%;
        }
        .field-min-membership .description {
            display: block;
            margin-top: 5px;
            font-style: italic;
            color: #666;
        }
    </style>
    <?php
}
add_action('admin_head-nav-menus.php', 'starter_menu_item_membership_field_styles');
