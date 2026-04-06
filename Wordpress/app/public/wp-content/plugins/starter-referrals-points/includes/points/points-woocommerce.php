<?php
/**
 * Integración del sistema de puntos con WooCommerce
 * 
 * Funciones para procesar puntos relacionados con pedidos y productos de WooCommerce.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Verificar si se deben procesar puntos y hacerlo si es necesario
 */
function starter_rp_check_and_process_order_points($order_id) {
    // Obtener la orden
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }
    
    // Solo procesar si el pedido está pagado
    if ($order->is_paid()) {
        starter_rp_process_order_points($order_id);
    }
}

/**
 * Procesamiento de puntos por compra de productos
 */
function starter_rp_process_order_points($order_id) {
    // Obtener datos del pedido
    $order = wc_get_order($order_id);
    if (!$order) {
        starter_rp_log("Starter RP: No se pudo obtener el pedido ID: $order_id");
        return;
    }
    
    $user_id = $order->get_user_id();
    
    // Verificar si es un usuario registrado
    if (!$user_id) {
        starter_rp_log("Starter RP: Pedido $order_id sin usuario registrado - no se procesan puntos");
        return;
    }
    
    // No procesar comisiones para órdenes internas (compras de FC y membresías)
    // Estas órdenes se crean automáticamente para trazabilidad y ya acreditan puntos/membresía directamente
    $order_type = $order->get_meta('_order_type');
    if (in_array($order_type, ['virtual_coins_purchase', 'membership_purchase'], true)) {
        starter_rp_log("Starter RP: Pedido $order_id es de tipo '$order_type' - no se procesan comisiones de referidos");
        return;
    }
    
    // Verificar si ya se procesaron los puntos para este pedido
    $points_processed = get_post_meta($order_id, '_starter_points_processed', true);
    if ($points_processed === 'yes') {
        starter_rp_log("Starter RP: Puntos ya procesados para pedido ID: $order_id");
        return;
    }
    
    // Verificar si el usuario puede usar el sistema de puntos
    if (!starter_rp_can_user_use_points($user_id)) {
        starter_rp_log_access_denied('process_order_points', $user_id, 'Sistema de puntos deshabilitado o rol no permitido');
        return;
    }
    
    // Verificar si el evento de compra está habilitado para otorgar puntos
    if (!starter_rp_is_points_event_enabled('purchase')) {
        starter_rp_log("Starter RP: Evento de compra no habilitado para otorgar puntos. Pedido ID: $order_id");
        return;
    }
    
    $options = Starter_RP()->get_options();
    $order_total = $order->get_total();
    
    starter_rp_log("Starter RP: Procesando puntos para pedido ID: $order_id, Usuario: $user_id, Total: $order_total");
    
    // Verificar si el usuario que compra tiene un referidor
    global $wpdb;
    $referrals_table = $wpdb->prefix . 'starter_referrals';
    $referrer_id = $wpdb->get_var($wpdb->prepare("
        SELECT referrer_id FROM $referrals_table WHERE user_id = %d AND referrer_id IS NOT NULL
    ", $user_id));
    
    if ($referrer_id) {
        starter_rp_log("Starter RP: Usuario $user_id tiene referidor: $referrer_id");
        
        // Usar la función de procesamiento de referidos que considera el nivel de membresía
        // Esta función aplica las comisiones configuradas por nivel en Virtual Coins → Configuración → Comisiones por Membresía
        if (function_exists('starter_rp_process_referral_points')) {
            starter_rp_process_referral_points($user_id, $order_id, $order_total);
            starter_rp_log("Starter RP: Procesamiento de comisiones por membresía completado para pedido #$order_id");
        } else {
            // Fallback: usar porcentaje fijo si la función no existe
            $points_percentage = (float) ($options['points_percentage'] ?? 5);
            
            // Calcular puntos basado en el porcentaje del total de la compra
            $points_from_order = floor(($order_total * $points_percentage) / 100);
            
            if ($points_from_order > 0) {
                $user_info = get_userdata($user_id);
                $buyer_name = $user_info ? $user_info->display_name : "Usuario #$user_id";
                
                $description = sprintf(
                    'Tu referido %s hizo un aporte de $%s por lo que ganas %d puntos', 
                    $buyer_name,
                    number_format($order_total, 0, ',', '.'),
                    $points_from_order
                );
        
                // Añadir puntos al REFERIDOR (no al comprador)
                $result = starter_rp_add_points(
                    $referrer_id, 
                    $points_from_order, 
                    'referral_purchase', 
                    $description, 
                    $user_id, 
                    $options['points_expiry_days'] ?? 365
                );
                
                if ($result) {
                    starter_rp_log("Starter RP: ÉXITO (fallback) - $points_from_order puntos otorgados al referidor $referrer_id por compra de $user_id (Pedido #$order_id)");
                    
                    // Añadir nota al pedido
                    $order->add_order_note(sprintf(
                        'Se otorgaron %d Virtual Coins al referidor (ID: %d) por esta compra.',
                        $points_from_order,
                        $referrer_id
                    ));
                } else {
                    starter_rp_log("Starter RP: ERROR - No se pudieron añadir puntos al referidor $referrer_id");
                }
            } else {
                starter_rp_log("Starter RP: No se calcularon puntos (0) para el pedido $order_id");
            }
        }
    } else {
        starter_rp_log("Starter RP: Usuario $user_id no tiene referidor - no se otorgan puntos por compra");
    }
    
    // Procesar puntos adicionales por producto solo para el referidor (si están configurados)
    if ($referrer_id) {
    foreach ($order->get_items() as $item) {
        $product_id = $item->get_product_id();
        $quantity = $item->get_quantity();
        
            // Obtener puntos adicionales del producto (opcional)
            $additional_product_points = get_post_meta($product_id, '_starter_additional_product_points', true);
        
            if ($additional_product_points) {
                $points = (int) $additional_product_points * $quantity;
            
            // Descripción para la transacción
            $product_name = $item->get_name();
                $user_info = get_userdata($user_id);
                $buyer_name = $user_info ? $user_info->display_name : "Usuario #$user_id";
                
            $description = sprintf(
                    'Bonificación extra - %s compró %d x %s', 
                    $buyer_name,
                $quantity, 
                $product_name
            );
            
                // Añadir puntos adicionales al REFERIDOR
            starter_rp_add_points(
                    $referrer_id, 
                $points, 
                    'referral_product_bonus', 
                $description, 
                    $user_id, 
                    $options['points_expiry_days'] ?? 365
            );
                
                starter_rp_log("Starter RP: $points puntos adicionales por producto otorgados al referidor $referrer_id");
            }
        }
    }
    
    // Marcar que el pedido ha sido procesado para puntos
    update_post_meta($order_id, '_starter_points_processed', 'yes');
    update_post_meta($order_id, '_starter_points_processing_date', current_time('mysql'));
    
    starter_rp_log("Starter RP: Procesamiento de puntos completado para pedido ID: $order_id");
    
    // Procesar puntos utilizados (evitar doble deducción si ya se procesaron vía REST)
    if (!$order->get_meta('_virtual_coins_processed')) {
    $points_used = get_post_meta($order_id, '_starter_points_used', true);
    
    if ($points_used > 0) {
        $description = sprintf('Puntos utilizados en el pedido #%d', $order_id);
        starter_rp_use_points($user_id, $points_used, $description, $order_id);
            starter_rp_log("Starter RP: Deducidos $points_used puntos del usuario $user_id");
        }
    }
}

/**
 * Calcular cuántos puntos recibirá el REFERIDOR por la compra de este producto
 */
function starter_rp_calculate_product_points($product_id) {
    // Obtener puntos adicionales personalizados del producto (opcional)
    $additional_points = get_post_meta($product_id, '_starter_additional_product_points', true);
    
    // Obtener puntos basados en porcentaje del precio que recibirá el REFERIDOR
        $product = wc_get_product($product_id);
        if ($product) {
            $price = $product->get_price();
            
        // Usar porcentaje configurable para calcular puntos que van al REFERIDOR
            $options = Starter_RP()->get_options();
        $points_percentage = (float) ($options['points_percentage'] ?? 5);
        
        $percentage_points = floor(($price * $points_percentage) / 100);
        
        // Sumar puntos del porcentaje + puntos adicionales del producto
        $total_points = $percentage_points + ($additional_points ? (int) $additional_points : 0);
        
        return $total_points;
    }
    
    return $additional_points ? (int) $additional_points : 0;
}

/**
 * Mostrar campo de canje de puntos en checkout
 */
function starter_rp_points_redemption_field() {
    if (!is_user_logged_in()) {
        return;
    }
    
    $user_id = get_current_user_id();
    
    // Verificar si el usuario puede usar el sistema de puntos
    if (!starter_rp_can_user_use_points($user_id)) {
        return;
    }
    
    $available_points = starter_rp_get_user_points($user_id);
    $options = Starter_RP()->get_options();
    
    // Verificar mínimo de puntos para canjear
    $min_points_redemption = (int) ($options['min_points_redemption'] ?? 100);
    if ($available_points < $min_points_redemption) {
        ?>
        <div class="starter-points-redemption">
            <h3><?php _e('Usar puntos', 'starter-rp'); ?></h3>
            <p class="starter-points-minimum-notice">
                <?php 
                printf(
                    __('Necesitas al menos %d puntos para poder canjear. Tienes %d puntos.', 'starter-rp'),
                    $min_points_redemption,
                    $available_points
                ); 
                ?>
            </p>
        </div>
        <?php
        return;
    }
    
    // Usar la tasa de conversión correcta
    $conversion_rate = (float) ($options['points_conversion_rate'] ?? 0.1);
    $max_points_per_order = (int) ($options['max_points_per_order'] ?? 0);
    
    // Convertir puntos a moneda usando la tasa de conversión
    $points_currency_value = $available_points * $conversion_rate;
    
    // Calcular el total del carrito
    $cart_total = WC()->cart->get_cart_contents_total() + WC()->cart->get_shipping_total();
    
    // Calcular el máximo de puntos que se pueden usar
    $max_points = $available_points; // Por defecto, todos los puntos disponibles
    
    // Aplicar límite por pedido si está configurado
    if ($max_points_per_order > 0) {
        $max_points = min($max_points, $max_points_per_order);
    }
    
    // No permitir que el descuento sea mayor al total del carrito
    $max_points_by_cart = floor($cart_total / $conversion_rate);
    $max_points = min($max_points, $max_points_by_cart);
    
    // Asegurar que cumple el mínimo
    if ($max_points < $min_points_redemption) {
        $max_points = 0;
    }
    
    // Recuperar valor de sesión si existe
    $current_points = WC()->session->get('starter_points_to_use', 0);
    ?>
    <div class="starter-points-redemption">
        <h3><?php _e('Usar puntos', 'starter-rp'); ?></h3>
        <p>
            <?php 
            printf(
                __('Tienes %d puntos disponibles (valor: %s)', 'starter-rp'),
                $available_points,
                wc_price($points_currency_value)
            ); 
            ?>
        </p>
        <p>
            <label for="starter_points_to_use"><?php _e('Puntos a utilizar:', 'starter-rp'); ?></label>
            <input type="number" 
                   min="0" 
                   max="<?php echo $max_points; ?>" 
                   step="1" 
                   id="starter_points_to_use" 
                   name="starter_points_to_use" 
                   value="<?php echo $current_points; ?>" 
                   class="input-text" />
            <span class="description">
                <?php 
                if ($max_points > 0) {
                printf(
                    __('Máximo: %d puntos (descuento: %s)', 'starter-rp'),
                    $max_points,
                        wc_price($max_points * $conversion_rate)
                ); 
                } else {
                    _e('No puedes canjear puntos en este pedido.', 'starter-rp');
                }
                ?>
            </span>
        </p>
        <button type="button" class="button" id="starter_apply_points"><?php _e('Aplicar', 'starter-rp'); ?></button>
    </div>
    <script>
    jQuery(document).ready(function($) {
        $('#starter_apply_points').on('click', function() {
            var points = $('#starter_points_to_use').val();
            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'starter_rp_update_points_to_use',
                    points: points,
                    security: '<?php echo wp_create_nonce('starter-rp-points'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        $('body').trigger('update_checkout');
                    }
                }
            });
        });
    });
    </script>
    <?php
}

