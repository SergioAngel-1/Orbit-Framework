<?php
/**
 * Plugin Name: Site Settings
 * Description: Configuración central del sitio — identidad, branding, contacto, moneda, SEO y más. Panel de admin + REST API para frontend headless.
 * Version: 1.0.0
 * Author: E-Commerce Template
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Text Domain: site-settings
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SITE_SETTINGS_VERSION', '1.0.0');
define('SITE_SETTINGS_DIR', plugin_dir_path(__FILE__));
define('SITE_SETTINGS_URL', plugin_dir_url(__FILE__));
define('SITE_SETTINGS_OPTION_PREFIX', 'site_settings_');
define('SITE_SETTINGS_TRANSIENT_KEY', 'site_settings_config_cache');

/**
 * Clase principal del plugin (Singleton)
 */
class Site_Settings {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    private function load_dependencies() {
        require_once SITE_SETTINGS_DIR . 'includes/helpers.php';
        require_once SITE_SETTINGS_DIR . 'includes/class-settings-page.php';
        require_once SITE_SETTINGS_DIR . 'includes/class-rest-api.php';
    }

    private function init_hooks() {
        // Inicializar página de admin
        Site_Settings_Page::get_instance();

        // Inicializar REST API
        Site_Settings_REST_API::get_instance();

        // Invalidar caché al guardar opciones
        // update_option: se dispara cuando una opción existente cambia de valor
        // added_option: se dispara la primera vez que se crea una opción (Settings API primera escritura)
        add_action('update_option', [$this, 'maybe_invalidate_cache'], 10, 1);
        add_action('added_option', [$this, 'maybe_invalidate_cache'], 10, 1);

        // Permitir subida de archivos SVG (necesario para loader/iconos)
        add_filter('upload_mimes', [$this, 'allow_svg_uploads']);
        add_filter('wp_check_filetype_and_ext', [$this, 'fix_svg_mime_type'], 10, 5);
    }

    /**
     * Agregar SVG a los MIME types permitidos en uploads
     */
    public function allow_svg_uploads($mimes) {
        $mimes['svg']  = 'image/svg+xml';
        $mimes['svgz'] = 'image/svg+xml';
        return $mimes;
    }

    /**
     * Fix para que WordPress detecte correctamente el tipo MIME de SVG
     * (wp_check_filetype_and_ext a veces falla con SVG)
     */
    public function fix_svg_mime_type($data, $file, $filename, $mimes, $real_mime = '') {
        if (!empty($data['ext']) && !empty($data['type'])) {
            return $data;
        }
        $filetype = wp_check_filetype($filename, $mimes);
        if ($filetype['ext'] === 'svg' || $filetype['ext'] === 'svgz') {
            $data['ext']  = $filetype['ext'];
            $data['type'] = 'image/svg+xml';
        }
        return $data;
    }

    /**
     * Invalida el transient de caché cuando se actualiza una opción del plugin
     */
    public function maybe_invalidate_cache($option_name) {
        if (strpos($option_name, SITE_SETTINGS_OPTION_PREFIX) === 0) {
            delete_transient(SITE_SETTINGS_TRANSIENT_KEY);
        }
    }

    /**
     * Activación del plugin: registrar valores por defecto
     */
    public static function activate() {
        $defaults = self::get_defaults();
        foreach ($defaults as $key => $value) {
            $option_name = SITE_SETTINGS_OPTION_PREFIX . $key;
            if (get_option($option_name) === false) {
                update_option($option_name, $value);
            }
        }
    }

