<?php
/**
 * Integración con WooCommerce
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Inicializar integración con WooCommerce
 */
function starter_memberships_init_woocommerce() {
    // Validar que solo se pueda comprar una membresía a la vez
    add_filter('woocommerce_add_to_cart_validation', 'starter_memberships_validate_cart', 10, 3);
    
    // Validar acceso a productos por membresía al agregar al carrito
    add_filter('woocommerce_add_to_cart_validation', 'starter_memberships_validate_product_access', 15, 3);
    
    // Mostrar información de membresía en el carrito
    add_filter('woocommerce_get_item_data', 'starter_memberships_cart_item_data', 10, 2);
    
    // Agregar mensaje en checkout si hay membresía
    add_action('woocommerce_before_checkout_form', 'starter_memberships_checkout_notice');
    
    // Prevenir compra de membresía por invitados
    add_action('woocommerce_check_cart_items', 'starter_memberships_check_guest_purchase');
    
    // Validar acceso a productos en el checkout (por si la membresía expiró)
    add_action('woocommerce_check_cart_items', 'starter_memberships_validate_cart_items_access');
    
    // Agregar datos de membresía a la orden
    add_action('woocommerce_checkout_create_order_line_item', 'starter_memberships_add_order_item_meta', 10, 4);
    
    // Mostrar descuento de membresía en el admin de pedidos
    add_action('add_meta_boxes', 'starter_memberships_add_order_meta_box');
}

/**
 * Validar que solo se pueda agregar una membresía al carrito
 */
function starter_memberships_validate_cart($passed, $product_id, $quantity) {
    if (!starter_is_membership_product($product_id)) {
        return $passed;
    }
    
    // Verificar si ya hay una membresía en el carrito
    if (starter_cart_has_membership()) {
        wc_add_notice(
            __('Solo puedes comprar una membresía a la vez. Por favor, elimina la membresía actual del carrito primero.', 'starter-memberships'),
            'error'
        );
        return false;
    }
    
    // Verificar si el usuario puede comprar esta membresía
    $user_id = get_current_user_id();
    $level = starter_get_product_membership_level($product_id);
    $can_purchase = starter_can_user_purchase_membership($user_id, $level);
    
    if (!$can_purchase['can_purchase']) {
        wc_add_notice($can_purchase['reason'], 'error');
        return false;
    }
    
    return $passed;
}

/**
 * Mostrar información de membresía en el carrito
 */
function starter_memberships_cart_item_data($item_data, $cart_item) {
    $product_id = $cart_item['product_id'];
    
    if (!starter_is_membership_product($product_id)) {
        return $item_data;
    }
    
    $level = starter_get_product_membership_level($product_id);
    $level_info = Starter_Memberships::get_membership_level($level);
    $monthly_points = get_post_meta($product_id, '_membership_monthly_points', true);
    $duration = get_post_meta($product_id, '_membership_duration_days', true) ?: 30;
    
    $item_data[] = [
        'key' => __('Nivel', 'starter-memberships'),
        'value' => $level_info['icon'] . ' ' . $level_info['name']
    ];
    
    $item_data[] = [
        'key' => __('Duración', 'starter-memberships'),
        'value' => sprintf(__('%d días', 'starter-memberships'), $duration)
    ];
    
    $item_data[] = [
        'key' => __('Virtual Coins/mes', 'starter-memberships'),
        'value' => starter_format_fc($monthly_points)
    ];
    
    return $item_data;
}

/**
 * Mostrar aviso en checkout si hay membresía
 */
function starter_memberships_checkout_notice() {
    if (!starter_cart_has_membership()) {
        return;
    }
    
    $user_id = get_current_user_id();
    $current_level = starter_get_user_membership_level($user_id);
    $cart_level = starter_get_highest_membership_in_cart();
    
    if ($current_level > 0 && $cart_level > $current_level) {
        wc_print_notice(
            sprintf(
                __('🎉 ¡Estás actualizando tu membresía! Pasarás del nivel %d al nivel %d.', 'starter-memberships'),
                $current_level,
                $cart_level
            ),
            'notice'
        );
    } elseif ($current_level > 0) {
        wc_print_notice(
            __('📅 Estás renovando tu membresía. El tiempo se agregará a tu membresía actual.', 'starter-memberships'),
            'notice'
        );
    } else {
        wc_print_notice(
            __('🥕 ¡Bienvenido al club! Al completar tu compra, recibirás acceso inmediato a los beneficios de tu membresía.', 'starter-memberships'),
            'notice'
        );
    }
}

/**
 * Prevenir compra de membresía por invitados
 */
function starter_memberships_check_guest_purchase() {
    if (!starter_cart_has_membership()) {
        return;
    }
    
    if (!is_user_logged_in()) {
        wc_add_notice(
            __('Debes iniciar sesión para comprar una membresía. Por favor, inicia sesión o crea una cuenta.', 'starter-memberships'),
            'error'
        );
    }
}

