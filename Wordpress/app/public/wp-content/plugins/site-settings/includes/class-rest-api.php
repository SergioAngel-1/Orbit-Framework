<?php
/**
 * REST API para Site Settings
 * 
 * Expone la configuración del sitio como endpoint público
 * para que el frontend React pueda consumirla.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Site_Settings_REST_API {

    private static $instance = null;
    const NAMESPACE = 'site-settings/v1';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Registrar rutas REST
     */
    public function register_routes() {
        // GET /site-settings/v1/config — Configuración completa (público)
        register_rest_route(self::NAMESPACE, '/config', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_config'],
            'permission_callback' => '__return_true',
        ]);

        // PUT /site-settings/v1/config — Actualizar configuración (solo admin)
        register_rest_route(self::NAMESPACE, '/config', [
            'methods'             => 'PUT',
            'callback'            => [$this, 'update_config'],
            'permission_callback' => [$this, 'check_admin_permission'],
        ]);
    }

    /**
     * GET /config — Devuelve toda la configuración del sitio
     */
    public function get_config(\WP_REST_Request $request) {
        $config = site_get_all_config();

        // Agregar estado de plugins requeridos
        if (!function_exists('is_plugin_active')) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins_list = [];
        foreach (Site_Settings::get_required_plugins() as $plugin) {
            $plugins_list[] = [
                'name'      => $plugin['name'],
                'type'      => $plugin['type'],
                'required'  => $plugin['required'],
                'active'    => is_plugin_active($plugin['slug']),
                'installed' => file_exists(WP_PLUGIN_DIR . '/' . $plugin['slug']),
            ];
        }
        $config['plugins'] = $plugins_list;

        return new \WP_REST_Response([
            'success' => true,
            'data'    => $config,
            'cached'  => get_transient(SITE_SETTINGS_TRANSIENT_KEY) !== false,
        ], 200);
    }

    /**
     * PUT /config — Actualiza opciones (requiere admin)
     */
    public function update_config(\WP_REST_Request $request) {
        $body = $request->get_json_params();

        if (empty($body) || !is_array($body)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'No se proporcionaron datos para actualizar.',
            ], 400);
        }

        $defaults = Site_Settings::get_defaults();
        $updated = [];

        foreach ($body as $key => $value) {
            // Solo permitir claves que existen en los defaults
            if (!array_key_exists($key, $defaults)) {
                continue;
            }
            $sanitized = $this->sanitize_value($key, $value);
            update_option(SITE_SETTINGS_OPTION_PREFIX . $key, $sanitized);
            $updated[$key] = $sanitized;
        }

        // Invalidar caché
        delete_transient(SITE_SETTINGS_TRANSIENT_KEY);

        return new \WP_REST_Response([
            'success' => true,
            'message' => 'Configuración actualizada.',
            'updated' => $updated,
        ], 200);
    }

    /**
     * Verificar permisos de administrador
     */
    public function check_admin_permission() {
        return current_user_can('manage_options');
    }

    /**
     * Sanitizar valores según la clave
     */
    private function sanitize_value($key, $value) {
        // URLs
        if (strpos($key, '_url') !== false || strpos($key, 'social_') === 0 || strpos($key, 'whatsapp') !== false) {
            return esc_url_raw($value);
        }

        // Emails
        if (strpos($key, 'email') !== false) {
            return sanitize_email($value);
        }

        // Colores
        if (strpos($key, 'color') !== false) {
            return sanitize_hex_color($value) ?: $value;
        }

        // Variaciones de color — selects (source y mode)
        $source_keys = ['branding_dark_source', 'branding_light_source', 'branding_text_source', 'branding_hover_source', 'branding_border_source'];
        if (in_array($key, $source_keys)) {
            return in_array($value, ['primary', 'secondary', 'accent']) ? $value : 'primary';
        }
        $mode_keys = ['branding_dark_mode', 'branding_light_mode', 'branding_text_mode', 'branding_hover_mode', 'branding_border_mode'];
        if (in_array($key, $mode_keys)) {
            return in_array($value, ['darken', 'lighten']) ? $value : 'darken';
        }

        // Números (incluyendo variaciones de color _amount)
        $number_keys = ['currency_decimals', 'currency_rounding_multiple', 'limits_max_addresses', 'limits_min_age', 'limits_items_per_page',
                        'branding_dark_amount', 'branding_light_amount', 'branding_text_amount', 'branding_hover_amount', 'branding_border_amount'];
        if (in_array($key, $number_keys)) {
            return is_numeric($value) ? $value + 0 : $value;
        }

        // Float
        if ($key === 'virtual_currency_conversion_rate') {
            return is_numeric($value) ? (float)$value : $value;
        }

        // Imágenes (attachment ID) — detectar todos los campos tipo image
        // Cubre: branding_logo, branding_favicon, branding_og_image, branding_loader,
        //        virtual_currency_image_front, virtual_currency_image_back
        $image_patterns = ['logo', 'favicon', 'og_image', 'loader', '_image_'];
        foreach ($image_patterns as $pattern) {
            if (strpos($key, $pattern) !== false) {
                return absint($value);
            }
        }

        // Textareas
        if (in_array($key, ['site_description', 'seo_default_keywords'])) {
            return sanitize_textarea_field($value);
        }

        // Texto general
        return sanitize_text_field($value);
    }
}