    /**
     * Valores por defecto de todas las opciones
     */
    public static function get_defaults() {
        return [
            // Identidad
            'site_name'        => 'Mi Tienda',
            'site_short_name'  => 'Mi Tienda',
            'site_tagline'     => 'Tu tienda online',
            'site_description' => 'La mejor tienda online con membresías y beneficios exclusivos.',

            // URLs
            'frontend_url' => 'https://example.com',

            // Contacto
            'contact_email'    => 'info@example.com',
            'contact_phone'    => '+1 000 000 0000',
            'contact_whatsapp' => 'https://wa.me/10000000000',

            // Redes sociales
            'social_facebook'  => '',
            'social_instagram' => '',
            'social_tiktok'    => '',
            'social_twitter'   => '',

            // Branding
            'branding_primary_color'   => '#16a34a',
            'branding_secondary_color' => '#FF6B35',
            'branding_accent_color'    => '#8FD8B9',

            // Variaciones de color derivadas (source: primary|secondary|accent, mode: darken|lighten, amount: 0-100)
            'branding_dark_source'     => 'primary',
            'branding_dark_mode'       => 'darken',
            'branding_dark_amount'     => 25,
            'branding_light_source'    => 'primary',
            'branding_light_mode'      => 'lighten',
            'branding_light_amount'    => 8,
            'branding_text_source'     => 'primary',
            'branding_text_mode'       => 'darken',
            'branding_text_amount'     => 35,
            'branding_hover_source'    => 'primary',
            'branding_hover_mode'      => 'darken',
            'branding_hover_amount'    => 10,
            'branding_border_source'   => 'secondary',
            'branding_border_mode'     => 'lighten',
            'branding_border_amount'   => 30,

            'branding_font'            => 'Poppins',
            'branding_logo'            => '',
            'branding_favicon'         => '',
            'branding_og_image'        => '',
            'branding_loader'          => '',

            // Moneda
            'currency_code'             => 'USD',
            'currency_symbol'           => '$',
            'currency_decimals'         => 2,
            'currency_locale'           => 'en-US',
            'currency_rounding_multiple' => 1,

            // Geo
            'geo_country'  => 'US',
            'geo_region'   => 'United States',
            'geo_timezone' => 'America/New_York',

            // Moneda virtual
            'virtual_currency_name'            => 'Points',
            'virtual_currency_short'           => 'PTS',
            'virtual_currency_icon'            => '⭐',
            'virtual_currency_image_front'     => '',
            'virtual_currency_image_back'      => '',
            'virtual_currency_conversion_rate' => 0.01,

            // Límites
            'limits_max_addresses'  => 3,
            'limits_min_age'        => 18,
            'limits_items_per_page' => 12,

            // SEO
            'seo_title_suffix'      => '| Mi Tienda',
            'seo_default_keywords'  => '',
            'seo_author'            => 'Mi Tienda',

            // Pasarela de pago
            'payment_gateway' => 'wompi',
        ];
    }

