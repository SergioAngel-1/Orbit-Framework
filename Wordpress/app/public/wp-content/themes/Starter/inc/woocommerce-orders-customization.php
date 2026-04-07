<?php
/**
 * Personalizaciones de la tabla de pedidos de WooCommerce
 * 
 * Este archivo implementa las personalizaciones para la vista de pedidos
 * en el panel de administración de WooCommerce.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase para manejar las personalizaciones de la tabla de pedidos
 */
class Starter_WooCommerce_Orders_Table {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Inicializar hooks
        $this->init_hooks();
    }
    
    /**
     * Inicializar todos los hooks necesarios
     */
    private function init_hooks() {
        // Hooks para personalizar columnas (compatibles con HPOS)
        add_filter('manage_edit-shop_order_columns', array($this, 'customize_order_columns'), 999);
        add_filter('woocommerce_shop_order_list_table_columns', array($this, 'customize_order_columns'), 999);
        
        // Hooks para renderizar el contenido de las columnas
        add_action('manage_shop_order_posts_custom_column', array($this, 'render_order_column_content'), 20, 2);
        add_action('woocommerce_shop_order_list_table_custom_column', array($this, 'render_order_column_content'), 20, 2);
        
        // Hooks para hacer las columnas ordenables
        add_filter('manage_edit-shop_order_sortable_columns', array($this, 'make_columns_sortable'));
        add_filter('woocommerce_shop_order_list_table_sortable_columns', array($this, 'make_columns_sortable'));
        
        // Añadir estilos CSS
        add_action('admin_head', array($this, 'add_admin_styles'));
        
        // Mensaje de depuración
        add_action('admin_notices', array($this, 'debug_notice'));
    }
    
    /**
     * Mostrar un mensaje de depuración en la pantalla de pedidos
     */
    public function debug_notice() {
        $screen = get_current_screen();
        if ($screen && ($screen->id === 'edit-shop_order' || $screen->id === 'woocommerce_page_wc-orders')) {
            echo '<div class="notice notice-info is-dismissible"><p>Personalizaciones de la tabla de pedidos de Starter activadas.</p></div>';
        }
    }
    
    /**
     * Personalizar las columnas de la tabla de pedidos
     */
    public function customize_order_columns($columns) {
        // Definir las columnas personalizadas
        $custom_columns = array(
            'cb'                => isset($columns['cb']) ? $columns['cb'] : '<input type="checkbox" />',
            'order_number'      => __('Pedido', 'starter'),
            'order_date'        => __('Fecha', 'starter'),
            'order_status'      => __('Estado del pedido', 'starter'),
            'payment_method'    => __('Método de pago', 'starter'),
            'shipping_method'   => __('Opción de envío', 'starter'),
            'order_total'       => __('Total', 'starter'),
        );
        
        // Añadir la columna de acciones si existe
        if (isset($columns['wc_actions'])) {
            $custom_columns['wc_actions'] = $columns['wc_actions'];
        }
        
        return $custom_columns;
    }
    
    /**
     * Renderizar el contenido de las columnas personalizadas
     */
    public function render_order_column_content($column, $order_id) {
        // Obtener el objeto de pedido
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }
        
        switch ($column) {
            case 'payment_method':
                $this->render_payment_method_column($order);
                break;
                
            case 'shipping_method':
                $this->render_shipping_method_column($order);
                break;
                
            case 'order_benefits':
                $this->render_benefits_column($order);
                break;
        }
    }
    
    /**
     * Renderizar la columna de beneficios (muestra gratis, envío gratis, etc.)
     */
    private function render_benefits_column($order) {
        $benefits = [];
        
        // Verificar muestra gratis
        $free_samples_earned = $order->get_meta('_starter_fs_earned_grams');
        if (!empty($free_samples_earned) && floatval($free_samples_earned) > 0) {
            $benefits[] = '<span class="benefit-badge sample-badge" title="Muestra gratis: ' . esc_attr($free_samples_earned) . 'g">🌿 ' . $free_samples_earned . 'g</span>';
        }
        
        // Verificar envío gratis por membresía
        $use_free_delivery = $order->get_meta('_use_free_delivery_membership');
        if ($use_free_delivery === 'yes') {
            $benefits[] = '<span class="benefit-badge delivery-badge" title="Envío gratis por membresía">🚚 Gratis</span>';
        }
        
        // Verificar descuento de membresía
        $membership_discount = $order->get_meta('_membership_discount_total');
        if (!empty($membership_discount) && floatval($membership_discount) > 0) {
            $percentage = $order->get_meta('_membership_discount_percentage');
            $benefits[] = '<span class="benefit-badge discount-badge" title="Descuento de membresía: $' . number_format($membership_discount, 0) . '">💰 ' . $percentage . '%</span>';
        }
        
        if (empty($benefits)) {
            echo '<span class="na">&ndash;</span>';
        } else {
            echo implode(' ', $benefits);
        }
    }
    
    /**
     * Renderizar la columna de método de pago
     */
    private function render_payment_method_column($order) {
        $payment_method = $order->get_payment_method_title();
        $payment_method_id = $order->get_payment_method();
        
        // Si es pago con Wompi, mostrar con estilo distintivo
        if ($payment_method_id === 'wompi') {
            $order_type = $order->get_meta('_order_type');
            $wompi_ref = $order->get_meta('_wompi_reference');
            
            echo '<span class="wompi-payment"><strong>' . esc_html($payment_method) . '</strong></span>';
            
            // Mostrar tipo de orden si es FC o membresía
            if ($order_type === 'virtual_coins_purchase') {
                echo '<br><span class="order-type-badge fc-badge">Virtual Coins</span>';
            } elseif ($order_type === 'membership_purchase') {
                echo '<br><span class="order-type-badge membership-badge">Membresía</span>';
            }
            
            // Mostrar referencia Wompi si existe
            if (!empty($wompi_ref)) {
                echo '<br><small class="wompi-ref">' . esc_html($wompi_ref) . '</small>';
            }
        }
        // Si es transferencia bancaria, mostrar el tipo específico
        elseif ($payment_method_id === 'bacs' || $payment_method_id === 'bank_transfer' || $payment_method_id === 'bank') {
            // Usar el getter apropiado en lugar de get_meta para evitar el error is_internal_meta_key
            $payment_type = $order->get_meta('_bank_transfer_type');
            
            if (!empty($payment_type)) {
                // Si tenemos el tipo de transferencia, mostrarlo
                echo '<strong>' . esc_html($payment_method) . '</strong><br>';
                echo '<span class="bank-transfer-type">' . esc_html($payment_type) . '</span>';
            } else if (strpos($payment_method, '-') !== false) {
                // Si el título contiene un guión, asumimos que incluye el tipo
                echo esc_html($payment_method);
            } else {
                echo esc_html($payment_method);
            }
        } else {
            echo esc_html($payment_method);
        }
    }
    
    /**
     * Renderizar la columna de método de envío
     */
    private function render_shipping_method_column($order) {
        $shipping_methods = $order->get_shipping_methods();
        
        if (empty($shipping_methods)) {
            echo '<span class="na">&ndash;</span>';
            return;
        }
        
        $shipping_html = array();
        
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
    }
    
    /**
     * Hacer que las columnas sean ordenables
     */
    public function make_columns_sortable($columns) {
        $columns['payment_method'] = 'payment_method';
        $columns['shipping_method'] = 'shipping_method';
        return $columns;
    }
    
    /**
     * Añadir estilos CSS para la vista de pedidos
     */
    public function add_admin_styles() {
        $screen = get_current_screen();
        
        // Solo aplicar en la pantalla de pedidos
        if ($screen && ($screen->id === 'edit-shop_order' || $screen->id === 'woocommerce_page_wc-orders')) {
            ?>
            <style type="text/css">
                .premium-shipping {
                    color: #9c27b0;
                    font-weight: bold;
                }
                
                .bank-transfer-type {
                    color: #2196f3;
                    font-style: italic;
                }
                
                /* Estilos para pagos con Wompi */
                .wompi-payment {
                    color: #00a651;
                }
                
                .wompi-ref {
                    color: #666;
                    font-size: 11px;
                }
                
                /* Badges para tipos de orden */
                .order-type-badge {
                    display: inline-block;
                    padding: 2px 6px;
                    border-radius: 3px;
                    font-size: 10px;
                    font-weight: bold;
                    text-transform: uppercase;
                    margin-top: 3px;
                }
                
                .fc-badge {
                    background-color: #ffd700;
                    color: #333;
                }
                
                .membership-badge {
                    background-color: #9c27b0;
                    color: #fff;
                }
                
                /* Badges de beneficios */
                .benefit-badge {
                    display: inline-block;
                    padding: 3px 8px;
                    border-radius: 4px;
                    font-size: 11px;
                    font-weight: 600;
                    margin: 1px 2px;
                    white-space: nowrap;
                }
                
                .sample-badge {
                    background-color: #e8f5e9;
                    color: #2e7d32;
                    border: 1px solid #a5d6a7;
                }
                
                .delivery-badge {
                    background-color: #e3f2fd;
                    color: #1565c0;
                    border: 1px solid #90caf9;
                }
                
                .discount-badge {
                    background-color: #fff3e0;
                    color: #e65100;
                    border: 1px solid #ffcc80;
                }
                
                /* Columna de beneficios */
                .widefat .column-order_benefits,
                .woocommerce-orders-table .column-order_benefits {
                    width: 12%;
                }
                
                /* Ajustar el ancho de las columnas */
                .widefat .column-order_number,
                .woocommerce-orders-table .column-order_number {
                    width: 10%;
                }
                .widefat .column-order_date,
                .woocommerce-orders-table .column-order_date {
                    width: 12%;
                }
                .widefat .column-order_status,
                .woocommerce-orders-table .column-order_status {
                    width: 12%;
                }
                .widefat .column-payment_method,
                .woocommerce-orders-table .column-payment_method {
                    width: 18%;
                }
                .widefat .column-shipping_method,
                .woocommerce-orders-table .column-shipping_method {
                    width: 15%;
                }
                .widefat .column-order_total,
                .woocommerce-orders-table .column-order_total {
                    width: 10%;
                }
            </style>
            <?php
        }
    }
}

// Inicializar después de que WooCommerce esté listo
add_action('woocommerce_init', function() {
    new Starter_WooCommerce_Orders_Table();
});
