<?php
/**
 * Funciones helper globales para Site Settings
 * 
 * Estas funciones están disponibles para el tema y otros plugins.
 * Permiten acceder a la configuración del sitio sin conocer la estructura interna.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Obtiene un valor de configuración del sitio
 *
 * @param string $key     Clave de la opción (sin prefijo). Ej: 'site_name', 'contact_email'
 * @param mixed  $default Valor por defecto si la opción no existe
 * @return mixed
 */
function site_get_option($key, $default = null) {
    $option_name = SITE_SETTINGS_OPTION_PREFIX . $key;
    $value = get_option($option_name);

    if ($value === false) {
        // Si no existe en BD, intentar obtener el default del plugin
        if ($default !== null) {
            return $default;
        }
        $defaults = Site_Settings::get_defaults();
        return isset($defaults[$key]) ? $defaults[$key] : null;
    }

    return $value;
}

/**
 * Obtiene todos los valores de una sección de configuración
 *
 * @param string $section Nombre de la sección (ej: 'identity', 'branding', 'currency')
 * @return array Arreglo asociativo con los campos de la sección
 */
function site_get_section($section) {
    $fields_config = Site_Settings::get_fields_config();

    if (!isset($fields_config[$section])) {
        return [];
    }

    $result = [];
    foreach ($fields_config[$section]['fields'] as $key => $field) {
        $result[$key] = site_get_option($key);
    }

    return $result;
}

/**
 * Obtiene toda la configuración del sitio como array estructurado
 * Usa caché con transient para rendimiento
 *
 * @param bool $force_refresh Forzar recarga ignorando caché
 * @return array
 */
function site_get_all_config($force_refresh = false) {
    if (!$force_refresh) {
        $cached = get_transient(SITE_SETTINGS_TRANSIENT_KEY);
        if ($cached !== false) {
            return $cached;
        }
    }

    $fields_config = Site_Settings::get_fields_config();
    $config = [];

    foreach ($fields_config as $section_key => $section) {
        $section_data = [];
        foreach ($section['fields'] as $field_key => $field) {
            $value = site_get_option($field_key);
            // Convertir campos numéricos
            if (isset($field['type']) && $field['type'] === 'number') {
                $value = is_numeric($value) ? (strpos((string)$value, '.') !== false ? (float)$value : (int)$value) : $value;
            }
            // Convertir IDs de imagen a URLs
            if (isset($field['type']) && $field['type'] === 'image' && is_numeric($value) && (int)$value > 0) {
                $image_url = wp_get_attachment_url((int)$value);
                $value = $image_url ? $image_url : '';
            }
            $section_data[$field_key] = $value;
        }
        $config[$section_key] = $section_data;
    }

    // Agregar feature flags (no se cachean para reflejar cambios inmediatos)
    $config['features'] = function_exists('site_get_active_features') ? site_get_active_features() : [];

    // Cachear por 5 minutos
    set_transient(SITE_SETTINGS_TRANSIENT_KEY, $config, 5 * MINUTE_IN_SECONDS);

    return $config;
}

/**
 * Obtiene la URL del frontend configurada
 * Atajo frecuente para tema y plugins
 *
 * @return string
 */
function site_get_frontend_url() {
    return rtrim(site_get_option('frontend_url', 'https://example.com'), '/');
}

/**
 * Obtiene el nombre del sitio configurado
 *
 * @return string
 */
function site_get_name() {
    return site_get_option('site_name', 'Mi Tienda');
}

/**
 * Obtiene el email de contacto configurado
 *
 * @return string
 */
function site_get_contact_email() {
    return site_get_option('contact_email', get_option('admin_email'));
}

/**
 * Obtiene el color primario de branding
 *
 * @return string Código hex (ej: '#16a34a')
 */
function site_get_primary_color() {
    return site_get_option('branding_primary_color', '#16a34a');
}

/**
 * Obtiene el color secundario de branding
 *
 * @return string Código hex (ej: '#FF6B35')
 */
function site_get_secondary_color() {
    return site_get_option('branding_secondary_color', '#FF6B35');
}

/**
 * Obtiene la fuente configurada (Google Fonts)
 *
 * @return string Nombre de la fuente (ej: 'Poppins')
 */
function site_get_font() {
    return site_get_option('branding_font', 'Poppins');
}

