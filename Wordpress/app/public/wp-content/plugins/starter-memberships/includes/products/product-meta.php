<?php
/**
 * Meta boxes para productos de membresía en WooCommerce
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Inicializar meta boxes de productos
 */
function starter_memberships_init_products() {
    // Agregar pestaña de membresía en datos de producto
    add_filter('woocommerce_product_data_tabs', 'starter_memberships_product_tab');
    
    // Agregar contenido de la pestaña
    add_action('woocommerce_product_data_panels', 'starter_memberships_product_panel');
    
    // Guardar datos de membresía
    add_action('woocommerce_process_product_meta', 'starter_memberships_save_product_data');
    
    // Agregar columna en lista de productos
    add_filter('manage_edit-product_columns', 'starter_memberships_product_columns');
    add_action('manage_product_posts_custom_column', 'starter_memberships_product_column_content', 10, 2);
}

/**
 * Agregar pestaña de membresía en datos de producto
 */
function starter_memberships_product_tab($tabs) {
    $tabs['membership'] = [
        'label' => __('Membresía', 'starter-memberships'),
        'target' => 'membership_product_data',
        'class' => ['show_if_simple', 'show_if_virtual'],
        'priority' => 25
    ];
    
    return $tabs;
}

/**
 * Contenido del panel de membresía
 */
