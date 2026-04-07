<?php
/**
 * Banners - Campos para Redes Sociales
 * 
 * Este archivo contiene los campos específicos para banners de tipo "bottom" (redes sociales)
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renderiza los campos para redes sociales (banner tipo "bottom")
 */
function banner_render_social_fields($post, $social_networks) {
    // Obtener niveles de membresía
    $membership_levels = [];
    if (class_exists('Starter_Memberships')) {
        $membership_levels = Starter_Memberships::get_all_membership_levels();
    }
    ?>
    <div id="social_networks_section" style="<?php echo get_post_meta($post->ID, '_banner_type', true) !== 'bottom' ? 'display: none;' : ''; ?>">
        <h3>Redes Sociales</h3>
        <div class="social-networks-container" id="social-networks-container">
            <?php
            if (!empty($social_networks) && is_array($social_networks)) {
                foreach ($social_networks as $index => $network) {
                    ?>
                    <div class="social-network-item">
                        <h4>
                            Red Social #<?php echo ($index + 1); ?>
                            <a href="#" class="remove-social">Eliminar</a>
                        </h4>
                        
                        <div class="social-network-preview">
                            <div class="icon-preview" style="background-color: <?php echo esc_attr($network['color'] ?? '#000000'); ?>;">
                                <i class="dashicons dashicons-share"></i>
                            </div>
                            <div class="text-preview">
                                <?php echo esc_html($network['name'] ?? 'Nueva Red Social'); ?>
                            </div>
                        </div>
                        
                        <div class="social-fields-row">
                            <div class="form-field form-field-half">
                                <label>Nombre:</label>
                                <input type="text" name="banner_social_networks[<?php echo $index; ?>][name]" value="<?php echo esc_attr($network['name'] ?? ''); ?>" placeholder="Facebook" />
                            </div>
                            
                            <div class="form-field form-field-half">
                                <label>Color:</label>
                                <input type="text" name="banner_social_networks[<?php echo $index; ?>][color]" value="<?php echo esc_attr($network['color'] ?? '#000000'); ?>" placeholder="#1877F2" />
                            </div>
                        </div>
                        
                        <div class="form-field">
                            <label>URL:</label>
                            <input type="text" name="banner_social_networks[<?php echo $index; ?>][url]" value="<?php echo esc_attr($network['url'] ?? ''); ?>" placeholder="https://facebook.com/tupagina" />
                        </div>
                        
                        <div class="form-field">
                            <label>Icono:</label>
                            <input type="text" name="banner_social_networks[<?php echo $index; ?>][icon]" value="<?php echo esc_attr($network['icon'] ?? ''); ?>" placeholder="fab fa-facebook-f" />
                            <p class="description">Usa clases de Font Awesome (ej: fab fa-facebook-f, fab fa-instagram)</p>
                        </div>
                        
                        <?php if (!empty($membership_levels)) : 
                            $net_min_membership = isset($network['min_membership']) ? intval($network['min_membership']) : 0;
                            $net_membership_mode = isset($network['membership_mode']) ? $network['membership_mode'] : 'cascade';
                        ?>
                        <div class="social-fields-row" style="background: #f9f9f9; padding: 10px; border: 1px solid #ddd; border-radius: 4px; margin-top: 10px;">
                            <div class="form-field form-field-half">
                                <label>🔒 Membresía Mínima:</label>
                                <select name="banner_social_networks[<?php echo $index; ?>][min_membership]" style="width: 100%;">
                                    <?php 
                                    // Orden de visualización: niveles más altos primero
                                    $social_display_order = array(5, 4, 3, 2, 1, 0);
                                    foreach ($social_display_order as $level_id) : 
                                        if (!isset($membership_levels[$level_id])) continue;
                                        $level = $membership_levels[$level_id];
                                    ?>
                                        <option value="<?php echo esc_attr($level_id); ?>" <?php selected($net_min_membership, $level_id); ?>>
                                            <?php echo esc_html($level['icon'] . ' ' . $level['name']); ?>
                                            <?php if ($level_id === 0) echo '(Público)'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-field form-field-half">
                                <label>Modo:</label>
                                <select name="banner_social_networks[<?php echo $index; ?>][membership_mode]" style="width: 100%;">
                                    <option value="cascade" <?php selected($net_membership_mode, 'cascade'); ?>>📈 Cascada</option>
                                    <option value="exact" <?php selected($net_membership_mode, 'exact'); ?>>🎯 Exacto</option>
                                </select>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php
                }
            }
            ?>
        </div>
        
        <div class="social-buttons-group">
            <button type="button" class="button add-social-network">+ Añadir Red Social</button>
            <button type="button" class="button add-social-networks-quick">+ Añadir Redes Comunes</button>
        </div>
    </div>
    <?php
}