/**
 * Obtiene la URL del logo del sitio (resuelve attachment ID a URL)
 *
 * @param string $size Tamaño de WordPress (thumbnail, medium, large, full)
 * @return string URL del logo o cadena vacía
 */
function site_get_logo_url($size = 'full') {
    $logo_id = site_get_option('branding_logo', '');
    if (!empty($logo_id) && is_numeric($logo_id)) {
        $url = wp_get_attachment_image_url((int) $logo_id, $size);
        if ($url) {
            return $url;
        }
    }
    return '';
}

/**
 * Obtiene la URL del favicon
 *
 * @return string URL del favicon o cadena vacía
 */
function site_get_favicon_url() {
    $favicon_id = site_get_option('branding_favicon', '');
    if (!empty($favicon_id) && is_numeric($favicon_id)) {
        $url = wp_get_attachment_image_url((int) $favicon_id, 'thumbnail');
        if ($url) {
            return $url;
        }
    }
    return '';
}

/**
 * Genera un color más oscuro a partir de un hex (para hover states)
 *
 * @param string $hex Color hex (#RRGGBB)
 * @param int    $percent Porcentaje a oscurecer (0-100)
 * @return string Color hex oscurecido
 */
function site_darken_color($hex, $percent = 20) {
    $hex = ltrim($hex, '#');
    $r = max(0, hexdec(substr($hex, 0, 2)) - (int)(hexdec(substr($hex, 0, 2)) * $percent / 100));
    $g = max(0, hexdec(substr($hex, 2, 2)) - (int)(hexdec(substr($hex, 2, 2)) * $percent / 100));
    $b = max(0, hexdec(substr($hex, 4, 2)) - (int)(hexdec(substr($hex, 4, 2)) * $percent / 100));
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

/**
 * Genera un color más claro a partir de un hex (para fondos)
 *
 * @param string $hex Color hex (#RRGGBB)
 * @param float  $opacity Opacidad (0.0 - 1.0)
 * @return string Color rgba
 */
function site_lighten_color($hex, $opacity = 0.1) {
    $hex = ltrim($hex, '#');
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    return sprintf('rgba(%d, %d, %d, %s)', $r, $g, $b, $opacity);
}

/**
 * Obtiene todo el branding necesario para emails en un solo array
 * Evita múltiples llamadas a get_option en templates de email
 *
 * @return array
 */
function site_get_email_branding() {
    $primary = site_get_primary_color();
    $secondary = site_get_secondary_color();
    $font = site_get_font();
    $name = site_get_name();

    return [
        'site_name'       => $name,
        'logo_url'        => site_get_logo_url(),
        'primary_color'   => $primary,
        'secondary_color' => $secondary,
        'hover_color'     => site_darken_color($primary, 25),
        'light_bg'        => site_lighten_color($primary, 0.08),
        'border_color'    => site_lighten_color($primary, 0.25),
        'font'            => $font,
        'font_import'     => 'https://fonts.googleapis.com/css2?family=' . urlencode($font) . ':wght@300;400;500;600;700&display=swap',
        'contact_email'   => site_get_contact_email(),
        'frontend_url'    => site_get_frontend_url(),
    ];
}

/**
 * Convierte un color hex a array RGB [r, g, b]
 * Útil para librerías como TCPDF que requieren valores RGB separados
 *
 * @param string $hex Color hex (#RRGGBB)
 * @return array [r, g, b]
 */
function site_hex_to_rgb($hex) {
    $hex = ltrim($hex, '#');
    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    ];
}

// ──────────────────────────────────────────────
// Helpers de moneda y localización
// ──────────────────────────────────────────────

/**
 * Obtiene el código ISO 4217 de la moneda (ej: "COP", "USD", "EUR")
 * Intenta WooCommerce primero, luego Site Settings
 *
 * @return string
 */
function site_get_currency_code() {
    if (function_exists('get_woocommerce_currency')) {
        return get_woocommerce_currency();
    }
    return site_get_option('currency_code', 'USD');
}

/**
 * Obtiene el símbolo de la moneda (ej: "$", "€", "£")
 *
 * @return string
 */
function site_get_currency_symbol() {
    if (function_exists('get_woocommerce_currency_symbol')) {
        return get_woocommerce_currency_symbol();
    }
    return site_get_option('currency_symbol', '$');
}

/**
 * Obtiene la cantidad de decimales configurada para la moneda
 *
 * @return int
 */