function starter_memberships_product_panel() {
    global $post;
    
    $is_membership = get_post_meta($post->ID, '_is_membership_product', true);
    $membership_level = get_post_meta($post->ID, '_membership_level', true);
    $monthly_points = get_post_meta($post->ID, '_membership_monthly_points', true);
    $duration_days = get_post_meta($post->ID, '_membership_duration_days', true);
    $min_registration_days = get_post_meta($post->ID, '_membership_min_registration_days', true);
    $renewal_period = get_post_meta($post->ID, '_membership_renewal_period', true);
    $slug_en = get_post_meta($post->ID, '_membership_slug_en', true);
    
    // Usar niveles para admin (siempre incluye los predefinidos para poder crear productos)
    $levels = Starter_Memberships::get_levels_for_admin_select();
    ?>
    <div id="membership_product_data" class="panel woocommerce_options_panel">
        <div class="options_group">
            <p class="form-field">
                <label for="_is_membership_product">
                    <input type="checkbox" 
                           id="_is_membership_product" 
                           name="_is_membership_product" 
                           value="yes"
                           <?php checked($is_membership, 'yes'); ?>>
                    <?php _e('Este es un producto de membresía', 'starter-memberships'); ?>
                </label>
            </p>
            
            <p class="description" style="padding-left: 12px; color: #666;">
                <?php _e('Marcar esta opción para convertir este producto en una membresía que otorga acceso a contenido exclusivo y Virtual Coins por periodo.', 'starter-memberships'); ?>
            </p>
        </div>
        
        <div class="options_group membership-fields" style="<?php echo $is_membership !== 'yes' ? 'display:none;' : ''; ?>">
            <p class="form-field">
                <label for="_membership_level"><?php _e('Nivel de Membresía', 'starter-memberships'); ?></label>
                <select id="_membership_level" name="_membership_level" class="select short">
                    <?php foreach ($levels as $level_id => $level) : ?>
                            <option value="<?php echo esc_attr($level_id); ?>" <?php selected($membership_level, $level_id); ?>>
                                <?php echo esc_html($level['icon'] . ' ' . $level['name']); ?>
                            </option>
                    <?php endforeach; ?>
                </select>
            </p>
            
            <?php
            woocommerce_wp_text_input([
                'id' => '_membership_monthly_points',
                'label' => __('Virtual Coins (FC)', 'starter-memberships'),
                'description' => __('Cantidad de Virtual Coins que se otorgan UNA ÚNICA VEZ al activar la membresía. Este valor es obligatorio.', 'starter-memberships'),
                'desc_tip' => true,
                'type' => 'number',
                'custom_attributes' => [
                    'min' => '0',
                    'step' => '1000'
                ],
                'value' => $monthly_points,
                'placeholder' => __('Ej: 50000', 'starter-memberships')
            ]);
            
            woocommerce_wp_select([
                'id' => '_membership_duration_days',
                'label' => __('Duración', 'starter-memberships'),
                'description' => __('Duración de la membresía. Indefinida = no expira.', 'starter-memberships'),
                'desc_tip' => true,
                'options' => [
                    '0' => __('Indefinida (no expira)', 'starter-memberships'),
                    '30' => __('30 días', 'starter-memberships'),
                    '60' => __('60 días', 'starter-memberships'),
                    '90' => __('90 días', 'starter-memberships'),
                    '180' => __('180 días', 'starter-memberships'),
                    '365' => __('365 días (1 año)', 'starter-memberships'),
                ],
                'value' => $duration_days !== '' ? $duration_days : '30'
            ]);
            
            woocommerce_wp_text_input([
                'id' => '_membership_min_registration_days',
                'label' => __('Antigüedad requerida (días)', 'starter-memberships'),
                'description' => __('Días mínimos que el usuario debe estar registrado para poder comprar esta membresía. 0 = sin restricción.', 'starter-memberships'),
                'desc_tip' => true,
                'type' => 'number',
                'custom_attributes' => [
                    'min' => '0',
                    'step' => '1'
                ],
                'value' => $min_registration_days ?: 0,
                'placeholder' => '0'
            ]);
            
            // --- Slugs para URLs de membresía (SEO) ---
            // Solo visibles para niveles > 0 (no aplican para Zanahoria/público)
            ?>
            <div class="membership-slug-fields" style="<?php echo intval($membership_level) === 0 ? 'display:none;' : ''; ?>">
            <?php
            woocommerce_wp_text_input([
                'id' => '_membership_slug_es',
                'label' => __('Slug URL (Español)', 'starter-memberships'),
                'description' => __('Slug usado en la URL del catálogo en español. Ej: "plata", "oro". Si está vacío se genera del nombre del producto.', 'starter-memberships'),
                'desc_tip' => true,
                'type' => 'text',
                'value' => get_post_meta($post->ID, '_membership_slug_es', true),
                'placeholder' => sanitize_title($post->post_title)
            ]);

            woocommerce_wp_text_input([
                'id' => '_membership_slug_en',
                'label' => __('Slug URL (Inglés)', 'starter-memberships'),
                'description' => __('Slug usado en la URL del catálogo en inglés. Ej: "silver", "gold". Requerido para la versión en inglés del sitio.', 'starter-memberships'),
                'desc_tip' => true,
                'type' => 'text',
                'value' => $slug_en,
                'placeholder' => ''
            ]);
            ?>
            </div>
            <?php

            woocommerce_wp_select([
                'id' => '_membership_renewal_period',
                'label' => __('Periodo de renovación', 'starter-memberships'),
                'description' => __('Con qué frecuencia se renueva automáticamente la membresía.', 'starter-memberships'),
                'desc_tip' => true,
                'options' => [
                    'none' => __('Sin renovación automática', 'starter-memberships'),
                    'monthly' => __('Mensual (cada 30 días)', 'starter-memberships'),
                    'bimonthly' => __('Bimestral (cada 60 días)', 'starter-memberships'),
                    'quarterly' => __('Trimestral (cada 90 días)', 'starter-memberships'),
                    'biannual' => __('Semestral (cada 180 días)', 'starter-memberships'),
                    'annual' => __('Anual (cada 365 días)', 'starter-memberships'),
                ],
                'value' => $renewal_period ?: 'monthly'
            ]);
            ?>
            
            <p class="form-field" style="margin-top: 10px;">
                <span class="description" style="display: block; padding: 10px; background: #e7f3ff; border-left: 3px solid #2271b1; border-radius: 3px;">
                    💡 <?php _e('Los beneficios se configuran en', 'starter-memberships'); ?> 
                    <a href="<?php echo admin_url('admin.php?page=starter-memberships-benefits'); ?>"><?php _e('Membresías → Beneficios', 'starter-memberships'); ?></a>
                </span>
            </p>
            
            <div class="membership-level-preview" style="margin: 15px 12px; padding: 15px; background: #f8f8f8; border-radius: 5px;">
                <h4 style="margin-top: 0;"><?php _e('Vista previa del nivel', 'starter-memberships'); ?></h4>
                <div id="membership-preview-content">
                    <?php if ($membership_level) : 
                        $level_info = $levels[$membership_level] ?? $levels[1];
                    ?>
                        <p>
                            <strong><?php echo esc_html($level_info['icon'] . ' ' . $level_info['name']); ?></strong><br>
                            <span style="color: <?php echo esc_attr($level_info['color']); ?>;">
                                <?php echo esc_html($level_info['description'] ?? ''); ?>
                            </span>
                        </p>
                    <?php else : ?>
                        <p><em><?php _e('Selecciona un nivel para ver la vista previa.', 'starter-memberships'); ?></em></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script type="text/javascript">
    jQuery(function($) {
        // Toggle campos de membresía
        $('#_is_membership_product').on('change', function() {
            if ($(this).is(':checked')) {
                $('.membership-fields').slideDown();
                // Marcar como virtual automáticamente
                $('#_virtual').prop('checked', true).trigger('change');
            } else {
                $('.membership-fields').slideUp();
            }
        });
        
        // Ocultar/mostrar campos de slug según nivel seleccionado
        // NOTA: Los FC se definen manualmente en el producto, no hay valores por defecto
        $('#_membership_level').on('change', function() {
            var level = parseInt($(this).val(), 10);
            
            // Ocultar campos de slug para nivel 0 (Zanahoria/público)
            if (level === 0) {
                $('.membership-slug-fields').slideUp();
            } else {
                $('.membership-slug-fields').slideDown();
            }
        }).trigger('change');
        
        // Ocultar/mostrar periodo de renovación según duración
        $('#_membership_duration_days').on('change', function() {
            var duration = $(this).val();
            var $renewalField = $('#_membership_renewal_period').closest('.form-field');
            
            if (duration === '0') {
                // Duración indefinida: ocultar renovación y seleccionar 'none'
                $renewalField.slideUp();
                $('#_membership_renewal_period').val('none');
            } else {
                $renewalField.slideDown();
            }
        }).trigger('change');
    });
    </script>
    <?php
}

