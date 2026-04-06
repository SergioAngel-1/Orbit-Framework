<?php
/**
 * WordPress Configuration Template
 * 
 * Copy this file to wp-config.php and fill in the values for your environment.
 * NEVER commit wp-config.php with real credentials to version control.
 *
 * @package Starter
 */

// ** Database settings ** //
define('DB_NAME',     '%%DB_NAME%%');
define('DB_USER',     '%%DB_USER%%');
define('DB_PASSWORD', '%%DB_PASSWORD%%');
define('DB_HOST',     '%%DB_HOST%%');       // Usually 'localhost' or '127.0.0.1'
define('DB_CHARSET',  'utf8');
define('DB_COLLATE',  '');

// ** Environment ** //
// Values: 'local', 'development', 'staging', 'production'
define('WP_ENVIRONMENT_TYPE', '%%ENVIRONMENT%%');

// ** Headless Mode ** //
// URL del frontend React (para redirección headless y CORS)
define('HEADLESS_MODE_CLIENT_URL', '%%FRONTEND_URL%%');

// URL del frontend para emails de restablecimiento de contraseña
define('FRONTEND_URL', '%%FRONTEND_URL%%');

// ** CORS ** //
// Origins permitidos (comma-separated). Si se omite, se deriva de Site Settings → frontend_url.
// define('ALLOWED_CORS_ORIGINS', 'https://mystore.com,https://www.mystore.com');

// ** JWT Authentication ** //
// Generate a unique key: https://api.wordpress.org/secret-key/1.1/salt/
define('JWT_AUTH_SECRET_KEY', '%%JWT_SECRET%%');
define('JWT_AUTH_CORS_ENABLE', true);

// ** WooCommerce REST API ** //
// Generate in WP Admin → WooCommerce → Settings → Advanced → REST API
define('WC_REST_CONSUMER_KEY',    '%%WC_KEY%%');
define('WC_REST_CONSUMER_SECRET', '%%WC_SECRET%%');

// ** Sitemap Generator (optional) ** //
// Uncomment and set if your frontend public dir isn't auto-detected
// define('STARTER_SITEMAPS_DIR', '/path/to/frontend/public/sitemaps');
// define('STARTER_SITEMAP_INDEX_PATH', '/path/to/frontend/public/sitemap-index.xml');

/**#@+
 * Authentication unique keys and salts.
 * Generate at: https://api.wordpress.org/secret-key/1.1/salt/
 */
define('AUTH_KEY',          '%%GENERATE_UNIQUE_KEY%%');
define('SECURE_AUTH_KEY',   '%%GENERATE_UNIQUE_KEY%%');
define('LOGGED_IN_KEY',     '%%GENERATE_UNIQUE_KEY%%');
define('NONCE_KEY',         '%%GENERATE_UNIQUE_KEY%%');
define('AUTH_SALT',         '%%GENERATE_UNIQUE_KEY%%');
define('SECURE_AUTH_SALT',  '%%GENERATE_UNIQUE_KEY%%');
define('LOGGED_IN_SALT',    '%%GENERATE_UNIQUE_KEY%%');
define('NONCE_SALT',        '%%GENERATE_UNIQUE_KEY%%');
define('WP_CACHE_KEY_SALT', '%%GENERATE_UNIQUE_KEY%%');
/**#@-*/

/** Database table prefix. */
$table_prefix = 'wp_';

// Force correct site URLs based on environment
if (!defined('WP_HOME') || !defined('WP_SITEURL')) {
    $http_host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    $is_local_env = (defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE === 'local')
        || preg_match('/(localhost|\.local$|\.test$)/i', $http_host);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if (!empty($http_host)) {
        if (!defined('WP_HOME'))    define('WP_HOME', $scheme . '://' . $http_host);
        if (!defined('WP_SITEURL')) define('WP_SITEURL', $scheme . '://' . $http_host);
    }
}

/** Security hardening */
define('DISALLOW_FILE_EDIT', true);
if (defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE !== 'local') {
    define('FORCE_SSL_ADMIN', true);
}

/** Debugging */
$is_production = defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE === 'production';
define('WP_DEBUG',         !$is_production);
define('WP_DEBUG_LOG',     $is_production ? false : __DIR__ . '/wp-content/logs/debug.log');
define('WP_DEBUG_DISPLAY', !$is_production);
define('SCRIPT_DEBUG',     !$is_production);

if (!$is_production) {
    @ini_set('display_errors', 1);
}

/** Absolute path to the WordPress directory. */
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
