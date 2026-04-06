<?php
/**
 * Banners - Estilos y Scripts
 * 
 * Este archivo contiene los estilos y scripts necesarios para el funcionamiento
 * de los meta boxes de banners
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registra y carga los estilos CSS para los meta boxes
 */
function banner_register_admin_styles() {
    // Usar get_stylesheet_directory para temas hijo
    $css_path = get_stylesheet_directory() . '/inc/banners/meta-boxes/css/banner-metabox.css';
    
    // Si no existe en el tema hijo, buscar en el tema padre
    if (!file_exists($css_path)) {
        $css_path = get_template_directory() . '/inc/banners/meta-boxes/css/banner-metabox.css';
        $css_url = get_template_directory_uri() . '/inc/banners/meta-boxes/css/banner-metabox.css';
    } else {
        $css_url = get_stylesheet_directory_uri() . '/inc/banners/meta-boxes/css/banner-metabox.css';
    }
    
    // Verificar que el archivo existe
    if (file_exists($css_path)) {
        $css_version = filemtime($css_path);
        wp_register_style('banner-metabox-styles', $css_url, array(), $css_version);
    }
}
add_action('admin_init', 'banner_register_admin_styles');

/**
 * Renderiza los estilos CSS para los meta boxes
 */
function banner_render_styles() {
    // Solo cargar en las páginas de edición de banners
    global $post_type;
    if ($post_type === 'banner') {
        wp_enqueue_style('banner-metabox-styles');
    }
}

/**
 * Renderiza los scripts JavaScript para los meta boxes
 */
