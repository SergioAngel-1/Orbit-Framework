<?php
/**
 * Handler: Membresía Gratis para Referidos
 * 
 * Los usuarios que se registren con el código de referido de este nivel
 * reciben membresía gratis por un tiempo determinado.
 * 
 * Configuración esperada:
 * - membership_level: Nivel de membresía a otorgar (1-4)
 * - duration_days: Duración en días
 * 
 * @package Starter_Memberships
 * @subpackage Benefits/Handlers
 * @since 1.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Starter_Referral_Membership_Bonus_Handler extends Starter_Benefit_Handler_Base {
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->key = 'referral_membership_bonus';
        $this->name = 'Membresía Gratis para Referidos';
        $this->description = 'Los usuarios que se registren con tu código de referido reciben membresía gratis';
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
        // No se necesitan hooks adicionales, la lógica está en referrals-integration.php
    }
    
    /**
     * {@inheritdoc}
     */
    public function apply(int $user_id, array $context = []) {
        $config = $this->get_config_for_user($user_id);
        
        if (!$config) {
            return null;
        }
        
        return [
            'membership_level' => intval($config['membership_level'] ?? 2),
            'duration_days' => intval($config['duration_days'] ?? 30),
            'level_name' => $this->get_level_name(intval($config['membership_level'] ?? 2))
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
        
        $level = intval($config['membership_level'] ?? 2);
        $days = intval($config['duration_days'] ?? 30);
        $level_name = $this->get_level_name($level);
        
        return sprintf('%s por %d días', $level_name, $days);
    }
    
    /**
     * Obtener nombre del nivel de membresía
     * 
     * @param int $level
     * @return string
     */
    private function get_level_name(int $level): string {
        if (class_exists('Starter_Memberships')) {
            $level_info = Starter_Memberships::get_membership_level($level);
            return $level_info['name'] ?? "Nivel $level";
        }
        
        $names = [
            0 => 'Zanahoria',
            1 => 'Bronce',
            2 => 'Plata',
            3 => 'Oro',
            4 => 'Diamante',
            5 => 'Platino'
        ];
        
        return $names[$level] ?? "Nivel $level";
    }
}

// Registrar el handler inmediatamente
// (las clases core se cargan antes que los handlers en benefits/index.php)
starter_benefit_registry()->register(new Starter_Referral_Membership_Bonus_Handler());
