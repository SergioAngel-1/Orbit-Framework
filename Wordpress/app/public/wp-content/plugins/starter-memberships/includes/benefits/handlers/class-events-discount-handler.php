<?php
/**
 * Handler: Descuento en Eventos
 * 
 * Aplica descuentos porcentuales en productos de categorías de eventos
 * según el nivel de membresía del usuario.
 * 
 * Configuración esperada:
 * - percentage: Porcentaje de descuento (0-100)
 * - categories: Array de IDs de categorías de eventos
 * 
 * @package Starter_Memberships
 * @subpackage Benefits/Handlers
 * @since 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Starter_Events_Discount_Handler extends Starter_Benefit_Handler_Base {
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->key = 'events_discount';
        $this->name = 'Descuento en Eventos';
        $this->description = 'Porcentaje de descuento en eventos de la marca';
        $this->requires_wc = false;
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
        // Hook para aplicar descuento en precio de eventos
        add_filter('starter_event_ticket_price', [$this, 'apply_event_discount'], 10, 3);
        
        // Hook para mostrar descuento en listado de eventos
        add_filter('starter_event_display_price', [$this, 'modify_event_price_display'], 10, 2);
    }
    
    /**
     * {@inheritdoc}
     */
    public function apply(int $user_id, array $context = []) {
        $config = $this->get_config_for_user($user_id);
        
        if (!$config) {
            return null;
        }
        
        $percentage = floatval($config['percentage'] ?? 0);
        $categories = $config['categories'] ?? [];
        
        if ($percentage <= 0) {
            return null;
        }
        
        // Si se proporciona producto, verificar si está en las categorías de eventos
        if (isset($context['product_id']) && !empty($categories)) {
            $product_id = intval($context['product_id']);
            $product_categories = wc_get_product_term_ids($product_id, 'product_cat');
            
            // Verificar si el producto está en alguna categoría de eventos
            $in_event_category = !empty(array_intersect($product_categories, $categories));
            
            if (!$in_event_category) {
                return null; // El producto no está en categorías de eventos
            }
        }
        
        // Si se proporciona precio original, calcular descuento
        if (isset($context['original_price'])) {
            $original_price = floatval($context['original_price']);
            $discount_amount = $original_price * ($percentage / 100);
            $final_price = $original_price - $discount_amount;
            
            return [
                'percentage' => $percentage,
                'categories' => $categories,
                'original_price' => $original_price,
                'discount_amount' => round($discount_amount, 2),
                'final_price' => round(max(0, $final_price), 2)
            ];
        }
        
        return [
            'percentage' => $percentage,
            'categories' => $categories
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
        $categories = $config['categories'] ?? [];
        
        if ($percentage <= 0) {
            return '';
        }
        
        $text = sprintf(__('%d%% de descuento', 'starter-memberships'), $percentage);
        
        // Agregar nombres de categorías si existen
        if (!empty($categories)) {
            $category_names = [];
            foreach ($categories as $cat_id) {
                $term = get_term($cat_id, 'product_cat');
                if ($term && !is_wp_error($term)) {
                    $category_names[] = $term->name;
                }
            }
            if (!empty($category_names)) {
                $text .= ' en ' . implode(', ', $category_names);
            }
        }
        
        return $text;
    }
    
    /**
     * Aplicar descuento al precio de un evento
     * 
     * @param float $price Precio original
     * @param int $event_id ID del evento
     * @param int $user_id ID del usuario
     * @return float
     */
    public function apply_event_discount(float $price, int $event_id, int $user_id): float {
        if (!$this->is_enabled_for_user($user_id)) {
            return $price;
        }
        
        $result = $this->apply($user_id, ['original_price' => $price]);
        
        if (!$result) {
            return $price;
        }
        
        $this->log("Descuento en evento aplicado", [
            'event_id' => $event_id,
            'user_id' => $user_id,
            'original' => $price,
            'final' => $result['final_price']
        ]);
        
        return $result['final_price'];
    }
    
    /**
     * Modificar display del precio de evento
     * 
     * @param string $price_html
     * @param int $event_id
     * @return string
     */
    public function modify_event_price_display(string $price_html, int $event_id): string {
        $user_id = get_current_user_id();
        
        if (!$user_id || !$this->is_enabled_for_user($user_id)) {
            return $price_html;
        }
        
        $config = $this->get_config_for_user($user_id);
        $percentage = $config['percentage'] ?? 0;
        
        if ($percentage <= 0) {
            return $price_html;
        }
        
        // Agregar badge de descuento
        $badge = sprintf(
            '<span class="starter-event-discount-badge" style="background: #FF6B35; color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px; margin-left: 5px;">-%d%% Membresía</span>',
            $percentage
        );
        
        return $price_html . $badge;
    }
}

// Registrar el handler inmediatamente
// (las clases core se cargan antes que los handlers en benefits/index.php)
starter_benefit_registry()->register(new Starter_Events_Discount_Handler());
