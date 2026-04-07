<?php
/**
 * Virtual Coins Product Type — Definición del tipo de producto en WooCommerce
 * 
 * Responsabilidades:
 * - Crear categoría "Paquetes de Virtual Coins"
 * - Helpers para identificar y leer productos FC
 * - Panel de administración en el editor de producto
 * - Guardar metadatos del producto FC
 * 
 * @package Starter
 * @since 1.1.0
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// ─── Categoría ───────────────────────────────────────────────────────────────

/**
 * Crear categoría "Paquetes de Virtual Coins" si no existe
 */
add_action('init', 'starter_create_virtual_coins_category', 5);
function starter_create_virtual_coins_category() {
    // Solo ejecutar si WooCommerce está activo
    if (!taxonomy_exists('product_cat')) {
        return;
    }
    
    $category_slug = 'paquetes-virtual-coins';
    
    // Verificar si la categoría ya existe
    if (!term_exists($category_slug, 'product_cat')) {
        wp_insert_term(
            'Paquetes de Virtual Coins',
            'product_cat',
            array(
                'slug' => $category_slug,
                'description' => 'Paquetes de Virtual Coins disponibles para compra. Estos productos son virtuales y se procesan a través de Wompi.',
            )
        );
        
        // Marcar la categoría como interna (no mostrar en el frontend público)
        $term = get_term_by('slug', $category_slug, 'product_cat');
        if ($term) {
            update_term_meta($term->term_id, '_starter_internal_category', 'yes');
            update_term_meta($term->term_id, '_min_membership_level', 0); // Accesible para todos los niveles
        }
    }
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * NOTA: En lugar de crear un tipo de producto personalizado,
 * usamos productos simples virtuales con meta fields adicionales.
 * Esto permite que WooCommerce maneje correctamente los campos de precio.
 * 
 * Un producto es de tipo "Virtual Coins" si tiene el meta '_is_virtual_coins_product' = 'yes'
 */

/**
 * Helper: Verificar si un producto es de tipo Virtual Coins
 */
function starter_is_virtual_coins_product($product_id) {
    return get_post_meta($product_id, '_is_virtual_coins_product', true) === 'yes';
}

/**
 * Helper: Obtener cantidad de Virtual Coins del producto
 */
function starter_get_product_coins_amount($product_id) {
    return (int) get_post_meta($product_id, '_virtual_coins_amount', true);
}

/**
 * Helper: Obtener bonus de Virtual Coins
 */
function starter_get_product_coins_bonus($product_id) {
    return (int) get_post_meta($product_id, '_virtual_coins_bonus', true);
}

/**
 * Helper: Obtener total de Virtual Coins (cantidad + bonus)
 */
function starter_get_product_total_coins($product_id) {
    return starter_get_product_coins_amount($product_id) + starter_get_product_coins_bonus($product_id);
}

/**
 * Helper: Verificar si es el paquete más popular
 */
function starter_is_popular_coins_product($product_id) {
    return get_post_meta($product_id, '_virtual_coins_popular', true) === 'yes';
}

// ─── Admin UI ────────────────────────────────────────────────────────────────

/**
 * Agregar pestaña de datos de Virtual Coins en el editor de producto
 * Usa show_if_simple y show_if_virtual para que se muestre con productos simples
 */
add_filter('woocommerce_product_data_tabs', 'starter_virtual_coins_product_tab');
function starter_virtual_coins_product_tab($tabs) {
    $tabs['virtual_coins'] = array(
        'label' => __('Virtual Coins', 'starter'),
        'target' => 'virtual_coins_product_data',
        'class' => array('show_if_simple', 'show_if_virtual'),
        'priority' => 21,
    );
    return $tabs;
}

/**
 * Contenido de la pestaña de Virtual Coins
 * Similar al panel de membresías: checkbox para activar + campos adicionales
 */
add_action('woocommerce_product_data_panels', 'starter_virtual_coins_product_panel');
function starter_virtual_coins_product_panel() {
    global $post;
    
    $is_fc_product = get_post_meta($post->ID, '_is_virtual_coins_product', true);
    $amount = get_post_meta($post->ID, '_virtual_coins_amount', true) ?: 0;
    $bonus = get_post_meta($post->ID, '_virtual_coins_bonus', true) ?: 0;
    $is_popular = get_post_meta($post->ID, '_virtual_coins_popular', true);
    $min_membership = get_post_meta($post->ID, '_virtual_coins_min_membership', true);
    $min_membership = $min_membership !== '' ? intval($min_membership) : 0;
    $membership_mode = get_post_meta($post->ID, '_virtual_coins_membership_mode', true) ?: 'cascade';
    
    // Obtener niveles de membresía si el plugin está activo
    $levels = array();
    if (class_exists('Starter_Memberships')) {
        $levels = Starter_Memberships::get_all_membership_levels();
    }
    ?>
    <div id="virtual_coins_product_data" class="panel woocommerce_options_panel">
        <div class="options_group">
            <p class="form-field">
                <label for="_is_virtual_coins_product">
                    <input type="checkbox" 
                           id="_is_virtual_coins_product" 
                           name="_is_virtual_coins_product" 
                           value="yes"
                           <?php checked($is_fc_product, 'yes'); ?>>
                    <?php _e('Este es un paquete de Virtual Coins', 'starter'); ?>
                </label>
            </p>
            
            <p class="description" style="padding-left: 12px; color: #666;">
                <?php _e('Marcar esta opción para convertir este producto en un paquete de Virtual Coins que se puede comprar a través de Wompi.', 'starter'); ?>
            </p>
        </div>
        
        <div class="options_group virtual-coins-fields" style="<?php echo $is_fc_product !== 'yes' ? 'display:none;' : ''; ?>">
            <div style="padding: 12px; background: #f0f6fc; border-left: 4px solid #2271b1; margin: 0 12px 15px;">
                <strong style="display: block; margin-bottom: 4px;">💡 <?php _e('Precio del paquete', 'starter'); ?></strong>
                <span style="color: #666; font-size: 13px;"><?php _e('Configure el precio en la pestaña "General" usando el campo "Precio regular"', 'starter'); ?></span>
            </div>
            
            <?php
            woocommerce_wp_text_input(array(
                'id' => '_virtual_coins_amount',
                'label' => __('Cantidad de FC', 'starter'),
                'description' => __('Cantidad base de Virtual Coins que recibirá el usuario al comprar este paquete', 'starter'),
                'type' => 'number',
                'custom_attributes' => array(
                    'min' => '0',
                    'step' => '1',
                    'placeholder' => 'Ej: 50000',
                ),
                'desc_tip' => true,
                'value' => $amount,
            ));
            
            woocommerce_wp_text_input(array(
                'id' => '_virtual_coins_bonus',
                'label' => __('Bonus de FC', 'starter'),
                'description' => __('Cantidad adicional de Virtual Coins como bonificación (opcional)', 'starter'),
                'type' => 'number',
                'custom_attributes' => array(
                    'min' => '0',
                    'step' => '1',
                    'placeholder' => 'Ej: 5000',
                ),
                'desc_tip' => true,
                'value' => $bonus,
            ));
            
            woocommerce_wp_checkbox(array(
                'id' => '_virtual_coins_popular',
                'label' => __('Paquete destacado', 'starter'),
                'description' => __('Marcar este paquete como el más popular (se mostrará destacado en el frontend)', 'starter'),
                'value' => $is_popular,
            ));
            ?>
            
            <p class="form-field" style="padding: 12px;">
                <label style="float: left; width: 150px; padding-top: 0;"><?php _e('Total FC:', 'starter'); ?></label>
                <strong id="virtual_coins_total_display" style="font-size: 16px; color: #2271b1;">
                    <?php echo number_format($amount + $bonus) . ' FC'; ?>
                </strong>
                <?php if ($bonus > 0): ?>
                <span style="color: #46b450; margin-left: 8px; font-size: 12px;">
                    (<?php echo number_format($amount); ?> + <?php echo number_format($bonus); ?> bonus)
                </span>
                <?php endif; ?>
            </p>
        </div>
        
        <?php if (!empty($levels)): ?>
        <div class="options_group virtual-coins-fields" style="<?php echo $is_fc_product !== 'yes' ? 'display:none;' : ''; ?>">
            <div style="padding: 12px; background: #fff3cd; border-left: 4px solid #ffc107; margin: 0 12px 15px;">
                <strong style="display: block; margin-bottom: 4px;">🔒 <?php _e('Restricción por Membresía', 'starter'); ?></strong>
                <span style="color: #666; font-size: 13px;"><?php _e('Configure qué niveles de membresía pueden ver y comprar este paquete', 'starter'); ?></span>
            </div>
            
            <p class="form-field">
                <label for="_virtual_coins_min_membership"><?php _e('Membresía Mínima', 'starter'); ?></label>
                <select id="_virtual_coins_min_membership" name="_virtual_coins_min_membership" class="select short">
                    <?php foreach ($levels as $level_id => $level) : ?>
                        <option value="<?php echo esc_attr($level_id); ?>" <?php selected($min_membership, $level_id); ?>>
                            <?php echo esc_html($level['icon'] . ' ' . $level['name']); ?>
                            <?php if ($level_id === 0) : ?>
                                (<?php _e('Todos', 'starter'); ?>)
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="woocommerce-help-tip" data-tip="<?php esc_attr_e('Nivel mínimo de membresía requerido para ver este paquete', 'starter'); ?>"></span>
            </p>
            
            <p class="form-field">
                <label for="_virtual_coins_membership_mode"><?php _e('Modo de Acceso', 'starter'); ?></label>
                <select id="_virtual_coins_membership_mode" name="_virtual_coins_membership_mode" class="select short">
                    <option value="cascade" <?php selected($membership_mode, 'cascade'); ?>>
                        <?php _e('En cascada (nivel seleccionado y superiores)', 'starter'); ?>
                    </option>
                    <option value="direct" <?php selected($membership_mode, 'direct'); ?>>
                        <?php _e('Directo (solo el nivel seleccionado)', 'starter'); ?>
                    </option>
                </select>
                <span class="woocommerce-help-tip" data-tip="<?php esc_attr_e('Cascada: usuarios con el nivel seleccionado o superior pueden acceder. Directo: solo usuarios con exactamente ese nivel pueden acceder.', 'starter'); ?>"></span>
            </p>
            
            <p class="description" style="padding: 0 12px 12px; color: #666; font-size: 12px;">
                <strong><?php _e('Ejemplo:', 'starter'); ?></strong> 
                <?php _e('Si selecciona "Zanahoria Bronce" en modo cascada, los usuarios con Bronce, Plateada, Dorada y Diamante podrán ver este paquete.', 'starter'); ?>
            </p>
        </div>
        <?php endif; ?>
    </div>
    
    <script type="text/javascript">
    jQuery(function($) {
        // Toggle campos de Virtual Coins
        $('#_is_virtual_coins_product').on('change', function() {
            if ($(this).is(':checked')) {
                $('.virtual-coins-fields').slideDown();
                // Marcar como virtual automáticamente
                $('#_virtual').prop('checked', true).trigger('change');
            } else {
                $('.virtual-coins-fields').slideUp();
            }
        });
        
        // Actualizar total
        function updateTotal() {
            var amount = parseInt($('#_virtual_coins_amount').val()) || 0;
            var bonus = parseInt($('#_virtual_coins_bonus').val()) || 0;
            var total = amount + bonus;
            var display = total.toLocaleString() + ' FC';
            
            if (bonus > 0) {
                display += ' <span style="color: #46b450; font-size: 12px;">(' + amount.toLocaleString() + ' + ' + bonus.toLocaleString() + ' bonus)</span>';
            }
            
            $('#virtual_coins_total_display').html(display);
        }
        
        $('#_virtual_coins_amount, #_virtual_coins_bonus').on('input', updateTotal);
    });
    </script>
    <?php
}

/**
 * Guardar datos de Virtual Coins
 */
add_action('woocommerce_process_product_meta', 'starter_save_virtual_coins_product_data');
function starter_save_virtual_coins_product_data($post_id) {
    // Guardar si es producto de Virtual Coins
    $is_fc_product = isset($_POST['_is_virtual_coins_product']) ? 'yes' : 'no';
    update_post_meta($post_id, '_is_virtual_coins_product', $is_fc_product);
    
    if ($is_fc_product === 'yes') {
        // Guardar cantidad de FC
        $amount = isset($_POST['_virtual_coins_amount']) ? absint($_POST['_virtual_coins_amount']) : 0;
        update_post_meta($post_id, '_virtual_coins_amount', $amount);
        
        // Guardar bonus
        $bonus = isset($_POST['_virtual_coins_bonus']) ? absint($_POST['_virtual_coins_bonus']) : 0;
        update_post_meta($post_id, '_virtual_coins_bonus', $bonus);
        
        // Guardar si es popular
        $popular = isset($_POST['_virtual_coins_popular']) ? 'yes' : 'no';
        update_post_meta($post_id, '_virtual_coins_popular', $popular);
        
        // Guardar membresía mínima
        $min_membership = isset($_POST['_virtual_coins_min_membership']) ? absint($_POST['_virtual_coins_min_membership']) : 0;
        update_post_meta($post_id, '_virtual_coins_min_membership', $min_membership);
        
        // Guardar modo de acceso (cascade o direct)
        $membership_mode = isset($_POST['_virtual_coins_membership_mode']) ? sanitize_text_field($_POST['_virtual_coins_membership_mode']) : 'cascade';
        if (!in_array($membership_mode, array('cascade', 'direct'))) {
            $membership_mode = 'cascade';
        }
        update_post_meta($post_id, '_virtual_coins_membership_mode', $membership_mode);
        
        // Asegurar que el producto sea virtual
        update_post_meta($post_id, '_virtual', 'yes');
        
        // Log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'Starter FC: Producto %d configurado como paquete de Virtual Coins: %d FC + %d bonus = %d total, membresía mínima: %d (%s)',
                $post_id, $amount, $bonus, $amount + $bonus, $min_membership, $membership_mode
            ));
        }
    }
}

/**
 * Mostrar pestañas de precio para productos Virtual Coins
 * Inyectar CSS para forzar visibilidad de campos de precio
 */
add_action('admin_head', 'starter_virtual_coins_admin_styles');
function starter_virtual_coins_admin_styles() {
    global $post;
    
    if (!$post || get_post_type($post) !== 'product') {
        return;
    }
    ?>
    <style type="text/css">
        /* Estilos para la pestaña Virtual Coins */
        #virtual_coins_product_data .options_group {
            border-bottom: 1px solid #eee;
            padding: 12px 0;
        }
        
        #virtual_coins_product_data .options_group:last-child {
            border-bottom: none;
        }
    </style>
    <?php
}
