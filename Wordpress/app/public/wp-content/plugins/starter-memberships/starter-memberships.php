<?php
/**
 * Plugin Name: Starter Memberships
 * Description: Sistema de membresías para WooCommerce con niveles Zanahoria
 * Version: 1.0.0
 * Author: Starter
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Definir constantes
define('STARTER_MEMBERSHIPS_DIR', plugin_dir_path(__FILE__));
define('STARTER_MEMBERSHIPS_URL', plugin_dir_url(__FILE__));
define('STARTER_MEMBERSHIPS_VERSION', '1.0.0');

// Registrar scripts y estilos
add_action('admin_enqueue_scripts', 'starter_memberships_admin_scripts');

/**
 * Cargar scripts y estilos para el admin
 */
function starter_memberships_admin_scripts($hook) {
    // Cargar en páginas del plugin y edición de productos
    $screen = get_current_screen();
    
    if (strpos($hook, 'starter-memberships') !== false || 
        ($screen && $screen->post_type === 'product')) {
        wp_enqueue_style(
            'starter-memberships-admin', 
            STARTER_MEMBERSHIPS_URL . 'assets/css/admin.css', 
            [], 
            STARTER_MEMBERSHIPS_VERSION
        );
        wp_enqueue_script(
            'starter-memberships-admin', 
            STARTER_MEMBERSHIPS_URL . 'assets/js/admin.js', 
            ['jquery'], 
            STARTER_MEMBERSHIPS_VERSION, 
            true
        );
    }
}

/**
 * Clase principal del plugin
 */
class Starter_Memberships {
    
    // Singleton instance
    private static $instance = null;
    
    // Opciones del plugin
    private $options;
    
    // Nivel base (sin membresía) - siempre disponible
    public static $base_level = [
        0 => [
            'name' => 'Zanahoria',
            'slug' => 'zanahoria',
            'slug_en' => '',
            'icon' => '🥕',
            'color' => '#FF6B35',
            'price_min' => 0,
            'price_max' => 0,
            'monthly_points' => 0,
            'purchasable' => false,
            'min_registration_days' => 0,
            'description' => 'Nivel gratuito con acceso básico al catálogo'
        ]
    ];
    
    // Cache de niveles cargados desde productos WC
    private static $membership_levels_cache = null;
    
    // Transient key para cache persistente
    const LEVELS_CACHE_KEY = 'starter_membership_levels';
    const LEVELS_CACHE_EXPIRY = 3600; // 1 hora
    
    /**
     * Constructor
     */
    private function __construct() {
        // Llamar init directamente ya que se instancia desde plugins_loaded
        $this->init();
    }
    
    /**
     * Cargar opciones (llamar después de init)
     */
    private function load_options() {
        $this->options = get_option('starter_memberships_settings', [
            'enable_memberships' => 1,
            'default_membership_level' => 0,
            'membership_duration_days' => 30,
            'auto_renew' => 0,
            'grace_period_days' => 3,
            'send_expiry_reminder' => 1,
            'reminder_days_before' => 7,
        ]);
    }
    
    /**
     * Obtener instancia (Singleton)
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Inicializar plugin
     */
    public function init() {
        // WooCommerce ya está verificado antes de instanciar
        
        // Cargar opciones
        $this->load_options();
        
        // Cargar archivos del plugin
        require_once STARTER_MEMBERSHIPS_DIR . 'includes/loader.php';
        
        // Inicializar componentes
        starter_memberships_init_admin();
        starter_memberships_init_products();
        starter_memberships_init_user();
        starter_memberships_register_api_endpoints();
        starter_memberships_init_woocommerce();
        
        // Asignar membresías por antigüedad a usuarios existentes (solo una vez)
        $this->maybe_assign_legacy_memberships();
        
        // Programar tareas
        if (!wp_next_scheduled('starter_memberships_daily_check')) {
            wp_schedule_event(time(), 'daily', 'starter_memberships_daily_check');
        }
        
        // Hook para recargar opciones
        add_action('update_option_starter_memberships_settings', [$this, 'reload_options']);
        
        
    }
    
    /**
     * Aviso de WooCommerce faltante
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="error">
            <p><?php _e('Starter Memberships requiere que WooCommerce esté instalado y activado.', 'starter-memberships'); ?></p>
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
     * Obtener nivel de membresía por ID
     * 
     * Los niveles se cargan dinámicamente desde los productos de membresía en WooCommerce.
     * El nivel 0 (Zanahoria) siempre está disponible como nivel base sin membresía.
     */
    public static function get_membership_level($level_id) {
        $levels = self::get_all_membership_levels();
        return $levels[$level_id] ?? self::$base_level[0];
    }
    