/**
 * Aplicar descuento de puntos al carrito
 */
function starter_rp_apply_points_discount($cart) {
    if (!is_admin() && !defined('DOING_AJAX')) {
        return;
    }
    
    if (!is_user_logged_in()) {
        return;
    }
    
    $user_id = get_current_user_id();
    
    // Verificar si el usuario puede usar el sistema de puntos
    if (!starter_rp_can_user_use_points($user_id)) {
        return;
    }
    
    // Verificar si hay puntos para usar
    $points_to_use = WC()->session->get('starter_points_to_use', 0);
    
    if ($points_to_use <= 0) {
        return;
    }
    
    $user_id = get_current_user_id();
    $available_points = starter_rp_get_user_points($user_id);
    
    // Asegurarse de que el usuario tiene suficientes puntos
    if ($points_to_use > $available_points) {
        $points_to_use = $available_points;
        WC()->session->set('starter_points_to_use', $points_to_use);
    }
    
    // Calcular valor del descuento
    $options = Starter_RP()->get_options();
    $conversion_rate = (float) ($options['points_conversion_rate'] ?? 0.1);
    $discount_amount = $points_to_use * $conversion_rate;
    
    if ($discount_amount > 0) {
        // Añadir el descuento al carrito
        $cart->add_fee(
            sprintf(__('Descuento por %d puntos', 'starter-rp'), $points_to_use),
            -$discount_amount,
            false
        );
    }
}

/**
 * Guardar puntos utilizados en el pedido
 */
function starter_rp_save_order_points_used($order_id) {
    $user_id = get_current_user_id();
    
    // Verificar si el usuario puede usar el sistema de puntos
    if (!starter_rp_can_user_use_points($user_id)) {
        return;
    }
    
    // Verificar si hay puntos para usar
    $points_to_use = WC()->session->get('starter_points_to_use', 0);
    
    if ($points_to_use > 0) {
        // Verificar que el usuario tiene suficientes puntos
        $available_points = starter_rp_get_user_points($user_id);
        
        if ($points_to_use > $available_points) {
            $points_to_use = $available_points;
        }
        
        // Guardar en metadatos del pedido
        update_post_meta($order_id, '_starter_points_used', $points_to_use);
        
        // Calcular valor del descuento
        $options = Starter_RP()->get_options();
        $conversion_rate = (float) ($options['points_conversion_rate'] ?? 0.1);
        $discount_amount = $points_to_use * $conversion_rate;
        
        update_post_meta($order_id, '_starter_points_discount', $discount_amount);
        
        // Limpiar la sesión
        WC()->session->set('starter_points_to_use', 0);
    }
}
