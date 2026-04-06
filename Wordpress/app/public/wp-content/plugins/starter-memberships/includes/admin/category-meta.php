<?php
/**
 * Meta campos para categorías de productos (nivel mínimo de membresía)
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Agregar campo al crear categoría
add_action('product_cat_add_form_fields', 'starter_memberships_add_category_fields');

// Agregar campo al editar categoría
add_action('product_cat_edit_form_fields', 'starter_memberships_edit_category_fields', 10, 2);

// Guardar campo
add_action('created_product_cat', 'starter_memberships_save_category_fields');
add_action('edited_product_cat', 'starter_memberships_save_category_fields');

// Agregar columna en lista de categorías
add_filter('manage_edit-product_cat_columns', 'starter_memberships_category_columns');
add_filter('manage_product_cat_custom_column', 'starter_memberships_category_column_content', 10, 3);

/**
 * Campos al crear categoría
 * Solo se muestra para categorías padre (sin parent seleccionado)
 * Las subcategorías heredan el nivel de su padre
 */
function starter_memberships_add_category_fields() {
    $levels = Starter_Memberships::get_all_membership_levels();
    ?>
    <div class="form-field starter-membership-field">
        <label for="min_membership_level"><?php _e('Membresía Mínima Requerida', 'starter-memberships'); ?></label>
        <select id="min_membership_level" name="min_membership_level">
            <?php foreach ($levels as $level_id => $level) : ?>
                <option value="<?php echo esc_attr($level_id); ?>">
                    <?php echo esc_html($level['icon'] . ' ' . $level['name']); ?>
                    <?php if ($level_id === 0) : ?>
                        (<?php _e('Público', 'starter-memberships'); ?>)
                    <?php endif; ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php _e('Nivel mínimo de membresía requerido para ver los productos de esta categoría. Las subcategorías heredarán este nivel automáticamente.', 'starter-memberships'); ?>
        </p>
    </div>
    <div class="form-field starter-membership-inherited-notice" style="display: none;">
        <label><?php _e('Membresía Mínima Requerida', 'starter-memberships'); ?></label>
        <div style="padding: 8px 12px; background: #f0f6fc; border: 1px solid #c3c4c7; border-radius: 4px; color: #1d2327;">
            <span class="dashicons dashicons-info" style="color: #2271b1; margin-right: 5px;"></span>
            <?php _e('Esta subcategoría heredará automáticamente el nivel de membresía de la categoría padre seleccionada.', 'starter-memberships'); ?>
        </div>
    </div>
    <script>
    jQuery(document).ready(function($) {
        var $membershipField = $('.starter-membership-field');
        var $inheritedNotice = $('.starter-membership-inherited-notice');
        var $parentSelect = $('#parent');
        
        // Función para ocultar/mostrar campo según si tiene padre seleccionado
        function toggleMembershipField() {
            var parentVal = $parentSelect.val();
            var hasParent = parentVal && parentVal !== '-1' && parentVal !== '0' && parentVal !== '';
            
            if (hasParent) {
                $membershipField.slideUp(200, function() {
                    $inheritedNotice.slideDown(200);
                });
            } else {
                $inheritedNotice.slideUp(200, function() {
                    $membershipField.slideDown(200);
                });
            }
        }
        
        // Ejecutar al cargar (sin animación inicial)
        (function initToggle() {
            var parentVal = $parentSelect.val();
            var hasParent = parentVal && parentVal !== '-1' && parentVal !== '0' && parentVal !== '';
            if (hasParent) {
                $membershipField.hide();
                $inheritedNotice.show();
            } else {
                $membershipField.show();
                $inheritedNotice.hide();
            }
        })();
        
        // Escuchar cambios en el select de padre
        $parentSelect.on('change', toggleMembershipField);
        
        // También escuchar el evento select2 si está activo (WooCommerce usa select2)
        if ($.fn.select2) {
            $parentSelect.on('select2:select select2:unselect select2:clear', toggleMembershipField);
        }
        
        // Observar cambios en el DOM por si el select se recarga via AJAX
        if (typeof MutationObserver !== 'undefined') {
            var observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.type === 'childList' && mutation.target.id === 'parent') {
                        toggleMembershipField();
                    }
                });
            });
            
            if ($parentSelect.length) {
                observer.observe($parentSelect[0], { childList: true, subtree: true });
            }
        }
    });
    </script>
    <?php
}

