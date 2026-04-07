<?php
/**
 * Personalizaciones del panel de administración de WooCommerce
 * 
 * Este archivo contiene funciones para personalizar la interfaz de administración
 * de WooCommerce, especialmente la vista de pedidos.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Registrar un mensaje de depuración para confirmar que el archivo se está cargando
add_action('admin_notices', function() {
    $screen = get_current_screen();
    if ($screen && $screen->id === 'edit-shop_order') {
        echo '<div class="notice notice-info is-dismissible"><p>Personalizaciones de pedidos cargadas correctamente.</p></div>';
    }
});

/**
 * Personalizar las columnas en la lista de pedidos de WooCommerce
 */
function starter_customize_shop_order_columns($columns) {
    // Definir las columnas que queremos mostrar y su orden
    $new_columns = array(
        'cb'                => $columns['cb'], // Checkbox
        'order_number'      => __('Pedido', 'starter'),
        'order_date'        => __('Fecha', 'starter'),
        'order_status'      => __('Estado del pedido', 'starter'),
        'payment_method'    => __('Método de pago', 'starter'),
        'shipping_method'   => __('Opción de envío', 'starter'),
        'order_total'       => __('Total', 'starter'),
        'wc_actions'        => $columns['wc_actions'], // Acciones
    );
    
    return $new_columns;
}
// Usar ambos hooks para mayor compatibilidad
add_filter('manage_edit-shop_order_columns', 'starter_customize_shop_order_columns', 99);
add_filter('woocommerce_shop_order_list_table_columns', 'starter_customize_shop_order_columns', 99);

/**
 * Renderizar el contenido de las columnas personalizadas
 */
function starter_render_shop_order_columns($column, $post_id) {
    $order = wc_get_order($post_id);
    
    if (!$order) {
        return;
    }
    
    switch ($column) {
        case 'payment_method':
            $payment_method = $order->get_payment_method_title();
            $payment_method_id = $order->get_payment_method();
            
            // Si es transferencia bancaria, mostrar el submétodo específico
            if ($payment_method_id === 'bank_transfer' || $payment_method_id === 'bacs') {
                // Intentar obtener el submétodo desde los metadatos del pedido
                $payment_details = $order->get_meta('_payment_method_title');
                if (strpos($payment_details, '-') !== false) {
                    // Si el título contiene un guión, asumimos que incluye el submétodo
                    echo esc_html($payment_details);
                } else {
                    echo esc_html($payment_method);
                }
            } else {
                echo esc_html($payment_method);
            }
            break;
            
        case 'shipping_method':
            $shipping_methods = $order->get_shipping_methods();
            $shipping_html = array();
            
            if (!empty($shipping_methods)) {
                foreach ($shipping_methods as $shipping_method) {
                    $method_name = $shipping_method->get_method_title();
                    $method_id = $shipping_method->get_method_id();
                    
                    // Verificar si es envío premium
                    if ($method_id === 'flat_rate' && strpos(strtolower($method_name), 'premium') !== false) {
                        $shipping_html[] = '<span class="premium-shipping">' . esc_html($method_name) . '</span>';
                    } else {
                        $shipping_html[] = esc_html($method_name);
                    }
                }
                echo implode(', ', $shipping_html);
            } else {
                echo '<span class="na">&ndash;</span>';
            }
            break;
    }
}
// Usar ambos hooks para mayor compatibilidad (HPOS y sistema tradicional)
add_action('manage_shop_order_posts_custom_column', 'starter_render_shop_order_columns', 20, 2);
add_action('manage_woocommerce_page_wc-orders_custom_column', 'starter_render_shop_order_columns', 20, 2);
add_action('woocommerce_shop_order_list_table_custom_column', 'starter_render_shop_order_columns', 20, 2);

/**
 * Añadir estilos CSS para la vista de pedidos
 */
