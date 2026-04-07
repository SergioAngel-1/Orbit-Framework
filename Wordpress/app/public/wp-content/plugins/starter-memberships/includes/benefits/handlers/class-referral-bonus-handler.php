<?php
/**
 * Handler: Bonus en Referidos
 * 
 * Incrementa el porcentaje de comisión en el sistema de referidos
 * según el nivel de membresía del usuario.
 * 
 * Configuración esperada:
 * - percentage: Porcentaje adicional para nivel 1 de referidos
 * - percentage_level2: Porcentaje adicional para nivel 2 de referidos
 * 
 * @package Starter_Memberships
 * @subpackage Benefits/Handlers
 * @since 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Starter_Referral_Bonus_Handler extends Starter_Benefit_Handler_Base {
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->key = 'referral_bonus';
        $this->name = 'Bonus en Referidos';
        $this->description = 'Porcentaje adicional en comisiones del sistema de referidos';
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
        // Hook para modificar la comisión de referidos (nivel 1)
        add_filter('starter_rp_referral_commission_first', [$this, 'apply_bonus_level1'], 10, 2);
        add_filter('starter_rp_referral_commission_subsequent', [$this, 'apply_bonus_level1'], 10, 2);
        
        // Hook para modificar la comisión de referidos (nivel 2)
        add_filter('starter_rp_referral_commission_level2', [$this, 'apply_bonus_level2'], 10, 2);
    }
    
    /**
     * {@inheritdoc}
     */
    public function apply(int $user_id, array $context = []) {
        $config = $this->get_config_for_user($user_id);
        
        if (!$config) {
            return null;
        }
        
        $level = $context['referral_level'] ?? 1;
        $base_commission = $context['base_commission'] ?? 0;
        
        if ($level === 1) {
            $bonus = floatval($config['percentage'] ?? 0);
        } else {
            $bonus = floatval($config['percentage_level2'] ?? 0);
        }
        
        return [
            'base_commission' => $base_commission,
            'bonus_percentage' => $bonus,
            'total_commission' => $base_commission + $bonus
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
        
        // Usar los campos correctos de la configuración del plugin de referidos
        // Solo mostramos N1 (compras siguientes) y N2 (indirectos)
        $subsequent = $config['subsequent_commission'] ?? 0;
        $level2 = $config['level2_commission'] ?? 0;
        
        if ($subsequent <= 0 && $level2 <= 0) {
            return '';
        }
        
        // Formato: "1% (N1) / 0.2% (N2)"
        $parts = [];
        
        if ($subsequent > 0) {
            $parts[] = $subsequent . '% (N1)';
        }
        
        if ($level2 > 0) {
            $parts[] = $level2 . '% (N2)';
        }
        
        return implode(' / ', $parts);
    }
    
    /**
     * Sobrescribimos para leer la configuración desde el plugin de referidos
     * Las comisiones ahora se configuran directamente por nivel de membresía
     */
    public function get_config_for_user(int $user_id): ?array {
        $cache_key = $this->key . '_' . $user_id;
        
        // Verificar caché
        if (isset(self::$config_cache[$cache_key])) {
            return self::$config_cache[$cache_key];
        }
        
        // Obtener nivel del usuario
        if (!function_exists('starter_get_user_membership_level')) {
            self::$config_cache[$cache_key] = null;
            return null;
        }
        
        $level = starter_get_user_membership_level($user_id);
        
        // Obtener configuración desde el plugin de referidos
        $rp_settings = get_option('starter_rp_settings', []);
        $membership_commissions = $rp_settings['membership_commissions'] ?? [];
        
        // Obtener configuración para este nivel (todos los niveles tienen configuración)
        $level_config = $membership_commissions[$level] ?? [];
        
        // Valores por defecto según nivel (fallbacks)
        // Los valores reales se configuran en: Virtual Coins → Configuración → Comisiones por Membresía
        $defaults = [
            0 => ['first' => 0, 'subsequent' => 0, 'level2' => 0],
            1 => ['first' => 3, 'subsequent' => 1, 'level2' => 0.2],
            2 => ['first' => 4, 'subsequent' => 2, 'level2' => 0.5],
            3 => ['first' => 6, 'subsequent' => 3, 'level2' => 1],
            4 => ['first' => 8, 'subsequent' => 4, 'level2' => 1.5],
            5 => ['first' => 10, 'subsequent' => 5, 'level2' => 2],
        ];
        
        $default = $defaults[$level] ?? $defaults[0];
        
        $config = [
            'enabled' => true,
            'first_commission' => floatval($level_config['first_commission'] ?? $default['first']),
            'subsequent_commission' => floatval($level_config['subsequent_commission'] ?? $default['subsequent']),
            'level2_commission' => floatval($level_config['level2_commission'] ?? $default['level2']),
            'level' => $level
        ];
        
        self::$config_cache[$cache_key] = $config;
        return $config;
    }
    
    /**
     * Aplicar bonus a comisión de nivel 1
     * 
     * @param float $commission Comisión base
     * @param int $referrer_id ID del referidor
     * @return float
     */
    public function apply_bonus_level1(float $commission, int $referrer_id): float {
        if (!$this->is_enabled_for_user($referrer_id)) {
            return $commission;
        }
        
        $config = $this->get_config_for_user($referrer_id);
        $bonus = floatval($config['percentage'] ?? 0);
        
        if ($bonus <= 0) {
            return $commission;
        }
        
        $new_commission = $commission + $bonus;
        
        $this->log("Bonus nivel 1 aplicado", [
            'user_id' => $referrer_id,
            'original' => $commission,
            'bonus' => $bonus,
            'final' => $new_commission
        ]);
        
        return $new_commission;
    }
    
    /**
     * Aplicar bonus a comisión de nivel 2
     * 
     * @param float $commission Comisión base
     * @param int $referrer_id ID del referidor
     * @return float
     */
    public function apply_bonus_level2(float $commission, int $referrer_id): float {
        if (!$this->is_enabled_for_user($referrer_id)) {
            return $commission;
        }
        
        $config = $this->get_config_for_user($referrer_id);
        $bonus = floatval($config['percentage_level2'] ?? 0);
        
        if ($bonus <= 0) {
            return $commission;
        }
        
        $new_commission = $commission + $bonus;
        
        $this->log("Bonus nivel 2 aplicado", [
            'user_id' => $referrer_id,
            'original' => $commission,
            'bonus' => $bonus,
            'final' => $new_commission
        ]);
        
        return $new_commission;
    }
}

// Registrar el handler inmediatamente
// (las clases core se cargan antes que los handlers en benefits/index.php)
starter_benefit_registry()->register(new Starter_Referral_Bonus_Handler());