/**
 * Agregar meta datos de membresía a los items de la orden
 */
function starter_memberships_add_order_item_meta($item, $cart_item_key, $values, $order) {
    $product_id = $values['product_id'];
    
    if (!starter_is_membership_product($product_id)) {
        return;
    }
    
    $level = starter_get_product_membership_level($product_id);
    $level_info = Starter_Memberships::get_membership_level($level);
    $monthly_points = get_post_meta($product_id, '_membership_monthly_points', true);
    $duration = get_post_meta($product_id, '_membership_duration_days', true) ?: 30;
    
    $item->add_meta_data('_membership_level', $level);
    $item->add_meta_data('_membership_level_name', $level_info['name']);
    $item->add_meta_data('_membership_monthly_points', $monthly_points);
    $item->add_meta_data('_membership_duration_days', $duration);
    
    // Meta visible en la orden
    $item->add_meta_data(__('Nivel de Membresía', 'starter-memberships'), $level_info['icon'] . ' ' . $level_info['name']);
    $item->add_meta_data(__('Duración', 'starter-memberships'), sprintf(__('%d días', 'starter-memberships'), $duration));
    $item->add_meta_data(__('Virtual Coins/mes', 'starter-memberships'), starter_format_fc($monthly_points));
}

/**
 * Agregar meta box para descuento de membresía en pedidos
 */
function starter_memberships_add_order_meta_box() {
    $screen = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
        ? wc_get_page_screen_id('shop-order')
        : 'shop_order';
    
    add_meta_box(
        'starter_membership_discount',
        __('🥕 Descuento de Membresía', 'starter-memberships'),
        'starter_memberships_render_discount_meta_box',
        $screen,
        'side',
        'default'
    );
}

/**
 * Renderizar meta box de descuento de membresía
 */
