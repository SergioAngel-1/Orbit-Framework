<?php
/**
 * Plugin Name: Starter Home Sections
 * Description: Plugin para gestionar las secciones de productos en la página de inicio
 * Version: 1.0
 * Author: Starter
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Definir constantes del plugin
define('FIHS_PLUGIN_FILE', __FILE__);
define('FIHS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FIHS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FIHS_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('FIHS_VERSION', '1.0.0');

// Cargar archivos principales
require_once FIHS_PLUGIN_DIR . 'includes/class-starter-home-sections.php';

/**
 * Iniciar el plugin
 * 
 * @return Starter_Home_Sections
 */
function starter_home_sections() {
    return Starter_Home_Sections::get_instance();
}

// Inicializar el plugin
$starter_home_sections = starter_home_sections();