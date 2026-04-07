<?php
/**
 * Banners - Campos Comunes
 * 
 * Este archivo contiene los campos comunes para todos los tipos de banners
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renderiza los campos comunes para todos los tipos de banners
 */
function banner_render_common_fields($post, $type, $subtitle, $cta, $link, $order, $image, $image_mobile) {
    
    // Obtener membresía mínima y modo del banner
    $min_membership = get_post_meta($post->ID, '_banner_min_membership', true);
    $min_membership = $min_membership !== '' ? intval($min_membership) : 0;
    $membership_mode = get_post_meta($post->ID, '_banner_membership_mode', true);
    $membership_mode = $membership_mode ?: 'cascade';
    
    // Obtener niveles de membresía
    $membership_levels = [];
    if (class_exists('Starter_Memberships')) {
        $membership_levels = Starter_Memberships::get_all_membership_levels();
    }
    
    // Contar banners del mismo tipo para determinar si mostrar el campo orden
    $banners_of_same_type = get_posts(array(
        'post_type' => 'banner',
        'meta_query' => array(
            array(
                'key' => '_banner_type',
                'value' => $type,
                'compare' => '='
            )
        ),
        'post_status' => array('publish', 'draft'),
        'numberposts' => -1,
        'exclude' => array($post->ID) // Excluir el post actual
    ));
    
    $show_order_field = count($banners_of_same_type) > 0; // Mostrar si hay más de un banner del mismo tipo
    
    ?>
    <div class="form-field" style="width: 100%;">
        <label for="banner_type">Tipo de Banner:</label>
        <select name="banner_type" id="banner_type">
            <option value="main" <?php selected($type, 'main'); ?>>Principal (Carrusel)</option>
            <option value="middle" <?php selected($type, 'middle'); ?>>Intermedio</option>
            <option value="bottom" <?php selected($type, 'bottom'); ?>>Inferior (Redes Sociales)</option>
            <option value="landing_toures" <?php selected($type, 'landing_toures'); ?>>Landing Toures (Carrusel)</option>
            <option value="experience_toures" <?php selected($type, 'experience_toures'); ?>>Experiencia Toures (Carrusel)</option>
        </select>
        <p class="description">El tipo de banner determina dónde se mostrará en la página de inicio. Las especificaciones detalladas se encuentran en el panel informativo de la derecha.</p>
    </div>
    
    <!-- Campos estándar para banners de tipo 'middle' (no para 'main' ni 'bottom') -->
    <div class="standard-fields" id="standard_fields" style="<?php echo ($type === 'main' || $type === 'bottom' || $type === 'landing_toures' || $type === 'experience_toures') ? 'display: none;' : ''; ?>">
        <div class="form-field">
            <label for="banner_subtitle">Subtítulo:</label>
            <input type="text" name="banner_subtitle" id="banner_subtitle" value="<?php echo esc_attr($subtitle); ?>" />
        </div>
        
        <div class="form-field">
            <label for="banner_link">Enlace del banner:</label>
            <input type="text" name="banner_link" id="banner_link" value="<?php echo esc_attr($link); ?>" placeholder="https://" />
        </div>
        
        <?php if (!empty($membership_levels)) : ?>
        <!-- Campos de membresía para banners tipo middle -->
        <div class="form-field membership-fields" style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd; border-radius: 4px; margin-top: 15px;">
            <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 10px;">
                <div style="flex: 1; min-width: 200px;">
                    <label for="banner_min_membership">🔒 Membresía Mínima:</label>
                    <select name="banner_min_membership" id="banner_min_membership" style="width: 100%;">
                        <?php 
                        // Orden de visualización: niveles más altos primero
                        $display_order = array(5, 4, 3, 2, 1, 0);
                        foreach ($display_order as $level_id) : 
                            if (!isset($membership_levels[$level_id])) continue;
                            $level = $membership_levels[$level_id];
                        ?>
                            <option value="<?php echo esc_attr($level_id); ?>" <?php selected($min_membership, $level_id); ?>>
                                <?php echo esc_html($level['icon'] . ' ' . $level['name']); ?>
                                <?php if ($level_id === 0) : ?>(Público)<?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div style="flex: 1; min-width: 200px;">
                    <label for="banner_membership_mode">Modo de Visibilidad:</label>
                    <select name="banner_membership_mode" id="banner_membership_mode" style="width: 100%;">
                        <option value="cascade" <?php selected($membership_mode, 'cascade'); ?>>📈 Cascada (este nivel y superiores)</option>
                        <option value="exact" <?php selected($membership_mode, 'exact'); ?>>🎯 Exacto (solo este nivel)</option>
                    </select>
                </div>
            </div>
            <p class="description" style="margin: 0; color: #666;">
                <strong>Jerarquía:</strong> 👑 Antigüedad → 💎 Diamante → 🥇 Dorada → 🥈 Plateada → 🥉 Bronce → 🥕 Zanahoria
            </p>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Campos para imágenes (solo para banners de tipo 'middle', no para 'main' ni 'bottom') -->
    <div class="form-field image-fields" id="banner_image_fields" style="<?php echo ($type === 'main' || $type === 'bottom' || $type === 'landing_toures') ? 'display: none;' : ''; ?>">
        <label for="banner_image">Imagen del Banner:</label>
        <input type="text" name="banner_image" id="banner_image" class="banner-image-url" value="<?php echo esc_attr($image); ?>" readonly />
        <button type="button" class="button banner-image-upload">Seleccionar imagen</button>
        <button type="button" class="button banner-image-remove" <?php echo empty($image) ? 'style="display:none;"' : ''; ?>>Eliminar imagen</button>
        
        <div class="image-preview" id="banner_image_preview" <?php echo empty($image) ? 'style="display:none;"' : ''; ?>>
            <img src="<?php echo esc_url($image); ?>" alt="Vista previa del banner" />
        </div>
    </div>
    
    <div class="form-field image-fields" id="banner_image_mobile_fields" style="<?php echo ($type === 'main' || $type === 'bottom' || $type === 'landing_toures') ? 'display: none;' : ''; ?>">
        <label for="banner_image_mobile">Imagen del Banner (Móvil):</label>
        <input type="text" name="banner_image_mobile" id="banner_image_mobile" class="banner-image-mobile-url" value="<?php echo esc_attr($image_mobile); ?>" readonly />
        <button type="button" class="button banner-image-mobile-upload">Seleccionar imagen</button>
        <button type="button" class="button banner-image-mobile-remove" <?php echo empty($image_mobile) ? 'style="display:none;"' : ''; ?>>Eliminar imagen</button>
        
        <div class="image-preview" id="banner_image_mobile_preview" <?php echo empty($image_mobile) ? 'style="display:none;"' : ''; ?>>
            <img src="<?php echo esc_url($image_mobile); ?>" alt="Vista previa del banner móvil" />
        </div>
    </div>
    
    <?php 
    $image_en = get_post_meta($post->ID, '_banner_image_en', true);
    $image_mobile_en = get_post_meta($post->ID, '_banner_image_mobile_en', true);
    ?>
    <!-- Imagen Desktop EN (middle / experience_toures) -->
    <div class="form-field image-fields banner-translation-field" id="banner_image_en_fields" style="<?php echo ($type === 'main' || $type === 'bottom' || $type === 'landing_toures') ? 'display: none;' : ''; ?>">
        <label for="banner_image_en">🇬🇧 Banner Image (EN):</label>
        <input type="text" name="banner_image_en" id="banner_image_en" class="banner-image-en-url" value="<?php echo esc_attr($image_en); ?>" readonly />
        <button type="button" class="button banner-image-en-upload">Select image</button>
        <button type="button" class="button banner-image-en-remove" <?php echo empty($image_en) ? 'style="display:none;"' : ''; ?>>Remove</button>
        
        <div class="image-preview" id="banner_image_en_preview" <?php echo empty($image_en) ? 'style="display:none;"' : ''; ?>>
            <img src="<?php echo esc_url($image_en); ?>" alt="English banner preview" />
        </div>
        <p class="description">English version of the desktop image. If empty, the Spanish image will be used.</p>
    </div>
    
    <!-- Imagen Móvil EN (middle / experience_toures) -->
    <div class="form-field image-fields banner-translation-field" id="banner_image_mobile_en_fields" style="<?php echo ($type === 'main' || $type === 'bottom' || $type === 'landing_toures') ? 'display: none;' : ''; ?>">
        <label for="banner_image_mobile_en">🇬🇧 Banner Image Mobile (EN):</label>
        <input type="text" name="banner_image_mobile_en" id="banner_image_mobile_en" class="banner-image-mobile-en-url" value="<?php echo esc_attr($image_mobile_en); ?>" readonly />
        <button type="button" class="button banner-image-mobile-en-upload">Select image</button>
        <button type="button" class="button banner-image-mobile-en-remove" <?php echo empty($image_mobile_en) ? 'style="display:none;"' : ''; ?>>Remove</button>
        
        <div class="image-preview" id="banner_image_mobile_en_preview" <?php echo empty($image_mobile_en) ? 'style="display:none;"' : ''; ?>>
            <img src="<?php echo esc_url($image_mobile_en); ?>" alt="English mobile banner preview" />
        </div>
        <p class="description">English version of the mobile image. If empty, the English desktop or Spanish mobile will be used.</p>
    </div>
    
    <!-- Campo de orden - Solo mostrar si hay más de un banner del mismo tipo -->
    <?php if ($show_order_field): ?>
    <div class="form-field" id="banner_order_field">
        <label for="banner_order">Orden:</label>
        <input type="number" name="banner_order" id="banner_order" value="<?php echo esc_attr($order); ?>" min="0" step="1" />
        <p class="description">Número para ordenar los banners del mismo tipo. Los números más bajos aparecen primero.</p>
    </div>
    <?php else: ?>
    <!-- Campo oculto para mantener el valor cuando no se muestra -->
    <input type="hidden" name="banner_order" value="<?php echo esc_attr($order ?: 0); ?>" />
    <div class="form-field" style="background: #e7f3ff; border: 1px solid #b3d9ff; padding: 10px; border-radius: 3px;">
        <p style="margin: 0; color: #0073aa;"><strong>ℹ️ Información:</strong> Como este es el único banner de tipo "<?php echo ucfirst($type); ?>", el campo de orden no es necesario.</p>
    </div>
    <?php endif;
}
