<?php
/**
 * Página de administración de beneficios por nivel
 * 
 * Renderiza la interfaz de usuario para configurar beneficios.
 * 
 * @package Starter_Memberships
 * @subpackage Admin/Benefits
 * @since 1.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Starter_Benefits_Page {
    
    /**
     * Renderizar página de beneficios
     */
    public static function render() {
        // Procesar guardado
        if (isset($_POST['starter_save_benefits']) && check_admin_referer('starter_benefits_nonce')) {
            $level = isset($_POST['benefit_level']) ? intval($_POST['benefit_level']) : 0;
            $benefits = isset($_POST['benefits']) ? $_POST['benefits'] : [];
            Starter_Benefits_Service::save_benefits_from_post($level, $benefits);
            echo '<div class="notice notice-success is-dismissible"><p>Beneficios guardados correctamente.</p></div>';
        }
        
        // Mostrar mensaje de restauración
        if (isset($_GET['restored'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Beneficios restaurados a valores predeterminados.</p></div>';
        }
        
        $levels = Starter_Memberships::get_all_membership_levels();
        $benefit_types = Starter_Benefits_Config::get_benefit_types();
        $benefit_categories = Starter_Benefits_Config::get_benefit_categories();
        $current_level = isset($_GET['level']) ? intval($_GET['level']) : 1;
        
        // Obtener categorías de productos
        $categories = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false
        ]);
        
        ?>
        <div class="wrap">
            <h1>🎁 <?php _e('Configuración de Beneficios', 'starter-memberships'); ?></h1>
            <p class="description">
                <?php _e('Configura los beneficios que reciben los usuarios según su nivel de membresía.', 'starter-memberships'); ?>
            </p>
            
            <?php self::render_level_tabs($levels, $current_level); ?>
            
            <?php 
            $current_level_info = $levels[$current_level];
            $current_benefits = Starter_Benefits_Service::get_level_benefits($current_level);
            ?>
            
            <div class="card" style="max-width: 100%; padding: 20px; border-left: 4px solid <?php echo esc_attr($current_level_info['color']); ?>;">
                <h2 style="margin-top: 0;">
                    <?php echo esc_html($current_level_info['icon'] . ' ' . $current_level_info['name']); ?>
                    <span style="font-weight: normal; font-size: 14px; color: #666;">
                        - <?php echo esc_html($current_level_info['description']); ?>
                    </span>
                </h2>
                
                <form method="post" action="">
                    <?php wp_nonce_field('starter_benefits_nonce'); ?>
                    <input type="hidden" name="benefit_level" value="<?php echo esc_attr($current_level); ?>">
                    
                    <?php self::render_benefits_by_category($benefit_types, $benefit_categories, $current_benefits, $categories, $current_level); ?>
                    
                    <p class="submit">
                        <input type="submit" name="starter_save_benefits" class="button button-primary" 
                               value="<?php _e('Guardar Beneficios', 'starter-memberships'); ?>">
                        <a href="<?php echo admin_url('admin.php?page=starter-memberships-benefits&level=' . $current_level . '&reset=1'); ?>" 
                           class="button"
                           onclick="return confirm('¿Restaurar beneficios predeterminados para este nivel?');">
                            <?php _e('Restaurar Predeterminados', 'starter-memberships'); ?>
                        </a>
                    </p>
                </form>
            </div>
            
            <?php self::render_preview_and_comparison($levels, $current_level, $current_level_info, $benefit_types); ?>
        </div>
        
        <?php self::render_scripts(); ?>
        <?php
    }
    
    /**
     * Renderizar tabs de niveles
     */
    private static function render_level_tabs($levels, $current_level) {
        ?>
        <nav class="nav-tab-wrapper" style="margin-bottom: 20px;">
            <?php foreach ($levels as $level_id => $level) : ?>
                <a href="<?php echo admin_url('admin.php?page=starter-memberships-benefits&level=' . $level_id); ?>" 
                   class="nav-tab <?php echo $current_level === $level_id ? 'nav-tab-active' : ''; ?>"
                   style="<?php echo $current_level === $level_id ? 'border-bottom-color: ' . $level['color'] . ';' : ''; ?>">
                    <?php echo esc_html($level['icon'] . ' ' . $level['name']); ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php
    }
    
    /**
     * Renderizar beneficios agrupados por categoría
     */
    private static function render_benefits_by_category($benefit_types, $benefit_categories, $current_benefits, $categories, $current_level) {
        // Agrupar beneficios por categoría
        $grouped = [];
        foreach ($benefit_types as $key => $type) {
            $cat = $type['category'] ?? 'general';
            if (!isset($grouped[$cat])) {
                $grouped[$cat] = [];
            }
            $grouped[$cat][$key] = $type;
        }
        
        foreach ($grouped as $cat_key => $benefits) :
            $cat_info = $benefit_categories[$cat_key] ?? ['name' => ucfirst($cat_key), 'icon' => '📦'];
        ?>
            <div class="benefit-category" style="margin-bottom: 30px;">
                <h3 style="border-bottom: 2px solid #ddd; padding-bottom: 10px;">
                    <?php echo esc_html($cat_info['icon'] . ' ' . $cat_info['name']); ?>
                </h3>
                
                <table class="form-table">
                    <?php foreach ($benefits as $key => $type) : 
                        $config = $current_benefits[$key] ?? ['enabled' => false];
                    ?>
                        <tr>
                            <th scope="row" style="width: 250px;">
                                <label>
                                    <input type="checkbox" 
                                           name="benefits[<?php echo esc_attr($key); ?>][enabled]" 
                                           value="1"
                                           <?php checked(!empty($config['enabled'])); ?>
                                           class="benefit-toggle"
                                           data-target="<?php echo esc_attr($key); ?>">
                                    <?php echo esc_html($type['icon'] . ' ' . $type['name']); ?>
                                </label>
                            </th>
                            <td>
                                <?php if ($type['type'] === 'variable' && isset($type['fields'])) : ?>
                                    <div class="benefit-fields" id="fields-<?php echo esc_attr($key); ?>" 
                                         style="<?php echo empty($config['enabled']) ? 'opacity: 0.5;' : ''; ?>">
                                        <?php self::render_benefit_fields($key, $type['fields'], $config, $categories, $current_level); ?>
                                    </div>
                                <?php elseif ($type['type'] === 'readonly') : ?>
                                    <div class="benefit-readonly" style="<?php echo empty($config['enabled']) ? 'opacity: 0.5;' : ''; ?>">
                                        <?php echo self::render_readonly_value($current_level, $type); ?>
                                    </div>
                                <?php endif; ?>
                                <p class="description"><?php echo esc_html($type['description']); ?></p>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endforeach;
    }
    
    /**
     * Renderizar valor de solo lectura (viene del producto o configuración externa)
     */
    private static function render_readonly_value($level, $type) {
        // Verificar si es configuración externa (como referral_bonus)
        if (!empty($type['external_config'])) {
            $external_url = $type['external_config_url'] ?? '';
            $rp_settings = get_option('starter_rp_settings', []);
            
            // Obtener valores de comisión por membresía
            $membership_commissions = $rp_settings['membership_commissions'] ?? [];
            $level_config = $membership_commissions[$level] ?? [];
            
            // Campos actuales: first_commission, subsequent_commission, level2_commission
            // Solo mostramos N1 (compras siguientes) y N2 (indirectos)
            $first_commission = floatval($level_config['first_commission'] ?? 0);
            $subsequent_commission = floatval($level_config['subsequent_commission'] ?? 0);
            $level2_commission = floatval($level_config['level2_commission'] ?? 0);
            
            $value_text = '';
            if ($subsequent_commission > 0 || $level2_commission > 0) {
                // Formato: "1% (N1) / 0.2% (N2)" con primera compra entre paréntesis
                $parts = [];
                if ($subsequent_commission > 0) {
                    $parts[] = sprintf('%s%% (N1)', $subsequent_commission);
                }
                if ($level2_commission > 0) {
                    $parts[] = sprintf('%s%% (N2)', $level2_commission);
                }
                $value_text = implode(' / ', $parts);
                // Agregar primera compra como referencia
                if ($first_commission > 0) {
                    $value_text .= sprintf(' — Primera compra: %s%%', $first_commission);
                }
            } else {
                $value_text = 'Sin comisiones configuradas';
            }
            
            return sprintf(
                '<strong style="font-size: 14px; color: #2271b1;">%s</strong> 
                 <a href="%s" class="button button-small" style="margin-left: 10px;">Configurar en Virtual Coins</a>',
                esc_html($value_text),
                esc_url(admin_url($external_url))
            );
        }
        
        // Obtener producto de membresía para este nivel
        $product = function_exists('starter_get_membership_product_by_level') 
            ? starter_get_membership_product_by_level($level) 
            : null;
        
        if (!$product) {
            return '<span style="color: #999;"><em>Sin producto configurado</em></span>';
        }
        
        $product_id = $product->get_id();
        $meta_key = $type['product_meta'] ?? '';
        $format = $type['format'] ?? 'text';
        $value = get_post_meta($product_id, $meta_key, true);
        
        if ($value === '' || $value === null) {
            return '<span style="color: #999;"><em>No configurado en producto</em></span>';
        }
        
        // Formatear según tipo
        switch ($format) {
            case 'fc':
                $formatted = starter_format_fc(intval($value));
                break;
            case 'days':
                $days = intval($value);
                $labels = [
                    '0' => 'Infinita',
                    '30' => '1 mes (30 días)',
                    '60' => '2 meses (60 días)',
                    '90' => '3 meses (90 días)',
                    '180' => '6 meses (180 días)',
                    '365' => '1 año (365 días)',
                ];
                $formatted = $labels[$days] ?? $days . ' días';
                break;
            default:
                $formatted = esc_html($value);
        }
        
        $product_link = get_edit_post_link($product_id);
        
        return sprintf(
            '<strong style="font-size: 16px; color: #2271b1;">%s</strong> 
             <a href="%s" class="button button-small" style="margin-left: 10px;">Editar en producto</a>',
            $formatted,
            esc_url($product_link)
        );
    }
    
    /**
     * Verificar si el segundo nivel de referidos está habilitado
     */
    private static function is_second_level_referrals_enabled() {
        $rp_options = get_option('starter_rp_settings', []);
        return !empty($rp_options['enable_second_level']);
    }
    
    /**
     * Obtener valores por defecto del plugin de referidos
     */
    private static function get_referrals_defaults() {
        $rp_options = get_option('starter_rp_settings', []);
        return [
            'commission_first' => $rp_options['referral_commission_first'] ?? 10,
            'commission_subsequent' => $rp_options['referral_commission_subsequent'] ?? 5,
            'second_level_commission' => $rp_options['second_level_commission'] ?? 2,
        ];
    }
    
    /**
     * Renderizar campos de un beneficio
     */
    private static function render_benefit_fields($benefit_key, $fields, $config, $categories, $current_level = 0) {
        foreach ($fields as $field_key => $field) :
            // Verificar condición para campos condicionales
            if (isset($field['conditional']) && $field['conditional'] === 'second_level_enabled') {
                if (!self::is_second_level_referrals_enabled()) {
                    continue; // Saltar este campo si el segundo nivel no está habilitado
                }
            }
            ?>
            <p style="margin: 5px 0;">
                <label><?php echo esc_html($field['label']); ?>:</label>
                <?php
                switch ($field['type']) :
                    case 'number':
                        ?>
                        <input type="number" 
                               name="benefits[<?php echo esc_attr($benefit_key); ?>][<?php echo esc_attr($field_key); ?>]"
                               value="<?php echo esc_attr($config[$field_key] ?? ''); ?>"
                               min="<?php echo esc_attr($field['min'] ?? 0); ?>"
                               max="<?php echo esc_attr($field['max'] ?? ''); ?>"
                               step="<?php echo esc_attr($field['step'] ?? 1); ?>"
                               class="small-text">
                        <?php if (isset($field['suffix'])) : ?>
                            <span><?php echo esc_html($field['suffix']); ?></span>
                        <?php endif; ?>
                        <?php
                        break;
                        
                    case 'select':
                        ?>
                        <select name="benefits[<?php echo esc_attr($benefit_key); ?>][<?php echo esc_attr($field_key); ?>]">
                            <?php foreach ($field['options'] as $opt_value => $opt_label) : ?>
                                <option value="<?php echo esc_attr($opt_value); ?>"
                                        <?php selected($config[$field_key] ?? '', $opt_value); ?>>
                                    <?php echo esc_html($opt_label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php
                        break;
                        
                    case 'checkbox':
                        $is_disabled = !empty($field['disabled']);
                        ?>
                        <input type="checkbox" 
                               name="benefits[<?php echo esc_attr($benefit_key); ?>][<?php echo esc_attr($field_key); ?>]"
                               value="1"
                               <?php checked(!empty($config[$field_key])); ?>
                               <?php echo $is_disabled ? 'disabled="disabled"' : ''; ?>>
                        <?php
                        break;
                    
                    case 'text':
                        ?>
                        <input type="text" 
                               name="benefits[<?php echo esc_attr($benefit_key); ?>][<?php echo esc_attr($field_key); ?>]"
                               value="<?php echo esc_attr($config[$field_key] ?? ''); ?>"
                               placeholder="<?php echo esc_attr($field['placeholder'] ?? ''); ?>"
                               class="regular-text">
                        <?php
                        break;
                        
                    case 'categories':
                        // Filtrar categorías por nivel de membresía (solo las accesibles para este nivel e inferiores)
                        $filtered_categories = self::get_categories_for_level($categories, $current_level);
                        $selected_cats = $config[$field_key] ?? [];
                        ?>
                        <br>
                        <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff; border-radius: 4px;">
                            <label style="display: block; margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid #eee;">
                                <input type="checkbox" 
                                       class="category-select-all" 
                                       data-target="<?php echo esc_attr($benefit_key . '_' . $field_key); ?>"
                                       <?php checked(empty($selected_cats)); ?>>
                                <strong><?php _e('Todas las categorías disponibles', 'starter-memberships'); ?></strong>
                            </label>
                            <?php if (empty($filtered_categories)) : ?>
                                <p style="color: #999; margin: 0;"><em><?php _e('No hay categorías exclusivas para este nivel', 'starter-memberships'); ?></em></p>
                            <?php else : ?>
                                <?php foreach ($filtered_categories as $cat) : 
                                    $cat_level = get_term_meta($cat->term_id, '_min_membership_level', true);
                                    $cat_level = $cat_level !== '' ? intval($cat_level) : 0;
                                    $level_info = Starter_Memberships::get_membership_level($cat_level);
                                ?>
                                    <label style="display: block; margin: 4px 0; padding: 4px; border-radius: 3px; <?php echo in_array($cat->term_id, $selected_cats) ? 'background: #e7f3ff;' : ''; ?>">
                                        <input type="checkbox" 
                                               name="benefits[<?php echo esc_attr($benefit_key); ?>][<?php echo esc_attr($field_key); ?>][]"
                                               value="<?php echo esc_attr($cat->term_id); ?>"
                                               class="category-checkbox-<?php echo esc_attr($benefit_key . '_' . $field_key); ?>"
                                               <?php checked(in_array($cat->term_id, $selected_cats)); ?>>
                                        <?php echo esc_html($cat->name); ?>
                                        <?php if ($cat_level > 0) : ?>
                                            <span style="color: <?php echo esc_attr($level_info['color']); ?>; font-size: 11px;">
                                                (<?php echo esc_html($level_info['icon']); ?>)
                                            </span>
                                        <?php endif; ?>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <span class="description"><?php _e('Solo categorías accesibles para este nivel de membresía', 'starter-memberships'); ?></span>
                        <?php
                        break;
                endswitch;
                ?>
            </p>
            <?php
        endforeach;
    }
    
    /**
     * Obtener categorías filtradas por nivel de membresía
     * Solo muestra categorías accesibles para este nivel e inferiores
     */
    private static function get_categories_for_level($categories, $level) {
        $filtered = [];
        
        foreach ($categories as $cat) {
            $cat_level = get_term_meta($cat->term_id, '_min_membership_level', true);
            $cat_level = $cat_level !== '' ? intval($cat_level) : 0;
            
            // Incluir si el nivel de la categoría es menor o igual al nivel actual
            if ($cat_level <= $level) {
                $filtered[] = $cat;
            }
        }
        
        return $filtered;
    }
    
    /**
     * Renderizar vista previa y comparativa
     */
    private static function render_preview_and_comparison($levels, $current_level, $current_level_info, $benefit_types) {
        ?>
        <style>
            .starter-benefits-grid { display: grid; grid-template-columns: 1fr 2fr; gap: 20px; margin-top: 20px; }
            .starter-benefits-grid .card { max-width: none !important; width: 100% !important; margin: 0 !important; padding: 20px; }
            @media (max-width: 1200px) { .starter-benefits-grid { grid-template-columns: 1fr; } }
        </style>
        <div class="starter-benefits-grid">
            <!-- Vista previa -->
            <div class="card">
                <h3 style="margin-top: 0;">👁️ <?php _e('Vista Previa para el Usuario', 'starter-memberships'); ?></h3>
                <div style="background: #f9f9f9; padding: 15px; border-radius: 8px;">
                    <?php 
                    $formatted = Starter_Benefits_Service::format_benefits_for_display($current_level);
                    if (empty($formatted)) :
                    ?>
                        <p><em><?php _e('No hay beneficios activos para este nivel.', 'starter-memberships'); ?></em></p>
                    <?php else : ?>
                        <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 8px;">
                            <?php foreach ($formatted as $benefit) : ?>
                                <li style="background: #fff; padding: 10px; border-radius: 5px; border-left: 3px solid <?php echo esc_attr($current_level_info['color']); ?>;">
                                    <strong><?php echo esc_html($benefit['icon'] . ' ' . $benefit['name']); ?></strong>
                                    <?php if (isset($benefit['value']) && $benefit['value']) : ?>
                                        <br><span style="color: <?php echo esc_attr($current_level_info['color']); ?>; font-weight: bold; font-size: 13px;">
                                            <?php echo esc_html($benefit['value']); ?>
                                        </span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Comparativa -->
            <div class="card">
                <h3 style="margin-top: 0;">📊 <?php _e('Comparativa de Niveles', 'starter-memberships'); ?></h3>
                <div style="overflow-x: auto;">
                    <?php self::render_comparison_table($levels, $benefit_types); ?>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Renderizar tabla comparativa
     */
    private static function render_comparison_table($levels, $benefit_types) {
        ?>
        <table class="widefat striped" style="font-size: 12px;">
            <thead>
                <tr>
                    <th><?php _e('Beneficio', 'starter-memberships'); ?></th>
                    <?php foreach ($levels as $level_id => $level) : ?>
                        <th style="text-align: center; background: <?php echo esc_attr($level['color']); ?>20; min-width: 80px;">
                            <?php echo esc_html($level['icon']); ?><br>
                            <small><?php echo esc_html($level['name']); ?></small>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($benefit_types as $key => $type) : ?>
                    <tr>
                        <td><strong><?php echo esc_html($type['icon'] . ' ' . $type['name']); ?></strong></td>
                        <?php foreach ($levels as $level_id => $level) : 
                            $level_benefits = Starter_Benefits_Service::get_level_benefits($level_id);
                            $config = $level_benefits[$key] ?? ['enabled' => false];
                        ?>
                            <td style="text-align: center;">
                                <?php if (empty($config['enabled'])) : ?>
                                    <span style="color: #ccc;">—</span>
                                <?php elseif ($type['type'] === 'fixed') : ?>
                                    <span style="color: #00a32a;">✓</span>
                                <?php elseif ($type['type'] === 'readonly') : ?>
                                    <?php echo self::get_readonly_compact_value($level_id, $type); ?>
                                <?php else : ?>
                                    <?php echo esc_html(self::get_compact_value($key, $config)); ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
    
    /**
     * Obtener valor compacto de readonly para tabla comparativa
     */
    private static function get_readonly_compact_value($level, $type) {
        // Manejar configuración externa (como referral_bonus)
        if (!empty($type['external_config'])) {
            $rp_settings = get_option('starter_rp_settings', []);
            $membership_commissions = $rp_settings['membership_commissions'] ?? [];
            $level_config = $membership_commissions[$level] ?? [];
            
            // Solo mostramos N1 (compras siguientes) y N2 (indirectos)
            $subsequent = floatval($level_config['subsequent_commission'] ?? 0);
            $level2 = floatval($level_config['level2_commission'] ?? 0);
            
            if ($subsequent > 0 || $level2 > 0) {
                $parts = [];
                if ($subsequent > 0) $parts[] = $subsequent . '%';
                if ($level2 > 0) $parts[] = $level2 . '%';
                return '<strong>' . implode('/', $parts) . '</strong>';
            }
            return '<span style="color: #999;">—</span>';
        }
        
        $product = function_exists('starter_get_membership_product_by_level') 
            ? starter_get_membership_product_by_level($level) 
            : null;
        
        if (!$product) {
            return '<span style="color: #999;">—</span>';
        }
        
        $value = get_post_meta($product->get_id(), $type['product_meta'] ?? '', true);
        
        if ($value === '' || $value === null) {
            return '<span style="color: #999;">—</span>';
        }
        
        switch ($type['format'] ?? 'text') {
            case 'fc':
                return '<strong>' . number_format(intval($value) / 1000) . 'k</strong>';
            case 'days':
                $days = intval($value);
                if ($days == 0) return 'Infinita';
                if ($days == 30) return '1m';
                if ($days == 60) return '2m';
                if ($days == 90) return '3m';
                if ($days == 180) return '6m';
                if ($days == 365) return '1a';
                return $days . 'd';
            default:
                return esc_html($value);
        }
    }
    
    /**
     * Obtener valor compacto para tabla comparativa
     */
    private static function get_compact_value($key, $config) {
        switch ($key) {
            case 'category_discount':
            case 'partner_discount_licorera':
            case 'events_discount':
                return ($config['percentage'] ?? 0) . '%';
            case 'referral_bonus':
                $val = '+' . ($config['percentage'] ?? 0) . '%';
                if (!empty($config['percentage_level2'])) {
                    $val .= '/+' . $config['percentage_level2'] . '%';
                }
                return $val;
            case 'partner_club_casa_kush':
                return '$' . number_format(($config['price'] ?? 0) / 1000) . 'k';
            case 'free_deliveries':
                return ($config['quantity'] ?? 0) . 'x';
            case 'free_samples':
                $total = $config['total_grams'] ?? $config['grams'] ?? 0;
                $per_delivery = $config['grams_per_delivery'] ?? 0;
                $every = $config['every_orders'] ?? 1;
                if ($per_delivery > 0 && $every > 0) {
                    return $total . 'g (' . $per_delivery . 'g/' . $every . 'p)';
                }
                return $total . 'g';
            case 'delivery_options':
                $opts = [];
                if (!empty($config['home_delivery'])) $opts[] = 'D';
                if (!empty($config['pickup'])) $opts[] = 'R';
                return implode('+', $opts) ?: '—';
            case 'security_benefits':
                $opts = [];
                if (!empty($config['safe_space'])) $opts[] = 'ES';
                if (!empty($config['legal_advice'])) $opts[] = 'AJ';
                return implode('+', $opts) ?: '—';
            case 'referral_membership_bonus':
                $level = intval($config['membership_level'] ?? 2);
                $days = intval($config['duration_days'] ?? 30);
                $level_info = Starter_Memberships::get_membership_level($level);
                $icon = $level_info['icon'] ?? '🥕';
                return $icon . '/' . $days . 'd';
            default:
                return '✓';
        }
    }
    
    /**
     * Renderizar scripts
     */
    private static function render_scripts() {
        ?>
        <script>
        jQuery(function($) {
            // Toggle campos de beneficios
            $('.benefit-toggle').on('change', function() {
                var target = $(this).data('target');
                var $fields = $('#fields-' + target);
                
                if ($(this).is(':checked')) {
                    $fields.css('opacity', '1');
                } else {
                    $fields.css('opacity', '0.5');
                }
            });
            
            // Manejar "Todas las categorías"
            $('.category-select-all').on('change', function() {
                var target = $(this).data('target');
                var $checkboxes = $('.category-checkbox-' + target);
                
                if ($(this).is(':checked')) {
                    // Desmarcar todas las categorías individuales
                    $checkboxes.prop('checked', false);
                } else {
                    // Si se desmarca "todas", no hacer nada especial
                }
            });
            
            // Cuando se marca una categoría individual, desmarcar "Todas"
            $('[class^="category-checkbox-"]').on('change', function() {
                var classes = $(this).attr('class').split(' ');
                var targetClass = classes.find(c => c.startsWith('category-checkbox-'));
                if (targetClass) {
                    var target = targetClass.replace('category-checkbox-', '');
                    var $selectAll = $('.category-select-all[data-target="' + target + '"]');
                    
                    if ($(this).is(':checked')) {
                        $selectAll.prop('checked', false);
                    }
                }
            });
        });
        </script>
        <?php
    }
    
    /**
     * Manejar solicitud de restaurar predeterminados
     */
    public static function handle_reset_request() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'starter-memberships-benefits' || !isset($_GET['reset'])) {
            return;
        }
        
        $level = isset($_GET['level']) ? intval($_GET['level']) : 0;
        $defaults = Starter_Benefits_Config::get_default_level_benefits();
        
        if (isset($defaults[$level])) {
            Starter_Benefits_Service::save_level_benefits($level, $defaults[$level]);
        }
        
        wp_redirect(admin_url('admin.php?page=starter-memberships-benefits&level=' . $level . '&restored=1'));
        exit;
    }
}
