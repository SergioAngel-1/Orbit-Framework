<?php
/**
 * Módulo de Aplicación de Beneficios de Membresías
 * 
 * Este módulo gestiona la APLICACIÓN de beneficios (hooks, filtros, lógica de negocio).
 * Es diferente al módulo admin/benefits/ que gestiona la CONFIGURACIÓN.
 * 
 * Estructura:
 * - interface-benefit-handler.php: Contrato para todos los handlers
 * - class-benefit-handler-base.php: Implementación base común
 * - class-benefit-registry.php: Registro central de handlers
 * - handlers/: Un archivo por cada beneficio
 * 
 * @package Starter_Memberships
 * @subpackage Benefits
 * @since 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Definir constante del directorio del módulo
if (!defined('STARTER_BENEFITS_DIR')) {
    define('STARTER_BENEFITS_DIR', __DIR__);
}

/**
 * Cargar archivos core del módulo
 * 
 * IMPORTANTE: Se ejecuta inmediatamente al incluir este archivo,
 * NO en un hook, para que las clases estén disponibles cuando
 * los handlers intenten registrarse.
 */
function starter_benefits_load_core() {
    require_once STARTER_BENEFITS_DIR . '/interface-benefit-handler.php';
    require_once STARTER_BENEFITS_DIR . '/class-benefit-handler-base.php';
    require_once STARTER_BENEFITS_DIR . '/class-benefit-registry.php';
    
    // Cargar API REST
    if (is_dir(STARTER_BENEFITS_DIR . '/api')) {
        require_once STARTER_BENEFITS_DIR . '/api/class-benefits-api.php';
    }
}

// CRÍTICO: Cargar clases core INMEDIATAMENTE para que estén disponibles
// cuando los handlers se registren
starter_benefits_load_core();

/**
 * Cargar todos los handlers de beneficios
 * 
 * NOTA: Los handlers se auto-registran al ser incluidos gracias al
 * código al final de cada archivo que llama a starter_benefit_registry()->register()
 */
function starter_benefits_load_handlers() {
    static $loaded = false;
    
    // Evitar cargar múltiples veces
    if ($loaded) {
        return;
    }
    $loaded = true;
    
    $handlers_dir = STARTER_BENEFITS_DIR . '/handlers';
    
    if (!is_dir($handlers_dir)) {
        return;
    }
    
    // Cargar todos los archivos de handlers
    $handler_files = glob($handlers_dir . '/class-*-handler.php');
    
    foreach ($handler_files as $file) {
        if (is_readable($file)) {
            require_once $file;
        }
    }
}

// CRÍTICO: Cargar handlers INMEDIATAMENTE después de las clases core
// Esto permite que se auto-registren sin depender de hooks
starter_benefits_load_handlers();

/**
 * Registrar todos los handlers en el registry
 * Los handlers se auto-registran en su archivo, pero aquí podemos
 * hacer registro manual si es necesario
 */
function starter_benefits_register_handlers() {
    $registry = starter_benefit_registry();
    
    // Los handlers se registran automáticamente al ser instanciados
    // en sus respectivos archivos. Aquí solo verificamos.
    
    /**
     * Hook para que otros plugins/temas registren handlers adicionales
     * 
     * @param Starter_Benefit_Registry $registry
     */
    do_action('starter_register_benefit_handlers', $registry);
}

/**
 * Inicializar el módulo de beneficios (fase final)
 * 
 * Esta función se ejecuta después de que todo esté cargado para:
 * 1. Permitir que otros plugins registren handlers adicionales
 * 2. Registrar los hooks de WooCommerce de todos los handlers
 * 
 * NOTA: Las clases core y handlers ya están cargados y registrados
 * al momento de incluir este archivo (líneas 48 y 83).
 */
function starter_benefits_init() {
    // Permitir que otros plugins registren handlers adicionales
    starter_benefits_register_handlers();
    
    // Registrar hooks de WooCommerce de todos los handlers
    $registry = starter_benefit_registry();
    $registry->register_all_hooks();
    
    /**
     * Hook para indicar que el módulo de beneficios está listo
     */
    do_action('starter_benefits_ready');
}

// Ejecutar fase final en plugins_loaded con prioridad 26
// (después de WooCommerce para que los hooks de WC funcionen)
add_action('plugins_loaded', 'starter_benefits_init', 26);

/**
 * Limpiar caché estático de handlers al inicio de cada request REST API
 * 
 * IMPORTANTE: En servidores con PHP-FPM o workers persistentes, las variables
 * estáticas pueden persistir entre requests de diferentes usuarios.
 * Esto puede causar que el caché de configuración de un usuario "contamine"
 * las respuestas de otro usuario si comparten el mismo worker PHP.
 * 
 * Esta función se ejecuta al inicio de cada request REST API para garantizar
 * que cada petición comience con caché limpio.
 * 
 * @since 1.5.0
 */
function starter_benefits_clear_static_cache_on_request() {
    // Solo limpiar si la clase existe (ya fue cargada)
    if (class_exists('Starter_Benefit_Handler_Base')) {
        Starter_Benefit_Handler_Base::clear_cache();
    }
}

// Limpiar caché al inicio de requests REST API
add_action('rest_api_init', 'starter_benefits_clear_static_cache_on_request', 1);

// También limpiar cuando cambia el usuario actual (switch_to_user, wp_set_current_user)
add_action('set_current_user', 'starter_benefits_clear_static_cache_on_request', 1);

// =========================================================================
// FUNCIONES HELPER GLOBALES
// =========================================================================

/**
 * Verificar si un usuario tiene un beneficio específico
 * 
 * @param int $user_id
 * @param string $benefit_key
 * @return bool
 */
function starter_user_has_benefit_handler(int $user_id, string $benefit_key): bool {
    return starter_benefit_registry()->user_has_benefit($user_id, $benefit_key);
}

/**
 * Aplicar un beneficio a un contexto
 * 
 * @param string $benefit_key
 * @param int $user_id
 * @param array $context
 * @return mixed
 */
function starter_apply_benefit(string $benefit_key, int $user_id, array $context = []) {
    return starter_benefit_registry()->apply_benefit($benefit_key, $user_id, $context);
}

/**
 * Obtener todos los beneficios activos de un usuario (via handlers)
 * 
 * @param int $user_id
 * @return array
 */
function starter_get_user_active_benefits_from_handlers(int $user_id): array {
    return starter_benefit_registry()->get_active_benefits_for_user($user_id);
}

/**
 * Obtener un handler específico
 * 
 * @param string $benefit_key
 * @return Starter_Benefit_Handler_Interface|null
 */
function starter_get_benefit_handler(string $benefit_key): ?Starter_Benefit_Handler_Interface {
    return starter_benefit_registry()->get($benefit_key);
}
