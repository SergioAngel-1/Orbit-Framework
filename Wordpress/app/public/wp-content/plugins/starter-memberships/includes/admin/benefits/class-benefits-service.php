<?php
/**
 * Servicio de beneficios de membresía
 * 
 * Contiene la lógica de negocio para gestionar beneficios.
 * 
 * @package Starter_Memberships
 * @subpackage Admin/Benefits
 * @since 1.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Starter_Benefits_Service {
    
    /**
     * Obtener beneficios configurados para un nivel
     * 
     * @param int $level
     * @return array
     */
    public static function get_level_benefits($level) {
        $saved_benefits = get_option('starter_membership_benefits', []);
        $defaults = Starter_Benefits_Config::get_default_level_benefits();
        
        if (isset($saved_benefits[$level])) {
            return array_merge($defaults[$level] ?? [], $saved_benefits[$level]);
        }
        
        return $defaults[$level] ?? $defaults[0];
    }
    
    /**
     * Guardar beneficios para un nivel
     * 
     * @param int $level
     * @param array $benefits
     */
    public static function save_level_benefits($level, $benefits) {
        $saved_benefits = get_option('starter_membership_benefits', []);
        $saved_benefits[$level] = $benefits;
        update_option('starter_membership_benefits', $saved_benefits);
    }
    
    /**
     * Verificar si un usuario tiene un beneficio específico
     * 
     * @param int $user_id
     * @param string $benefit_key
     * @return bool
     */
    public static function user_has_benefit($user_id, $benefit_key) {
        $level = starter_get_user_membership_level($user_id);
        $benefits = self::get_level_benefits($level);
        
        return isset($benefits[$benefit_key]) && 
               isset($benefits[$benefit_key]['enabled']) && 
               $benefits[$benefit_key]['enabled'];
    }
    
    /**
     * Obtener valor de un beneficio para un usuario
     * 
     * @param int $user_id
     * @param string $benefit_key
     * @param string|null $field
     * @return mixed
     */
    public static function get_user_benefit_value($user_id, $benefit_key, $field = null) {
        $level = starter_get_user_membership_level($user_id);
        $benefits = self::get_level_benefits($level);
        
        if (!isset($benefits[$benefit_key]) || !$benefits[$benefit_key]['enabled']) {
            return null;
        }
        
        if ($field) {
            return $benefits[$benefit_key][$field] ?? null;
        }
        
        return $benefits[$benefit_key];
    }
    
    /**
     * Obtener todos los beneficios activos de un usuario
     * 
     * @param int $user_id
     * @return array
     */
    public static function get_user_active_benefits($user_id) {
        $level = starter_get_user_membership_level($user_id);
        $benefits = self::get_level_benefits($level);
        $benefit_types = Starter_Benefits_Config::get_benefit_types();
        
        $active = [];
        
        foreach ($benefits as $key => $config) {
            if (isset($config['enabled']) && $config['enabled'] && isset($benefit_types[$key])) {
                $active[$key] = array_merge($benefit_types[$key], $config);
            }
        }
        
        return $active;
    }
    
    /**
     * Formatear beneficios para mostrar al usuario
     * 
     * @param int $level
     * @return array
     */
    public static function format_benefits_for_display($level) {
        // Establecer nivel global para uso en format_benefit_value
        global $current_format_level;
        $current_format_level = $level;
        
        $benefits = self::get_level_benefits($level);
        $benefit_types = Starter_Benefits_Config::get_benefit_types();
        
        $formatted = [];
        
        foreach ($benefits as $key => $config) {
            if (!isset($config['enabled']) || !$config['enabled'] || !isset($benefit_types[$key])) {
                continue;
            }
            
            $type = $benefit_types[$key];
            $display = [
                'key' => $key,
                'name' => $type['name'],
                'icon' => $type['icon'],
                'description' => $type['description'],
                'category' => $type['category'] ?? 'general'
            ];
            
            // Agregar valor formateado para beneficios variables y readonly
            if ($type['type'] === 'variable' || $type['type'] === 'readonly') {
                $value = self::format_benefit_value($key, $config);
                // Si el valor es un array (como category_discount), extraer solo el texto para display
                if (is_array($value) && isset($value['text'])) {
                    $display['value'] = $value['text'];
                    // Agregar categorías si existen
                    if (isset($value['categories'])) {
                        $display['categories'] = $value['categories'];
                    }
                } else if (!empty($value)) {
                    $display['value'] = $value;
                }
            }
            
            $formatted[] = $display;
        }
        
        return $formatted;
    }
    
    /**
     * Formatear valor de un beneficio para mostrar
     * 
     * @param string $key
     * @param array $config
     * @return string|array
     */
    private static function format_benefit_value($key, $config) {
        switch ($key) {
            case 'category_discount':
                $percentage = ($config['percentage'] ?? 0);
                $categories = $config['categories'] ?? [];
                
                // Si hay categorías específicas, obtener sus nombres
                if (!empty($categories)) {
                    $category_names = [];
                    foreach ($categories as $cat_id) {
                        $term = get_term($cat_id, 'product_cat');
                        if ($term && !is_wp_error($term)) {
                            $category_names[] = $term->name;
                        }
                    }
                    
                    if (!empty($category_names)) {
                        return [
                            'text' => $percentage . '% de descuento',
                            'categories' => $category_names
                        ];
                    }
                }
                
                // Si no hay categorías específicas, aplica a todas
                return [
                    'text' => $percentage . '% de descuento',
                    'categories' => ['Todas las categorías']
                ];
                
            case 'referral_bonus':
                // Obtener datos reales del plugin de referidos
                $rp_settings = get_option('starter_rp_settings', []);
                $membership_commissions = $rp_settings['membership_commissions'] ?? [];
                
                // Necesitamos el nivel para obtener la configuración correcta
                // El nivel viene en el contexto de la llamada a format_benefits_for_display
                global $current_format_level;
                $level = $current_format_level ?? 0;
                $level_config = $membership_commissions[$level] ?? [];
                
                // Solo mostramos N1 (compras siguientes) y N2 (indirectos)
                $subsequent = floatval($level_config['subsequent_commission'] ?? 0);
                $level2 = floatval($level_config['level2_commission'] ?? 0);
                
                if ($subsequent > 0 || $level2 > 0) {
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
                
                // Fallback a config local si no hay datos del plugin
                $text = ($config['percentage'] ?? 0) . '% (N1)';
                if (!empty($config['percentage_level2'])) {
                    $text .= ' / ' . $config['percentage_level2'] . '% (N2)';
                }
                return $text;
                
            case 'partner_discount_licorera':
                return ($config['percentage'] ?? 0) . '% de descuento';
                
            case 'events_discount':
                $percentage = ($config['percentage'] ?? 0);
                $categories = $config['categories'] ?? [];
                
                // Si hay categorías específicas, obtener sus nombres
                if (!empty($categories)) {
                    $category_names = [];
                    foreach ($categories as $cat_id) {
                        $term = get_term($cat_id, 'product_cat');
                        if ($term && !is_wp_error($term)) {
                            $category_names[] = $term->name;
                        }
                    }
                    
                    if (!empty($category_names)) {
                        return [
                            'text' => $percentage . '% de descuento',
                            'categories' => $category_names
                        ];
                    }
                }
                
                return $percentage . '% de descuento';
                
            case 'partner_club_casa_kush':
                $price = $config['price'] ?? 0;
                return $price == 0 ? 'Entrada gratis' : starter_format_cop($price) . ' entrada';
                
            case 'delivery_options':
                $options = [];
                if (!empty($config['home_delivery'])) $options[] = 'Domicilio';
                if (!empty($config['pickup'])) $options[] = 'Recoger en sede';
                return implode(' + ', $options) ?: 'Sin opciones';
                
            case 'free_deliveries':
                $qty = $config['quantity'] ?? 0;
                return $qty . ' entrega' . ($qty != 1 ? 's' : '') . ' gratis';
                
            case 'free_samples':
                $total = $config['total_grams'] ?? 0;
                $per_delivery = $config['grams_per_delivery'] ?? 0;
                $every = $config['every_orders'] ?? 1;
                
                if ($total > 0 && $per_delivery > 0 && $every > 0) {
                    return $total . 'g gratis (' . $per_delivery . 'g cada ' . $every . ' pedidos)';
                }
                return $total . 'g gratis';
                
            case 'security_benefits':
                $options = [];
                if (!empty($config['safe_space'])) $options[] = 'Espacio seguro';
                if (!empty($config['legal_advice'])) $options[] = 'Asesoría jurídica';
                return implode(' + ', $options) ?: 'Sin opciones';
                
            case 'referral_membership_bonus':
                $level = intval($config['membership_level'] ?? 2);
                $days = intval($config['duration_days'] ?? 30);
                $level_info = Starter_Memberships::get_membership_level($level);
                $level_name = $level_info['name'] ?? "Nivel $level";
                return $level_name . ' por ' . $days . ' días';
                
            default:
                return '';
        }
    }
    
    /**
     * Procesar guardado de beneficios desde POST
     * 
     * @param int $level
     * @param array $benefits_post
     */
    public static function save_benefits_from_post($level, $benefits_post) {
        $benefit_types = Starter_Benefits_Config::get_benefit_types();
        $sanitized = [];
        
        foreach ($benefit_types as $key => $type) {
            $config = $benefits_post[$key] ?? [];
            
            $sanitized[$key] = [
                'enabled' => !empty($config['enabled'])
            ];
            
            // Procesar campos adicionales para beneficios variables
            if ($type['type'] === 'variable' && isset($type['fields'])) {
                foreach ($type['fields'] as $field_key => $field) {
                    switch ($field['type']) {
                        case 'number':
                            $sanitized[$key][$field_key] = isset($config[$field_key]) 
                                ? floatval($config[$field_key]) 
                                : 0;
                            break;
                            
                        case 'select':
                            $sanitized[$key][$field_key] = sanitize_text_field($config[$field_key] ?? '');
                            break;
                            
                        case 'categories':
                            $cats = isset($config[$field_key]) ? $config[$field_key] : [];
                            $sanitized[$key][$field_key] = array_filter(array_map('intval', (array)$cats));
                            break;
                            
                        case 'checkbox':
                            $sanitized[$key][$field_key] = !empty($config[$field_key]);
                            break;
                            
                        case 'text':
                            $sanitized[$key][$field_key] = sanitize_text_field($config[$field_key] ?? '');
                            break;
                    }
                }
            }
        }
        
        self::save_level_benefits($level, $sanitized);
        
        error_log(sprintf(
            'Starter Memberships: Beneficios del nivel %d actualizados',
            $level
        ));
    }
}