function starter_memberships_render_discount_meta_box($post_or_order) {
    // Obtener el objeto order
    if ($post_or_order instanceof WP_Post) {
        $order = wc_get_order($post_or_order->ID);
    } else {
        $order = $post_or_order;
    }
    
    if (!$order) {
        echo '<p>' . __('No se pudo cargar el pedido.', 'starter-memberships') . '</p>';
        return;
    }
    
    // Obtener meta datos del descuento de membresía
    $discount_total = $order->get_meta('_membership_discount_total');
    $discount_percentage = $order->get_meta('_membership_discount_percentage');
    $membership_name = $order->get_meta('_membership_name');
    $membership_level = $order->get_meta('_membership_level');
    $items_with_discount = $order->get_meta('_membership_items_with_discount');
    $discounted_items_json = $order->get_meta('_membership_discounted_items');
    
    // Si no hay descuento de membresía, mostrar mensaje
    if (empty($discount_total) || $discount_total == 0) {
        echo '<p style="color: #666; font-style: italic;">' . __('Este pedido no tiene descuento de membresía aplicado.', 'starter-memberships') . '</p>';
        return;
    }
    
    // Mostrar información del descuento
    ?>
    <div class="starter-membership-discount-info">
        <p style="margin-bottom: 10px;">
            <strong style="color: #C72C6C;"><?php echo esc_html($membership_name); ?></strong>
            <span style="background: #C72C6C; color: white; padding: 2px 8px; border-radius: 10px; font-size: 11px; margin-left: 5px;">
                -<?php echo esc_html($discount_percentage); ?>%
            </span>
        </p>
        
        <table style="width: 100%; font-size: 12px; border-collapse: collapse;">
            <tr>
                <td style="padding: 4px 0; color: #666;">Nivel:</td>
                <td style="padding: 4px 0; text-align: right; font-weight: bold;"><?php echo esc_html($membership_level); ?></td>
            </tr>
            <tr>
                <td style="padding: 4px 0; color: #666;">Productos con descuento:</td>
                <td style="padding: 4px 0; text-align: right; font-weight: bold;"><?php echo esc_html($items_with_discount); ?></td>
            </tr>
            <tr style="border-top: 1px solid #eee;">
                <td style="padding: 8px 0 4px; color: #666; font-weight: bold;">Total descuento:</td>
                <td style="padding: 8px 0 4px; text-align: right; font-weight: bold; color: #C72C6C; font-size: 14px;">
                    -<?php echo wc_price($discount_total); ?>
                </td>
            </tr>
        </table>
        
        <?php if (!empty($discounted_items_json)): ?>
            <?php $discounted_items = json_decode($discounted_items_json, true); ?>
            <?php if (is_array($discounted_items) && count($discounted_items) > 0): ?>
                <details style="margin-top: 10px;">
                    <summary style="cursor: pointer; color: #666; font-size: 11px;">Ver detalle de productos</summary>
                    <div style="margin-top: 8px; padding: 8px; background: #f9f9f9; border-radius: 4px;">
                        <?php foreach ($discounted_items as $item): ?>
                            <div style="margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid #eee;">
                                <div style="font-size: 11px; font-weight: bold; margin-bottom: 2px;">
                                    <?php echo esc_html($item['productName']); ?>
                                </div>
                                <div style="font-size: 10px; color: #666;">
                                    <?php echo esc_html($item['quantity']); ?>x · 
                                    <span style="text-decoration: line-through;"><?php echo wc_price($item['originalPrice']); ?></span>
                                    → <?php echo wc_price($item['finalPrice']); ?>
                                    <span style="color: #C72C6C;">(-<?php echo wc_price($item['discountAmount']); ?>)</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Validar acceso a productos por membresía al agregar al carrito
 * 
 * Verifica que el usuario tenga el nivel de membresía requerido para
 * comprar productos de categorías restringidas.
 */
function starter_memberships_validate_product_access($passed, $product_id, $quantity) {
    // Si ya falló otra validación, no continuar
    if (!$passed) {
        return $passed;
    }
    
    // Obtener el producto
    $product = wc_get_product($product_id);
    if (!$product) {
        return $passed;
    }
    
    // Obtener categorías del producto
    $category_ids = $product->get_category_ids();
    if (empty($category_ids)) {
        return $passed; // Sin categorías, permitir
    }
    
    // Obtener nivel de membresía del usuario
    $user_id = get_current_user_id();
    $user_level = starter_get_user_membership_level($user_id);
    
    // Verificar cada categoría del producto
    foreach ($category_ids as $category_id) {
        $min_level = starter_get_category_min_membership($category_id);
        
        if ($min_level > 0 && $user_level < $min_level) {
            $category = get_term($category_id, 'product_cat');
            $level_info = Starter_Memberships::get_membership_level($min_level);
            
            wc_add_notice(
                sprintf(
                    __('El producto "%s" pertenece a la categoría "%s" que requiere membresía %s %s o superior para poder comprarlo.', 'starter-memberships'),
                    $product->get_name(),
                    $category ? $category->name : '',
                    $level_info['icon'],
                    $level_info['name']
                ),
                'error'
            );
            return false;
        }
    }
    
    return $passed;
}

/**
 * Validar acceso a todos los productos del carrito en el checkout
 * 
 * Esta validación es importante porque:
 * 1. La membresía del usuario puede haber expirado después de agregar productos
 * 2. Alguien podría manipular el carrito directamente
 * 3. Protege contra URLs directas de "agregar al carrito"
 */
function starter_memberships_validate_cart_items_access() {
    if (!WC()->cart || WC()->cart->is_empty()) {
        return;
    }
    
    $user_id = get_current_user_id();
    $user_level = starter_get_user_membership_level($user_id);
    $restricted_products = [];
    
    foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
        $product_id = $cart_item['product_id'];
        $product = wc_get_product($product_id);
        
        if (!$product) {
            continue;
        }
        
        // Saltar productos de membresía (tienen su propia validación)
        if (starter_is_membership_product($product_id)) {
            continue;
        }
        
        $category_ids = $product->get_category_ids();
        
        foreach ($category_ids as $category_id) {
            $min_level = starter_get_category_min_membership($category_id);
            
            if ($min_level > 0 && $user_level < $min_level) {
                $category = get_term($category_id, 'product_cat');
                $level_info = Starter_Memberships::get_membership_level($min_level);
                
                $restricted_products[] = [
                    'product_name' => $product->get_name(),
                    'category_name' => $category ? $category->name : '',
                    'required_level' => $min_level,
                    'level_name' => $level_info['name'],
                    'level_icon' => $level_info['icon'],
                    'cart_item_key' => $cart_item_key
                ];
                break; // Solo necesitamos una categoría restringida por producto
            }
        }
    }
    
    if (!empty($restricted_products)) {
        // Construir mensaje de error detallado
        $product_list = array_map(function($item) {
            return sprintf(
                '• <strong>%s</strong> (requiere %s %s)',
                esc_html($item['product_name']),
                $item['level_icon'],
                esc_html($item['level_name'])
            );
        }, $restricted_products);
        
        $user_level_info = Starter_Memberships::get_membership_level($user_level);
        
        wc_add_notice(
            sprintf(
                __('No puedes completar la compra porque tu membresía actual (%s %s) no tiene acceso a los siguientes productos:<br>%s<br><br>Por favor, elimina estos productos del carrito o <a href="%s">mejora tu membresía</a>.', 'starter-memberships'),
                $user_level_info['icon'],
                $user_level_info['name'],
                implode('<br>', $product_list),
                esc_url(get_permalink(wc_get_page_id('shop')) . '?category=memberships')
            ),
            'error'
        );
    }
}
