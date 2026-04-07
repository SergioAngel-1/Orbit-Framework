<?php
/**
 * Módulo de Beneficios de Membresías
 * 
 * Este archivo carga todos los componentes del módulo de beneficios.
 * 
 * Estructura modular:
 * - class-benefits-config.php: Definición de tipos de beneficios y defaults
 * - class-benefits-service.php: Lógica de negocio (guardar, obtener, verificar)
 * - class-benefits-page.php: Renderizado UI de la página de admin
 * 
 * @package Starter_Memberships
 * @subpackage Admin/Benefits
 * @since 1.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Cargar clases del módulo
require_once __DIR__ . '/class-benefits-config.php';
require_once __DIR__ . '/class-benefits-service.php';
require_once __DIR__ . '/class-benefits-page.php';

/**
 * Inicializar módulo de beneficios
 */
function starter_benefits_module_init() {
    // Registrar handler para restaurar predeterminados
    add_action('admin_init', [Starter_Benefits_Page::class, 'handle_reset_request']);
}
add_action('init', 'starter_benefits_module_init');

// =========================================================================
// FUNCIONES WRAPPER PARA COMPATIBILIDAD
// =========================================================================

/**
 * Obtener tipos de beneficios disponibles (compatibilidad)
 * 
 * @return array
 */
function starter_get_benefit_types() {
    return Starter_Benefits_Config::get_benefit_types();
}

/**
 * Obtener beneficios predeterminados por nivel (compatibilidad)
 * 
 * @return array
 */
function starter_get_default_level_benefits() {
    return Starter_Benefits_Config::get_default_level_benefits();
}

/**
 * Obtener beneficios configurados para un nivel (compatibilidad)
 * 
 * @param int $level
 * @return array
 */
function starter_get_level_benefits($level) {
    return Starter_Benefits_Service::get_level_benefits($level);
}

/**
 * Guardar beneficios para un nivel (compatibilidad)
 * 
 * @param int $level
 * @param array $benefits
 */
function starter_save_level_benefits($level, $benefits) {
    Starter_Benefits_Service::save_level_benefits($level, $benefits);
}

/**
 * Verificar si un usuario tiene un beneficio específico (compatibilidad)
 * 
 * @param int $user_id
 * @param string $benefit_key
 * @return bool
 */
function starter_user_has_benefit($user_id, $benefit_key) {
    return Starter_Benefits_Service::user_has_benefit($user_id, $benefit_key);
}

/**
 * Obtener valor de un beneficio para un usuario (compatibilidad)
 * 
 * @param int $user_id
 * @param string $benefit_key
 * @param string|null $field
 * @return mixed
 */
function starter_get_user_benefit_value($user_id, $benefit_key, $field = null) {
    return Starter_Benefits_Service::get_user_benefit_value($user_id, $benefit_key, $field);
}

/**
 * Obtener todos los beneficios activos de un usuario (compatibilidad)
 * 
 * @param int $user_id
 * @return array
 */
function starter_get_user_active_benefits($user_id) {
    return Starter_Benefits_Service::get_user_active_benefits($user_id);
}

/**
 * Formatear beneficios para mostrar al usuario (compatibilidad)
 * 
 * @param int $level
 * @return array
 */
function starter_format_benefits_for_display($level) {
    return Starter_Benefits_Service::format_benefits_for_display($level);
}

/**
 * Renderizar página de beneficios (compatibilidad)
 */
function starter_memberships_benefits_page() {
    Starter_Benefits_Page::render();
}
