<?php
/**
 * Plugin Name: Starter Referrals & Points
 * Description: Sistema integral de referidos y puntos para WooCommerce
 * Version: 1.0
 * Author: Starter
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Asegurarse de que WP_REST_Response esté disponible
if (!class_exists('WP_REST_Response')) {
    require_once ABSPATH . 'wp-includes/rest-api/class-wp-rest-response.php';
}

// Definir constantes
define('STARTER_RP_DIR', plugin_dir_path(__FILE__));
define('STARTER_RP_URL', plugin_dir_url(__FILE__));
define('STARTER_RP_VERSION', '1.0.0');

/**
 * Log condicional: solo escribe al log cuando WP_DEBUG está activo.
 * Reemplaza todos los error_log() del plugin para evitar llenar logs en producción.
 *
 * @param string $message Mensaje a loguear
 */
function starter_rp_log($message) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log($message);
    }
}

// Incluir archivo cargador principal
require_once STARTER_RP_DIR . 'includes/loader.php';

// Registrar scripts y estilos
add_action('admin_enqueue_scripts', 'starter_rp_admin_scripts');
add_action('wp_enqueue_scripts', 'starter_rp_frontend_scripts');

/**
 * Cargar scripts y estilos para el admin
 */
function starter_rp_admin_scripts() {
    $screen = get_current_screen();
    
    // Cargar solo en páginas del plugin
    if (strpos($screen->id, 'starter-rp') !== false) {
        wp_enqueue_style('starter-rp-admin', STARTER_RP_URL . 'assets/css/admin.css', [], STARTER_RP_VERSION);
        wp_enqueue_script('starter-rp-admin', STARTER_RP_URL . 'assets/js/admin.js', ['jquery'], STARTER_RP_VERSION, true);
    }
}

/**
 * Cargar scripts y estilos para el frontend
 */
function starter_rp_frontend_scripts() {
    // Solo cargar si estamos en un entorno de React
    if (defined('STARTER_HEADLESS_MODE') && STARTER_HEADLESS_MODE) {
        return;
    }
    
    wp_enqueue_style('starter-rp-frontend', STARTER_RP_URL . 'assets/css/frontend.css', [], STARTER_RP_VERSION);
    wp_enqueue_script('starter-rp-frontend', STARTER_RP_URL . 'assets/js/frontend.js', ['jquery'], STARTER_RP_VERSION, true);
}

/**
 * NOTA: La configuración CORS ha sido centralizada en el tema (cors-functions.php)
 * Este plugin ya no necesita configurar CORS manualmente.
 * 
 * @see themes/Starter/inc/cors-functions.php Para la configuración CORS unificada
 */

// Clase principal del plugin
class Starter_Referrals_Points {
    
    // Singleton instance
    private static $instance = null;
    
    // Opciones del plugin
    private $options;
    
    /**
     * Constructor
     */
    private function __construct() {
        // Cargar opciones con valores predeterminados completos
        $this->options = get_option('starter_rp_settings', [
            // Configuración general
            'enable_points' => 1,
            'enable_referrals' => 1,
            'allowed_roles' => ['customer'],
            
            // Configuración de puntos
            'points_conversion_rate' => 0.1,    // 0.1 = 10 Virtual Coins = 1 peso
            'points_percentage' => 5,           // 5% del valor de compra se convierte en puntos
            'min_points_redemption' => 100,     // Mínimo para canjear
            'max_points_per_order' => 0,        // Máximo por pedido (0 = sin límite)
            'points_expiry_days' => 365,        // Caducidad en días
            'point_triggers' => ['purchase'],   // Eventos que otorgan puntos
            'points_registration' => 100,       // Puntos por registro
            'points_review' => 50,              // Puntos por reseña
            'points_birthday' => 200,           // Puntos por cumpleaños
            
            // Configuración de referidos
            'referral_commission_first' => 10,      // Comisión primera compra (%)
            'referral_commission_subsequent' => 5,  // Comisión compras siguientes (%)
            'referral_commission_duration' => 365,  // Duración de comisión (días)
            'enable_second_level' => 0,             // Habilitar referidos de segundo nivel
            'second_level_commission' => 2,         // Comisión segundo nivel (%)
            'signup_points_level1' => 100,          // Puntos por referido nivel 1
            'signup_points_level2' => 50,           // Puntos por referido nivel 2
            
            // Configuración de visualización
            'display_points_checkout' => 1,         // Habilitar canje en checkout
            'redeem_points_text' => 'Usar mis Virtual Coins disponibles ({points} puntos)',
            'insufficient_points_text' => 'Necesitas al menos {min_points} Virtual Coins para canjear. Tienes {current_points} FC.',
            'discount_applied_text' => 'Descuento de {points} Virtual Coins aplicado (-{discount})',
        ]);
        
        // Hooks de activación/desactivación
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Inicializar componentes
        add_action('plugins_loaded', array($this, 'init'));
    }
    
