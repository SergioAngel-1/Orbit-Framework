<?php
/**
 * Handler: Opciones de Entrega
 * 
 * Controla qué métodos de entrega están disponibles para el usuario
 * según su nivel de membresía (domicilio, recoger en sede).
 * 
 * Configuración esperada:
 * - home_delivery: bool - Si puede recibir a domicilio
 * - pickup: bool - Si puede recoger en sede
 * 
 * @package Starter_Memberships
 * @subpackage Benefits/Handlers
 * @since 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Starter_Delivery_Options_Handler extends Starter_Benefit_Handler_Base {
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->key = 'delivery_options';
        $this->name = 'Opciones de Entrega';
        $this->description = 'Métodos de entrega disponibles para el usuario';
        $this->requires_wc = true;
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_name(): string {
        return __($this->name, 'starter-memberships');
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_description(): string {
        return __($this->description, 'starter-memberships');
    }
    
    /**
     * {@inheritdoc}
     */
    public function register_hooks(): void {
        // Hook para filtrar métodos de envío disponibles
        add_filter('woocommerce_package_rates', [$this, 'filter_shipping_rates'], 100, 2);
        
        // Hook para mostrar mensaje en checkout si hay restricciones
        add_action('woocommerce_before_checkout_shipping_form', [$this, 'show_delivery_restrictions_notice']);
    }
    
    /**
     * {@inheritdoc}
     */
    public function apply(int $user_id, array $context = []) {
        $config = $this->get_config_for_user($user_id);
        
        // Si no hay configuración, permitir todo por defecto
        if (!$config) {
            return [
                'home_delivery' => true,
                'pickup' => true
            ];
        }
        
        return [
            'home_delivery' => !empty($config['home_delivery']),
            'pickup' => !empty($config['pickup'])
        ];
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_display_value(int $user_id): string {
        $config = $this->get_config_for_user($user_id);
        
        if (!$config) {
            return '';
        }
        
        $options = [];
        
        if (!empty($config['home_delivery'])) {
            $options[] = __('Domicilio', 'starter-memberships');
        }
        
        if (!empty($config['pickup'])) {
            $options[] = __('Recoger en sede', 'starter-memberships');
        }
        
        if (empty($options)) {
            return __('Sin opciones de entrega', 'starter-memberships');
        }
        
        return implode(' + ', $options);
    }
    
    /**
     * Filtrar métodos de envío según configuración de membresía
     * 
     * @param array $rates
     * @param array $package
     * @return array
     */
    public function filter_shipping_rates(array $rates, array $package): array {
        $user_id = get_current_user_id();
        
        // Si no hay usuario, no filtrar
        if (!$user_id) {
            return $rates;
        }
        
        // Si el beneficio no está habilitado, no filtrar
        if (!$this->is_enabled_for_user($user_id)) {
            return $rates;
        }
        
        $options = $this->apply($user_id, []);
        
        // Si tiene todas las opciones, no filtrar
        if ($options['home_delivery'] && $options['pickup']) {
            return $rates;
        }
        
        $filtered_rates = [];
        
        foreach ($rates as $rate_id => $rate) {
            $method_id = $rate->get_method_id();
            
            // Identificar tipo de método
            $is_pickup = $this->is_pickup_method($method_id, $rate);
            $is_delivery = !$is_pickup;
            
            // Filtrar según opciones
            if ($is_pickup && $options['pickup']) {
                $filtered_rates[$rate_id] = $rate;
            } elseif ($is_delivery && $options['home_delivery']) {
                $filtered_rates[$rate_id] = $rate;
            }
        }
        
        // Si no quedó ningún método, devolver original con aviso
        if (empty($filtered_rates)) {
            $this->log("Todos los métodos filtrados, devolviendo original", [
                'user_id' => $user_id,
                'options' => $options
            ]);
            return $rates;
        }
        
        return $filtered_rates;
    }
    
    /**
     * Mostrar aviso de restricciones de entrega
     */
    public function show_delivery_restrictions_notice(): void {
        $user_id = get_current_user_id();
        
        if (!$user_id || !$this->is_enabled_for_user($user_id)) {
            return;
        }
        
        $options = $this->apply($user_id, []);
        
        // Si tiene todas las opciones, no mostrar aviso
        if ($options['home_delivery'] && $options['pickup']) {
            return;
        }
        
        $message = '';
        
        if (!$options['home_delivery'] && $options['pickup']) {
            $message = __('Tu membresía actual solo permite recoger en sede.', 'starter-memberships');
        } elseif ($options['home_delivery'] && !$options['pickup']) {
            $message = __('Tu membresía actual solo permite entrega a domicilio.', 'starter-memberships');
        } elseif (!$options['home_delivery'] && !$options['pickup']) {
            $message = __('Tu membresía actual no tiene opciones de entrega configuradas.', 'starter-memberships');
        }
        
        if ($message) {
            echo '<div class="woocommerce-info starter-delivery-notice">' . esc_html($message) . '</div>';
        }
    }
    
    /**
     * Determinar si un método de envío es de tipo "recoger en sede"
     * 
     * @param string $method_id
     * @param WC_Shipping_Rate $rate
     * @return bool
     */
    private function is_pickup_method(string $method_id, $rate): bool {
        // IDs comunes de métodos de recogida
        $pickup_methods = [
            'local_pickup',
            'pickup_location',
            'store_pickup',
            'click_collect'
        ];
        
        if (in_array($method_id, $pickup_methods)) {
            return true;
        }
        
        // Verificar por nombre del método
        $label = strtolower($rate->get_label());
        $pickup_keywords = ['recog', 'pickup', 'sede', 'tienda', 'local'];
        
        foreach ($pickup_keywords as $keyword) {
            if (strpos($label, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }
}

// Registrar el handler inmediatamente
// (las clases core se cargan antes que los handlers en benefits/index.php)
starter_benefit_registry()->register(new Starter_Delivery_Options_Handler());