/**
 * Campos al editar categoría
 * Solo editable para categorías padre. Las subcategorías muestran el nivel heredado.
 */
function starter_memberships_edit_category_fields($term, $taxonomy) {
    $min_level = get_term_meta($term->term_id, '_min_membership_level', true);
    $min_level = $min_level !== '' ? intval($min_level) : 0;
    $levels = Starter_Memberships::get_all_membership_levels();
    $is_child = $term->parent > 0;
    
    // Si es subcategoría, obtener el nivel del padre
    $inherited_level = 0;
    $parent_name = '';
    if ($is_child) {
        $inherited_level = starter_get_parent_membership_level($term->term_id);
        $parent_term = get_term($term->parent, 'product_cat');
        if ($parent_term && !is_wp_error($parent_term)) {
            $parent_name = $parent_term->name;
        }
    }
    
    $level_info = Starter_Memberships::get_membership_level($is_child ? $inherited_level : $min_level);
    ?>
    <tr class="form-field">
        <th scope="row">
            <label for="min_membership_level"><?php _e('Membresía Mínima Requerida', 'starter-memberships'); ?></label>
        </th>
        <td>
            <?php if ($is_child) : ?>
                <!-- Subcategoría: mostrar nivel heredado (solo lectura) -->
                <div style="padding: 8px 12px; background: #f0f0f1; border-radius: 4px; display: inline-block;">
                    <span style="color: <?php echo esc_attr($level_info['color']); ?>; font-weight: 500;">
                        <?php echo esc_html($level_info['icon'] . ' ' . $level_info['name']); ?>
                    </span>
                    <span style="color: #666; margin-left: 8px;">
                        (<?php printf(__('Heredado de "%s"', 'starter-memberships'), esc_html($parent_name)); ?>)
                    </span>
                </div>
                <p class="description">
                    <?php _e('Las subcategorías heredan automáticamente el nivel de membresía de su categoría padre. Para cambiar el nivel, edita la categoría padre.', 'starter-memberships'); ?>
                </p>
            <?php else : ?>
                <!-- Categoría padre: selector editable -->
                <select id="min_membership_level" name="min_membership_level">
                    <?php foreach ($levels as $level_id => $level) : ?>
                        <option value="<?php echo esc_attr($level_id); ?>" <?php selected($min_level, $level_id); ?>>
                            <?php echo esc_html($level['icon'] . ' ' . $level['name']); ?>
                            <?php if ($level_id === 0) : ?>
                                (<?php _e('Público', 'starter-memberships'); ?>)
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description">
                    <?php _e('Nivel mínimo de membresía requerido. Este nivel se aplicará automáticamente a todas las subcategorías.', 'starter-memberships'); ?>
                </p>
            <?php endif; ?>
        </td>
    </tr>
    <?php
}

/**
 * Guardar campo de categoría
 * Solo guarda para categorías padre y propaga a subcategorías en cascada
 */
function starter_memberships_save_category_fields($term_id) {
    $term = get_term($term_id, 'product_cat');
    
    // Solo procesar si es categoría padre (sin parent)
    if ($term && $term->parent == 0 && isset($_POST['min_membership_level'])) {
        $min_level = absint($_POST['min_membership_level']);
        update_term_meta($term_id, '_min_membership_level', $min_level);
        
        // Propagar a todas las subcategorías en cascada
        starter_propagate_membership_to_children($term_id, $min_level);
        
        // Invalidar cachés relacionadas con categorías y productos
        starter_invalidate_membership_related_caches();
        
        error_log(sprintf(
            'Starter Memberships: Categoría padre %d actualizada con nivel %d y propagado a subcategorías',
            $term_id, $min_level
        ));
    }
}

/**
 * Invalidar todas las cachés relacionadas cuando cambia la membresía de una categoría
 * Esto incluye: home-sections, featured-categories, proxy WooCommerce
 */
