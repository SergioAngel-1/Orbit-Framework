<?php
/**
 * Banners - Guardar Meta Boxes
 * 
 * Este archivo contiene las funciones para guardar los datos de los meta boxes
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Guardar los datos de los campos personalizados
 */
function banner_save_meta($post_id) {
    // Verificar si es un guardado automático
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    
    // Verificar el nonce
    if (!isset($_POST['starter_banner_metabox_nonce']) || !wp_verify_nonce($_POST['starter_banner_metabox_nonce'], 'starter_banner_metabox')) {
        return;
    }
    
    // Verificar permisos
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }
    
    // Guardar tipo de banner
    if (isset($_POST['banner_type'])) {
        update_post_meta($post_id, '_banner_type', sanitize_text_field($_POST['banner_type']));
    }
    
    // Guardar campos estándar
    if (isset($_POST['banner_subtitle'])) {
        update_post_meta($post_id, '_banner_subtitle', sanitize_text_field($_POST['banner_subtitle']));
    }
    
    // El campo CTA no aplica para banners de tipo intermedio
    $banner_type = sanitize_text_field($_POST['banner_type'] ?? '');
    if (isset($_POST['banner_cta']) && $banner_type !== 'middle') {
        update_post_meta($post_id, '_banner_cta', sanitize_text_field($_POST['banner_cta']));
    } elseif ($banner_type === 'middle') {
        // Limpiar el campo CTA si es banner intermedio
        delete_post_meta($post_id, '_banner_cta');
    }
    
    if (isset($_POST['banner_link'])) {
        // Permitir prefijo action: para acciones internas del frontend
        if (strpos($_POST['banner_link'], 'action:') === 0) {
            update_post_meta($post_id, '_banner_link', sanitize_text_field($_POST['banner_link']));
        } else {
            update_post_meta($post_id, '_banner_link', esc_url_raw($_POST['banner_link']));
        }
    }
    
    // Guardar imágenes
    if (isset($_POST['banner_image'])) {
        update_post_meta($post_id, '_banner_image', esc_url_raw($_POST['banner_image']));
    }
    
    if (isset($_POST['banner_image_mobile'])) {
        update_post_meta($post_id, '_banner_image_mobile', esc_url_raw($_POST['banner_image_mobile']));
    }
    
    // Guardar imágenes EN
    if (isset($_POST['banner_image_en'])) {
        update_post_meta($post_id, '_banner_image_en', esc_url_raw($_POST['banner_image_en']));
    }
    
    if (isset($_POST['banner_image_mobile_en'])) {
        update_post_meta($post_id, '_banner_image_mobile_en', esc_url_raw($_POST['banner_image_mobile_en']));
    }
    
    // Guardar orden
    if (isset($_POST['banner_order'])) {
        update_post_meta($post_id, '_banner_order', intval($_POST['banner_order']));
    }
    
    // Guardar membresía mínima del banner (para banners tipo middle)
    if (isset($_POST['banner_min_membership'])) {
        update_post_meta($post_id, '_banner_min_membership', absint($_POST['banner_min_membership']));
    }
    
    // Guardar modo de membresía (cascade o exact)
    if (isset($_POST['banner_membership_mode'])) {
        $mode = sanitize_text_field($_POST['banner_membership_mode']);
        $mode = in_array($mode, ['cascade', 'exact']) ? $mode : 'cascade';
        update_post_meta($post_id, '_banner_membership_mode', $mode);
    }
    
    // Guardar redes sociales
    if (isset($_POST['banner_social_networks']) && is_array($_POST['banner_social_networks'])) {
        $social_networks = array();
        
        foreach ($_POST['banner_social_networks'] as $network) {
            // Validar modo de membresía
            $membership_mode = isset($network['membership_mode']) ? sanitize_text_field($network['membership_mode']) : 'cascade';
            $membership_mode = in_array($membership_mode, ['cascade', 'exact']) ? $membership_mode : 'cascade';
            
            $social_networks[] = array(
                'name' => sanitize_text_field($network['name'] ?? ''),
                'username' => sanitize_text_field($network['username'] ?? ''),
                'url' => esc_url_raw($network['url'] ?? ''),
                'color' => sanitize_hex_color($network['color'] ?? '#000000'),
                'icon' => sanitize_text_field($network['icon'] ?? ''),
                'min_membership' => isset($network['min_membership']) ? absint($network['min_membership']) : 0,
                'membership_mode' => $membership_mode,
            );
        }
        
        update_post_meta($post_id, '_banner_social_networks', $social_networks);
    } else {
        delete_post_meta($post_id, '_banner_social_networks');
    }
    
    // Guardar imágenes del carrusel
    if (isset($_POST['banner_carousel_images']) && is_array($_POST['banner_carousel_images'])) {
        $carousel_images = array();
        
        foreach ($_POST['banner_carousel_images'] as $image) {
            // Solo guardar imágenes que tengan URL
            if (!empty($image['url'])) {
                // Validar y sanitizar el enlace del botón CTA
                $cta_link = '';
                if (!empty($image['link'])) {
                    // Permitir prefijo action: para acciones internas del frontend
                    if (strpos($image['link'], 'action:') === 0) {
                        $cta_link = sanitize_text_field($image['link']);
                    } else {
                        $cta_link = esc_url_raw($image['link']);
                        // Validar que sea una URL válida si se proporciona
                        if (!filter_var($cta_link, FILTER_VALIDATE_URL)) {
                            $cta_link = ''; // Si no es válida, no la guardamos
                        }
                    }
                }
                
                // Validar y sanitizar la URL del banner completo
                $banner_url = '';
                if (!empty($image['banner_url'])) {
                    // Permitir prefijo action: para acciones internas del frontend
                    if (strpos($image['banner_url'], 'action:') === 0) {
                        $banner_url = sanitize_text_field($image['banner_url']);
                    } else {
                        $banner_url = esc_url_raw($image['banner_url']);
                        // Validar que sea una URL válida si se proporciona
                        if (!filter_var($banner_url, FILTER_VALIDATE_URL)) {
                            $banner_url = ''; // Si no es válida, no la guardamos
                        }
                    }
                }
                
                // Validar y sanitizar el orden
                $order = isset($image['order']) ? intval($image['order']) : 0;
                if ($order < 1) {
                    $order = 0; // Valor por defecto si es inválido
                }
                
                // Validar y sanitizar membresía mínima y modo
                $min_membership = isset($image['min_membership']) ? absint($image['min_membership']) : 0;
                $membership_mode = isset($image['membership_mode']) ? sanitize_text_field($image['membership_mode']) : 'cascade';
                $membership_mode = in_array($membership_mode, ['cascade', 'exact']) ? $membership_mode : 'cascade';
                
                $carousel_images[] = array(
                    'url' => esc_url_raw($image['url'] ?? ''),
                    'mobile_url' => esc_url_raw($image['mobile_url'] ?? ''),
                    'url_en' => esc_url_raw($image['url_en'] ?? ''),
                    'mobile_url_en' => esc_url_raw($image['mobile_url_en'] ?? ''),
                    'order' => $order,
                    'subtitle' => sanitize_text_field($image['subtitle'] ?? ''),
                    'subtitle_en' => sanitize_text_field($image['subtitle_en'] ?? ''),
                    'description' => sanitize_textarea_field($image['description'] ?? ''),
                    'description_en' => sanitize_textarea_field($image['description_en'] ?? ''),
                    'cta' => sanitize_text_field($image['cta'] ?? ''),
                    'cta_en' => sanitize_text_field($image['cta_en'] ?? ''),
                    'link' => $cta_link,        // URL del botón CTA
                    'banner_url' => $banner_url, // URL del banner completo
                    'hide_info_box' => isset($image['hide_info_box']) ? '1' : '0',
                    'min_membership' => $min_membership, // Membresía mínima requerida
                    'membership_mode' => $membership_mode, // Modo de visibilidad (cascade/exact)
                );
            }
        }
        
        // Ordenar las imágenes por el campo order antes de guardar
        if (!empty($carousel_images)) {
            usort($carousel_images, function($a, $b) {
                $orderA = $a['order'] ?: 999; // Si no tiene orden, va al final
                $orderB = $b['order'] ?: 999;
                return $orderA <=> $orderB;
            });
        }
        
        update_post_meta($post_id, '_banner_carousel_images', $carousel_images);
    } else {
        delete_post_meta($post_id, '_banner_carousel_images');
    }
}
add_action('save_post', 'banner_save_meta');
