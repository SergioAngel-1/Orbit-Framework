<?php
/**
 * API Optimization - Helpers para rutas
 * 
 * Este archivo contiene funciones auxiliares para trabajar con rutas de API,
 * identificar tipos de contenido y extraer IDs.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Identifica el tipo de contenido basado en la ruta de la API
 * 
 * @param string $route La ruta de la API
 * @return string|null El tipo de contenido identificado o null si no se puede identificar
 */
function identify_content_type_from_route($route) {
    // Registrar en el log para depuración si está habilitado
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("Identificando tipo de contenido para ruta: {$route}");
    }
    
    // Patrones comunes de rutas
    if (preg_match('#^/wc/v3/products(/\d+)?#', $route)) {
        return 'products';
    } else if (preg_match('#^/wc/v3/products/categories(/\d+)?#', $route)) {
        return 'categories';
    } else if (preg_match('#^/wp/v2/users(/\d+)?#', $route)) {
        return 'users';
    } else if (preg_match('#^/wp/v2/posts(/\d+)?#', $route)) {
        return 'posts';
    } else if (preg_match('#^/wp/v2/pages(/\d+)?#', $route)) {
        return 'pages';
    } else if (preg_match('#^/wp/v2/categories(/\d+)?#', $route)) {
        return 'categories';
    } else if (preg_match('#^/wp/v2/tags(/\d+)?#', $route)) {
        return 'tags';
    } else if (preg_match('#^/starter/v1/menu#', $route)) {
        return 'menu';
    } else if (preg_match('#^/starter/v1/home-sections#', $route)) {
        return 'homeSection';
    } else if (preg_match('#^/starter/v1/catalogs(/\d+)?$#', $route)) {
        return 'catalog';
    } else if (preg_match('#^/starter/v1/catalogs/\d+/complete-products#', $route)) {
        return 'catalog_products';
    }
    
    // Detectar otros endpoints personalizados basados en patrones comunes
    if (strpos($route, '/starter/v1/') === 0) {
        // Extraer el nombre del endpoint (todo lo que sigue a /starter/v1/ hasta el siguiente / o el final)
        if (preg_match('#^/starter/v1/([^/]+)#', $route, $matches)) {
            $endpoint_name = $matches[1];
            
            // Registrar en el log para depuración
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("Endpoint personalizado detectado: {$endpoint_name}");
            }
            
            // Mapear endpoints personalizados a tipos de contenido
            $custom_endpoints_map = [
                'menu' => 'menu',
                'featured-categories' => 'categories',
                'promotional-grid' => 'homeSection',
                'catalogs' => 'catalog',
                // Agregar aquí más mapeos según sea necesario
            ];
            
            // Verificar si es un endpoint más específico con subrutas
            if (preg_match('#^/starter/v1/referrals/code#', $route)) {
                return 'referral_code'; // Tipo específico para el código de referido
            } else if (preg_match('#^/starter/v1/referrals/stats#', $route)) {
                return 'referral_stats'; // Tipo específico para estadísticas de referidos
            }
            
            // Si el endpoint está en nuestro mapa, devolver el tipo de contenido correspondiente
            if (isset($custom_endpoints_map[$endpoint_name])) {
                return $custom_endpoints_map[$endpoint_name];
            }
            
            // Si no está en el mapa pero es un endpoint personalizado, usar el nombre como tipo
            return $endpoint_name;
        }
    }
    
    return null;
}

/**
 * Extrae el ID de la ruta si existe
 * 
 * @param string $route La ruta de la API
 * @return string|null El ID extraído o null si no se encuentra
 */
function extract_id_from_route($route) {
    if (preg_match('/\/(\d+)(?:\/|$)/', $route, $matches)) {
        return $matches[1];
    }
    return null;
}