function starter_invalidate_membership_related_caches() {
    // 1. Invalidar caché de home-sections
    do_action('starter_home_sections_changed');
    
    // 2. Invalidar caché del proxy WooCommerce (productos + categorías afectados por membresía)
    if (class_exists('Starter_WC_Cache_Manager')) {
        Starter_WC_Cache_Manager::invalidate_by_route_type(['products', 'categories']);
    }
    
    // 3. Invalidar transients de featured-categories (si existen)
    delete_transient('starter_featured_categories');
    
    // 4. Limpiar cualquier transient relacionado con categorías
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_fihs_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_fihs_%'");
    
    error_log('Starter Memberships: Cachés invalidadas por cambio de membresía en categoría');
}

/**
 * Propagar nivel de membresía a todas las subcategorías recursivamente
 */
function starter_propagate_membership_to_children($parent_id, $level) {
    $children = get_terms([
        'taxonomy' => 'product_cat',
        'parent' => $parent_id,
        'hide_empty' => false
    ]);
    
    if (empty($children) || is_wp_error($children)) {
        return;
    }
    
    foreach ($children as $child) {
        // Actualizar el nivel de la subcategoría
        update_term_meta($child->term_id, '_min_membership_level', $level);
        
        error_log(sprintf(
            'Starter Memberships: Subcategoría %d (%s) actualizada con nivel %d (heredado de padre %d)',
            $child->term_id, $child->name, $level, $parent_id
        ));
        
        // Recursivamente propagar a los hijos de esta subcategoría
        starter_propagate_membership_to_children($child->term_id, $level);
    }
}

/**
 * Obtener el nivel de membresía del padre (recursivo hasta encontrar categoría raíz)
 */
function starter_get_parent_membership_level($term_id) {
    $term = get_term($term_id, 'product_cat');
    
    if (!$term || is_wp_error($term) || $term->parent == 0) {
        // Es categoría raíz, retornar su propio nivel
        $level = get_term_meta($term_id, '_min_membership_level', true);
        return $level !== '' ? intval($level) : 0;
    }
    
    // Buscar recursivamente hasta encontrar la categoría padre raíz
    return starter_get_parent_membership_level($term->parent);
}

/**
 * Agregar columna de membresía en lista de categorías
 */
function starter_memberships_category_columns($columns) {
    $new_columns = [];
    
    foreach ($columns as $key => $value) {
        $new_columns[$key] = $value;
        
        if ($key === 'name') {
            $new_columns['min_membership'] = __('Membresía Mín.', 'starter-memberships');
        }
    }
    
    return $new_columns;
}

/**
 * Contenido de la columna de membresía
 * Muestra indicador si el nivel es heredado del padre
 */
function starter_memberships_category_column_content($content, $column_name, $term_id) {
    if ($column_name !== 'min_membership') {
        return $content;
    }
    
    $term = get_term($term_id, 'product_cat');
    $is_child = $term && $term->parent > 0;
    
    $min_level = get_term_meta($term_id, '_min_membership_level', true);
    $min_level = $min_level !== '' ? intval($min_level) : 0;
    $level_info = Starter_Memberships::get_membership_level($min_level);
    
    $inherited_text = $is_child ? ' <small style="color:#666;">(heredado)</small>' : '';
    
    return sprintf(
        '<span style="color: %s;">%s %s</span>%s',
        esc_attr($level_info['color']),
        esc_html($level_info['icon']),
        esc_html($level_info['name']),
        $inherited_text
    );
}

/**
 * Agregar datos de membresía a la respuesta de categoría en API
 */
add_filter('woocommerce_rest_prepare_product_cat', 'starter_add_membership_data_to_category_api', 10, 3);

function starter_add_membership_data_to_category_api($response, $item, $request) {
    $data = $response->get_data();
    
    $min_level = get_term_meta($item->term_id, '_min_membership_level', true);
    $min_level = $min_level !== '' ? intval($min_level) : 0;
    $level_info = Starter_Memberships::get_membership_level($min_level);
    
    $data['min_membership_level'] = $min_level;
    $data['min_membership_info'] = [
        'level' => $min_level,
        'name' => $level_info['name'],
        'slug' => $level_info['slug'],
        'icon' => $level_info['icon'],
        'color' => $level_info['color'],
        'is_public' => $min_level === 0
    ];
    
    $response->set_data($data);
    
    return $response;
}
