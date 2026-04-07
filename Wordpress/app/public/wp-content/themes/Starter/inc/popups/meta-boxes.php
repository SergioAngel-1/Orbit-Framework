<?php
/**
 * Popups - Meta Boxes
 * 
 * Este archivo contiene el registro y renderizado de los meta boxes
 * para gestionar los campos personalizados de los popups.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Añadir metabox para los campos personalizados del popup
 */
function starter_popup_add_meta_box() {
    add_meta_box(
        'popup_metabox',
        'Configuración del Popup',
        'starter_popup_metabox_callback',
        'starter_popup',
        'normal',
        'high'
    );
}
add_action('add_meta_boxes', 'starter_popup_add_meta_box');

/**
 * Callback para el metabox
 */
function starter_popup_metabox_callback($post) {
    // Obtener valores guardados
    $type = get_post_meta($post->ID, '_popup_type', true) ?: 'general';
    $active = get_post_meta($post->ID, '_popup_active', true);
    $content = get_post_meta($post->ID, '_popup_content', true);
    $image = get_post_meta($post->ID, '_popup_image', true);
    $image_mobile = get_post_meta($post->ID, '_popup_image_mobile', true);
    $display_frequency = get_post_meta($post->ID, '_popup_display_frequency', true) ?: 'once_per_session';
    $dismissible = get_post_meta($post->ID, '_popup_dismissible', true);
    $show_overlay = get_post_meta($post->ID, '_popup_show_overlay', true);
    $priority = get_post_meta($post->ID, '_popup_priority', true) ?: 10;
    $min_membership = get_post_meta($post->ID, '_popup_min_membership', true);
    $display_delay = get_post_meta($post->ID, '_popup_display_delay', true) ?: 0;
    
    // Botones
    $primary_button_text = get_post_meta($post->ID, '_popup_primary_button_text', true);
    $primary_button_action = get_post_meta($post->ID, '_popup_primary_button_action', true) ?: 'close';
    $primary_button_url = get_post_meta($post->ID, '_popup_primary_button_url', true);
    $secondary_button_text = get_post_meta($post->ID, '_popup_secondary_button_text', true);
    $secondary_button_action = get_post_meta($post->ID, '_popup_secondary_button_action', true) ?: 'close';
    
    // Valores por defecto para checkboxes
    if ($dismissible === '') $dismissible = '1';
    if ($show_overlay === '') $show_overlay = '1';
    
    // Nonce para seguridad
    wp_nonce_field('starter_popup_metabox', 'starter_popup_metabox_nonce');
    
    // Asegurar que wp.media esté disponible
    wp_enqueue_media();
    
    // Obtener tipos de popup
    $popup_types = starter_get_popup_types();
    
    ?>
    <style>
        .popup-metabox-container {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .popup-field-group {
            background: #f9f9f9;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
        }
        .popup-field-group h4 {
            margin: 0 0 15px 0;
            padding-bottom: 10px;
            border-bottom: 1px solid #e0e0e0;
            color: #1d2327;
        }
        .popup-field {
            margin-bottom: 15px;
        }
        .popup-field:last-child {
            margin-bottom: 0;
        }
        .popup-field label {
            display: block;
            font-weight: 600;
            margin-bottom: 5px;
            color: #1d2327;
        }
        .popup-field input[type="text"],
        .popup-field input[type="number"],
        .popup-field input[type="url"],
        .popup-field select,
        .popup-field textarea {
            width: 100%;
            max-width: 500px;
        }
        .popup-field textarea {
            min-height: 100px;
        }
        .popup-field .description {
            color: #666;
            font-size: 12px;
            margin-top: 5px;
        }
        .popup-image-preview {
            max-width: 300px;
            max-height: 200px;
            margin-top: 10px;
            border-radius: 4px;
            border: 1px solid #ddd;
        }
        .popup-image-buttons {
            margin-top: 10px;
        }
        .popup-image-buttons .button {
            margin-right: 5px;
        }
        .popup-type-info {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 4px;
            padding: 10px;
            margin-bottom: 15px;
        }
        .popup-type-info .icon {
            font-size: 20px;
            margin-right: 8px;
        }
        .popup-checkbox-field {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .popup-checkbox-field input[type="checkbox"] {
            margin: 0;
        }
        .popup-row {
            display: flex;
            gap: 20px;
        }
        .popup-row > .popup-field {
            flex: 1;
        }
        .conditional-field {
            display: none;
        }
        .conditional-field.visible {
            display: block;
        }
        .popup-type-details {
            background: #f0f6fc;
            border: 1px solid #0073aa;
            border-radius: 6px;
            padding: 15px;
            margin-top: 15px;
        }
        .popup-type-details h5 {
            margin: 0 0 12px 0;
            color: #0073aa;
            font-size: 14px;
        }
        .popup-type-details-section {
            margin-bottom: 12px;
        }
        .popup-type-details-section:last-child {
            margin-bottom: 0;
        }
        .popup-type-details-section strong {
            display: block;
            color: #1d2327;
            margin-bottom: 5px;
            font-size: 12px;
            text-transform: uppercase;
        }
        .popup-type-details-section ul {
            margin: 0;
            padding-left: 20px;
        }
        .popup-type-details-section li {
            font-size: 13px;
            color: #50575e;
            margin-bottom: 3px;
        }
        .popup-type-details-section li:last-child {
            margin-bottom: 0;
        }
        .popup-type-details .action-accept {
            color: #00a32a;
        }
        .popup-type-details .action-reject {
            color: #d63638;
        }
    </style>
    
    <div class="popup-metabox-container">
        <!-- Tipo de Popup -->
        <div class="popup-field-group">
            <h4>📋 Tipo de Popup</h4>
            
            <div class="popup-field">
                <label for="popup_type">Tipo</label>
                <select name="popup_type" id="popup_type">
                    <?php foreach ($popup_types as $type_key => $type_info): ?>
                        <option value="<?php echo esc_attr($type_key); ?>" <?php selected($type, $type_key); ?>>
                            <?php echo esc_html($type_info['icon'] . ' ' . $type_info['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description" id="popup_type_description">
                    <?php echo esc_html($popup_types[$type]['description'] ?? ''); ?>
                </p>
            </div>
            
            <div class="popup-type-info" id="popup_type_info">
                <span class="icon"><?php echo esc_html($popup_types[$type]['icon'] ?? '📢'); ?></span>
                <strong><?php echo esc_html($popup_types[$type]['label'] ?? 'Popup'); ?></strong>
                <?php if (!empty($popup_types[$type]['unique'])): ?>
                    <br><small>⚠️ Solo puede haber un popup de este tipo activo a la vez.</small>
                <?php endif; ?>
            </div>
            
            <!-- Detalles del tipo de popup -->
            <div class="popup-type-details" id="popup_type_details">
                <?php 
                $details = $popup_types[$type]['details'] ?? null;
                if ($details): 
                ?>
                    <h5>ℹ️ <?php echo esc_html($details['title'] ?? 'Información del Popup'); ?></h5>
                    
                    <?php if (!empty($details['conditions'])): ?>
                    <div class="popup-type-details-section">
                        <strong>📋 Condiciones para mostrar:</strong>
                        <ul>
                            <?php foreach ($details['conditions'] as $condition): ?>
                                <li><?php echo esc_html($condition); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($details['behavior'])): ?>
                    <div class="popup-type-details-section">
                        <strong>⚙️ Comportamiento:</strong>
                        <ul>
                            <?php foreach ($details['behavior'] as $behavior): ?>
                                <li><?php echo esc_html($behavior); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($details['actions'])): ?>
                    <div class="popup-type-details-section">
                        <strong>🎯 Acciones del usuario:</strong>
                        <ul>
                            <?php foreach ($details['actions'] as $action): 
                                $class = '';
                                if (strpos($action, 'ACEPTA') !== false || strpos($action, 'RENOVAR') !== false || strpos($action, 'VER') !== false || strpos($action, 'INICIAR') !== false) {
                                    $class = 'action-accept';
                                } elseif (strpos($action, 'RECHAZA') !== false) {
                                    $class = 'action-reject';
                                }
                            ?>
                                <li class="<?php echo esc_attr($class); ?>"><?php echo esc_html($action); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($details['recommended_frequency'])): ?>
                    <div class="popup-type-details-section" style="background: #fff3cd; padding: 10px; border-radius: 4px; margin-top: 10px;">
                        <strong>⚠️ Frecuencia recomendada:</strong>
                        <p style="margin: 5px 0 0 0; color: #856404; font-weight: 600;"><?php echo esc_html($details['recommended_frequency']); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($details['bonus'])): ?>
                    <div class="popup-type-details-section" style="background: #d4edda; padding: 10px; border-radius: 4px; margin-top: 10px;">
                        <strong>🎁 Beneficio:</strong>
                        <p style="margin: 5px 0 0 0; color: #155724; font-weight: 600;"><?php echo esc_html($details['bonus']); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($details['tip'])): ?>
                    <div class="popup-type-details-section" style="background: #cce5ff; padding: 10px; border-radius: 4px; margin-top: 10px;">
                        <strong>💡 Consejo:</strong>
                        <p style="margin: 5px 0 0 0; color: #004085;"><?php echo esc_html($details['tip']); ?></p>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            
            <div class="popup-field popup-checkbox-field">
                <input type="checkbox" name="popup_active" id="popup_active" value="1" <?php checked($active, '1'); ?>>
                <label for="popup_active" style="font-weight: normal;">Popup activo</label>
            </div>
        </div>
        
        <!-- Contenido -->
        <div class="popup-field-group">
            <h4>📝 Contenido</h4>
            
            <div class="popup-field">
                <label for="popup_content">Mensaje del Popup</label>
                <textarea name="popup_content" id="popup_content" rows="4"><?php echo esc_textarea($content); ?></textarea>
                <p class="description">Texto principal que se mostrará en el popup. Soporta HTML básico.</p>
            </div>
        </div>
        
        <!-- Imágenes -->
        <div class="popup-field-group">
            <h4>🖼️ Imágenes</h4>
            
            <div class="popup-row">
                <div class="popup-field">
                    <label for="popup_image">Imagen Desktop</label>
                    <input type="text" name="popup_image" id="popup_image" value="<?php echo esc_url($image); ?>" placeholder="URL de la imagen">
                    <?php if ($image): ?>
                        <img src="<?php echo esc_url($image); ?>" class="popup-image-preview" id="popup_image_preview">
                    <?php else: ?>
                        <img src="" class="popup-image-preview" id="popup_image_preview" style="display:none;">
                    <?php endif; ?>
                    <div class="popup-image-buttons">
                        <button type="button" class="button" id="popup_image_button">Seleccionar imagen</button>
                        <button type="button" class="button" id="popup_image_remove" <?php echo empty($image) ? 'style="display:none;"' : ''; ?>>Eliminar</button>
                    </div>
                </div>
                
                <div class="popup-field">
                    <label for="popup_image_mobile">Imagen Mobile (opcional)</label>
                    <input type="text" name="popup_image_mobile" id="popup_image_mobile" value="<?php echo esc_url($image_mobile); ?>" placeholder="URL de la imagen móvil">
                    <?php if ($image_mobile): ?>
                        <img src="<?php echo esc_url($image_mobile); ?>" class="popup-image-preview" id="popup_image_mobile_preview">
                    <?php else: ?>
                        <img src="" class="popup-image-preview" id="popup_image_mobile_preview" style="display:none;">
                    <?php endif; ?>
                    <div class="popup-image-buttons">
                        <button type="button" class="button" id="popup_image_mobile_button">Seleccionar imagen</button>
                        <button type="button" class="button" id="popup_image_mobile_remove" <?php echo empty($image_mobile) ? 'style="display:none;"' : ''; ?>>Eliminar</button>
                    </div>
                </div>
            </div>
            
            <div class="popup-field">
                <label for="popup_image_url">URL de destino de la imagen (opcional)</label>
                <input type="url" name="popup_image_url" id="popup_image_url" value="<?php echo esc_url(get_post_meta($post->ID, '_popup_image_url', true)); ?>" placeholder="https://... (si se define, la imagen será clickeable)">
                <p class="description">Si se define, al hacer clic en la imagen se abrirá esta URL.</p>
            </div>
        </div>
        
        <!-- Información -->
        <div class="popup-field-group">
            <h4>ℹ️ Información</h4>
            <p class="description" style="margin: 0; padding: 10px; background: #f0f0f1; border-radius: 4px;">
                Los botones y acciones del popup se controlan desde el componente del frontend según el tipo de popup seleccionado.
                La imagen es el contenido principal del popup (diseño de marca).
            </p>
        </div>
        
        <!-- Configuración de Visualización -->
        <div class="popup-field-group">
            <h4>⚙️ Configuración de Visualización</h4>
            
            <div class="popup-row">
                <div class="popup-field">
                    <label for="popup_display_frequency">Frecuencia de Visualización</label>
                    <select name="popup_display_frequency" id="popup_display_frequency">
                        <option value="always" <?php selected($display_frequency, 'always'); ?>>Siempre</option>
                        <option value="once_per_session" <?php selected($display_frequency, 'once_per_session'); ?>>Una vez por sesión</option>
                        <option value="once_per_day" <?php selected($display_frequency, 'once_per_day'); ?>>Una vez al día</option>
                        <option value="once" <?php selected($display_frequency, 'once'); ?>>Solo una vez (nunca más)</option>
                        <option value="until_criteria" <?php selected($display_frequency, 'until_criteria'); ?>>Hasta que se cumpla criterio</option>
                    </select>
                    <p class="description" id="frequency_description"></p>
                </div>
                
                <div class="popup-field">
                    <label for="popup_priority">Prioridad</label>
                    <input type="number" name="popup_priority" id="popup_priority" value="<?php echo esc_attr($priority); ?>" min="1" max="100">
                    <p class="description">Menor número = mayor prioridad. Si hay varios popups, se muestra el de mayor prioridad primero.</p>
                </div>
            </div>
            
            <div class="popup-row">
                <div class="popup-field">
                    <label for="popup_display_delay">Retraso antes de mostrar (segundos)</label>
                    <input type="number" name="popup_display_delay" id="popup_display_delay" value="<?php echo esc_attr($display_delay); ?>" min="0" max="300" step="1">
                    <p class="description">Tiempo en segundos que debe pasar antes de mostrar el popup. Útil para no interrumpir al usuario inmediatamente.</p>
                </div>
            </div>
            
            <div class="popup-row">
                <div class="popup-field popup-checkbox-field">
                    <input type="checkbox" name="popup_dismissible" id="popup_dismissible" value="1" <?php checked($dismissible, '1'); ?>>
                    <label for="popup_dismissible" style="font-weight: normal;">Permitir cerrar con X o click fuera</label>
                </div>
                
                <div class="popup-field popup-checkbox-field">
                    <input type="checkbox" name="popup_show_overlay" id="popup_show_overlay" value="1" <?php checked($show_overlay, '1'); ?>>
                    <label for="popup_show_overlay" style="font-weight: normal;">Mostrar overlay oscuro de fondo</label>
                </div>
            </div>
            
            <!-- Campo condicional para membresía mínima (solo para tipo general) -->
            <div class="popup-field conditional-field <?php echo $type === 'general' ? 'visible' : ''; ?>" id="min_membership_field">
                <label for="popup_min_membership">Membresía Mínima Requerida</label>
                <select name="popup_min_membership" id="popup_min_membership">
                    <option value="0" <?php selected($min_membership, '0'); ?>>Todos los usuarios</option>
                    <option value="1" <?php selected($min_membership, '1'); ?>>🥉 Zanahoria Bronce o superior</option>
                    <option value="2" <?php selected($min_membership, '2'); ?>>🥈 Zanahoria Plateada o superior</option>
                    <option value="3" <?php selected($min_membership, '3'); ?>>🥇 Zanahoria Dorada o superior</option>
                    <option value="4" <?php selected($min_membership, '4'); ?>>💎 Zanahoria Diamante</option>
                </select>
                <p class="description">Solo usuarios con este nivel de membresía o superior verán el popup.</p>
            </div>
        </div>
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        var popupTypes = <?php echo json_encode($popup_types); ?>;
        
        // Función para generar HTML de detalles del tipo
        function generateDetailsHtml(details) {
            if (!details) return '';
            
            var html = '<h5>ℹ️ ' + (details.title || 'Información del Popup') + '</h5>';
            
            if (details.conditions && details.conditions.length > 0) {
                html += '<div class="popup-type-details-section">';
                html += '<strong>📋 Condiciones para mostrar:</strong>';
                html += '<ul>';
                details.conditions.forEach(function(condition) {
                    html += '<li>' + condition + '</li>';
                });
                html += '</ul></div>';
            }
            
            if (details.behavior && details.behavior.length > 0) {
                html += '<div class="popup-type-details-section">';
                html += '<strong>⚙️ Comportamiento:</strong>';
                html += '<ul>';
                details.behavior.forEach(function(behavior) {
                    html += '<li>' + behavior + '</li>';
                });
                html += '</ul></div>';
            }
            
            if (details.actions && details.actions.length > 0) {
                html += '<div class="popup-type-details-section">';
                html += '<strong>🎯 Acciones del usuario:</strong>';
                html += '<ul>';
                details.actions.forEach(function(action) {
                    var cssClass = '';
                    if (action.indexOf('ACEPTA') !== -1 || action.indexOf('RENOVAR') !== -1 || action.indexOf('VER') !== -1 || action.indexOf('INICIAR') !== -1) {
                        cssClass = 'action-accept';
                    } else if (action.indexOf('RECHAZA') !== -1) {
                        cssClass = 'action-reject';
                    }
                    html += '<li class="' + cssClass + '">' + action + '</li>';
                });
                html += '</ul></div>';
            }
            
            // Frecuencia recomendada
            if (details.recommended_frequency) {
                html += '<div class="popup-type-details-section" style="background: #fff3cd; padding: 10px; border-radius: 4px; margin-top: 10px;">';
                html += '<strong>⚠️ Frecuencia recomendada:</strong>';
                html += '<p style="margin: 5px 0 0 0; color: #856404; font-weight: 600;">' + details.recommended_frequency + '</p>';
                html += '</div>';
            }
            
            // Bonus/Beneficio
            if (details.bonus) {
                html += '<div class="popup-type-details-section" style="background: #d4edda; padding: 10px; border-radius: 4px; margin-top: 10px;">';
                html += '<strong>🎁 Beneficio:</strong>';
                html += '<p style="margin: 5px 0 0 0; color: #155724; font-weight: 600;">' + details.bonus + '</p>';
                html += '</div>';
            }
            
            // Consejo/Tip
            if (details.tip) {
                html += '<div class="popup-type-details-section" style="background: #cce5ff; padding: 10px; border-radius: 4px; margin-top: 10px;">';
                html += '<strong>💡 Consejo:</strong>';
                html += '<p style="margin: 5px 0 0 0; color: #004085;">' + details.tip + '</p>';
                html += '</div>';
            }
            
            return html;
        }
        
        // Actualizar descripción, info y detalles al cambiar tipo
        $('#popup_type').on('change', function() {
            var type = $(this).val();
            var typeInfo = popupTypes[type];
            
            $('#popup_type_description').text(typeInfo.description);
            
            var infoHtml = '<span class="icon">' + typeInfo.icon + '</span> <strong>' + typeInfo.label + '</strong>';
            if (typeInfo.unique) {
                infoHtml += '<br><small>⚠️ Solo puede haber un popup de este tipo activo a la vez.</small>';
            }
            $('#popup_type_info').html(infoHtml);
            
            // Actualizar detalles del tipo
            var detailsHtml = generateDetailsHtml(typeInfo.details);
            $('#popup_type_details').html(detailsHtml);
            
            // Mostrar/ocultar campo de membresía mínima
            if (type === 'general') {
                $('#min_membership_field').addClass('visible');
            } else {
                $('#min_membership_field').removeClass('visible');
            }
        });
        
        // Criterios por tipo de popup para "until_criteria"
        var criteriaDescriptions = {
            'membership_legacy': 'Se mostrará hasta que el usuario acepte o rechace la membresía por antigüedad.',
            'membership_expiration': 'Se mostrará hasta que el usuario renueve su membresía o esta expire.',
            'membership_expired': 'Se mostrará hasta que el usuario renueve su membresía.',
            'referral_bonus': 'Se mostrará hasta que el usuario vea la notificación de bonificación.',
            'login_prompt': 'Se mostrará hasta que el usuario inicie sesión o se registre.',
            'general': 'Se mostrará según la frecuencia seleccionada.'
        };
        
        // Actualizar descripción de frecuencia
        function updateFrequencyDescription() {
            var frequency = $('#popup_display_frequency').val();
            var type = $('#popup_type').val();
            var description = '';
            
            if (frequency === 'until_criteria') {
                description = criteriaDescriptions[type] || 'El criterio depende del tipo de popup.';
            }
            
            $('#frequency_description').text(description);
        }
        
        $('#popup_display_frequency, #popup_type').on('change', updateFrequencyDescription);
        updateFrequencyDescription(); // Ejecutar al cargar
        
        // Media uploader para imagen desktop
        var mediaUploader;
        $('#popup_image_button').on('click', function(e) {
            e.preventDefault();
            
            if (mediaUploader) {
                mediaUploader.open();
                return;
            }
            
            mediaUploader = wp.media({
                title: 'Seleccionar imagen del popup',
                button: { text: 'Usar esta imagen' },
                multiple: false
            });
            
            mediaUploader.on('select', function() {
                var attachment = mediaUploader.state().get('selection').first().toJSON();
                $('#popup_image').val(attachment.url);
                $('#popup_image_preview').attr('src', attachment.url).show();
                $('#popup_image_remove').show();
            });
            
            mediaUploader.open();
        });
        
        $('#popup_image_remove').on('click', function(e) {
            e.preventDefault();
            $('#popup_image').val('');
            $('#popup_image_preview').hide();
            $(this).hide();
        });
        
        // Media uploader para imagen mobile
        var mediaUploaderMobile;
        $('#popup_image_mobile_button').on('click', function(e) {
            e.preventDefault();
            
            if (mediaUploaderMobile) {
                mediaUploaderMobile.open();
                return;
            }
            
            mediaUploaderMobile = wp.media({
                title: 'Seleccionar imagen móvil del popup',
                button: { text: 'Usar esta imagen' },
                multiple: false
            });
            
            mediaUploaderMobile.on('select', function() {
                var attachment = mediaUploaderMobile.state().get('selection').first().toJSON();
                $('#popup_image_mobile').val(attachment.url);
                $('#popup_image_mobile_preview').attr('src', attachment.url).show();
                $('#popup_image_mobile_remove').show();
            });
            
            mediaUploaderMobile.open();
        });
        
        $('#popup_image_mobile_remove').on('click', function(e) {
            e.preventDefault();
            $('#popup_image_mobile').val('');
            $('#popup_image_mobile_preview').hide();
            $(this).hide();
        });
    });
    </script>
    <?php
}