function site_get_currency_decimals() {
    if (function_exists('wc_get_price_decimals')) {
        return wc_get_price_decimals();
    }
    $val = site_get_option('currency_decimals', 0);
    return is_numeric($val) ? (int) $val : 0;
}

/**
 * Obtiene el locale de la moneda (ej: "es-CO", "en-US")
 *
 * @return string
 */
function site_get_currency_locale() {
    return site_get_option('currency_locale', 'en-US');
}

/**
 * Obtiene el múltiplo de redondeo (ej: 50 para COP, 1 para USD)
 *
 * @return int
 */
function site_get_currency_rounding() {
    $val = site_get_option('currency_rounding_multiple', 1);
    return is_numeric($val) ? max(1, (int) $val) : 1;
}

/**
 * Obtiene toda la configuración de moneda en un solo array
 *
 * @return array
 */
function site_get_currency_config() {
    return [
        'code'             => site_get_currency_code(),
        'symbol'           => site_get_currency_symbol(),
        'decimals'         => site_get_currency_decimals(),
        'locale'           => site_get_currency_locale(),
        'rounding_multiple' => site_get_currency_rounding(),
    ];
}

/**
 * Redondea un monto al múltiplo configurado (ceil)
 * Ej: con rounding_multiple=50 y amount=1230, retorna 1250
 *
 * @param float $amount
 * @return float
 */
function site_round_currency($amount) {
    $multiple = site_get_currency_rounding();
    if ($multiple <= 1) {
        return round($amount);
    }
    return ceil($amount / $multiple) * $multiple;
}

/**
 * Formatea un monto usando la configuración de moneda
 * Usa wc_price() si WooCommerce está disponible, sino formato manual
 *
 * @param float $amount
 * @return string
 */
function site_format_price($amount) {
    if (function_exists('wc_price')) {
        return wc_price($amount);
    }
    $decimals = site_get_currency_decimals();
    $symbol = site_get_currency_symbol();
    return $symbol . number_format($amount, $decimals, ',', '.');
}

/**
 * Obtiene la abreviación de la moneda virtual (ej: "FC", "PTS", "VC")
 *
 * @return string
 */
function site_get_vc_short() {
    return site_get_option('virtual_currency_short', 'VC');
}

/**
 * Obtiene el nombre completo de la moneda virtual (ej: "Virtual Coins", "Store Points")
 *
 * @return string
 */
function site_get_vc_name() {
    return site_get_option('virtual_currency_name', 'Virtual Coins');
}

// ──────────────────────────────────────────────
// Feature flags — Detección de plugins opcionales
// ──────────────────────────────────────────────

/**
 * Mapa de features → detección
 * Cada feature define:
 *   - plugin: slug del plugin para is_plugin_active()
 *   - check:  función que confirma que el plugin realmente cargó su API
 *   - label:  nombre legible
 *
 * @return array
 */
function site_get_features_registry() {
    return [
        'memberships' => [
            'plugin' => 'starter-memberships/starter-memberships.php',
            'check'  => 'Starter_Memberships',
            'label'  => 'Membresías',
        ],
        'referrals_points' => [
            'plugin' => 'starter-referrals-points/starter-referrals-points.php',
            'check'  => 'Starter_RP',
            'label'  => 'Referidos y Puntos',
        ],
        'home_sections' => [
            'plugin' => 'starter-home-sections/starter-home-sections.php',
            'check'  => 'starter_home_sections',
            'label'  => 'Secciones de Home',
        ],
        'woocommerce' => [
            'plugin' => 'woocommerce/woocommerce.php',
            'check'  => 'WC',
            'label'  => 'WooCommerce',
        ],
    ];
}

/**
 * Obtiene el estado de todas las features (plugins opcionales)
 * Devuelve un array asociativo feature_key => bool
 *
 * @return array
 */
function site_get_active_features() {
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $registry = site_get_features_registry();
    $features = [];

    foreach ($registry as $key => $def) {
        $features[$key] = is_plugin_active($def['plugin']) && function_exists($def['check']);
    }

    return $features;
}

/**
 * Verifica si una feature específica está activa
 *
 * @param string $feature_key Clave de la feature (ej: 'memberships', 'referrals_points')
 * @return bool
 */
function site_is_feature_active($feature_key) {
    $features = site_get_active_features();
    return isset($features[$feature_key]) ? $features[$feature_key] : false;
}