function banner_render_scripts($post, $social_networks, $carousel_images) {
    ?>
    <script>
    jQuery(document).ready(function($) {
        // Cambiar campos visibles según el tipo de banner
        $('#banner_type').on('change', function() {
            var type = $(this).val();
            
            // Mostrar/ocultar campos estándar
            if (type === 'main' || type === 'bottom' || type === 'landing_toures' || type === 'experience_toures') {
                $('#standard_fields').hide();
            } else {
                $('#standard_fields').show();
            }
            
            // Mostrar/ocultar campos de imagen
            if (type === 'main' || type === 'bottom' || type === 'landing_toures' || type === 'experience_toures') {
                $('#banner_image_fields').hide();
                $('#banner_image_mobile_fields').hide();
                $('#banner_image_en_fields').hide();
                $('#banner_image_mobile_en_fields').hide();
            } else {
                $('#banner_image_fields').show();
                $('#banner_image_mobile_fields').show();
                $('#banner_image_en_fields').show();
                $('#banner_image_mobile_en_fields').show();
            }
            
            // Mostrar/ocultar sección de redes sociales
            if (type === 'bottom') {
                $('#social_networks_section').show();
            } else {
                $('#social_networks_section').hide();
            }
            
            // Mostrar/ocultar sección de carrusel
            if (type === 'main' || type === 'landing_toures' || type === 'experience_toures') {
                $('#carousel_section').show();
            } else {
                $('#carousel_section').hide();
            }
            
            // Las especificaciones ahora se manejan en el panel lateral
        });
        
        // Funcionalidad para la imagen principal
        $('.banner-image-upload').on('click', function(e) {
            e.preventDefault();
            
            var image_frame;
            
            if (image_frame) {
                image_frame.open();
                return;
            }
            
            image_frame = wp.media({
                title: 'Seleccionar imagen para el banner',
                multiple: false,
                library: {
                    type: 'image'
                }
            });
            
            image_frame.on('select', function() {
                var attachment = image_frame.state().get('selection').first().toJSON();
                $('#banner_image').val(attachment.url);
                $('#banner_image_preview').show().find('img').attr('src', attachment.url);
                $('.banner-image-remove').show();
            });
            
            image_frame.open();
        });
        
        $('.banner-image-remove').on('click', function(e) {
            e.preventDefault();
            $('#banner_image').val('');
            $('#banner_image_preview').hide();
            $(this).hide();
        });
        
        // Funcionalidad para la imagen móvil
        $('.banner-image-mobile-upload').on('click', function(e) {
            e.preventDefault();
            
            var image_frame;
            
            if (image_frame) {
                image_frame.open();
                return;
            }
            
            image_frame = wp.media({
                title: 'Seleccionar imagen móvil para el banner',
                multiple: false,
                library: {
                    type: 'image'
                }
            });
            
            image_frame.on('select', function() {
                var attachment = image_frame.state().get('selection').first().toJSON();
                $('#banner_image_mobile').val(attachment.url);
                $('#banner_image_mobile_preview').show().find('img').attr('src', attachment.url);
                $('.banner-image-mobile-remove').show();
            });
            
            image_frame.open();
        });
        
        $('.banner-image-mobile-remove').on('click', function(e) {
            e.preventDefault();
            $('#banner_image_mobile').val('');
            $('#banner_image_mobile_preview').hide();
            $(this).hide();
        });
        
        // Funcionalidad para la imagen EN desktop (middle banner)
        $('.banner-image-en-upload').on('click', function(e) {
            e.preventDefault();
            var image_frame = wp.media({ title: 'Select English banner image', multiple: false, library: { type: 'image' } });
            image_frame.on('select', function() {
                var attachment = image_frame.state().get('selection').first().toJSON();
                $('#banner_image_en').val(attachment.url);
                $('#banner_image_en_preview').show().find('img').attr('src', attachment.url);
                $('.banner-image-en-remove').show();
            });
            image_frame.open();
        });
        
        $('.banner-image-en-remove').on('click', function(e) {
            e.preventDefault();
            $('#banner_image_en').val('');
            $('#banner_image_en_preview').hide();
            $(this).hide();
        });
        
        // Funcionalidad para la imagen EN móvil (middle banner)
        $('.banner-image-mobile-en-upload').on('click', function(e) {
            e.preventDefault();
            var image_frame = wp.media({ title: 'Select English mobile banner image', multiple: false, library: { type: 'image' } });
            image_frame.on('select', function() {
                var attachment = image_frame.state().get('selection').first().toJSON();
                $('#banner_image_mobile_en').val(attachment.url);
                $('#banner_image_mobile_en_preview').show().find('img').attr('src', attachment.url);
                $('.banner-image-mobile-en-remove').show();
            });
            image_frame.open();
        });
        
        $('.banner-image-mobile-en-remove').on('click', function(e) {
            e.preventDefault();
            $('#banner_image_mobile_en').val('');
            $('#banner_image_mobile_en_preview').hide();
            $(this).hide();
        });
        
        // Funcionalidad para añadir redes sociales
        var socialNetworkIndex = <?php echo !empty($social_networks) ? count($social_networks) : 0; ?>;
        
        $('.add-social-network').on('click', function(e) {
            e.preventDefault();
            
            var template = `
                <div class="social-network-item">
                    <h4>
                        Red Social #${socialNetworkIndex + 1}
                        <a href="#" class="remove-social">Eliminar</a>
                    </h4>
                    
                    <div class="social-network-preview">
                        <div class="icon-preview" style="background-color: #000000;">
                            <i class="dashicons dashicons-share"></i>
                        </div>
                        <div class="text-preview">
                            Nueva Red Social
                        </div>
                    </div>
                    
                    <div class="social-fields-row">
                        <div class="form-field form-field-half">
                            <label>Nombre:</label>
                            <input type="text" name="banner_social_networks[${socialNetworkIndex}][name]" value="Nueva Red Social" placeholder="Facebook" />
                        </div>
                        
                        <div class="form-field form-field-half">
                            <label>Color:</label>
                            <input type="text" name="banner_social_networks[${socialNetworkIndex}][color]" value="#000000" placeholder="#1877F2" />
                        </div>
                    </div>
                    
                    <div class="form-field">
                        <label>URL:</label>
                        <input type="text" name="banner_social_networks[${socialNetworkIndex}][url]" value="" placeholder="https://facebook.com/tupagina" />
                    </div>
                    
                    <div class="form-field">
                        <label>Icono:</label>
                        <input type="text" name="banner_social_networks[${socialNetworkIndex}][icon]" value="" placeholder="fab fa-facebook-f" />
                        <p class="description">Usa clases de Font Awesome (ej: fab fa-facebook-f, fab fa-instagram)</p>
                    </div>
                </div>
            `;
            
            $('#social-networks-container').append(template);
            socialNetworkIndex++;
        });
        
        // Agregar rápido todas las redes sociales comunes
        $('.add-social-networks-quick').on('click', function(e) {
            e.preventDefault();
            
            // Definir redes sociales comunes con sus colores
            var commonNetworks = [
                {name: 'Facebook', url: '', color: '#1877F2', icon: 'fab fa-facebook-f'},
                {name: 'Instagram', url: '', color: '#E4405F', icon: 'fab fa-instagram'},
                {name: 'Twitter', url: '', color: '#1DA1F2', icon: 'fab fa-twitter'},
                {name: 'YouTube', url: '', color: '#FF0000', icon: 'fab fa-youtube'},
                {name: 'LinkedIn', url: '', color: '#0A66C2', icon: 'fab fa-linkedin-in'},
                {name: 'Pinterest', url: '', color: '#E60023', icon: 'fab fa-pinterest-p'},
                {name: 'WhatsApp', url: '', color: '#25D366', icon: 'fab fa-whatsapp'}
            ];
            
            // Agregar cada red social
            commonNetworks.forEach(function(network) {
                var template = `
                    <div class="social-network-item">
                        <h4>
                            Red Social #${socialNetworkIndex + 1}
                            <a href="#" class="remove-social">Eliminar</a>
                        </h4>
                        
                        <div class="social-network-preview">
                            <div class="icon-preview" style="background-color: ${network.color};">
                                <i class="dashicons dashicons-share"></i>
                            </div>
                            <div class="text-preview">
                                ${network.name}
                            </div>
                        </div>
                        
                        <div class="social-fields-row">
                            <div class="form-field form-field-half">
                                <label>Nombre:</label>
                                <input type="text" name="banner_social_networks[${socialNetworkIndex}][name]" value="${network.name}" placeholder="Facebook" />
                            </div>
                            
                            <div class="form-field form-field-half">
                                <label>Color:</label>
                                <input type="text" name="banner_social_networks[${socialNetworkIndex}][color]" value="${network.color}" placeholder="#1877F2" />
                            </div>
                        </div>
                        
                        <div class="form-field">
                            <label>URL:</label>
                            <input type="text" name="banner_social_networks[${socialNetworkIndex}][url]" value="" placeholder="https://" />
                        </div>
                        
                        <div class="form-field">
                            <label>Icono:</label>
                            <input type="text" name="banner_social_networks[${socialNetworkIndex}][icon]" value="${network.icon}" placeholder="fab fa-facebook-f" />
                            <p class="description">Usa clases de Font Awesome (ej: fab fa-facebook-f, fab fa-instagram)</p>
                        </div>
                    </div>
                `;
                
                $('#social-networks-container').append(template);
                socialNetworkIndex++;
            });
        });
        
        // Eliminar red social
        $(document).on('click', '.remove-social', function(e) {
            e.preventDefault();
            $(this).closest('.social-network-item').remove();
        });
        
        // Funcionalidad para añadir imágenes al carrusel
        var carouselImageIndex = <?php echo !empty($carousel_images) ? count($carousel_images) : 0; ?>;
        
        $('.add-carousel-image').on('click', function(e) {
            e.preventDefault();
            
            var template = `
                <div class="social-network-item carousel-image-item">
                    <h4>
                        Imagen #${carouselImageIndex + 1}
                        <a href="#" class="remove-carousel-image">Eliminar</a>
                    </h4>
                    
                    <!-- Imagen Desktop -->
                    <div class="form-field">
                        <label>Imagen Desktop:</label>
                        <div class="image-upload-group">
                            <input type="text" name="banner_carousel_images[${carouselImageIndex}][url]" class="carousel-image-url" value="" readonly />
                            <button type="button" class="button carousel-image-upload">Seleccionar</button>
                            <button type="button" class="button carousel-image-remove" style="display:none;">×</button>
                        </div>
                        <p class="description">Imagen principal para dispositivos desktop (recomendado: 1920x1280px)</p>
                    </div>
                    
                    <div class="image-preview" style="display:none;">
                        <img src="" alt="Vista previa del carrusel desktop" />
                    </div>
                    
                    <!-- Imagen Móvil (Opcional) -->
                    <div class="form-field">
                        <label>Imagen Móvil (Opcional):</label>
                        <div class="image-upload-group">
                            <input type="text" name="banner_carousel_images[${carouselImageIndex}][mobile_url]" class="carousel-image-mobile-url" value="" readonly />
                            <button type="button" class="button carousel-image-mobile-upload">Seleccionar</button>
                            <button type="button" class="button carousel-image-mobile-remove" style="display:none;">×</button>
                        </div>
                        <p class="description">Imagen optimizada para móviles (recomendado: 800x800px). Si no se especifica, se usará la imagen desktop.</p>
                    </div>
                    
                    <div class="image-preview mobile-preview" style="display:none;">
                        <img src="" alt="Vista previa del carrusel móvil" />
                    </div>
                    
                    <!-- Imagen Desktop EN -->
                    <div class="form-field banner-translation-field">
                        <label>🇬🇧 Desktop Image (EN):</label>
                        <div class="image-upload-group">
                            <input type="text" name="banner_carousel_images[${carouselImageIndex}][url_en]" class="carousel-image-url-en" value="" readonly />
                            <button type="button" class="button carousel-image-en-upload">Select</button>
                            <button type="button" class="button carousel-image-en-remove" style="display:none;">×</button>
                        </div>
                        <p class="description">English version of the desktop image. If empty, the Spanish image will be used.</p>
                    </div>
                    
                    <div class="image-preview en-desktop-preview" style="display:none;">
                        <img src="" alt="English desktop preview" />
                    </div>
                    
                    <!-- Imagen Móvil EN -->
                    <div class="form-field banner-translation-field">
                        <label>🇬🇧 Mobile Image (EN):</label>
                        <div class="image-upload-group">
                            <input type="text" name="banner_carousel_images[${carouselImageIndex}][mobile_url_en]" class="carousel-image-mobile-url-en" value="" readonly />
                            <button type="button" class="button carousel-image-mobile-en-upload">Select</button>
                            <button type="button" class="button carousel-image-mobile-en-remove" style="display:none;">×</button>
                        </div>
                        <p class="description">English version of the mobile image. If empty, the English desktop or Spanish mobile will be used.</p>
                    </div>
                    
                    <div class="image-preview en-mobile-preview" style="display:none;">
                        <img src="" alt="English mobile preview" />
                    </div>
                    
                    <!-- Campo Orden -->
                    <div class="form-field">
                        <label>Orden de visualización:</label>
                        <input type="number" name="banner_carousel_images[${carouselImageIndex}][order]" value="${carouselImageIndex + 1}" min="1" max="100" step="1" style="width: 80px;" />
                        <p class="description">Número que define la posición del banner dentro del carrusel (1 = primero).</p>
                    </div>

                    <!-- URL del Banner completo -->
                    <div class="form-field">
                        <label>URL del Banner (Opcional):</label>
                        <input type="url" name="banner_carousel_images[${carouselImageIndex}][banner_url]" value="" placeholder="https://ejemplo.com/pagina" class="carousel-banner-url-field" />
                        <p class="description">URL a la que redirige al hacer clic en toda la imagen del banner. <strong>Independiente del botón CTA.</strong></p>
                    </div>
                    
                    <div class="form-field">
                        <label>Subtítulo:</label>
                        <input type="text" name="banner_carousel_images[${carouselImageIndex}][subtitle]" value="" placeholder="Texto breve descriptivo" />
                    </div>
                    
                    <div class="form-field banner-translation-field">
                        <label>🇬🇧 Subtitle (EN):</label>
                        <input type="text" name="banner_carousel_images[${carouselImageIndex}][subtitle_en]" value="" placeholder="Short descriptive text" />
                    </div>
                    
                    <div class="form-field">
                        <label>Descripción:</label>
                        <textarea name="banner_carousel_images[${carouselImageIndex}][description]" rows="2"></textarea>
                    </div>
                    
                    <div class="form-field banner-translation-field">
                        <label>🇬🇧 Description (EN):</label>
                        <textarea name="banner_carousel_images[${carouselImageIndex}][description_en]" rows="2"></textarea>
                    </div>
                    
                    <div class="form-field">
                        <label>Botón (CTA):</label>
                        <input type="text" name="banner_carousel_images[${carouselImageIndex}][cta]" value="" placeholder="Ej: Ver más" />
                    </div>
                    
                    <div class="form-field banner-translation-field">
                        <label>🇬🇧 Button CTA (EN):</label>
                        <input type="text" name="banner_carousel_images[${carouselImageIndex}][cta_en]" value="" placeholder="E.g: See more" />
                    </div>
                    
                    <div class="form-field">
                        <label>URL del Botón CTA (Opcional):</label>
                        <input type="url" name="banner_carousel_images[${carouselImageIndex}][link]" value="" placeholder="https://ejemplo.com/pagina" class="carousel-link-field" />
                        <p class="description">URL específica para el botón CTA del info box. <strong>Solo funciona si hay texto CTA definido.</strong></p>
                    </div>
                    
                    <div class="form-field checkbox-field">
                        <label class="checkbox-label">
                            <input type="checkbox" name="banner_carousel_images[${carouselImageIndex}][hide_info_box]" value="1" />
                            Ocultar cuadro de información
                        </label>
                        <p class="description">Solo se mostrará la imagen sin textos superpuestos.</p>
                    </div>
                </div>
            `;
            
            $('#carousel-images-container').append(template);
            carouselImageIndex++;
        });
        
        // Eliminar imagen del carrusel
        $(document).on('click', '.remove-carousel-image', function(e) {
            e.preventDefault();
            $(this).closest('.carousel-image-item').remove();
        });
        
        // Funcionalidad para seleccionar imagen del carrusel (desktop)
        $(document).on('click', '.carousel-image-upload', function(e) {
            e.preventDefault();
            
            var button = $(this);
            var image_frame;
            
            if (image_frame) {
                image_frame.open();
                return;
            }
            
            image_frame = wp.media({
                title: 'Seleccionar imagen desktop para el carrusel',
                multiple: false,
                library: {
                    type: 'image'
                }
            });
            
            image_frame.on('select', function() {
                var attachment = image_frame.state().get('selection').first().toJSON();
                button.siblings('.carousel-image-url').val(attachment.url);
                button.siblings('.carousel-image-remove').show();
                button.closest('.form-field').siblings('.image-preview').not('.mobile-preview').show().find('img').attr('src', attachment.url);
            });
            
            image_frame.open();
        });
        
        // Funcionalidad para seleccionar imagen móvil del carrusel
        $(document).on('click', '.carousel-image-mobile-upload', function(e) {
            e.preventDefault();
            
            var button = $(this);
            var image_frame;
            
            if (image_frame) {
                image_frame.open();
                return;
            }
            
            image_frame = wp.media({
                title: 'Seleccionar imagen móvil para el carrusel',
                multiple: false,
                library: {
                    type: 'image'
                }
            });
            
            image_frame.on('select', function() {
                var attachment = image_frame.state().get('selection').first().toJSON();
                button.siblings('.carousel-image-mobile-url').val(attachment.url);
                button.siblings('.carousel-image-mobile-remove').show();
                button.closest('.form-field').siblings('.mobile-preview').show().find('img').attr('src', attachment.url);
            });
            
            image_frame.open();
        });
        
        // Eliminar imagen desktop del carrusel
        $(document).on('click', '.carousel-image-remove', function(e) {
            e.preventDefault();
            $(this).siblings('.carousel-image-url').val('');
            $(this).hide();
            $(this).closest('.form-field').siblings('.image-preview').not('.mobile-preview').hide();
        });
        
        // Eliminar imagen móvil del carrusel
        $(document).on('click', '.carousel-image-mobile-remove', function(e) {
            e.preventDefault();
            $(this).siblings('.carousel-image-mobile-url').val('');
            $(this).hide();
            $(this).closest('.form-field').siblings('.mobile-preview').hide();
        });
        
        // Funcionalidad para seleccionar imagen EN desktop del carrusel
        $(document).on('click', '.carousel-image-en-upload', function(e) {
            e.preventDefault();
            var button = $(this);
            var image_frame = wp.media({ title: 'Select English desktop image', multiple: false, library: { type: 'image' } });
            image_frame.on('select', function() {
                var attachment = image_frame.state().get('selection').first().toJSON();
                button.siblings('.carousel-image-url-en').val(attachment.url);
                button.siblings('.carousel-image-en-remove').show();
                button.closest('.form-field').siblings('.en-desktop-preview').show().find('img').attr('src', attachment.url);
            });
            image_frame.open();
        });
        
        // Funcionalidad para seleccionar imagen EN móvil del carrusel
        $(document).on('click', '.carousel-image-mobile-en-upload', function(e) {
            e.preventDefault();
            var button = $(this);
            var image_frame = wp.media({ title: 'Select English mobile image', multiple: false, library: { type: 'image' } });
            image_frame.on('select', function() {
                var attachment = image_frame.state().get('selection').first().toJSON();
                button.siblings('.carousel-image-mobile-url-en').val(attachment.url);
                button.siblings('.carousel-image-mobile-en-remove').show();
                button.closest('.form-field').siblings('.en-mobile-preview').show().find('img').attr('src', attachment.url);
            });
            image_frame.open();
        });
        
        // Eliminar imagen EN desktop del carrusel
        $(document).on('click', '.carousel-image-en-remove', function(e) {
            e.preventDefault();
            $(this).siblings('.carousel-image-url-en').val('');
            $(this).hide();
            $(this).closest('.form-field').siblings('.en-desktop-preview').hide();
        });
        
        // Eliminar imagen EN móvil del carrusel
        $(document).on('click', '.carousel-image-mobile-en-remove', function(e) {
            e.preventDefault();
            $(this).siblings('.carousel-image-mobile-url-en').val('');
            $(this).hide();
            $(this).closest('.form-field').siblings('.en-mobile-preview').hide();
        });
        
        // Validación en tiempo real para campos de enlace (CTA y Banner)
        $(document).on('input blur', '.carousel-link-field, .carousel-banner-url-field', function() {
            var field = $(this);
            var value = field.val().trim();
            var isValid = true;
            
            // Si está vacío, es válido (opcional)
            if (value === '') {
                field.removeClass('invalid').removeClass('valid');
                field.siblings('.url-validation-message').remove();
                return;
            }
            
            // Permitir prefijo action: para acciones internas del frontend (ej: action:profile:digitalCard)
            if (value.indexOf('action:') === 0) {
                field.removeClass('invalid').addClass('valid');
                field.siblings('.url-validation-message').remove();
                field.after('<p class="url-validation-message" style="color: #2271b1; font-size: 12px; margin: 2px 0 0 0;">🔗 Acción interna del frontend</p>');
                return;
            }
            
            // Validar URL
            try {
                var url = new URL(value);
                if (url.protocol !== 'http:' && url.protocol !== 'https:') {
                    isValid = false;
                }
            } catch (e) {
                isValid = false;
            }
            
            // Aplicar estilos de validación
            if (isValid) {
                field.removeClass('invalid').addClass('valid');
                field.siblings('.url-validation-message').remove();
            } else {
                field.removeClass('valid').addClass('invalid');
                field.siblings('.url-validation-message').remove();
                field.after('<p class="url-validation-message" style="color: #d63638; font-size: 12px; margin: 2px 0 0 0;">⚠️ Debe ser una URL válida que comience con https:// o http://</p>');
            }
        });
        
        // Validar antes de enviar el formulario
        $('form').on('submit', function(e) {
            var hasErrors = false;
            
            $('.carousel-link-field, .carousel-banner-url-field').each(function() {
                var field = $(this);
                var value = field.val().trim();
                
                if (value !== '' && value.indexOf('action:') !== 0) {
                    try {
                        var url = new URL(value);
                        if (url.protocol !== 'http:' && url.protocol !== 'https:') {
                            hasErrors = true;
                            field.focus();
                        }
                    } catch (e) {
                        hasErrors = true;
                        field.focus();
                    }
                }
            });
            
            if (hasErrors) {
                e.preventDefault();
                alert('Por favor, corrige los enlaces antes de guardar. Todos los enlaces deben ser URLs válidas que comiencen con https:// o http://');
                return false;
            }
        });
    });
    </script>
    <?php
}