function starter_admin_order_styles() {
    $screen = get_current_screen();
    
    // Solo aplicar en la pantalla de pedidos (compatible con HPOS)
    if ($screen && ($screen->id === 'edit-shop_order' || $screen->id === 'woocommerce_page_wc-orders')) {
        ?>
        <style type="text/css">
            .premium-shipping {
                color: #9c27b0;
                font-weight: bold;
            }
            
            /* Ajustar el ancho de las columnas */
            .widefat .column-order_number {
                width: 10%;
            }
            .widefat .column-order_date {
                width: 12%;
            }
            .widefat .column-order_status {
                width: 12%;
            }
            .widefat .column-payment_method {
                width: 18%;
            }
            .widefat .column-shipping_method {
                width: 15%;
            }
            .widefat .column-order_total {
                width: 10%;
            }
        </style>
        <?php
    }
}
add_action('admin_head', 'starter_admin_order_styles');

/**
 * Hacer que las columnas sean ordenables
 */
function starter_shop_order_sortable_columns($columns) {
    $columns['payment_method'] = 'payment_method';
    $columns['shipping_method'] = 'shipping_method';
    return $columns;
}
add_filter('manage_edit-shop_order_sortable_columns', 'starter_shop_order_sortable_columns');
add_filter('woocommerce_shop_order_list_table_sortable_columns', 'starter_shop_order_sortable_columns');

/**
 * Manejar la ordenación de columnas personalizadas
 */
function starter_shop_order_request($vars) {
    if (isset($vars['orderby'])) {
        if ('payment_method' === $vars['orderby']) {
            $vars = array_merge($vars, array(
                'meta_key'  => '_payment_method_title',
                'orderby'   => 'meta_value'
            ));
        }
        
        // La ordenación por método de envío es más compleja y podría requerir una consulta personalizada
        // Por ahora, lo dejamos pendiente
    }
    
    return $vars;
}
add_filter('request', 'starter_shop_order_request');

/**
 * Añadir metabox de Virtual Coins en la vista de detalle del pedido
 */
function starter_add_order_virtual_coins_metabox() {
    // Para el sistema tradicional de posts
    add_meta_box(
        'starter-virtual-coins',
        __('Virtual Coins Utilizados', 'starter'),
        'starter_render_virtual_coins_metabox',
        'shop_order',
        'side',
        'default'
    );
    
    // Para HPOS (High-Performance Order Storage)
    if (function_exists('wc_get_container') && wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()) {
        add_meta_box(
            'starter-virtual-coins',
            __('Virtual Coins Utilizados', 'starter'),
            'starter_render_virtual_coins_metabox',
            'woocommerce_page_wc-orders',
            'side',
            'default'
        );
    }
}
add_action('add_meta_boxes', 'starter_add_order_virtual_coins_metabox');
add_action('add_meta_boxes_woocommerce_page_wc-orders', 'starter_add_order_virtual_coins_metabox');

/**
 * Renderizar el contenido del metabox de Virtual Coins
 */
