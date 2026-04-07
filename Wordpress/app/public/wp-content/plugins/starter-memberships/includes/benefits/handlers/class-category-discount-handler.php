<?php
/**
 * Handler: Descuento en Categorías
 * 
 * Aplica descuentos porcentuales a productos de categorías específicas
 * según el nivel de membresía del usuario.
 * 
 * Configuración esperada:
 * - percentage: Porcentaje de descuento (0-100)
 * - categories: Array de IDs de categorías (vacío = todas las accesibles)
 * 
 * @package Starter_Memberships
 * @subpackage Benefits/Handlers
 * @since 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Starter_Category_Discount_Handler extends Starter_Benefit_Handler_Base {
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->key = 'category_discount';
        $this->name = 'Descuento en Categorías';
        $this->description = 'Porcentaje de descuento en productos de categorías específicas';
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
        // Hook para modificar el precio del producto
        add_filter('woocommerce_product_get_price', [$this, 'apply_discount_to_price'], 100, 2);
        add_filter('woocommerce_product_get_sale_price', [$this, 'apply_discount_to_price'], 100, 2);
        add_filter('woocommerce_product_variation_get_price', [$this, 'apply_discount_to_price'], 100, 2);
        add_filter('woocommerce_product_variation_get_sale_price', [$this, 'apply_discount_to_price'], 100, 2);
        
        // Hook para mostrar el descuento en el frontend
        add_filter('woocommerce_get_price_html', [$this, 'modify_price_html'], 100, 2);
    }
    
    /**
     * {@inheritdoc}
     */
    public function apply(int $user_id, array $context = []) {
        if (!isset($context['product_id'])) {
            return null;
        }
        
        $product_id = intval($context['product_id']);
        $original_price = $context['original_price'] ?? null;
        
        if ($original_price === null) {
            $product = wc_get_product($product_id);
            if (!$product) {
                return null;
            }
            $original_price = floatval($product->get_regular_price());
        }
        
        $discount = $this->calculate_discount($user_id, $product_id, $original_price);
        
        if ($discount === null) {
            return null;
        }
        
        return [
            'original_price' => $original_price,
            'discount_percentage' => $discount['percentage'],
            'discount_amount' => $discount['amount'],
            'final_price' => $discount['final_price']
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
        
        $percentage = $config['percentage'] ?? 0;
        
        if ($percentage <= 0) {
            return '';
        }
        
        return sprintf(__('%d%% de descuento', 'starter-memberships'), $percentage);
    }
    
    /**
     * Aplicar descuento al precio del producto
     * 
     * @param string $price
     * @param WC_Product $product
     * @return string
     */
    public function apply_discount_to_price($price, $product) {
        // Evitar recursión
        static $applying = false;
        if ($applying) {
            return $price;
        }
        
        // No aplicar en admin
        if (is_admin() && !wp_doing_ajax()) {
            return $price;
        }
        
        // No aplicar si no hay precio
        if (empty($price)) {
            return $price;
        }
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            return $price;
        }
        
        // Verificar si el beneficio está habilitado
        if (!$this->is_enabled_for_user($user_id)) {
            return $price;
        }
        
        $applying = true;
        $discount = $this->calculate_discount($user_id, $product->get_id(), floatval($price));
        $applying = false;
        
        if ($discount === null) {
            return $price;
        }
        
        return $discount['final_price'];
    }
    
    /**
     * Modificar el HTML del precio para mostrar el descuento
     * 
     * @param string $price_html
     * @param WC_Product $product
     * @return string
     */
    public function modify_price_html($price_html, $product) {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return $price_html;
        }
        
        if (!$this->is_enabled_for_user($user_id)) {
            return $price_html;
        }
        
        $config = $this->get_config_for_user($user_id);
        if (!$config || !$this->product_qualifies($product->get_id(), $config)) {
            return $price_html;
        }
        
        $percentage = $config['percentage'] ?? 0;
        if ($percentage <= 0) {
            return $price_html;
        }
        
        // Agregar badge de descuento por membresía
        $badge = sprintf(
            '<span class="starter-membership-discount-badge" style="background: #FF6B35; color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px; margin-left: 5px;">-%d%% Membresía</span>',
            $percentage
        );
        
        return $price_html . $badge;
    }
    
    /**
     * Calcular el descuento para un producto
     * 
     * @param int $user_id
     * @param int $product_id
     * @param float $original_price
     * @return array|null
     */
    private function calculate_discount(int $user_id, int $product_id, float $original_price): ?array {
        $config = $this->get_config_for_user($user_id);
        
        if (!$config) {
            return null;
        }
        
        // Verificar si el producto califica
        if (!$this->product_qualifies($product_id, $config)) {
            return null;
        }
        
        $percentage = floatval($config['percentage'] ?? 0);
        
        if ($percentage <= 0) {
            return null;
        }
        
        $discount_amount = $original_price * ($percentage / 100);
        $final_price = $original_price - $discount_amount;
        
        return [
            'percentage' => $percentage,
            'amount' => round($discount_amount, 2),
            'final_price' => round(max(0, $final_price), 2)
        ];
    }
    
    /**
     * Verificar si un producto califica para el descuento
     * 
     * @param int $product_id
     * @param array $config
     * @return bool
     */
    private function product_qualifies(int $product_id, array $config): bool {
        $categories = $config['categories'] ?? [];
        
        // Si no hay categorías específicas, aplicar a todas
        if (empty($categories)) {
            return true;
        }
        
        // Obtener categorías del producto
        $product_categories = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
        
        if (is_wp_error($product_categories)) {
            return false;
        }
        
        // Verificar si alguna categoría del producto está en la lista
        return !empty(array_intersect($product_categories, $categories));
    }
}

// Registrar el handler inmediatamente
// (las clases core se cargan antes que los handlers en benefits/index.php)
starter_benefit_registry()->register(new Starter_Category_Discount_Handler());
