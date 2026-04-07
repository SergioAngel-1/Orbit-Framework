<?php
/**
 * Cargador principal del plugin Starter Memberships
 * 
 * Este archivo carga todos los componentes del plugin organizados
 * en carpetas según su funcionalidad.
 * 
 * NOTA: Este archivo se carga desde plugins_loaded con prioridad 20,
 * después de que WooCommerce esté disponible.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar archivos del núcleo
require_once dirname(__FILE__) . '/core/database.php';
require_once dirname(__FILE__) . '/core/helpers.php';

// Cargar sistema de productos (meta boxes para WooCommerce)
require_once dirname(__FILE__) . '/products/product-meta.php';
require_once dirname(__FILE__) . '/products/product-functions.php';

// Cargar sistema de usuarios/membresías
require_once dirname(__FILE__) . '/user/user-membership.php';
require_once dirname(__FILE__) . '/user/user-functions.php';

// Cargar integraciones
require_once dirname(__FILE__) . '/integrations/woocommerce-integration.php';
require_once dirname(__FILE__) . '/integrations/referrals-integration.php';

// Cargar administración - módulos refactorizados
require_once dirname(__FILE__) . '/admin/core/index.php';      // Menú, Dashboard, Settings, Levels, Stats
require_once dirname(__FILE__) . '/admin/admin-panel.php';      // Estilos CSS admin
require_once dirname(__FILE__) . '/admin/category-meta.php';    // Meta de categorías

// Cargar módulo de beneficios refactorizado
require_once dirname(__FILE__) . '/admin/benefits/index.php';   // Beneficios (Config, Service, Page)

// Cargar módulo de aplicación de beneficios (handlers)
require_once dirname(__FILE__) . '/benefits/index.php';         // Handlers de beneficios

// Cargar módulo legacy refactorizado (reemplaza legacy-membership-page.php)
require_once dirname(__FILE__) . '/admin/legacy/index.php';

// Cargar API REST
require_once dirname(__FILE__) . '/api/api-loader.php';

// Inicializar panel de admin (solo carga estilos)
starter_memberships_init_admin_panel();

/**
 * Invalidar caché de home-sections cuando cambia la membresía de un usuario
 * 
 * Esto es necesario porque las secciones del home se cachean por nivel de membresía.
 * Cuando un usuario cambia de nivel (activación, expiración, upgrade), el caché
 * del nivel anterior puede contener datos que ya no son válidos para ese nivel.
 * 
 * @since 1.5.0
 */
function starter_memberships_invalidate_home_sections_cache() {
    static $scheduled = false;
    
    // Debounce: agrupar múltiples invalidaciones en la misma request/cron-tick
    // La invalidación real se ejecuta UNA vez al final via shutdown hook
    if ($scheduled) {
        return;
    }
    $scheduled = true;
    
    add_action('shutdown', function() {
        do_action('starter_home_sections_changed');
        error_log('Starter Memberships: Caché de home-sections invalidado por cambio de membresía (batch)');
    });
}

// Invalidar caché cuando se activa una membresía
add_action('starter_membership_activated', 'starter_memberships_invalidate_home_sections_cache', 10, 0);

// Invalidar caché cuando expira una membresía
add_action('starter_membership_expired', 'starter_memberships_invalidate_home_sections_cache', 10, 0);

// Invalidar caché cuando se otorga membresía por referido
add_action('starter_referral_membership_granted', 'starter_memberships_invalidate_home_sections_cache', 10, 0);