function starter_render_virtual_coins_metabox($post_or_order_object) {
    // Obtener el ID del pedido según el contexto
    if (is_numeric($post_or_order_object)) {
        $order_id = $post_or_order_object;
    } elseif (isset($post_or_order_object->ID)) {
        $order_id = $post_or_order_object->ID;
    } elseif (method_exists($post_or_order_object, 'get_id')) {
        $order_id = $post_or_order_object->get_id();
    } else {
        error_log('Starter: No se pudo determinar el ID del pedido para Virtual Coins metabox');
        return;
    }
    
    $order = wc_get_order($order_id);
    
    if (!$order) {
        echo '<p>' . __('No se pudo cargar la información del pedido.', 'starter') . '</p>';
        return;
    }
    
    // Obtener información de Virtual Coins del pedido
    $virtual_coins_used = $order->get_meta('_virtual_coins_used');
    $virtual_coins_discount = $order->get_meta('_virtual_coins_discount');
    
    if (empty($virtual_coins_used) || $virtual_coins_used <= 0) {
        echo '<p style="color: #666; font-style: italic;">' . __('No se utilizaron Virtual Coins en este pedido.', 'starter') . '</p>';
        return;
    }
    
    // Formatear el descuento
    $discount_formatted = wc_price($virtual_coins_discount);
    
    ?>
    <div class="virtual-coins-info">
        <table class="widefat striped">
            <tbody>
                <tr>
                    <td><strong><?php _e('Virtual Coins utilizados:', 'starter'); ?></strong></td>
                    <td><?php echo esc_html($virtual_coins_used); ?> FC</td>
                </tr>
                <tr>
                    <td><strong><?php _e('Descuento aplicado:', 'starter'); ?></strong></td>
                    <td style="color: #46b450; font-weight: bold;">-<?php echo $discount_formatted; ?></td>
                </tr>
                <tr>
                    <td><strong><?php _e('Tasa de conversión:', 'starter'); ?></strong></td>
                    <td>
                        <?php 
                        if ($virtual_coins_used > 0) {
                            $conversion_rate = $virtual_coins_discount / $virtual_coins_used;
                            echo wc_price($conversion_rate) . ' ' . __('por FC', 'starter');
                        } else {
                            echo '—';
                        }
                        ?>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <div style="margin-top: 10px; padding: 8px; background: #f0f6fc; border: 1px solid #c3dafe; border-radius: 4px;">
            <small style="color: #1e40af;">
                <strong><?php _e('Nota:', 'starter'); ?></strong> 
                <?php _e('Este descuento ya está aplicado en el total del pedido.', 'starter'); ?>
            </small>
        </div>
    </div>
    
    <style>
        .virtual-coins-info table {
            margin: 0;
        }
        .virtual-coins-info td {
            padding: 8px 10px;
            vertical-align: middle;
        }
        .virtual-coins-info td:first-child {
            width: 60%;
        }
        .virtual-coins-info td:last-child {
            text-align: right;
            font-weight: 500;
        }
    </style>
    <?php
}

 /**
  * Añadir información de Virtual Coins en los detalles del pedido
  */
 function starter_add_virtual_coins_order_details($order) {
     if (!$order) return;
     
     $virtual_coins_used = $order->get_meta('_virtual_coins_used');
     $virtual_coins_discount = $order->get_meta('_virtual_coins_discount');
     
     // También verificar si hay cupones relacionados con Virtual Coins
     if (empty($virtual_coins_used)) {
         $coupons = $order->get_coupon_codes();
         foreach ($coupons as $coupon_code) {
             if (strpos($coupon_code, 'virtual-coins-') === 0) {
                 // Extraer puntos del código del cupón
                 $parts = explode('-', $coupon_code);
                 $points_part = end($parts);
                 $coupon_points = intval(str_replace('fc', '', $points_part));
                 
                 if ($coupon_points > 0) {
                     $virtual_coins_used = $coupon_points;
                     
                     // Calcular descuento basado en el cupón
                     $conversion_rate = function_exists('site_get_option') ? floatval(site_get_option('virtual_currency_conversion_rate', 0.1)) : 0.1;
                     if (function_exists('Starter_RP')) {
                         $options = Starter_RP()->get_options();
                         $conversion_rate = floatval($options['points_conversion_rate'] ?? $conversion_rate);
                     }
                     $virtual_coins_discount = $coupon_points * $conversion_rate;
                     break;
                 }
             }
         }
     }
     
     // También verificar si hay fees relacionados con Virtual Coins (compatibilidad)
     if (empty($virtual_coins_used)) {
         $fees = $order->get_fees();
         foreach ($fees as $fee) {
             if (strpos($fee->get_name(), 'Virtual Coins') !== false) {
                 // Intentar extraer la cantidad de FC del fee
                 $fee_total = abs($fee->get_total());
                 $virtual_coins_discount = $fee_total;
                 
                 // Si tenemos tasa de conversión, calcular FC usados
                 $conversion_rate = function_exists('site_get_option') ? floatval(site_get_option('virtual_currency_conversion_rate', 0.1)) : 0.1;
                 if (function_exists('Starter_RP')) {
                     $options = Starter_RP()->get_options();
                     $conversion_rate = floatval($options['points_conversion_rate'] ?? $conversion_rate);
                 }
                 if ($conversion_rate > 0) {
                     $virtual_coins_used = $fee_total / $conversion_rate;
                 }
                 break;
             }
         }
     }
     
     if (empty($virtual_coins_used) || $virtual_coins_used <= 0) {
         return;
     }
     
     $discount_formatted = wc_price($virtual_coins_discount);
     
     ?>
     <tr class="virtual-coins-discount">
         <td class="label">
             <?php _e('Descuento Virtual Coins:', 'starter'); ?>
             <?php if ($virtual_coins_used > 0): ?>
             <small style="color: #666; display: block;">
                 (<?php echo esc_html(round($virtual_coins_used)); ?> FC utilizados)
             </small>
             <?php endif; ?>
         </td>
         <td width="1%"></td>
         <td class="total">
             <span style="color: #46b450; font-weight: bold;">-<?php echo $discount_formatted; ?></span>
         </td>
     </tr>
     <?php
 }
 add_action('woocommerce_admin_order_totals_after_discount', 'starter_add_virtual_coins_order_details');
 add_action('woocommerce_admin_order_totals_after_fee', 'starter_add_virtual_coins_order_details');