/**
 * Guardar datos de membresía del producto
 */
function starter_memberships_save_product_data($post_id) {
    // Verificar nonce (WooCommerce ya lo hace)
    
    $is_membership = isset($_POST['_is_membership_product']) ? 'yes' : 'no';
    update_post_meta($post_id, '_is_membership_product', $is_membership);
    
    // Invalidar cache de niveles cuando se actualiza un producto de membresía
    Starter_Memberships::invalidate_levels_cache();
    
    if ($is_membership === 'yes') {
        // Guardar nivel
        $level = isset($_POST['_membership_level']) ? absint($_POST['_membership_level']) : 1;
        update_post_meta($post_id, '_membership_level', $level);
        
        // Guardar puntos por periodo (FC se definen SOLO en el producto, no hay fallback)
        $monthly_points = isset($_POST['_membership_monthly_points']) ? absint($_POST['_membership_monthly_points']) : 0;
        update_post_meta($post_id, '_membership_monthly_points', $monthly_points);
        
        // Guardar duración
        $duration = isset($_POST['_membership_duration_days']) ? absint($_POST['_membership_duration_days']) : 30;
        update_post_meta($post_id, '_membership_duration_days', $duration);
        
        // Guardar antigüedad requerida
        $min_registration_days = isset($_POST['_membership_min_registration_days']) ? absint($_POST['_membership_min_registration_days']) : 0;
        update_post_meta($post_id, '_membership_min_registration_days', $min_registration_days);
        
        // Guardar periodo de renovación
        $renewal_period = isset($_POST['_membership_renewal_period']) ? sanitize_text_field($_POST['_membership_renewal_period']) : 'monthly';
        $valid_periods = ['none', 'monthly', 'bimonthly', 'quarterly', 'biannual', 'annual'];
        if (!in_array($renewal_period, $valid_periods)) {
            $renewal_period = 'monthly';
        }
        update_post_meta($post_id, '_membership_renewal_period', $renewal_period);
        
        // Guardar slugs de URL
        $slug_es = isset($_POST['_membership_slug_es']) ? sanitize_title($_POST['_membership_slug_es']) : '';
        update_post_meta($post_id, '_membership_slug_es', $slug_es);
        
        $slug_en = isset($_POST['_membership_slug_en']) ? sanitize_title($_POST['_membership_slug_en']) : '';
        update_post_meta($post_id, '_membership_slug_en', $slug_en);
        
        // Asegurar que el producto sea virtual
        update_post_meta($post_id, '_virtual', 'yes');
        
        // Log
        error_log(sprintf(
            'Starter Memberships: Producto %d configurado como membresía nivel %d con %d FC/mes, antigüedad requerida: %d días, renovación: %s',
            $post_id, $level, $monthly_points, $min_registration_days, $renewal_period
        ));
    }
}

/**
 * Agregar columna de membresía en lista de productos
 */
function starter_memberships_product_columns($columns) {
    $new_columns = [];
    
    foreach ($columns as $key => $value) {
        $new_columns[$key] = $value;
        
        // Insertar después de la columna de precio
        if ($key === 'price') {
            $new_columns['membership'] = __('Membresía', 'starter-memberships');
        }
    }
    
    return $new_columns;
}

/**
 * Contenido de la columna de membresía
 */
function starter_memberships_product_column_content($column, $post_id) {
    if ($column !== 'membership') {
        return;
    }
    
    $is_membership = get_post_meta($post_id, '_is_membership_product', true);
    
    if ($is_membership === 'yes') {
        $level = intval(get_post_meta($post_id, '_membership_level', true));
        $level_info = Starter_Memberships::get_membership_level($level);
        $monthly_points = get_post_meta($post_id, '_membership_monthly_points', true);
        
        echo '<span style="color: ' . esc_attr($level_info['color']) . ';">';
        echo esc_html($level_info['icon'] . ' ' . $level_info['name']);
        echo '</span><br>';
        echo '<small>' . starter_format_fc($monthly_points) . '</small>';
    } else {
        echo '<span style="color: #999;">—</span>';
    }
}
