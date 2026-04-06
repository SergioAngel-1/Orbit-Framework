<?php
/**
 * Banners - Funciones Helper
 * 
 * Funciones auxiliares para el sistema de banners
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Procesar y formatear un banner para la API
 * 
 * @param WP_Post $banner El post del banner
 * @param int $user_membership_level Nivel de membresía del usuario
 * @return array|null Array con datos del banner o null si no tiene acceso
 */
function starter_process_banner_for_api($banner, $user_membership_level = 0, $lang = 'es') {
    // Obtener tipo de banner
    $banner_type = get_post_meta($banner->ID, '_banner_type', true);
    
    // Filtrado de membresía para banners tipo 'middle'
    if ($banner_type === 'middle') {
        $min_membership = get_post_meta($banner->ID, '_banner_min_membership', true);
        $min_membership = $min_membership !== '' ? intval($min_membership) : 0;
        $membership_mode = get_post_meta($banner->ID, '_banner_membership_mode', true);
        $membership_mode = $membership_mode ?: 'cascade';
        
        // Verificar acceso según el modo
        $can_view = false;
        
        if ($min_membership === 0) {
            // Nivel público: todos pueden ver
            $can_view = true;
        } elseif ($membership_mode === 'exact') {
            // Modo exacto: solo el nivel específico puede ver
            $can_view = ($user_membership_level === $min_membership);
        } else {
            // Modo cascada: este nivel y superiores
            $can_view = ($user_membership_level >= $min_membership);
        }
        
        if (!$can_view) {
            return null; // Usuario no tiene acceso a este banner
        }
    }
    
    // El filtrado de membresía para carruseles se hace por imagen individual más abajo
    
    // Obtener la URL de la imagen destacada (thumbnail)
    $featured_image_url = '';
    
    if (has_post_thumbnail($banner->ID)) {
        $featured_image_url = get_the_post_thumbnail_url($banner->ID, 'full');
    }
    
    // Usar los campos personalizados si están definidos, o la imagen destacada como respaldo
    $image = get_post_meta($banner->ID, '_banner_image', true);
    $image_mobile = get_post_meta($banner->ID, '_banner_image_mobile', true);
    
    // Si no hay imagen en el campo personalizado, usar la imagen destacada
    if (empty($image) && !empty($featured_image_url)) {
        $image = $featured_image_url;
    }
    
    // Si no hay imagen móvil, usar la imagen normal o la destacada
    if (empty($image_mobile)) {
        $image_mobile = $image;
    }
    
    // Swap images for English if available
    if ($lang !== 'es') {
        $image_en = get_post_meta($banner->ID, '_banner_image_en', true);
        $image_mobile_en = get_post_meta($banner->ID, '_banner_image_mobile_en', true);
        
        if (!empty($image_en)) {
            $image = $image_en;
        }
        if (!empty($image_mobile_en)) {
            $image_mobile = $image_mobile_en;
        } elseif (!empty($image_en)) {
            // If no mobile EN but desktop EN exists, use desktop EN for mobile too
            $image_mobile = $image_en;
        }
    }
    
    // Obtener imágenes del carrusel si existen
    $carousel_images = get_post_meta($banner->ID, '_banner_carousel_images', true);
    
    // Verificar que las imágenes del carrusel tengan todas las propiedades necesarias
    if (!empty($carousel_images) && is_array($carousel_images)) {
        foreach ($carousel_images as $key => $img) {
            // Verificar membresía de cada imagen del carrusel
            $img_min_membership = isset($img['min_membership']) ? intval($img['min_membership']) : 0;
            $img_membership_mode = isset($img['membership_mode']) ? $img['membership_mode'] : 'cascade';
            
            // Verificar acceso según el modo
            $can_view = false;
            
            if ($img_min_membership === 0) {
                // Nivel público: todos pueden ver
                $can_view = true;
            } elseif ($img_membership_mode === 'exact') {
                // Modo exacto: solo el nivel específico puede ver
                $can_view = ($user_membership_level === $img_min_membership);
            } else {
                // Modo cascada: este nivel y superiores
                $can_view = ($user_membership_level >= $img_min_membership);
            }
            
            if (!$can_view) {
                unset($carousel_images[$key]);
                continue; // Saltar al siguiente item
            }
            
            // Asegurar que todas las propiedades estén definidas
            if (!isset($img['description'])) {
                $carousel_images[$key]['description'] = '';
            }
            if (!isset($img['subtitle'])) {
                $carousel_images[$key]['subtitle'] = '';
            }
            if (!isset($img['cta'])) {
                $carousel_images[$key]['cta'] = '';
            }
            if (!isset($img['mobile_url'])) {
                $carousel_images[$key]['mobile_url'] = '';
            }
            if (!isset($img['order'])) {
                $carousel_images[$key]['order'] = 0;
            }
            if (!isset($img['link'])) {
                $carousel_images[$key]['link'] = '';
            }
            if (!isset($img['banner_url'])) {
                $carousel_images[$key]['banner_url'] = '';
            }
            
            // Agregar la propiedad hideInfoBox
            $carousel_images[$key]['hideInfoBox'] = !empty($img['hide_info_box']);
            
            // Agregar información de membresía de la imagen (estandarizado)
            // Siempre incluir min_membership_level para consistencia
            $carousel_images[$key]['min_membership_level'] = $img_min_membership;
            
            if ($img_min_membership > 0 && class_exists('Starter_Memberships')) {
                $level_info = Starter_Memberships::get_membership_level($img_min_membership);
                $carousel_images[$key]['min_membership_info'] = array(
                    'level' => $img_min_membership,
                    'name' => $level_info['name'],
                    'icon' => $level_info['icon'],
                    'color' => $level_info['color'],
                    'mode' => $img_membership_mode
                );
            }
            
            // Swap carousel images and text for English if available
            if ($lang !== 'es') {
                if (!empty($img['url_en'])) {
                    $carousel_images[$key]['url'] = $img['url_en'];
                }
                if (!empty($img['mobile_url_en'])) {
                    $carousel_images[$key]['mobile_url'] = $img['mobile_url_en'];
                } elseif (!empty($img['url_en'])) {
                    // If no mobile EN but desktop EN exists, use desktop EN for mobile
                    $carousel_images[$key]['mobile_url'] = $img['url_en'];
                }
                if (!empty($img['subtitle_en'])) {
                    $carousel_images[$key]['subtitle'] = $img['subtitle_en'];
                }
                if (!empty($img['description_en'])) {
                    $carousel_images[$key]['description'] = $img['description_en'];
                }
                if (!empty($img['cta_en'])) {
                    $carousel_images[$key]['cta'] = $img['cta_en'];
                }
            }
            
            // Validar que la URL de la imagen exista
            if (empty($carousel_images[$key]['url'])) {
                // Eliminar imágenes sin URL
                unset($carousel_images[$key]);
            }
        }
        
        // Reindexar el array si se eliminaron elementos
        $carousel_images = array_values($carousel_images);
        
        // Ordenar las imágenes por el campo order
        if (!empty($carousel_images)) {
            usort($carousel_images, function($a, $b) {
                $orderA = $a['order'] ?: 999;
                $orderB = $b['order'] ?: 999;
                return $orderA <=> $orderB;
            });
        }
    }
    
    // Filtrar redes sociales por membresía
    $social_networks = get_post_meta($banner->ID, '_banner_social_networks', true);
    
    if (!empty($social_networks) && is_array($social_networks)) {
        foreach ($social_networks as $key => $network) {
            $net_min_membership = isset($network['min_membership']) ? intval($network['min_membership']) : 0;
            $net_membership_mode = isset($network['membership_mode']) ? $network['membership_mode'] : 'cascade';
            
            // Verificar acceso según el modo
            $can_view = false;
            
            if ($net_min_membership === 0) {
                // Nivel público: todos pueden ver
                $can_view = true;
            } elseif ($net_membership_mode === 'exact') {
                // Modo exacto: solo el nivel específico puede ver
                $can_view = ($user_membership_level === $net_min_membership);
            } else {
                // Modo cascada: este nivel y superiores
                $can_view = ($user_membership_level >= $net_min_membership);
            }
            
            if (!$can_view) {
                unset($social_networks[$key]);
                continue;
            }
            
            // Agregar información de membresía si aplica (estandarizado)
            $social_networks[$key]['min_membership_level'] = $net_min_membership;
            
            if ($net_min_membership > 0 && class_exists('Starter_Memberships')) {
                $level_info = Starter_Memberships::get_membership_level($net_min_membership);
                $social_networks[$key]['min_membership_info'] = array(
                    'level' => $net_min_membership,
                    'name' => $level_info['name'],
                    'icon' => $level_info['icon'],
                    'color' => $level_info['color'],
                    'mode' => $net_membership_mode
                );
            }
        }
        
        // Reindexar el array
        $social_networks = array_values($social_networks);
    }
    
    return array(
        'id' => $banner->ID,
        'title' => $banner->post_title,
        'subtitle' => get_post_meta($banner->ID, '_banner_subtitle', true),
        'description' => get_post_meta($banner->ID, '_banner_description', true) ?: '',
        'cta' => get_post_meta($banner->ID, '_banner_cta', true),
        'link' => get_post_meta($banner->ID, '_banner_link', true),
        'image' => $image,
        'imageMobile' => $image_mobile,
        'order' => (int) get_post_meta($banner->ID, '_banner_order', true),
        'type' => $banner_type,
        'socialNetworks' => $social_networks,
        'carouselImages' => $carousel_images,
    );
}

/**
 * Obtener nivel de membresía del usuario actual
 * CRÍTICO: Usa verificación JWT para evitar problemas con cookies de sesión
 * 
 * @return int Nivel de membresía (0 si no está autenticado o no tiene membresía)
 */
function starter_get_current_user_membership_level() {
    // Usar helper JWT si está disponible (resuelve problema de cookies persistentes)
    if (function_exists('starter_get_jwt_user_membership_level')) {
        return starter_get_jwt_user_membership_level();
    }
    
    // Fallback al método tradicional
    $user_id = get_current_user_id();
    
    if (!$user_id) {
        return 0;
    }
    
    if (function_exists('starter_get_user_membership_level')) {
        return starter_get_user_membership_level($user_id);
    }
    
    return 0;
}