    /**
     * Obtener instancia (Singleton)
     */
    public static function get_instance() {
        if (self::$instance == null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Activar plugin
     */
    public function activate() {
        // Crear tablas en la base de datos
        starter_rp_create_tables();
        
        // Opciones por defecto - usar el mismo nombre de opción en todo el plugin
        if (!get_option('starter_rp_settings')) {
            update_option('starter_rp_settings', $this->options);
        }
        
        // Migrar opciones antiguas si existen
        $old_options = get_option('starter_referrals_points_options');
        if ($old_options && !get_option('starter_rp_settings')) {
            update_option('starter_rp_settings', $old_options);
            // Opcional: eliminar las opciones antiguas
            // delete_option('starter_referrals_points_options');
        }
        
        // Solo marcar generación de códigos en la PRIMERA activación del plugin
        // para evitar regenerar (y potencialmente sobreescribir) códigos en reactivaciones
        if (get_option('starter_rp_generate_codes') === false) {
            update_option('starter_rp_generate_codes', 'yes');
        }
        
        // Log de activación
        starter_rp_log('Plugin Starter Referrals & Points activado');
    }
    
    /**
     * Desactivar plugin
     */
    public function deactivate() {
        // Limpiar cron jobs
        wp_clear_scheduled_hook('starter_rp_daily_maintenance');
        
        // Log de desactivación
        starter_rp_log('Plugin Starter Referrals & Points desactivado');
    }
    
    /**
     * Inicializar plugin
     */
    public function init() {
        // Verificar dependencias
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }
        
        // Inicializar componentes
        starter_rp_init_admin();
        starter_rp_init_points();
        starter_rp_init_referrals();
        starter_rp_register_api_endpoints();
        starter_rp_init_woocommerce_integration();
        
        // Programar tarea diaria
        if (!wp_next_scheduled('starter_rp_daily_maintenance')) {
            wp_schedule_event(time(), 'daily', 'starter_rp_daily_maintenance');
        }
        
        // Hook para recargar opciones cuando se actualicen
        add_action('update_option_starter_rp_settings', array($this, 'reload_options'));
        
        // Generar códigos de referido para usuarios existentes si es necesario
        if (get_option('starter_rp_generate_codes') === 'yes') {
            $this->generate_referral_codes_for_existing_users();
            update_option('starter_rp_generate_codes', 'no');
        }
        
        
    }
    
    /**
     * Generar códigos de referido para usuarios existentes
     */
    private function generate_referral_codes_for_existing_users() {
        $users = get_users(['fields' => 'ID']);
        foreach ($users as $user_id) {
            starter_rp_generate_referral_code($user_id);
        }
    }
    
    /**
     * Aviso de WooCommerce faltante
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="error">
            <p><?php _e('Starter Referrals & Points requiere que WooCommerce esté instalado y activado.', 'starter-rp'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Obtener opciones
     */
    public function get_options() {
        return $this->options;
    }
    
    /**
     * Actualizar opciones
     */
    public function update_options($new_options) {
        $this->options = array_merge($this->options, $new_options);
        update_option('starter_rp_settings', $this->options);
        
        // Log para depuración
        starter_rp_log('Starter RP: Opciones actualizadas - ' . wp_json_encode($this->options));
    }
    
    /**
     * Recargar opciones desde la base de datos
     */
    public function reload_options() {
        $this->options = get_option('starter_rp_settings', $this->options);
    }
}

// Inicializar el plugin
function Starter_RP() {
    return Starter_Referrals_Points::get_instance();
}

// Comenzar
Starter_RP();
