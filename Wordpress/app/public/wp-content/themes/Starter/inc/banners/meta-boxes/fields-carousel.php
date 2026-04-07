<?php
/**
 * Banners - Campos para Carrusel
 * 
 * Este archivo contiene los campos específicos para banners de tipo "main" (carrusel)
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renderiza los campos para el carrusel (banner tipo "main")
 */
function banner_render_carousel_fields($post, $carousel_images) {
    // Obtener niveles de membresía si el plugin está activo
    $membership_levels = [];
    if (class_exists('Starter_Memberships')) {
        $membership_levels = Starter_Memberships::get_all_membership_levels();
    }
    ?>
    <div id="carousel_section" style="<?php $ct = get_post_meta($post->ID, '_banner_type', true); echo ($ct !== 'main' && $ct !== 'landing_toures' && $ct !== 'experience_toures') ? 'display: none;' : ''; ?>">
        <h3>Imágenes del Carrusel</h3>
        <div id="carousel-images-container">
            <?php
            if (!empty($carousel_images) && is_array($carousel_images)) {
                foreach ($carousel_images as $index => $carousel_image) {
                    ?>
                    <div class="social-network-item carousel-image-item">
                        <h4>
                            Imagen #<?php echo ($index + 1); ?>
                            <a href="#" class="remove-carousel-image">Eliminar</a>
                        </h4>
                        
                        <!-- Imagen Desktop -->
                        <div class="form-field">
                            <label>Imagen Desktop:</label>
                            <div class="image-upload-group">
                                <input type="text" name="banner_carousel_images[<?php echo $index; ?>][url]" class="carousel-image-url" value="<?php echo esc_attr($carousel_image['url'] ?? ''); ?>" readonly />
                                <button type="button" class="button carousel-image-upload">Seleccionar</button>
                                <button type="button" class="button carousel-image-remove" <?php echo empty($carousel_image['url']) ? 'style="display:none;"' : ''; ?>>×</button>
                            </div>
                            <p class="description">Imagen principal para dispositivos desktop (recomendado: 1920x1280px)</p>
                        </div>
                        
                        <div class="image-preview" style="<?php echo empty($carousel_image['url']) ? 'display:none;' : ''; ?>">
                            <img src="<?php echo esc_url($carousel_image['url'] ?? ''); ?>" alt="Vista previa del carrusel desktop" />
                        </div>
                        
                        <!-- Imagen Móvil (Opcional) -->
                        <div class="form-field">
                            <label>Imagen Móvil (Opcional):</label>
                            <div class="image-upload-group">
                                <input type="text" name="banner_carousel_images[<?php echo $index; ?>][mobile_url]" class="carousel-image-mobile-url" value="<?php echo esc_attr($carousel_image['mobile_url'] ?? ''); ?>" readonly />
                                <button type="button" class="button carousel-image-mobile-upload">Seleccionar</button>
                                <button type="button" class="button carousel-image-mobile-remove" <?php echo empty($carousel_image['mobile_url']) ? 'style="display:none;"' : ''; ?>>×</button>
                            </div>
                            <p class="description">Imagen optimizada para móviles (recomendado: 800x800px). Si no se especifica, se usará la imagen desktop.</p>
                        </div>
                        
                        <div class="image-preview mobile-preview" style="<?php echo empty($carousel_image['mobile_url']) ? 'display:none;' : ''; ?>">
                            <img src="<?php echo esc_url($carousel_image['mobile_url'] ?? ''); ?>" alt="Vista previa del carrusel móvil" />
                        </div>
                        
                        <!-- Imagen Desktop EN -->
                        <div class="form-field banner-translation-field">
                            <label>🇬🇧 Desktop Image (EN):</label>
                            <div class="image-upload-group">
                                <input type="text" name="banner_carousel_images[<?php echo $index; ?>][url_en]" class="carousel-image-url-en" value="<?php echo esc_attr($carousel_image['url_en'] ?? ''); ?>" readonly />
                                <button type="button" class="button carousel-image-en-upload">Select</button>
                                <button type="button" class="button carousel-image-en-remove" <?php echo empty($carousel_image['url_en']) ? 'style="display:none;"' : ''; ?>>×</button>
                            </div>
                            <p class="description">English version of the desktop image. If empty, the Spanish image will be used.</p>
                        </div>
                        
                        <div class="image-preview en-desktop-preview" style="<?php echo empty($carousel_image['url_en']) ? 'display:none;' : ''; ?>">
                            <img src="<?php echo esc_url($carousel_image['url_en'] ?? ''); ?>" alt="English desktop preview" />
                        </div>
                        
                        <!-- Imagen Móvil EN -->
                        <div class="form-field banner-translation-field">
                            <label>🇬🇧 Mobile Image (EN):</label>
                            <div class="image-upload-group">
                                <input type="text" name="banner_carousel_images[<?php echo $index; ?>][mobile_url_en]" class="carousel-image-mobile-url-en" value="<?php echo esc_attr($carousel_image['mobile_url_en'] ?? ''); ?>" readonly />
                                <button type="button" class="button carousel-image-mobile-en-upload">Select</button>
                                <button type="button" class="button carousel-image-mobile-en-remove" <?php echo empty($carousel_image['mobile_url_en']) ? 'style="display:none;"' : ''; ?>>×</button>
                            </div>
                            <p class="description">English version of the mobile image. If empty, the English desktop or Spanish mobile will be used.</p>
                        </div>
                        
                        <div class="image-preview en-mobile-preview" style="<?php echo empty($carousel_image['mobile_url_en']) ? 'display:none;' : ''; ?>">
                            <img src="<?php echo esc_url($carousel_image['mobile_url_en'] ?? ''); ?>" alt="English mobile preview" />
                        </div>
                        
                        <!-- Campo Orden -->
                        <div class="form-field">
                            <label>Orden de visualización:</label>
                            <input type="number" name="banner_carousel_images[<?php echo $index; ?>][order]" value="<?php echo esc_attr($carousel_image['order'] ?? ($index + 1)); ?>" min="1" max="100" step="1" style="width: 80px;" />
                            <p class="description">Número que define la posición del banner dentro del carrusel (1 = primero).</p>
                        </div>
                        
                        <?php if (!empty($membership_levels)) : 
                            $item_min_membership = isset($carousel_image['min_membership']) ? intval($carousel_image['min_membership']) : 0;
                            $item_membership_mode = isset($carousel_image['membership_mode']) ? $carousel_image['membership_mode'] : 'cascade';
                        ?>
                        <!-- Membresía Requerida -->
                        <div class="form-field" style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd; border-radius: 4px; margin-top: 10px;">
                            <h5 style="margin-top: 0; margin-bottom: 10px;">🔒 Membresía Mínima Requerida</h5>
                            
                            <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                                <div style="flex: 1; min-width: 200px;">
                                    <label>Membresía Mínima:</label>
                                    <select name="banner_carousel_images[<?php echo $index; ?>][min_membership]" style="width: 100%;">
                                        <?php 
                                        // Orden de visualización: niveles más altos primero
                                        $carousel_display_order = array(5, 4, 3, 2, 1, 0);
                                        foreach ($carousel_display_order as $level_id) : 
                                            if (!isset($membership_levels[$level_id])) continue;
                                            $level = $membership_levels[$level_id];
                                        ?>
                                            <option value="<?php echo esc_attr($level_id); ?>" <?php selected($item_min_membership, $level_id); ?>>
                                                <?php echo esc_html($level['icon'] . ' ' . $level['name']); ?>
                                                <?php if ($level_id === 0) : ?>
                                                    (Público)
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div style="flex: 1; min-width: 200px;">
                                    <label>Modo de Visibilidad:</label>
                                    <select name="banner_carousel_images[<?php echo $index; ?>][membership_mode]" style="width: 100%;">
                                        <option value="cascade" <?php selected($item_membership_mode, 'cascade'); ?>>📈 Cascada (este nivel y superiores)</option>
                                        <option value="exact" <?php selected($item_membership_mode, 'exact'); ?>>🎯 Exacto (solo este nivel)</option>
                                    </select>
                                </div>
                            </div>
                            <p class="description" style="margin-top: 8px;"><strong>Cascada:</strong> Visible para este nivel y superiores. <strong>Exacto:</strong> Solo visible para este nivel específico.</p>
                        </div>
                        <?php endif; ?>

                        <!-- URL del Banner completo -->
                        <div class="form-field">
                            <label>URL del Banner (Opcional):</label>
                            <input type="url" name="banner_carousel_images[<?php echo $index; ?>][banner_url]" value="<?php echo esc_attr($carousel_image['banner_url'] ?? ''); ?>" placeholder="https://ejemplo.com/pagina" class="carousel-banner-url-field" />
                            <p class="description">URL a la que redirige al hacer clic en toda la imagen del banner. <strong>Independiente del botón CTA.</strong></p>
                        </div>
                        
                        <div class="form-field">
                            <label>Subtítulo:</label>
                            <input type="text" name="banner_carousel_images[<?php echo $index; ?>][subtitle]" value="<?php echo esc_attr($carousel_image['subtitle'] ?? ''); ?>" placeholder="Texto breve descriptivo" />
                        </div>
                        
                        <div class="form-field banner-translation-field">
                            <label>🇬🇧 Subtitle (EN):</label>
                            <input type="text" name="banner_carousel_images[<?php echo $index; ?>][subtitle_en]" value="<?php echo esc_attr($carousel_image['subtitle_en'] ?? ''); ?>" placeholder="Short descriptive text" />
                        </div>
                        
                        <div class="form-field">
                            <label>Descripción:</label>
                            <textarea name="banner_carousel_images[<?php echo $index; ?>][description]" rows="2"><?php echo esc_textarea($carousel_image['description'] ?? ''); ?></textarea>
                        </div>
                        
                        <div class="form-field banner-translation-field">
                            <label>🇬🇧 Description (EN):</label>
                            <textarea name="banner_carousel_images[<?php echo $index; ?>][description_en]" rows="2"><?php echo esc_textarea($carousel_image['description_en'] ?? ''); ?></textarea>
                        </div>
                        
                        <div class="form-field">
                            <label>Botón (CTA):</label>
                            <input type="text" name="banner_carousel_images[<?php echo $index; ?>][cta]" value="<?php echo esc_attr($carousel_image['cta'] ?? ''); ?>" placeholder="Ej: Ver más" />
                        </div>
                        
                        <div class="form-field banner-translation-field">
                            <label>🇬🇧 Button CTA (EN):</label>
                            <input type="text" name="banner_carousel_images[<?php echo $index; ?>][cta_en]" value="<?php echo esc_attr($carousel_image['cta_en'] ?? ''); ?>" placeholder="E.g: See more" />
                        </div>
                        
                        <div class="form-field">
                            <label>URL del Botón CTA (Opcional):</label>
                            <input type="url" name="banner_carousel_images[<?php echo $index; ?>][link]" value="<?php echo esc_attr($carousel_image['link'] ?? ''); ?>" placeholder="https://ejemplo.com/pagina" class="carousel-link-field" />
                            <p class="description">URL específica para el botón CTA del info box. <strong>Solo funciona si hay texto CTA definido.</strong></p>
                        </div>
                        
                        <div class="form-field checkbox-field">
                            <label class="checkbox-label">
                                <input type="checkbox" name="banner_carousel_images[<?php echo $index; ?>][hide_info_box]" value="1" <?php checked(!empty($carousel_image['hide_info_box'])); ?> />
                                Ocultar cuadro de información
                            </label>
                            <p class="description">Solo se mostrará la imagen sin textos superpuestos.</p>
                        </div>
                    </div>
                    <?php
                }
            }
            ?>
        </div>
        
        <button type="button" class="button button-primary add-carousel-image">+ Añadir Imagen al Carrusel</button>
    </div>
    <?php
}