/**
 * Guardar los campos del metabox
 */
function starter_popup_save_meta_box($post_id) {
    // Verificar nonce
    if (!isset($_POST['starter_popup_metabox_nonce']) || 
        !wp_verify_nonce($_POST['starter_popup_metabox_nonce'], 'starter_popup_metabox')) {
        return;
    }
    
    // Verificar autosave
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    
    // Verificar permisos
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }
    
    // Guardar campos
    $fields = array(
        '_popup_type' => 'sanitize_text_field',
        '_popup_active' => 'sanitize_text_field',
        '_popup_content' => 'wp_kses_post',
        '_popup_image' => 'esc_url_raw',
        '_popup_image_mobile' => 'esc_url_raw',
        '_popup_image_url' => 'esc_url_raw',
        '_popup_display_frequency' => 'sanitize_text_field',
        '_popup_priority' => 'absint',
        '_popup_display_delay' => 'absint',
        '_popup_min_membership' => 'absint',
        '_popup_primary_button_text' => 'sanitize_text_field',
        '_popup_primary_button_action' => 'sanitize_text_field',
        '_popup_primary_button_url' => 'esc_url_raw',
        '_popup_secondary_button_text' => 'sanitize_text_field',
        '_popup_secondary_button_action' => 'sanitize_text_field',
    );
    
    foreach ($fields as $meta_key => $sanitize_callback) {
        $field_name = str_replace('_popup_', 'popup_', $meta_key);
        
        if (isset($_POST[$field_name])) {
            $value = call_user_func($sanitize_callback, $_POST[$field_name]);
            update_post_meta($post_id, $meta_key, $value);
        } else {
            // Para checkboxes, guardar '0' si no está marcado
            if (in_array($meta_key, array('_popup_active', '_popup_dismissible', '_popup_show_overlay'))) {
                update_post_meta($post_id, $meta_key, '0');
            }
        }
    }
    
    // Manejar checkboxes específicamente
    update_post_meta($post_id, '_popup_dismissible', isset($_POST['popup_dismissible']) ? '1' : '0');
    update_post_meta($post_id, '_popup_show_overlay', isset($_POST['popup_show_overlay']) ? '1' : '0');
    update_post_meta($post_id, '_popup_active', isset($_POST['popup_active']) ? '1' : '0');
}
add_action('save_post_starter_popup', 'starter_popup_save_meta_box');
