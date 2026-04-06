<?php
/**
 * Handler: Descuentos en Marcas Aliadas
 * 
 * Gestiona descuentos en marcas aliadas como Licorera de Kush
 * y precio de entrada a Club Casa Kush.
 * 
 * Este handler maneja múltiples beneficios relacionados con aliados:
 * - partner_discount_licorera: Descuento en Licorera de Kush
 * - partner_club_casa_kush: Precio de entrada a Club Casa Kush
 * 
 * @package Starter_Memberships
 * @subpackage Benefits/Handlers
 * @since 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handler para descuento en Licorera de Kush
 */
class Starter_Partner_Discount_Licorera_Handler extends Starter_Benefit_Handler_Base {
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->key = 'partner_discount_licorera';
        $this->name = 'Descuento Licorera de Kush';
        $this->description = 'Descuento en marca aliada: Licorera de Kush';
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
        // Hook para generar código de descuento
        add_filter('starter_partner_licorera_discount_code', [$this, 'generate_discount_code'], 10, 2);
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
        
        if ($percentage <= 0) {
            return null;
        }
        
        return [
            'percentage' => $percentage,
            'partner_name' => 'Licorera de Kush',
            'discount_code' => $this->get_or_create_discount_code($user_id, $percentage)
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
     * Generar código de descuento para el partner
     * 
     * @param string $code Código existente
     * @param int $user_id
     * @return string
     */
    public function generate_discount_code(string $code, int $user_id): string {
        if (!$this->is_enabled_for_user($user_id)) {
            return $code;
        }
        
        $config = $this->get_config_for_user($user_id);
        $percentage = floatval($config['percentage'] ?? 0);
        
        return $this->get_or_create_discount_code($user_id, $percentage);
    }
    
    /**
     * Obtener o crear código de descuento para el usuario
     * 
     * @param int $user_id
     * @param float $percentage
     * @return string
     */
    private function get_or_create_discount_code(int $user_id, float $percentage): string {
        // Generar código único basado en user_id y porcentaje
        $code = 'LICORERA-' . strtoupper(substr(md5($user_id . '-' . $percentage), 0, 8));
        return $code;
    }
}

/**
 * Handler para Club Casa Kush
 */
class Starter_Partner_Club_Casa_Kush_Handler extends Starter_Benefit_Handler_Base {
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->key = 'partner_club_casa_kush';
        $this->name = 'Club Casa Kush';
        $this->description = 'Precio de entrada a Club Casa Kush';
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
        // Hook para obtener precio de entrada
        add_filter('starter_club_casa_kush_entry_price', [$this, 'get_entry_price'], 10, 2);
    }
    
    /**
     * {@inheritdoc}
     */
    public function apply(int $user_id, array $context = []) {
        $config = $this->get_config_for_user($user_id);
        
        if (!$config) {
            return null;
        }
        
        $price = floatval($config['price'] ?? 0);
        $regular_price = $context['regular_price'] ?? 25000; // Precio regular por defecto
        
        return [
            'entry_price' => $price,
            'regular_price' => $regular_price,
            'savings' => max(0, $regular_price - $price),
            'is_free' => $price <= 0
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
        
        $price = floatval($config['price'] ?? 0);
        
        if ($price <= 0) {
            return __('Entrada gratis', 'starter-memberships');
        }
        
        if (function_exists('starter_format_cop')) {
            return starter_format_cop($price) . ' ' . __('entrada', 'starter-memberships');
        }
        
        return '$' . number_format($price, 0, ',', '.') . ' ' . __('entrada', 'starter-memberships');
    }
    
    /**
     * Obtener precio de entrada para el usuario
     * 
     * @param float $price Precio por defecto
     * @param int $user_id
     * @return float
     */
    public function get_entry_price(float $price, int $user_id): float {
        if (!$this->is_enabled_for_user($user_id)) {
            return $price;
        }
        
        $config = $this->get_config_for_user($user_id);
        $member_price = floatval($config['price'] ?? $price);
        
        $this->log("Precio Club Casa Kush", [
            'user_id' => $user_id,
            'regular' => $price,
            'member' => $member_price
        ]);
        
        return $member_price;
    }
}

// Registrar los handlers inmediatamente
// (las clases core se cargan antes que los handlers en benefits/index.php)
starter_benefit_registry()->register(new Starter_Partner_Discount_Licorera_Handler());
starter_benefit_registry()->register(new Starter_Partner_Club_Casa_Kush_Handler());
