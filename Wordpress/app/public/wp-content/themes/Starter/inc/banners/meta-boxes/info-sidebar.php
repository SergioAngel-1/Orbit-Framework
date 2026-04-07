<?php
/**
 * Banners - Metabox Informativo Lateral
 * 
 * Este archivo contiene el metabox informativo que aparece en la barra lateral
 * con información relevante sobre medidas, mejores prácticas y datos del proyecto.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registrar el metabox informativo lateral
 */
function banner_register_info_sidebar_metabox() {
    add_meta_box(
        'starter_banner_info_sidebar',
        '📋 Información de Banners',
        'banner_info_sidebar_callback',
        'banner',
        'side',
        'default'
    );
}
add_action('add_meta_boxes', 'banner_register_info_sidebar_metabox');

/**
 * Callback para renderizar el contenido del metabox informativo
 */
function banner_info_sidebar_callback($post) {
    // Obtener datos del banner actual para mostrar estadísticas
    $banner_type = get_post_meta($post->ID, '_banner_type', true) ?: 'main';
    $carousel_images = get_post_meta($post->ID, '_banner_carousel_images', true) ?: array();
    $carousel_count = is_array($carousel_images) ? count($carousel_images) : 0;
    $has_social = !empty(get_post_meta($post->ID, '_banner_social_networks', true));
    
    ?>
    <div class="banner-info-sidebar">
        <!-- Especificaciones del Banner Actual -->
        <div class="info-section">
            <h4>📐 Especificaciones del Banner</h4>
            
            <?php if ($banner_type === 'main'): ?>
            <!-- Banner Principal (Main/Carrusel) -->
            <div class="banner-spec-section active-spec">
                <div class="spec-header">
                    <span class="spec-icon">🎠</span>
                    <strong>Principal (Carrusel)</strong>
                </div>
                <div class="measures-grid">
                    <div class="measure-item desktop-spec">
                        <strong>🖥️ Recomendado (Desktop/Tablet)</strong>
                        <span>1920×1080px</span>
                        <small>Ratio 16:9 • Máx 500KB</small>
                    </div>
                    <div class="measure-item mobile-spec">
                        <strong>📱 Móvil (0-639px)</strong>
                        <span>800×800px</span>
                        <small>Ratio 1:1 • Máx 300KB</small>
                    </div>
                </div>
                <div class="spec-notes">
                    <ul>
                        <li>✅ Usar 1920×1080px (16:9) para desktop/tablet</li>
                        <li>✅ Usar 800×800px (1:1) para móvil</li>
                        <li>Contenedor mantiene aspect-ratio 16:9 automáticamente</li>
                        <li>Object-fit: cover (llena sin espacios negros)</li>
                        <li>Sin recortes si la imagen es exactamente 16:9</li>
                        <li>Elementos importantes centrados por seguridad</li>
                    </ul>
                </div>
            </div>
            <?php elseif ($banner_type === 'middle'): ?>
            <!-- Banner Intermedio (Middle) -->
            <div class="banner-spec-section active-spec">
                <div class="spec-header">
                    <span class="spec-icon">🎯</span>
                    <strong>Intermedio</strong>
                </div>
                <div class="measures-grid">
                    <div class="measure-item desktop-spec">
                        <strong>🖥️ Desktop</strong>
                        <span>1600×400px</span>
                        <small>Ratio 4:1 • Máx 400KB</small>
                    </div>
                    <div class="measure-item mobile-spec">
                        <strong>📱 Móvil</strong>
                        <span>800×600px</span>
                        <small>Ratio 1.33:1 • Máx 250KB</small>
                    </div>
                </div>
                <div class="spec-notes">
                    <ul>
                        <li>Ancho completo de la página</li>
                        <li>Ideal para promociones especiales</li>
                        <li>Incluye texto, botón y enlace</li>
                        <li>Posición flexible mediante orden</li>
                    </ul>
                </div>
            </div>
            <?php elseif ($banner_type === 'landing_toures'): ?>
            <!-- Banner Landing Toures (Carrusel) -->
            <div class="banner-spec-section active-spec">
                <div class="spec-header">
                    <span class="spec-icon">🗺️</span>
                    <strong>Landing Toures (Carrusel)</strong>
                </div>
                <div class="measures-grid">
                    <div class="measure-item desktop-spec">
                        <strong>🖥️ Recomendado (Desktop/Tablet)</strong>
                        <span>1920×700px</span>
                        <small>Pantalla completa • Máx 500KB</small>
                    </div>
                    <div class="measure-item mobile-spec">
                        <strong>📱 Móvil (0-639px)</strong>
                        <span>800×600px</span>
                        <small>Ratio 4:3 • Máx 300KB</small>
                    </div>
                </div>
                <div class="spec-notes">
                    <ul>
                        <li>✅ Usar 1920×700px para desktop/tablet</li>
                        <li>✅ Usar 800×600px (4:3) para móvil</li>
                        <li>Hero de extremo a extremo en la página de Toures</li>
                        <li>Ocupa el 100% del ancho de pantalla</li>
                        <li>Object-fit: cover (llena sin espacios negros)</li>
                        <li>Elementos importantes centrados por seguridad</li>
                    </ul>
                </div>
            </div>
            <?php elseif ($banner_type === 'experience_toures'): ?>
            <!-- Banner Experiencia Toures (Carrusel) -->
            <div class="banner-spec-section active-spec">
                <div class="spec-header">
                    <span class="spec-icon">🌿</span>
                    <strong>Experiencia Toures (Carrusel)</strong>
                </div>
                <div class="measures-grid">
                    <div class="measure-item desktop-spec">
                        <strong>🖥️ Recomendado (Desktop/Tablet)</strong>
                        <span>600×800px</span>
                        <small>Ratio 3:4 (vertical) • Máx 400KB</small>
                    </div>
                    <div class="measure-item mobile-spec">
                        <strong>📱 Móvil (0-639px)</strong>
                        <span>800×600px</span>
                        <small>Ratio 4:3 • Máx 300KB</small>
                    </div>
                </div>
                <div class="spec-notes">
                    <ul>
                        <li>✅ Usar 600×800px (3:4 vertical) para desktop/tablet</li>
                        <li>✅ Usar 800×600px (4:3) para móvil</li>
                        <li>Se muestra en la sección "Experiencia 360°" de Toures</li>
                        <li>Formato vertical en columna lateral</li>
                        <li>Object-fit: cover (llena sin espacios negros)</li>
                        <li>Ideal para fotos de cultivos y naturaleza</li>
                    </ul>
                </div>
            </div>
            <?php elseif ($banner_type === 'bottom'): ?>
            <!-- Banner Inferior (Bottom/Redes) -->
            <div class="banner-spec-section active-spec">
                <div class="spec-header">
                    <span class="spec-icon">🌐</span>
                    <strong>Inferior (Redes)</strong>
                </div>
                <div class="measures-grid">
                    <div class="measure-item unified-spec">
                        <strong>🖥️📱 Unificado</strong>
                        <span>1200×400px</span>
                        <small>Ratio 3:1 • Máx 300KB</small>
                    </div>
                </div>
                <div class="spec-notes">
                    <ul>
                        <li>Incluye íconos de redes sociales</li>
                        <li>Íconos superpuestos sobre imagen</li>
                        <li>Dejar espacio libre para íconos</li>
                        <li>Call-to-action social al final</li>
                    </ul>
                </div>
            </div>
            <?php endif; ?>
        </div>



        <!-- Estadísticas del Banner Actual -->
        <div class="info-section">
            <h4>📊 Estado Actual</h4>
            <div class="stats-grid">
                <?php if ($banner_type === 'main'): ?>
                    <!-- Estados específicos para Banner Principal (Carrusel) -->
                    <div class="stat-item">
                        <span class="stat-number"><?php echo $carousel_count; ?></span>
                        <span class="stat-label">Imágenes Carrusel</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number"><?php echo $carousel_count > 0 ? '✅' : '❌'; ?></span>
                        <span class="stat-label">Carrusel Activo</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number"><?php 
                            $with_info = 0;
                            if (is_array($carousel_images)) {
                                foreach ($carousel_images as $img) {
                                    if (!empty($img['title']) || !empty($img['subtitle']) || !empty($img['description'])) {
                                        $with_info++;
                                    }
                                }
                            }
                            echo $with_info;
                        ?></span>
                        <span class="stat-label">Con Info Box</span>
                    </div>
                <?php elseif ($banner_type === 'landing_toures'): ?>
                    <!-- Estados específicos para Banner Landing Toures (Carrusel) -->
                    <div class="stat-item">
                        <span class="stat-number"><?php echo $carousel_count; ?></span>
                        <span class="stat-label">Imágenes Carrusel</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number"><?php echo $carousel_count > 0 ? '✅' : '❌'; ?></span>
                        <span class="stat-label">Carrusel Activo</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number"><?php 
                            $with_info = 0;
                            if (is_array($carousel_images)) {
                                foreach ($carousel_images as $img) {
                                    if (!empty($img['title']) || !empty($img['subtitle']) || !empty($img['description'])) {
                                        $with_info++;
                                    }
                                }
                            }
                            echo $with_info;
                        ?></span>
                        <span class="stat-label">Con Info Box</span>
                    </div>
                <?php elseif ($banner_type === 'experience_toures'): ?>
                    <!-- Estados específicos para Banner Experiencia Toures (Carrusel) -->
                    <div class="stat-item">
                        <span class="stat-number"><?php echo $carousel_count; ?></span>
                        <span class="stat-label">Imágenes Carrusel</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number"><?php echo $carousel_count > 0 ? '✅' : '❌'; ?></span>
                        <span class="stat-label">Carrusel Activo</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number"><?php 
                            $with_info = 0;
                            if (is_array($carousel_images)) {
                                foreach ($carousel_images as $img) {
                                    if (!empty($img['title']) || !empty($img['subtitle']) || !empty($img['description'])) {
                                        $with_info++;
                                    }
                                }
                            }
                            echo $with_info;
                        ?></span>
                        <span class="stat-label">Con Info Box</span>
                    </div>
                <?php elseif ($banner_type === 'middle'): ?>
                    <!-- Estados específicos para Banner Intermedio -->
                    <div class="stat-item">
                        <span class="stat-number"><?php echo !empty(get_post_meta($post->ID, '_banner_image', true)) ? '✅' : '❌'; ?></span>
                        <span class="stat-label">Imagen Principal</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number"><?php echo !empty(get_post_meta($post->ID, '_banner_mobile_image', true)) ? '✅' : '❌'; ?></span>
                        <span class="stat-label">Imagen Móvil</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number"><?php echo !empty(get_post_meta($post->ID, '_banner_link', true)) ? '✅' : '❌'; ?></span>
                        <span class="stat-label">Enlace Banner</span>
                    </div>
                <?php elseif ($banner_type === 'bottom'): ?>
                    <!-- Estados específicos para Banner Inferior (Redes) -->
                    <div class="stat-item">
                        <span class="stat-number"><?php echo $has_social ? '✅' : '❌'; ?></span>
                        <span class="stat-label">Redes Sociales</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number"><?php 
                            $social_networks = get_post_meta($post->ID, '_banner_social_networks', true);
                            echo is_array($social_networks) ? count($social_networks) : 0;
                        ?></span>
                        <span class="stat-label">Enlaces Sociales</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number"><?php 
                            $social_networks = get_post_meta($post->ID, '_banner_social_networks', true);
                            $active_networks = 0;
                            if (is_array($social_networks)) {
                                foreach ($social_networks as $network) {
                                    if (!empty($network['url'])) {
                                        $active_networks++;
                                    }
                                }
                            }
                            echo $active_networks;
                        ?></span>
                        <span class="stat-label">Enlaces Activos</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Mejores Prácticas -->
        <div class="info-section">
            <h4>💡 Mejores Prácticas</h4>
            <ul class="best-practices">
                <?php if ($banner_type === 'main'): ?>
                    <!-- Prácticas para Banner Principal (Carrusel) -->
                    <li><strong>Dimensión estándar:</strong> 1920×1080px (16:9) para desktop/tablet</li>
                    <li><strong>Ratio 16:9:</strong> Mantener esta proporción para evitar recortes</li>
                    <li><strong>Sin espacios negros:</strong> Contenedor aspect-ratio + object-fit cover</li>
                    <li><strong>Elementos centrados:</strong> Contenido importante en el centro por seguridad</li>
                    <li><strong>Máximo 5 imágenes:</strong> Para evitar sobrecarga visual</li>
                    <li><strong>Peso optimizado:</strong> Máximo 500KB desktop, 300KB móvil</li>
                <?php elseif ($banner_type === 'landing_toures'): ?>
                    <!-- Prácticas para Banner Landing Toures (Carrusel) -->
                    <li><strong>Dimensión estándar:</strong> 1920×700px para desktop/tablet</li>
                    <li><strong>Pantalla completa:</strong> Se extiende de extremo a extremo (100% ancho)</li>
                    <li><strong>Hero de Toures:</strong> Se muestra como carrusel hero en /toures</li>
                    <li><strong>Elementos centrados:</strong> Contenido importante en el centro por seguridad</li>
                    <li><strong>Máximo 5 imágenes:</strong> Para evitar sobrecarga visual</li>
                    <li><strong>Peso optimizado:</strong> Máximo 500KB desktop, 300KB móvil</li>
                <?php elseif ($banner_type === 'experience_toures'): ?>
                    <!-- Prácticas para Banner Experiencia Toures (Carrusel) -->
                    <li><strong>Dimensión estándar:</strong> 600×800px (3:4 vertical) para desktop/tablet</li>
                    <li><strong>Formato vertical:</strong> Ratio 3:4 ideal para la columna lateral</li>
                    <li><strong>Sección Experiencia:</strong> Se muestra en "La Experiencia 360°" de /toures</li>
                    <li><strong>Fotos de naturaleza:</strong> Ideal para cultivos, paisajes y experiencias</li>
                    <li><strong>Máximo 5 imágenes:</strong> Para evitar sobrecarga visual</li>
                    <li><strong>Peso optimizado:</strong> Máximo 400KB desktop, 300KB móvil</li>
                <?php elseif ($banner_type === 'middle'): ?>
                    <!-- Prácticas para Banner Intermedio -->
                    <li><strong>Promociones claras:</strong> Ideal para ofertas especiales</li>
                    <li><strong>Enlace directo:</strong> La imagen completa redirige al enlace</li>
                    <li><strong>Ancho completo:</strong> Aprovecha todo el espacio horizontal</li>
                    <li><strong>Contraste alto:</strong> Texto legible sobre imagen</li>
                    <li><strong>Peso optimizado:</strong> Máximo 400KB desktop, 250KB móvil</li>
                <?php elseif ($banner_type === 'bottom'): ?>
                    <!-- Prácticas para Banner Inferior (Redes) -->
                    <li><strong>Espacio para íconos:</strong> Deja área libre para redes sociales</li>
                    <li><strong>Enlaces funcionales:</strong> Verifica todos los links sociales</li>
                    <li><strong>Call-to-action social:</strong> Invita a seguir tus redes</li>
                    <li><strong>Diseño unificado:</strong> Una sola imagen para todos los dispositivos</li>
                    <li><strong>Peso optimizado:</strong> Máximo 300KB para carga rápida</li>
                <?php endif; ?>
            </ul>
        </div>

        <!-- Datos del Proyecto -->
        <div class="info-section">
            <h4>🌸 <?php echo esc_html(function_exists('site_get_name') ? site_get_name() : 'Mi Tienda'); ?></h4>
            <div class="project-info">
                <div class="project-stat">
                    <strong>Tema:</strong> Starter Custom
                </div>
                <div class="project-stat">
                    <strong>Frontend:</strong> React + TypeScript
                </div>
                <div class="project-stat">
                    <strong>API:</strong> WordPress REST + WooCommerce
                </div>
                <div class="project-stat">
                    <strong>Cache:</strong> W3 Total Cache + localStorage
                </div>
            </div>
        </div>

        <!-- Acciones Rápidas -->
        <div class="info-section">
            <h4>⚡ Acciones Rápidas</h4>
            <div class="quick-actions">
                <a href="<?php echo admin_url('edit.php?post_type=banner'); ?>" class="quick-action-btn">
                    📋 Ver Todos los Banners
                </a>
                <a href="<?php echo home_url('/'); ?>" target="_blank" class="quick-action-btn">
                    🌐 Ver Sitio Web
                </a>
                <a href="<?php echo admin_url('options-general.php?page=w3tc_dashboard'); ?>" class="quick-action-btn">
                    🚀 Gestión de Cache
                </a>
            </div>
        </div>

        <!-- Soporte Técnico -->
        <div class="info-section support-section">
            <h4>🛠️ Soporte Técnico</h4>
            <p class="support-text">
                <strong>Arquitectura:</strong> WordPress Headless<br>
                <strong>Carrusel:</strong> Swiper.js con autoplay 30s<br>
                <strong>Aspect-ratio:</strong> 16:9 (desktop) / 1:1 (mobile)<br>
                <strong>Object-fit:</strong> cover (sin espacios negros)<br>
                <strong>Sincronización:</strong> Altura dinámica con categorías<br>
                <strong>Última actualización:</strong> <?php echo date('d/m/Y'); ?>
            </p>
        </div>
    </div>

    <style>
    .banner-info-sidebar {
        font-size: 13px;
        line-height: 1.4;
    }

    .info-section {
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 1px solid #e0e0e0;
    }

    .info-section:last-child {
        border-bottom: none;
        margin-bottom: 0;
    }

    .info-section h4 {
        margin: 0 0 10px 0;
        font-size: 14px;
        font-weight: 600;
        color: #1d2327;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    /* Medidas */
    .measures-grid {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .measure-item {
        background: #f6f7f7;
        padding: 8px 10px;
        border-radius: 4px;
        border-left: 3px solid #2271b1;
    }

    .measure-item strong {
        display: block;
        color: #1d2327;
        font-size: 12px;
        font-weight: 600;
    }

    .measure-item span {
        display: block;
        color: #2271b1;
        font-weight: bold;
        font-size: 14px;
        margin: 2px 0;
    }

    .measure-item small {
        color: #646970;
        font-size: 11px;
    }

    .mobile-measure {
        border-left-color: #00a32a;
    }

    .mobile-measure span {
        color: #00a32a;
    }

    /* Tipos de Banner */
    .banner-types {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .type-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 6px 8px;
        border-radius: 4px;
        background: #f9f9f9;
        transition: all 0.2s ease;
    }

    .type-item.active {
        background: #e7f3ff;
        border: 1px solid #2271b1;
    }

    .type-icon {
        font-size: 16px;
        width: 20px;
        text-align: center;
    }

    .type-item strong {
        display: block;
        font-size: 12px;
        color: #1d2327;
    }

    .type-item small {
        display: block;
        font-size: 11px;
        color: #646970;
        line-height: 1.3;
    }

    /* Estadísticas */
    .stats-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }

    .stat-item {
        text-align: center;
        padding: 8px 6px;
        background: #f0f6fc;
        border-radius: 4px;
        border: 1px solid #c3c4c7;
    }

    .stat-number {
        display: block;
        font-weight: bold;
        font-size: 16px;
        color: #2271b1;
        line-height: 1;
    }

    .stat-label {
        display: block;
        font-size: 10px;
        color: #646970;
        margin-top: 2px;
    }

    /* Mejores Prácticas */
    .best-practices {
        margin: 0;
        padding-left: 0;
        list-style: none;
    }

    .best-practices li {
        margin-bottom: 6px;
        padding-left: 16px;
        position: relative;
        font-size: 11px;
        line-height: 1.4;
    }

    .best-practices li::before {
        content: "✓";
        position: absolute;
        left: 0;
        color: #00a32a;
        font-weight: bold;
    }

    /* Información del Proyecto */
    .project-info {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .project-stat {
        display: flex;
        justify-content: space-between;
        font-size: 11px;
        padding: 2px 0;
    }

    .project-stat strong {
        color: #1d2327;
    }

    /* Acciones Rápidas */
    .quick-actions {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .quick-action-btn {
        display: block;
        padding: 6px 10px;
        background: #f6f7f7;
        border: 1px solid #c3c4c7;
        border-radius: 4px;
        text-decoration: none;
        color: #2271b1;
        font-size: 11px;
        text-align: center;
        transition: all 0.2s ease;
    }

    .quick-action-btn:hover {
        background: #2271b1;
        color: white;
        text-decoration: none;
    }

    /* Soporte */
    .support-section {
        background: #f0f6fc;
        padding: 10px;
        border-radius: 4px;
        border: 1px solid #c3c4c7;
    }

    .support-text {
        margin: 0;
        font-size: 11px;
        line-height: 1.5;
        color: #1d2327;
    }

    /* Especificaciones de Banner */
    .banner-spec-section {
        margin-bottom: 15px;
        border: 1px solid #e0e0e0;
        border-radius: 6px;
        overflow: hidden;
        transition: all 0.3s ease;
    }

    .banner-spec-section.active-spec {
        border-color: #2271b1;
        background: #f0f6fc;
        box-shadow: 0 2px 8px rgba(34, 113, 177, 0.1);
    }

    .spec-header {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        background: #f6f7f7;
        border-bottom: 1px solid #e0e0e0;
        font-size: 12px;
        font-weight: 600;
        color: #1d2327;
    }

    .banner-spec-section.active-spec .spec-header {
        background: #2271b1;
        color: white;
        border-bottom-color: #2271b1;
    }

    .spec-icon {
        font-size: 14px;
    }

    .banner-spec-section .measures-grid {
        display: flex;
        flex-direction: column;
        gap: 6px;
        padding: 10px;
    }

    .desktop-spec {
        border-left-color: #2271b1 !important;
    }

    .desktop-spec span {
        color: #2271b1 !important;
    }

    .mobile-spec {
        border-left-color: #00a32a !important;
    }

    .mobile-spec span {
        color: #00a32a !important;
    }

    .unified-spec {
        border-left-color: #e74c3c !important;
    }

    .unified-spec span {
        color: #e74c3c !important;
    }

    .spec-notes {
        padding: 8px 12px;
        background: rgba(255, 255, 255, 0.5);
        border-top: 1px solid #e0e0e0;
    }

    .banner-spec-section.active-spec .spec-notes {
        background: rgba(255, 255, 255, 0.8);
    }

    .spec-notes ul {
        margin: 0;
        padding-left: 0;
        list-style: none;
    }

    .spec-notes li {
        font-size: 10px;
        line-height: 1.4;
        margin-bottom: 3px;
        padding-left: 12px;
        position: relative;
        color: #646970;
    }

    .spec-notes li::before {
        content: "•";
        position: absolute;
        left: 0;
        color: #2271b1;
        font-weight: bold;
    }

    .banner-spec-section.active-spec .spec-notes li {
        color: #1d2327;
    }

    /* Colapsar especificaciones no activas */
    .banner-spec-section:not(.active-spec) .measures-grid,
    .banner-spec-section:not(.active-spec) .spec-notes {
        display: none;
    }

    .banner-spec-section:not(.active-spec) {
        opacity: 0.6;
    }

    .banner-spec-section:not(.active-spec):hover {
        opacity: 0.8;
        cursor: pointer;
    }

    /* Responsive */
    @media screen and (max-width: 782px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
        
        .measures-grid {
            gap: 6px;
        }
    }
    </style>

    <script>
    // La sección informativa ahora muestra solo el tipo de banner seleccionado
    // Se actualiza automáticamente al guardar el post
    jQuery(document).ready(function($) {
        // Mensaje informativo cuando se cambia el tipo
        $(document).on('change', '#banner_type', function() {
            var selectedType = $(this).val();
            var typeName = $(this).find('option:selected').text();
            
            // Mostrar mensaje temporal
            var $message = $('<div class="notice notice-info inline" style="margin: 10px 0; padding: 5px 12px;"><p><strong>💡 Información:</strong> Las especificaciones se actualizarán para "' + typeName + '" al guardar.</p></div>');
            $('.banner-info-sidebar').prepend($message);
            
            // Remover mensaje después de 3 segundos
            setTimeout(function() {
                $message.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 3000);
        });
    });
    </script>
    <?php
}