    /**
     * Obtener todos los niveles de membresía
     * 
     * Carga dinámicamente los niveles desde los productos de membresía en WooCommerce.
     * Usa cache en memoria y transient para optimizar rendimiento.
     */
    public static function get_all_membership_levels() {
        // Cache en memoria para evitar múltiples cargas en la misma request
        if (self::$membership_levels_cache !== null) {
            return self::$membership_levels_cache;
        }
        
        // Intentar obtener del transient (cache persistente)
        $cached = get_transient(self::LEVELS_CACHE_KEY);
        if ($cached !== false) {
            self::$membership_levels_cache = $cached;
            return $cached;
        }
        
        // Cargar desde productos WooCommerce
        $levels = self::load_levels_from_products();
        
        // Guardar en cache
        self::$membership_levels_cache = $levels;
        set_transient(self::LEVELS_CACHE_KEY, $levels, self::LEVELS_CACHE_EXPIRY);
        
        return $levels;
    }
    
    /**
     * Niveles predefinidos como fallback (para cuando no hay productos o para el admin)
     * Estos valores se usan como plantilla cuando se crea un nuevo producto de membresía
     * 
     * NOTA: Los Virtual Coins (FC) se definen SOLO en el producto WooCommerce de membresía,
     * NO aquí. Estos defaults solo contienen nombre, icono y color para el selector del admin.
     */
    private static $default_levels = [
        1 => ['name' => 'Bronce', 'slug_en' => 'bronze', 'icon' => '🥉', 'color' => '#CD7F32'],
        2 => ['name' => 'Plata', 'slug_en' => 'silver', 'icon' => '🥈', 'color' => '#C0C0C0'],
        3 => ['name' => 'Oro', 'slug_en' => 'gold', 'icon' => '🥇', 'color' => '#FFD700'],
        4 => ['name' => 'Diamante', 'slug_en' => 'diamond', 'icon' => '💎', 'color' => '#B9F2FF'],
        5 => ['name' => 'Platino', 'slug_en' => 'platinum', 'icon' => '🏆', 'color' => '#8B4513'],
    ];
    
    /**
     * Cargar niveles de membresía desde productos WooCommerce
     * 
     * Busca todos los productos marcados como membresía y construye
     * el array de niveles con sus propiedades.
     * Si no hay productos, devuelve los niveles predefinidos como fallback.
     */
    private static function load_levels_from_products() {
        // Empezar con el nivel base (0 = sin membresía)
        $levels = self::$base_level;
        
        // Verificar que WooCommerce esté activo
        if (!function_exists('wc_get_products')) {
            // Sin WooCommerce, devolver niveles predefinidos
            return self::get_default_levels();
        }
        
        // Obtener todos los productos de membresía
        $products = wc_get_products([
            'status' => 'publish',
            'limit' => 20,
            'meta_key' => '_is_membership_product',
            'meta_value' => 'yes',
            'orderby' => 'meta_value_num',
            'order' => 'ASC'
        ]);
        
        // Si no hay productos de membresía, devolver niveles predefinidos
        if (empty($products)) {
            return self::get_default_levels();
        }
        
        foreach ($products as $product) {
            $level_id = intval(get_post_meta($product->get_id(), '_membership_level', true));
            
            // Saltar nivel 0 (ya está definido como base)
            if ($level_id === 0) {
                continue;
            }
            
            // Obtener metadatos del producto
            $monthly_points = intval(get_post_meta($product->get_id(), '_membership_monthly_points', true));
            $min_registration_days = intval(get_post_meta($product->get_id(), '_membership_min_registration_days', true));
            $price = floatval($product->get_price());
            
            // Slug ES: custom meta > auto-generado del nombre
            $custom_slug_es = get_post_meta($product->get_id(), '_membership_slug_es', true);
            $slug = $custom_slug_es ?: sanitize_title($product->get_name());
            
            // Slug EN: custom meta > default hardcodeado
            $custom_slug_en = get_post_meta($product->get_id(), '_membership_slug_en', true);
            
            // Obtener defaults para icono y color
            $defaults = self::$default_levels[$level_id] ?? ['icon' => '⭐', 'color' => '#666666'];
            $slug_en = $custom_slug_en ?: ($defaults['slug_en'] ?? '');
            
            // Si ya existe el nivel, actualizar; si no, crear
            if (!isset($levels[$level_id])) {
                $levels[$level_id] = [
                    'name' => $product->get_name(),
                    'slug' => $slug,
                    'slug_en' => $slug_en,
                    'icon' => $defaults['icon'],
                    'color' => $defaults['color'],
                    'price_min' => $price,
                    'price_max' => $price,
                    'monthly_points' => $monthly_points,
                    'purchasable' => $product->is_purchasable() && $product->get_status() === 'publish',
                    'min_registration_days' => $min_registration_days,
                    'description' => $product->get_short_description() ?: $product->get_description(),
                    'product_id' => $product->get_id()
                ];
            } else {
                // Actualizar rango de precios si hay múltiples productos del mismo nivel
                $levels[$level_id]['price_min'] = min($levels[$level_id]['price_min'], $price);
                $levels[$level_id]['price_max'] = max($levels[$level_id]['price_max'], $price);
            }
        }
        
        // Ordenar por nivel
        ksort($levels);
        
        return $levels;
    }
    