    /**
     * Definición de secciones y campos para el panel de admin
     */
    public static function get_fields_config() {
        return [
            'identity' => [
                'title'  => __('Identidad', 'site-settings'),
                'icon'   => 'dashicons-store',
                'fields' => [
                    'site_name'        => ['label' => 'Nombre del sitio',   'type' => 'text'],
                    'site_short_name'  => ['label' => 'Nombre corto',       'type' => 'text'],
                    'site_tagline'     => ['label' => 'Tagline / Eslogan',  'type' => 'text'],
                    'site_description' => ['label' => 'Descripción',        'type' => 'textarea'],
                ],
            ],
            'urls' => [
                'title'  => __('URLs', 'site-settings'),
                'icon'   => 'dashicons-admin-links',
                'fields' => [
                    'frontend_url' => ['label' => 'URL del Frontend', 'type' => 'url', 'description' => 'URL pública del sitio React (ej: https://mitienda.com)'],
                ],
            ],
            'contact' => [
                'title'  => __('Contacto', 'site-settings'),
                'icon'   => 'dashicons-phone',
                'fields' => [
                    'contact_email'    => ['label' => 'Email de contacto', 'type' => 'email'],
                    'contact_phone'    => ['label' => 'Teléfono',          'type' => 'text'],
                    'contact_whatsapp' => ['label' => 'Link de WhatsApp',  'type' => 'url', 'description' => 'Formato: https://wa.me/XXXXXXXXXXX'],
                ],
            ],
            'social' => [
                'title'  => __('Redes Sociales', 'site-settings'),
                'icon'   => 'dashicons-share',
                'fields' => [
                    'social_facebook'  => ['label' => 'Facebook',    'type' => 'url'],
                    'social_instagram' => ['label' => 'Instagram',   'type' => 'url'],
                    'social_tiktok'    => ['label' => 'TikTok',      'type' => 'url'],
                    'social_twitter'   => ['label' => 'Twitter / X', 'type' => 'url'],
                ],
            ],
            'branding' => [
                'title'  => __('Branding', 'site-settings'),
                'icon'   => 'dashicons-art',
                'fields' => [
                    'branding_primary_color'   => ['label' => 'Color primario',   'type' => 'color'],
                    'branding_secondary_color' => ['label' => 'Color secundario', 'type' => 'color'],
                    'branding_accent_color'    => ['label' => 'Color acento (éxito/positivo)', 'type' => 'color', 'description' => 'Color para estados de éxito, confirmaciones y elementos positivos.'],

                    'branding_dark_source'     => ['label' => 'Oscuro — color base',   'type' => 'select', 'options' => ['primary' => 'Primario', 'secondary' => 'Secundario', 'accent' => 'Acento']],
                    'branding_dark_mode'       => ['label' => 'Oscuro — modo',          'type' => 'select', 'options' => ['darken' => 'Oscurecer', 'lighten' => 'Aclarar']],
                    'branding_dark_amount'     => ['label' => 'Oscuro — intensidad (%)', 'type' => 'number', 'description' => '0-100. Ej: 25 = 25% de oscurecimiento/aclarado.'],

                    'branding_light_source'    => ['label' => 'Claro — color base',     'type' => 'select', 'options' => ['primary' => 'Primario', 'secondary' => 'Secundario', 'accent' => 'Acento']],
                    'branding_light_mode'      => ['label' => 'Claro — modo',            'type' => 'select', 'options' => ['darken' => 'Oscurecer', 'lighten' => 'Aclarar']],
                    'branding_light_amount'    => ['label' => 'Claro — intensidad (%)',   'type' => 'number', 'description' => '0-100. Ej: 8 = 8% de aclarado.'],

                    'branding_text_source'     => ['label' => 'Texto — color base',      'type' => 'select', 'options' => ['primary' => 'Primario', 'secondary' => 'Secundario', 'accent' => 'Acento']],
                    'branding_text_mode'       => ['label' => 'Texto — modo',             'type' => 'select', 'options' => ['darken' => 'Oscurecer', 'lighten' => 'Aclarar']],
                    'branding_text_amount'     => ['label' => 'Texto — intensidad (%)',    'type' => 'number', 'description' => '0-100. Ej: 35 = 35% de oscurecimiento.'],

                    'branding_hover_source'    => ['label' => 'Hover — color base',      'type' => 'select', 'options' => ['primary' => 'Primario', 'secondary' => 'Secundario', 'accent' => 'Acento']],
                    'branding_hover_mode'      => ['label' => 'Hover — modo',             'type' => 'select', 'options' => ['darken' => 'Oscurecer', 'lighten' => 'Aclarar']],
                    'branding_hover_amount'    => ['label' => 'Hover — intensidad (%)',    'type' => 'number', 'description' => '0-100. Ej: 10 = 10% de oscurecimiento.'],

                    'branding_border_source'   => ['label' => 'Borde — color base',      'type' => 'select', 'options' => ['primary' => 'Primario', 'secondary' => 'Secundario', 'accent' => 'Acento']],
                    'branding_border_mode'     => ['label' => 'Borde — modo',             'type' => 'select', 'options' => ['darken' => 'Oscurecer', 'lighten' => 'Aclarar']],
                    'branding_border_amount'   => ['label' => 'Borde — intensidad (%)',    'type' => 'number', 'description' => '0-100. Ej: 30 = 30% de aclarado.'],

                    'branding_font'            => ['label' => 'Fuente (Google Fonts)', 'type' => 'text', 'description' => 'Nombre exacto de Google Fonts (ej: Poppins, Inter, Roboto)'],
                    'branding_logo'            => ['label' => 'Logo',             'type' => 'image'],
                    'branding_favicon'         => ['label' => 'Favicon',          'type' => 'image'],
                    'branding_og_image'        => ['label' => 'Imagen OG (compartir)', 'type' => 'image'],
                    'branding_loader'          => ['label' => 'Loader / Spinner',      'type' => 'image', 'description' => 'Imagen animada de carga (soporta SVG, GIF, PNG, WEBP). Se usa como spinner en toda la app.'],
                ],
            ],
            'currency' => [
                'title'  => __('Moneda', 'site-settings'),
                'icon'   => 'dashicons-money-alt',
                'fields' => [
                    'currency_code'              => ['label' => 'Código (ISO 4217)',  'type' => 'text', 'description' => 'Ej: USD, COP, EUR, MXN'],
                    'currency_symbol'            => ['label' => 'Símbolo',            'type' => 'text'],
                    'currency_decimals'          => ['label' => 'Decimales',          'type' => 'number'],
                    'currency_locale'            => ['label' => 'Locale',             'type' => 'text', 'description' => 'Ej: en-US, es-CO, es-MX'],
                    'currency_rounding_multiple' => ['label' => 'Múltiplo de redondeo', 'type' => 'number', 'description' => 'Ej: 50 para COP, 1 para USD. Deja 1 si no aplica.'],
                ],
            ],
            'geo' => [
                'title'  => __('Geolocalización', 'site-settings'),
                'icon'   => 'dashicons-location',
                'fields' => [
                    'geo_country'  => ['label' => 'Código de país (ISO)', 'type' => 'text', 'description' => 'Ej: US, CO, MX, ES'],
                    'geo_region'   => ['label' => 'Región / País',        'type' => 'text'],
                    'geo_timezone' => ['label' => 'Zona horaria',         'type' => 'text', 'description' => 'Ej: America/New_York, America/Bogota'],
                ],
            ],
            'virtual_currency' => [
                'title'  => __('Moneda Virtual / Puntos', 'site-settings'),
                'icon'   => 'dashicons-star-filled',
                'fields' => [
                    'virtual_currency_name'            => ['label' => 'Nombre',              'type' => 'text', 'description' => 'Ej: Virtual Coins, Store Points'],
                    'virtual_currency_short'           => ['label' => 'Abreviación',         'type' => 'text', 'description' => 'Ej: FC, PTS'],
                    'virtual_currency_icon'            => ['label' => 'Ícono (emoji)',        'type' => 'text'],
                    'virtual_currency_image_front'     => ['label' => 'Imagen moneda (frente)', 'type' => 'image', 'description' => 'Imagen de la moneda virtual vista frontal (recomendado: webp/png cuadrado, mín. 128x128px)'],
                    'virtual_currency_image_back'      => ['label' => 'Imagen moneda (reverso)', 'type' => 'image', 'description' => 'Imagen de la moneda virtual vista trasera (recomendado: webp/png cuadrado, mín. 128x128px)'],
                    'virtual_currency_conversion_rate' => ['label' => 'Tasa de conversión',  'type' => 'number', 'description' => 'Cuánto vale 1 punto en la moneda del sitio. Ej: 0.1 = 10 puntos = 1 unidad monetaria'],
                ],
            ],
            'limits' => [
                'title'  => __('Límites', 'site-settings'),
                'icon'   => 'dashicons-admin-generic',
                'fields' => [
                    'limits_max_addresses'  => ['label' => 'Máx. direcciones por usuario', 'type' => 'number'],
                    'limits_min_age'        => ['label' => 'Edad mínima',                   'type' => 'number'],
                    'limits_items_per_page' => ['label' => 'Items por página',              'type' => 'number'],
                ],
            ],
            'seo' => [
                'title'  => __('SEO', 'site-settings'),
                'icon'   => 'dashicons-search',
                'fields' => [
                    'seo_title_suffix'     => ['label' => 'Sufijo del título',   'type' => 'text', 'description' => 'Se agrega al final del <title>. Ej: | Mi Tienda'],
                    'seo_default_keywords' => ['label' => 'Keywords por defecto', 'type' => 'textarea'],
                    'seo_author'           => ['label' => 'Autor',               'type' => 'text'],
                ],
            ],
            'payments' => [
                'title'  => __('Pasarela de Pago', 'site-settings'),
                'icon'   => 'dashicons-money-alt',
                'fields' => [
                    'payment_gateway' => ['label' => 'Pasarela activa', 'type' => 'select', 'options' => ['wompi' => 'Wompi (Colombia)', 'stripe' => 'Stripe', 'mercadopago' => 'MercadoPago'], 'description' => 'Pasarela de pago utilizada para procesar transacciones.'],
                ],
            ],
            'plugins' => [
                'title'  => __('Plugins Requeridos', 'site-settings'),
                'icon'   => 'dashicons-admin-plugins',
                'type'   => 'plugin_list',
                'fields' => [],
            ],
        ];
    }

