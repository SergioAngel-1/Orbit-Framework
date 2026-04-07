<?php
/**
 * Personalizaciones de la página de detalles del pedido en WooCommerce
 * 
 * @package Starter
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase para personalizar la página de detalles del pedido
 */
class Starter_WooCommerce_Order_Details {
    
    public function __construct() {
        add_action('add_meta_boxes', [$this, 'register_metaboxes']);
        add_action('admin_head', [$this, 'add_admin_styles']);
    }
    
    /**
     * Registrar todos los metaboxes
     */
    public function register_metaboxes(): void {
        $screens = ['shop_order'];
        if (function_exists('wc_get_page_screen_id')) {
            $screens[] = wc_get_page_screen_id('shop-order');
        }
        
        foreach ($screens as $screen) {
            add_meta_box('starter_payment_details', 'Detalles del método de pago', [$this, 'render_payment_metabox'], $screen, 'side', 'high');
            
            // Solo mostrar metabox de muestras si el pedido tiene o ganará muestra
            if ($this->should_show_samples_metabox()) {
                add_meta_box('starter_free_samples', '🌿 Muestras Gratis', [$this, 'render_free_samples_metabox'], $screen, 'side', 'high');
            }
        }
    }
    
    /**
     * Determinar si se debe mostrar el metabox de muestras gratis
     */
    private function should_show_samples_metabox(): bool {
        global $post, $theorder;
        
        // Obtener el pedido
        $order = null;
        if ($theorder instanceof \WC_Order) {
            $order = $theorder;
        } elseif (isset($_GET['id'])) {
            $order = wc_get_order(intval($_GET['id']));
        } elseif ($post) {
            $order = wc_get_order($post->ID);
        }
        
        if (!$order) {
            return false;
        }
        
        // Mostrar si ganó muestra
        $earned_grams = floatval($order->get_meta('_starter_fs_earned_grams'));
        if ($earned_grams > 0) {
            return true;
        }
        
        // Mostrar si va a ganar muestra (pedido "invisible")
        $will_earn = $order->get_meta('_starter_fs_will_earn');
        if ($will_earn === 'yes') {
            return true;
        }
        
        // Si no tiene metadatos pero está pendiente, intentar calcular si debería ganar
        if (empty($will_earn) && in_array($order->get_status(), ['pending', 'processing', 'on-hold'])) {
            $should_earn = $this->calculate_if_order_should_earn($order);
            if ($should_earn) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Calcular si un pedido debería ganar muestra (para pedidos sin metadatos)
     */
    private function calculate_if_order_should_earn($order): bool {
        $user_id = $order->get_user_id();
        if (!$user_id || !function_exists('starter_benefit_registry')) {
            return false;
        }
        
        $handler = starter_benefit_registry()->get('free_samples');
        if (!$handler) {
            return false;
        }
        
        $status = $handler->apply($user_id, []);
        if (!$status || !$status['can_receive_more']) {
            return false;
        }
        
        // Verificar si el progreso actual + pedidos pendientes >= ciclo completo + 1
        $orders_in_cycle = $status['orders_in_current_cycle'];
        $pending_count = $status['pending_orders_count'] ?? 0;
        $every_orders = $status['every_orders'];
        
        // Si hay suficientes pedidos pendientes para completar el ciclo y ganar muestra
        $total_progress = $orders_in_cycle + $pending_count;
        return $total_progress > $every_orders;
    }
    
    /**
     * Obtener orden de forma compatible con HPOS
     */
    private function get_order($post): ?\WC_Order {
        if ($post instanceof \WC_Order) {
            return $post;
        }
        $order_id = is_object($post) ? $post->ID : intval($post);
        return wc_get_order($order_id) ?: null;
    }
    
    /**
     * Metabox: Método de pago
     */
    public function render_payment_metabox($post): void {
        $order = $this->get_order($post);
        if (!$order) {
            echo '<p>No se pudo cargar el pedido.</p>';
            return;
        }
        
        $method_id = $order->get_payment_method();
        $method_title = $order->get_payment_method_title();
        $is_paid = $order->is_paid();
        
        echo '<div class="starter-payment-details">';
        echo '<p><strong>Método:</strong> ' . esc_html($method_title) . '</p>';
        
        if (in_array($method_id, ['bacs', 'bank_transfer'])) {
            $type = $order->get_meta('_bank_transfer_type');
            if ($type) {
                echo '<p><strong>Tipo:</strong> <span class="payment-type">' . esc_html($type) . '</span></p>';
            }
        } elseif ($method_id === 'cod') {
            echo '<p class="cod-info">Pago contra entrega</p>';
        }
        
        echo '<p class="payment-status ' . ($is_paid ? 'paid' : 'pending') . '">';
        echo '<strong>Estado:</strong> ' . ($is_paid ? 'Pagado' : 'Pendiente');
        echo '</p>';
        echo '</div>';
    }
    
    /**
     * Metabox: Muestras Gratis
     */
    public function render_free_samples_metabox($post): void {
        $order = $this->get_order($post);
        if (!$order) {
            echo '<p>No se pudo cargar el pedido.</p>';
            return;
        }
        
        $order_id = $order->get_id();
        $user_id = $order->get_user_id();
        $order_status = $order->get_status();
        
        // Meta keys del pedido
        $earned_grams = floatval($order->get_meta('_starter_fs_earned_grams'));
        $will_earn = $order->get_meta('_starter_fs_will_earn');
        $expected_grams = floatval($order->get_meta('_starter_fs_expected_grams'));
        $delivery_number = intval($order->get_meta('_starter_fs_delivery_number'));
        
        // Obtener config del beneficio
        $config = $this->get_user_samples_config($user_id);
        
        echo '<div class="starter-free-samples-details">';
        
        if ($earned_grams > 0) {
            // YA GANÓ MUESTRA (pedido completado)
            $this->render_earned_state($earned_grams, $delivery_number, $config);
        } elseif ($will_earn === 'yes' && $expected_grams > 0) {
            // VA A GANAR MUESTRA (pedido con metadatos)
            $this->render_will_earn_state($expected_grams, $delivery_number, $config, $order_status);
        } elseif (empty($will_earn) && $this->calculate_if_order_should_earn($order)) {
            // VA A GANAR MUESTRA (pedido sin metadatos - calculado dinámicamente)
            $grams = $config['grams_per_delivery'];
            $max_deliveries = floor($config['total_grams'] / $config['grams_per_delivery']);
            $current_deliveries = $this->get_user_deliveries_count($user_id);
            $delivery_num = $current_deliveries + 1;
            $this->render_will_earn_state($grams, $delivery_num, $config, $order_status);
        }
        
        echo '</div>';
    }
    
    /**
     * Obtener contador de entregas del usuario
     */
    private function get_user_deliveries_count(int $user_id): int {
        if (!function_exists('starter_benefit_registry')) {
            return 0;
        }
        
        $handler = starter_benefit_registry()->get('free_samples');
        if (!$handler) {
            return 0;
        }
        
        $status = $handler->apply($user_id, []);
        return $status ? intval($status['deliveries_earned']) : 0;
    }
    
    /**
     * Obtener configuración de muestras del usuario
     */
    private function get_user_samples_config(int $user_id): array {
        $default = ['every_orders' => 2, 'grams_per_delivery' => 1, 'total_grams' => 10];
        
        if (!$user_id || !function_exists('starter_benefit_registry')) {
            return $default;
        }
        
        $handler = starter_benefit_registry()->get('free_samples');
        if (!$handler) {
            return $default;
        }
        
        $status = $handler->apply($user_id, []);
        return $status ? [
            'every_orders' => $status['every_orders'],
            'grams_per_delivery' => $status['grams_per_delivery'],
            'total_grams' => $status['total_grams']
        ] : $default;
    }
    
    /**
     * Estado: Ganó muestra (pedido completado)
     */
    private function render_earned_state(float $grams, int $delivery_number, array $config): void {
        $max_deliveries = floor($config['total_grams'] / $config['grams_per_delivery']);
        
        echo '<p class="samples-earned">';
        echo '<span class="dashicons dashicons-yes-alt"></span> ';
        echo sprintf('<strong>INCLUIR %sg DE MUESTRA</strong>', $grams);
        echo '</p>';
        
        if ($delivery_number > 0 && $max_deliveries > 0) {
            echo '<p class="samples-info">';
            echo sprintf('Entrega #%d de %d', $delivery_number, $max_deliveries);
            echo '</p>';
        }
        
        echo '<p class="samples-action">✓ Agregar muestra física al envío</p>';
    }
    
    /**
     * Estado: Va a ganar muestra (pedido en processing)
     */
    private function render_will_earn_state(float $grams, int $delivery_number, array $config, string $status): void {
        $max_deliveries = isset($config['total_grams']) ? floor($config['total_grams'] / $config['grams_per_delivery']) : 10;
        
        echo '<p class="samples-will-earn">';
        echo '<span class="dashicons dashicons-warning"></span> ';
        echo sprintf('<strong>INCLUIR %sg DE MUESTRA</strong>', $grams);
        echo '</p>';
        
        if ($delivery_number > 0 && $max_deliveries > 0) {
            echo '<p class="samples-info">';
            echo sprintf('Será entrega #%d de %d', $delivery_number, $max_deliveries);
            echo '</p>';
        }
        
        echo '<p class="samples-action-pending">⚠️ Preparar muestra para cuando se complete el pedido</p>';
    }
    
    /**
     * Estilos CSS del admin
     */
    public function add_admin_styles(): void {
        $screen = get_current_screen();
        $hpos_id = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : '';
        
        if (!$screen || ($screen->id !== 'shop_order' && $screen->id !== $hpos_id)) {
            return;
        }
        ?>
        <style>
            .starter-payment-details, .starter-free-samples-details { padding: 5px 0; }
            .starter-payment-details p, .starter-free-samples-details p { margin: 5px 0; display: flex; align-items: center; gap: 5px; }
            .payment-type { color: #2196f3; font-weight: bold; }
            .payment-status.paid { color: #4CAF50; }
            .payment-status.pending { color: #FF9800; }
            .cod-info { font-style: italic; color: #607D8B; }
            
            /* Muestras gratis */
            .samples-earned { color: #4CAF50; font-weight: bold; font-size: 14px; }
            .samples-will-earn { color: #FF9800; font-weight: bold; font-size: 14px; }
            .samples-info { color: #666; font-size: 12px; font-style: italic; }
            .samples-action { color: #4CAF50; font-size: 12px; background: #e8f5e9; padding: 8px; border-radius: 4px; margin-top: 8px !important; }
            .samples-action-pending { color: #e65100; font-size: 12px; background: #fff3e0; padding: 8px; border-radius: 4px; margin-top: 8px !important; }
        </style>
        <?php
    }
}

// Inicializar después de que WooCommerce esté listo
add_action('woocommerce_init', function() {
    new Starter_WooCommerce_Order_Details();
});

/**
 * Handler para procesar muestras gratis manualmente
 */
add_action('admin_post_process_free_samples', function() {
    if (!current_user_can('edit_shop_orders')) {
        wp_die('No tienes permisos para realizar esta acción.');
    }
    
    $order_id = intval($_GET['order_id'] ?? 0);
    
    if (!$order_id || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'process_free_samples_' . $order_id)) {
        wp_die('Enlace inválido o expirado.');
    }
    
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_die('Pedido no encontrado.');
    }
    
    if (function_exists('starter_benefit_registry')) {
        $handler = starter_benefit_registry()->get('free_samples');
        if ($handler && method_exists($handler, 'on_order_completed')) {
            $handler->on_order_completed($order_id);
            wp_redirect(admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id . '&samples_processed=1'));
            exit;
        }
    }
    
    wp_die('No se pudo procesar. El sistema de beneficios no está disponible.');
});
