<?php

/**
 * Funciones principales del tema Starter
 * 
 * Este archivo carga todas las funcionalidades del tema a través del sistema
 * de inicialización en el directorio inc.
 */

/**
 * Incluir archivo de inicialización que carga todas las funcionalidades
 */
require_once __DIR__ . '/inc/init.php';

// Incluir el archivo de gestión de roles
require_once __DIR__ . '/inc/role-manager-functions.php';

// Incluir el archivo de configuración del Gestor de la tienda
require_once __DIR__ . '/inc/shop-manager-settings.php';

// Incluir el panel de administración de roles y permisos (modularizado)
require_once __DIR__ . '/inc/role-permissions-functions.php';

// Incluir el archivo de optimización de API
require_once __DIR__ . '/inc/api-optimization.php';

// Incluir el archivo principal de funciones CORS (CONFIGURACIÓN UNIFICADA)
require_once __DIR__ . '/inc/cors-functions.php';

// Seguridad (archivo de barril)
require_once __DIR__ . '/inc/security/index.php';

// Incluir el endpoint personalizado de autenticación
require_once __DIR__ . '/inc/custom-auth-endpoint.php';

// Incluir el endpoint de restablecimiento de contraseña
require_once __DIR__ . '/inc/password-reset-endpoint.php';

// Incluir funcion de gestión de usuarios
require_once __DIR__ . '/inc/user-management-endpoint.php';

// Incluir funcion de administración
require_once __DIR__ . '/inc/admin-functions.php';

// Incluir página de administración para aprobación automática de usuarios
require_once __DIR__ . '/inc/admin/auto-approval-page.php';

// Incluir página de administración para desbloquear usuarios
require_once __DIR__ . '/inc/admin/unblock-users-page.php';

// Incluir limpieza del perfil de usuario (ocultar secciones innecesarias)
require_once __DIR__ . '/inc/user-management/profile-cleanup.php';

// Incluir campos personalizados del perfil de usuario (teléfono, cédula, aceptaciones legales)
require_once __DIR__ . '/inc/user-management/user-profile-fields.php';

// Incluir módulo de personalización de correos electrónicos (disponible globalmente)
require_once __DIR__ . '/inc/email-customization/index.php';

// Incluir módulo de rate limiting para prevenir ataques de fuerza bruta
require_once __DIR__ . '/inc/rate-limiting.php';

/**
 * Pre-registrar script 'heartbeat' si no está registrado (WP 6.9.1+)
 * Evita PHP Notice en wp-auth-check que lo declara como dependencia.
 * Usa wp_default_scripts (prioridad 1) para ejecutarse ANTES de que WP
 * registre wp-auth-check con heartbeat como dependencia.
 */
add_action('wp_default_scripts', function ($scripts) {
    if (!isset($scripts->registered['heartbeat'])) {
        $scripts->add('heartbeat', '/wp-includes/js/heartbeat.min.js', array('jquery'), false, 1);
    }
}, 1);

// Incluir endpoint personalizado del carrito
require_once __DIR__ . '/inc/cart-endpoint.php';

/**
 * Tema headless: redirigir URLs públicas de WP al frontend React
 * 
 * Productos, páginas y archivos de WP no deben renderizarse en el dominio admin.
 * Se redirigen al dominio del frontend.
 * 
 * NOTA: Si el plugin "Headless Mode" está activo, desactivamos esta redirección
 * para evitar conflictos de doble redirección.
 */
add_action('template_redirect', function () {
    // Si Headless Mode plugin está activo, no aplicar redirección (ya lo maneja el plugin)
    if (function_exists('headless_mode_redirect') || function_exists('headless_mode_init')) {
        return;
    }

    // No redirigir en el admin, REST API, AJAX, cron ni wp-login
    if (is_admin() || defined('REST_REQUEST') || defined('DOING_AJAX') || defined('DOING_CRON') || wp_doing_ajax()) {
        return;
    }

    // Dominio del frontend (producción vs local)
    $frontend_url = defined('STARTER_FRONTEND_URL') ? STARTER_FRONTEND_URL : 'https://example.com';

    // Redirigir productos WC al catálogo del frontend
    if (is_singular('product')) {
        wp_redirect($frontend_url . '/catalogo', 301);
        exit;
    }

    // Redirigir categorías de productos
    if (is_tax('product_cat')) {
        $term = get_queried_object();
        $slug = $term ? $term->slug : '';
        wp_redirect($frontend_url . '/catalogo' . ($slug ? '/' . $slug : ''), 301);
        exit;
    }

    // Redirigir cualquier otra página pública al home del frontend
    if (!is_admin() && !is_robots() && !is_favicon()) {
        wp_redirect($frontend_url, 301);
        exit;
    }
}, 5); // Prioridad baja para que Headless Mode (prioridad 1) pueda desactivar esto
