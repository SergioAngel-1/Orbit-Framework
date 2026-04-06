<?php
/**
 * Configuración de Pedido Mínimo
 * 
 * Este archivo maneja la configuración del monto mínimo de pedido
 * y proporciona endpoints REST API para el frontend
 * 
 * @package Starter
 * @since 1.0.0
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase para manejar la configuración de pedido mínimo
 */
class Starter_Minimum_Order_Settings {
    
    /**
     * Nombre de la opción en la base de datos
     */
    const OPTION_NAME = 'starter_minimum_order_amount';
    
    /**
     * Valor por defecto (en pesos colombianos)
     */
    const DEFAULT_MINIMUM = 50000;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Inicializar hooks
     */
    private function init_hooks() {
        // Registrar endpoint REST API
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        
        // Agregar página de configuración en el admin
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // Validación en creación de órdenes
        add_filter('woocommerce_rest_pre_insert_shop_order_object', array($this, 'validate_minimum_order'), 10, 2);
    }
    
    /**
     * Registrar rutas REST API
     */
    public function register_rest_routes() {
        // Endpoint público para obtener el mínimo
        register_rest_route('starter/v1', '/settings/minimum-order', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_minimum_order'),
            'permission_callback' => '__return_true',
        ));
        
        // Endpoint protegido para actualizar el mínimo (solo admin)
        register_rest_route('starter/v1', '/settings/minimum-order', array(
            'methods' => 'POST',
            'callback' => array($this, 'update_minimum_order'),
            'permission_callback' => array($this, 'check_admin_permission'),
            'args' => array(
                'minimum_amount' => array(
                    'required' => true,
                    'type' => 'integer',
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param >= 0;
                    }
                )
            )
        ));
    }
    
    /**
     * Obtener el monto mínimo de pedido
     */
    public function get_minimum_order($request) {
        $minimum = $this->get_minimum_amount();
        
        // Verificar que WooCommerce esté activo y cargado
        if (!function_exists('get_woocommerce_currency') || !class_exists('WooCommerce')) {
            $code = function_exists('site_get_currency_code') ? site_get_currency_code() : 'USD';
            $symbol = function_exists('site_get_currency_symbol') ? site_get_currency_symbol() : '$';
            $decimals = function_exists('site_get_currency_decimals') ? site_get_currency_decimals() : 0;
            return rest_ensure_response(array(
                'minimum_amount' => $minimum,
                'currency' => $code,
                'currency_symbol' => $symbol,
                'formatted' => $symbol . number_format($minimum, $decimals, ',', '.')
            ));
        }
        
        return rest_ensure_response(array(
            'minimum_amount' => $minimum,
            'currency' => get_woocommerce_currency(),
            'currency_symbol' => get_woocommerce_currency_symbol(),
            'formatted' => wc_price($minimum)
        ));
    }
    
    /**
     * Actualizar el monto mínimo de pedido
     */
    public function update_minimum_order($request) {
        $minimum_amount = intval($request['minimum_amount']);
        
        update_option(self::OPTION_NAME, $minimum_amount);
        
        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Monto mínimo actualizado correctamente',
            'minimum_amount' => $minimum_amount
        ));
    }
    
    /**
     * Verificar permisos de administrador
     */
    public function check_admin_permission($request) {
        return current_user_can('manage_woocommerce');
    }
    
    /**
     * Obtener el monto mínimo configurado
     */
    public function get_minimum_amount() {
        return intval(get_option(self::OPTION_NAME, self::DEFAULT_MINIMUM));
    }
    
    /**
     * Validar pedido mínimo al crear orden
     */
    public function validate_minimum_order($order, $request) {
        $minimum = $this->get_minimum_amount();
        
        // Si el mínimo es 0, no validar
        if ($minimum <= 0) {
            return $order;
        }
        
        // Obtener el subtotal del pedido (sin envío)
        $order_subtotal = $order->get_subtotal();
        
        // Validar que el subtotal cumpla con el mínimo
        if ($order_subtotal < $minimum) {
            // Formatear precios con o sin WooCommerce
            $format_price = function($amount) {
                if (function_exists('wc_price')) {
                    return wc_price($amount);
                }
                return '$' . number_format($amount, 0, ',', '.');
            };
            
            return new WP_Error(
                'minimum_order_not_met',
                sprintf(
                    'El pedido mínimo es de %s. Tu pedido actual es de %s. Te faltan %s para completar el pedido mínimo.',
                    $format_price($minimum),
                    $format_price($order_subtotal),
                    $format_price($minimum - $order_subtotal)
                ),
                array(
                    'status' => 400,
                    'minimum_amount' => $minimum,
                    'current_amount' => $order_subtotal,
                    'missing_amount' => $minimum - $order_subtotal
                )
            );
        }
        
        return $order;
    }
    
    /**
     * Agregar página de configuración en el menú de admin
     */
    public function add_settings_page() {
        add_submenu_page(
            'woocommerce',
            'Pedido Mínimo',
            'Pedido Mínimo',
            'manage_woocommerce',
            'starter-minimum-order',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Registrar configuraciones
     */
    public function register_settings() {
        register_setting('starter_minimum_order_settings', self::OPTION_NAME, array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => self::DEFAULT_MINIMUM
        ));
        
        add_settings_section(
            'starter_minimum_order_section',
            'Configuración de Pedido Mínimo',
            array($this, 'render_section_description'),
            'starter-minimum-order'
        );
        
        add_settings_field(
            'starter_minimum_order_amount',
            'Monto Mínimo de Pedido (COP)',
            array($this, 'render_minimum_amount_field'),
            'starter-minimum-order',
            'starter_minimum_order_section'
        );
    }
    
    /**
     * Renderizar descripción de la sección
     */
    public function render_section_description() {
        echo '<p>Configura el monto mínimo que los clientes deben alcanzar para poder realizar un pedido.</p>';
        echo '<p><strong>Nota:</strong> Si estableces el monto en 0, no se aplicará ningún mínimo.</p>';
    }
    
    /**
     * Renderizar campo de monto mínimo
     */
    public function render_minimum_amount_field() {
        $minimum = $this->get_minimum_amount();
        ?>
        <input 
            type="number" 
            name="<?php echo esc_attr(self::OPTION_NAME); ?>" 
            value="<?php echo esc_attr($minimum); ?>" 
            min="0" 
            step="1000"
            class="regular-text"
        />
        <p class="description">
            Ingresa el monto mínimo en pesos colombianos (COP). 
            Ejemplo: 50000 para $50,000 COP.
            <br>
            <strong>Monto actual:</strong> <?php echo wc_price($minimum); ?>
        </p>
        <?php
    }
    
    /**
     * Renderizar página de configuración
     */
    public function render_settings_page() {
        // Verificar permisos
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('No tienes permisos suficientes para acceder a esta página.'));
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php settings_errors(); ?>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('starter_minimum_order_settings');
                do_settings_sections('starter-minimum-order');
                submit_button('Guardar Configuración');
                ?>
            </form>
            
            <hr>
            
            <h2>Información Adicional</h2>
            <table class="widefat">
                <tbody>
                    <tr>
                        <td><strong>Monto Mínimo Actual:</strong></td>
                        <td><?php echo wc_price($this->get_minimum_amount()); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Moneda:</strong></td>
                        <td><?php echo get_woocommerce_currency(); ?> (<?php echo get_woocommerce_currency_symbol(); ?>)</td>
                    </tr>
                    <tr>
                        <td><strong>Endpoint API:</strong></td>
                        <td><code>GET /wp-json/starter/v1/settings/minimum-order</code></td>
                    </tr>
                    <tr>
                        <td><strong>Estado:</strong></td>
                        <td>
                            <?php if ($this->get_minimum_amount() > 0): ?>
                                <span style="color: green;">✓ Activo</span>
                            <?php else: ?>
                                <span style="color: orange;">○ Desactivado (monto en 0)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }
}

// Inicializar la clase
new Starter_Minimum_Order_Settings();