/**
 * Añadir metabox de Reseña del Cliente en la vista de detalle del pedido
 */
function starter_add_order_review_metabox() {
    // Para el sistema tradicional de posts
    add_meta_box(
        'starter-order-review',
        __('Reseña del Cliente', 'starter'),
        'starter_render_order_review_metabox',
        'shop_order',
        'normal',
        'default'
    );
    
    // Para HPOS (High-Performance Order Storage)
    if (function_exists('wc_get_container') && wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()) {
        add_meta_box(
            'starter-order-review',
            __('Reseña del Cliente', 'starter'),
            'starter_render_order_review_metabox',
            'woocommerce_page_wc-orders',
            'normal',
            'default'
        );
    }
}
add_action('add_meta_boxes', 'starter_add_order_review_metabox');
add_action('add_meta_boxes_woocommerce_page_wc-orders', 'starter_add_order_review_metabox');

/**
 * Renderizar el contenido del metabox de Reseña del Cliente
 */
function starter_render_order_review_metabox($post_or_order_object) {
    // Obtener el ID del pedido según el contexto
    if (is_numeric($post_or_order_object)) {
        $order_id = $post_or_order_object;
    } elseif (isset($post_or_order_object->ID)) {
        $order_id = $post_or_order_object->ID;
    } elseif (method_exists($post_or_order_object, 'get_id')) {
        $order_id = $post_or_order_object->get_id();
    } else {
        return;
    }
    
    $order = wc_get_order($order_id);
    if (!$order) {
        echo '<p>' . __('No se pudo cargar la información del pedido.', 'starter') . '</p>';
        return;
    }
    
    // Verificar si el pedido fue calificado
    $is_rated = $order->get_meta('_starter_order_rated');
    if (!$is_rated) {
        echo '<p style="color: #666; font-style: italic;">' . __('Este pedido aún no ha sido calificado por el cliente.', 'starter') . '</p>';
        return;
    }
    
    $rating = intval($order->get_meta('_starter_order_rating'));
    $received_ok = $order->get_meta('_starter_order_received_ok');
    $observation = $order->get_meta('_starter_order_observation');
    
    // Buscar las reseñas de producto creadas desde este pedido
    $review_comments = get_comments(array(
        'meta_key'   => '_starter_from_order_rating',
        'meta_value' => $order_id,
        'type'       => 'review',
        'status'     => 'approve',
    ));
    
    $stars_full = str_repeat('★', $rating);
    $stars_empty = str_repeat('☆', 5 - $rating);
    
    ?>
    <div class="starter-order-review-info">
        <table class="widefat striped">
            <tbody>
                <tr>
                    <td><strong><?php _e('Calificación:', 'starter'); ?></strong></td>
                    <td>
                        <span style="color: #f59e0b; font-size: 16px; letter-spacing: 2px;"><?php echo $stars_full . $stars_empty; ?></span>
                        <span style="color: #666; margin-left: 4px;">(<?php echo $rating; ?>/5)</span>
                    </td>
                </tr>
                <?php if ($received_ok !== '') : ?>
                <tr>
                    <td><strong><?php _e('Recibió a conformidad:', 'starter'); ?></strong></td>
                    <td>
                        <?php if ($received_ok) : ?>
                            <span style="color: #16a34a; font-weight: 500;">✓ <?php _e('Sí', 'starter'); ?></span>
                        <?php else : ?>
                            <span style="color: #dc2626; font-weight: 500;">✗ <?php _e('No', 'starter'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
                <?php if (!empty($observation)) : ?>
                <tr>
                    <td><strong><?php _e('Observación:', 'starter'); ?></strong></td>
                    <td><?php echo esc_html($observation); ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <?php if (!empty($review_comments)) : ?>
        <div style="margin-top: 12px;">
            <strong style="display: block; margin-bottom: 8px;"><?php _e('Reseñas de productos creadas:', 'starter'); ?></strong>
            <?php foreach ($review_comments as $comment) :
                $product = wc_get_product($comment->comment_post_ID);
                $product_name = $product ? $product->get_name() : __('Producto eliminado', 'starter');
                $edit_url = get_edit_comment_link($comment->comment_ID);
            ?>
            <div style="background: #f8f9fa; border: 1px solid #e2e8f0; border-radius: 4px; padding: 10px; margin-bottom: 6px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                    <strong style="font-size: 13px;"><?php echo esc_html($product_name); ?></strong>
                    <?php if ($edit_url) : ?>
                        <a href="<?php echo esc_url($edit_url); ?>" style="font-size: 12px; text-decoration: none;" target="_blank"><?php _e('Ver reseña →', 'starter'); ?></a>
                    <?php endif; ?>
                </div>
                <p style="color: #4a5568; font-size: 13px; margin: 0; line-height: 1.5;"><?php echo esc_html(wp_trim_words($comment->comment_content, 30, '...')); ?></p>
            </div>
            <?php endforeach; ?>
        </div>
        <?php elseif ($is_rated) : ?>
        <p style="color: #666; font-style: italic; margin-top: 10px; font-size: 12px;">
            <?php _e('No se crearon reseñas de producto (todos los productos ya habían sido reseñados).', 'starter'); ?>
        </p>
        <?php endif; ?>
    </div>
    
    <style>
        .starter-order-review-info table { margin: 0; }
        .starter-order-review-info td { padding: 8px 10px; vertical-align: top; }
        .starter-order-review-info td:first-child { width: 40%; }
    </style>
    <?php
}

/**
 * Añadir columna de Virtual Coins en los items del pedido (opcional)
 */
function starter_add_virtual_coins_admin_order_item_headers() {
    ?>
    <th class="virtual-coins-column" style="text-align: center; width: 80px;">
        <?php _e('FC', 'starter'); ?>
        <span class="tips" data-tip="<?php _e('Virtual Coins relacionados con este pedido', 'starter'); ?>">?</span>
    </th>
    <?php
}
// Descomenta la siguiente línea si quieres mostrar una columna adicional en los items del pedido
// add_action('woocommerce_admin_order_item_headers', 'starter_add_virtual_coins_admin_order_item_headers');

/**
 * Mostrar información de Virtual Coins en las notas del pedido
 */
function starter_add_virtual_coins_order_note($order_id) {
    $order = wc_get_order($order_id);
    
    if (!$order) return;
    
    $virtual_coins_used = $order->get_meta('_virtual_coins_used');
    $virtual_coins_discount = $order->get_meta('_virtual_coins_discount');
    
    if (!empty($virtual_coins_used) && $virtual_coins_used > 0) {
        $note = sprintf(
            __('El cliente utilizó %d Virtual Coins obteniendo un descuento de %s.', 'starter'),
            $virtual_coins_used,
            wc_price($virtual_coins_discount)
        );
        
        // Añadir nota al pedido
        $order->add_order_note($note, false, true);
        
    }
}
add_action('woocommerce_checkout_order_processed', 'starter_add_virtual_coins_order_note', 20, 1);

/**
 * Añadir estilos adicionales para la información de Virtual Coins
 */
function starter_admin_virtual_coins_styles() {
    $screen = get_current_screen();
    
    if ($screen && ($screen->id === 'shop_order' || $screen->id === 'woocommerce_page_wc-orders')) {
        ?>
        <style type="text/css">
            /* Estilos para el metabox de Virtual Coins */
            #starter-virtual-coins .inside {
                margin: 0;
                padding: 0;
            }
            
            /* Estilos para la fila de descuento en totales */
            .virtual-coins-discount td.label {
                padding-left: 20px;
            }
            
            .virtual-coins-discount td.total {
                font-weight: bold;
            }
            
            /* Destacar información de Virtual Coins */
            .virtual-coins-info {
                font-size: 13px;
            }
            
            /* Icono para tooltips */
            .tips {
                cursor: help;
                text-decoration: none;
            }
        </style>
        <?php
    }
}
add_action('admin_head', 'starter_admin_virtual_coins_styles');