    /**
     * Obtener niveles predefinidos (fallback cuando no hay productos)
     * 
     * NOTA: monthly_points es 0 porque los FC se definen SOLO en el producto WC
     */
    private static function get_default_levels() {
        $levels = self::$base_level;
        
        foreach (self::$default_levels as $level_id => $defaults) {
            $levels[$level_id] = [
                'name' => $defaults['name'],
                'slug' => sanitize_title($defaults['name']),
                'slug_en' => $defaults['slug_en'] ?? '',
                'icon' => $defaults['icon'],
                'color' => $defaults['color'],
                'price_min' => 0,
                'price_max' => 0,
                'monthly_points' => 0,
                'purchasable' => false,
                'min_registration_days' => 0,
                'description' => '',
                'product_id' => null
            ];
        }
        
        return $levels;
    }
    
    /**
     * Obtener niveles disponibles para selector en admin
     * Siempre incluye los niveles predefinidos para poder crear productos
     * 
     * NOTA: No incluye monthly_points - los FC se definen en el producto WC
     */
    public static function get_levels_for_admin_select() {
        $levels = self::$base_level;
        
        foreach (self::$default_levels as $level_id => $defaults) {
            $levels[$level_id] = [
                'name' => $defaults['name'],
                'icon' => $defaults['icon'],
                'color' => $defaults['color']
            ];
        }
        
        return $levels;
    }
    
    /**
     * Invalidar cache de niveles
     * 
     * Llamar cuando se actualiza un producto de membresía
     */
    public static function invalidate_levels_cache() {
        self::$membership_levels_cache = null;
        delete_transient(self::LEVELS_CACHE_KEY);
    }
    
    /**
     * Recargar opciones
     */
    public function reload_options() {
        $this->options = get_option('starter_memberships_settings', $this->options);
    }
    
    /**
     * Asignar membresías por antigüedad a usuarios existentes (solo una vez)
     * DESACTIVADO: Los usuarios deben aceptar manualmente la membresía por antigüedad
     */
    private function maybe_assign_legacy_memberships() {
        // DESACTIVADO - No asignar automáticamente
        // Los usuarios deben ver la oferta y aceptar manualmente
        return;
        
        // Verificar si ya se asignaron
        if (get_option('starter_legacy_memberships_assigned') === 'yes') {
            return;
        }
        
        // Verificar si está marcado para asignar
        if (get_option('starter_assign_legacy_on_init') !== 'yes') {
            return;
        }
        
        // Ejecutar asignación directamente (en admin para evitar timeout en frontend)
        if (is_admin() && function_exists('starter_run_legacy_membership_assignment')) {
            starter_run_legacy_membership_assignment();
        }
    }
}

// Hooks de activación/desactivación (deben estar fuera de la clase)
register_activation_hook(__FILE__, 'starter_memberships_activate');
register_deactivation_hook(__FILE__, 'starter_memberships_deactivate');

function starter_memberships_activate() {
    // Cargar archivos necesarios para activación
    require_once plugin_dir_path(__FILE__) . 'includes/core/database.php';
    
    // Crear tablas
    starter_memberships_create_tables();
    
    // Crear categoría de membresías
    if (!term_exists('membresias', 'product_cat')) {
        wp_insert_term(
            'Membresías',
            'product_cat',
            [
                'slug' => 'membresias',
                'description' => 'Productos de membresía mensual'
            ]
        );
    }
    
    // Opciones por defecto
    if (!get_option('starter_memberships_settings')) {
        update_option('starter_memberships_settings', [
            'enable_memberships' => 1,
            'default_membership_level' => 0,
            'membership_duration_days' => 30,
            'auto_renew' => 0,
            'grace_period_days' => 3,
            'send_expiry_reminder' => 1,
            'reminder_days_before' => 7,
        ]);
    }
    
    // Marcar para asignar membresías por antigüedad a usuarios existentes
    if (!get_option('starter_legacy_memberships_assigned')) {
        update_option('starter_assign_legacy_on_init', 'yes');
    }
    
    error_log('Plugin Starter Memberships activado');
}

function starter_memberships_deactivate() {
    wp_clear_scheduled_hook('starter_memberships_daily_check');
    wp_clear_scheduled_hook('starter_memberships_monthly_points');
    error_log('Plugin Starter Memberships desactivado');
}

// Declarar compatibilidad con HPOS de WooCommerce
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Función helper para obtener instancia
function Starter_Memberships() {
    return Starter_Memberships::get_instance();
}

// Inicializar el plugin en plugins_loaded (después de WooCommerce)
add_action('plugins_loaded', function() {
    if (class_exists('WooCommerce')) {
        Starter_Memberships();
    }
}, 20);