    /**
     * Información del tema requerido por el template.
     */
    public static function get_required_theme() {
        return [
            'slug'        => 'Starter',
            'name'        => 'Starter',
            'description' => 'Tema principal del template E-Commerce. Gestiona la integración con WooCommerce, pagos, membresías y REST API.',
            'required'    => true,
        ];
    }

    /**
     * Lista de plugins requeridos por el template.
     * Cada plugin tiene: slug (carpeta), name, description, type (custom|commercial), required (bool).
     */
    public static function get_required_plugins() {
        return [
            // --- Plugins personalizados (del template) ---
            [
                'slug'        => 'site-settings/site-settings.php',
                'name'        => 'Site Settings',
                'description' => 'Configuración central del sitio (identidad, branding, contacto, moneda, SEO).',
                'type'        => 'custom',
                'required'    => true,
            ],
            [
                'slug'        => 'starter-memberships/starter-memberships.php',
                'name'        => 'Memberships',
                'description' => 'Sistema de membresías con niveles, beneficios e integración WooCommerce.',
                'type'        => 'custom',
                'required'    => false,
            ],
            [
                'slug'        => 'starter-referrals-points/starter-referrals-points.php',
                'name'        => 'Referrals & Points',
                'description' => 'Referidos, puntos (moneda virtual), comisiones y redención en checkout.',
                'type'        => 'custom',
                'required'    => false,
            ],
            [
                'slug'        => 'starter-home-sections/starter-home-sections.php',
                'name'        => 'Home Sections',
                'description' => 'Secciones dinámicas del home (productos destacados por categoría/nivel).',
                'type'        => 'custom',
                'required'    => false,
            ],
            // --- Plugins comerciales / terceros ---
            [
                'slug'        => 'woocommerce/woocommerce.php',
                'name'        => 'WooCommerce',
                'description' => 'Plataforma de e-commerce. Gestión de productos, pedidos, inventario.',
                'type'        => 'commercial',
                'required'    => true,
            ],
            [
                'slug'        => 'jwt-authentication-for-wp-rest-api/jwt-auth.php',
                'name'        => 'JWT Authentication for WP REST API',
                'description' => 'Autenticación via JSON Web Tokens para la REST API.',
                'type'        => 'commercial',
                'required'    => true,
            ],
            [
                'slug'        => 'headless-mode/headless-mode.php',
                'name'        => 'Headless Mode',
                'description' => 'Redirige el frontend de WordPress al sitio headless (React).',
                'type'        => 'commercial',
                'required'    => true,
            ],
            [
                'slug'        => 'two-factor/two-factor.php',
                'name'        => 'Two Factor Authentication',
                'description' => 'Autenticación de dos factores para mayor seguridad.',
                'type'        => 'commercial',
                'required'    => false,
            ],
            [
                'slug'        => 'w3-total-cache/w3-total-cache.php',
                'name'        => 'W3 Total Cache',
                'description' => 'Caché de objetos, páginas y base de datos para rendimiento.',
                'type'        => 'commercial',
                'required'    => false,
            ],
        ];
    }
}

// Activación
register_activation_hook(__FILE__, ['Site_Settings', 'activate']);

// Inicializar
add_action('plugins_loaded', function () {
    Site_Settings::get_instance();
});
