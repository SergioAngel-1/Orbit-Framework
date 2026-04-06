<?php
/**
 * WooCommerce HTTP Client
 * 
 * Handles HTTP requests to WooCommerce REST API with authentication
 * 
 * @package Starter
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Starter_WC_HTTP_Client {
    
    const TIMEOUT = 45;

    private static function normalize_headers($headers) {
        $result = [];
        if (is_array($headers)) {
            foreach ($headers as $key => $value) {
                $result[strtolower((string) $key)] = $value;
            }
            return $result;
        }

        if (is_object($headers) || $headers instanceof Traversable) {
            foreach ($headers as $key => $value) {
                $result[strtolower((string) $key)] = $value;
            }
        }

        return $result;
    }
    
    /**
     * Build WooCommerce API URL
     * 
     * @param string $route API route
     * @param array $params Query parameters
     * @return string
     */
    public static function build_url($route, $params = []) {
        $route = ltrim($route, '/');
        $base = home_url('/wp-json/wc/v3/');
        $url = $base . $route;
        
        if (!empty($params)) {
            $url = add_query_arg($params, $url);
        }
        
        return $url;
    }
    
    /**
     * Prepare request headers
     * 
     * @param array $credentials [key, secret]
     * @param array $additional_headers Additional headers to include
     * @return array
     */
    public static function prepare_headers($credentials, $additional_headers = []) {
        list($key, $secret) = $credentials;
        
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($key . ':' . $secret),
        ];
        
        return array_merge($headers, $additional_headers);
    }
    
    /**
     * Prepare request arguments
     * 
     * @param string $method HTTP method
     * @param array $credentials WooCommerce credentials
     * @param WP_REST_Request $request Original request
     * @param array $params Query parameters
     * @return array
     */
    public static function prepare_args($method, $credentials, WP_REST_Request $request, $params = []) {
        $method = strtoupper($method);
        
        // Add auth via query on non-HTTPS for compatibility
        $scheme = parse_url(home_url(), PHP_URL_SCHEME);
        if (strtolower($scheme) !== 'https') {
            list($key, $secret) = $credentials;
            $params['consumer_key'] = $key;
            $params['consumer_secret'] = $secret;
        }
        
        // Prepare headers
        $additional_headers = [];
        
        // Propagate idempotency header if provided
        $incoming_headers = function_exists('getallheaders') ? getallheaders() : [];
        if (!empty($incoming_headers['X-Idempotency-Key'])) {
            $additional_headers['X-Idempotency-Key'] = sanitize_text_field($incoming_headers['X-Idempotency-Key']);
        }
        
        // Propagate client IP for audit (usar función centralizada con trusted proxies)
        $client_ip = function_exists('starter_get_client_ip') ? starter_get_client_ip() : (
            !empty($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''
        );
            
        if (!empty($client_ip)) {
            $additional_headers['X-Forwarded-For'] = $client_ip;
        }
        
        $headers = self::prepare_headers($credentials, $additional_headers);
        
        $args = [
            'method'  => $method,
            'headers' => $headers,
            'timeout' => self::TIMEOUT,
        ];
        
        // Disable SSL verification in local environment
        if (defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE === 'local') {
            $args['sslverify'] = false;
        }
        
        // Add body for POST/PUT/PATCH
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $body = $request->get_json_params();
            if (empty($body)) {
                $body = $request->get_body_params();
            }

            // Prevenir IDOR: forzar customer_id al usuario JWT autenticado en POST /orders
            // Esta validación DEBE hacerse aquí (nivel proxy) porque get_current_user_id()
            // tiene el contexto JWT correcto. En el hook de WC interno (OAuth) no lo tiene.
            if ($method === 'POST' && isset($body['customer_id'])) {
                $current_user_id = get_current_user_id();
                $body_customer_id = absint($body['customer_id']);

                if ($current_user_id > 0 && $body_customer_id !== $current_user_id) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log(sprintf(
                            '[Starter WC Proxy] IDOR prevenido: usuario JWT %d intentó crear orden con customer_id=%d. Forzando customer_id=%d.',
                            $current_user_id,
                            $body_customer_id,
                            $current_user_id
                        ));
                    }
                    $body['customer_id'] = $current_user_id;
                }
            }

            $args['body'] = wp_json_encode($body);
        }
        
        return $args;
    }
    
    /**
     * Execute HTTP request to WooCommerce API
     * 
     * @param string $method HTTP method
     * @param string $route API route
     * @param WP_REST_Request $request Original request
     * @param array $params Query parameters
     * @return array|WP_Error Response array or error
     */
    public static function request($method, $route, WP_REST_Request $request, $params = []) {
        $credentials = Starter_WC_Credentials::get();
        if (is_wp_error($credentials)) {
            return $credentials;
        }

        $request_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('fi_', true);
        
        // CRITICAL FIX: Para peticiones GET a /orders, asegurar que el parámetro 'customer' 
        // esté presente para que WooCommerce valide correctamente los permisos
        if (strtoupper($method) === 'GET' && preg_match('#^orders(?:/|$|\?)#', $route)) {
            // Si el usuario está autenticado y no se especificó 'customer' en los params
            if (is_user_logged_in() && !isset($params['customer'])) {
                $current_user_id = get_current_user_id();
                $params['customer'] = $current_user_id;
                
                error_log(sprintf(
                    '[Starter WC Proxy] Auto-inyectando customer=%d en GET %s (id=%s)',
                    $current_user_id,
                    $route,
                    $request_id
                ));
            }
        }
        
        $url = self::build_url($route, $params);
        $args = self::prepare_args($method, $credentials, $request, $params);

        $is_get = strtoupper($method) === 'GET';
        $max_retries = 2;
        $base_delay_ms = 1000;

        $attempt = 0;
        $response = null;
        while (true) {
            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                if ($is_get && $attempt < $max_retries) {
                    $delay_ms = $base_delay_ms * (int) pow(2, $attempt);
                    usleep($delay_ms * 1000);
                    $attempt++;
                    continue;
                }

                error_log(sprintf(
                    '[Starter WC Proxy] Error en %s %s (id=%s): %s',
                    strtoupper($method),
                    $route,
                    $request_id,
                    $response->get_error_message()
                ));

                return new WP_Error(
                    'wc_proxy_error',
                    $response->get_error_message(),
                    ['status' => 502]
                );
            }

            $status = wp_remote_retrieve_response_code($response);
            if ($is_get && $status >= 500 && $attempt < $max_retries) {
                $delay_ms = $base_delay_ms * (int) pow(2, $attempt);
                usleep($delay_ms * 1000);
                $attempt++;
                continue;
            }

            break;
        }
        
        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $headers = wp_remote_retrieve_headers($response);

        // Log de errores de autorización para debugging
        if ($status === 401 || $status === 403) {
            $error_data = json_decode($body, true);
            error_log(sprintf(
                '[Starter WC Proxy] ERROR %d en %s %s (id=%s): %s',
                $status,
                strtoupper($method),
                $route,
                $request_id,
                isset($error_data['message']) ? $error_data['message'] : $body
            ));
            
            if ($status === 401) {
                error_log('[Starter WC Proxy] ⚠️ Error 401: Las credenciales OAuth en wp-config.php probablemente son inválidas');
                error_log('[Starter WC Proxy] 💡 Solución: Regenera las credenciales en WooCommerce → Ajustes → Avanzado → REST API');
            }
        }

        return [
            'status' => $status,
            'body' => $body,
            'headers' => $headers,
            'request_id' => $request_id,
        ];
    }
    
    /**
     * Extract headers to propagate from response
     * 
     * @param array $headers Response headers
     * @return array Headers to propagate
     */
    public static function extract_propagate_headers($headers) {
        $propagate = ['x-wp-total', 'x-wp-totalpages', 'link', 'retry-after', 'x-request-id', 'cf-ray'];
        $result = [];

        $normalized = self::normalize_headers($headers);
        foreach ($propagate as $key) {
            if (isset($normalized[$key])) {
                $result[$key] = $normalized[$key];
            }
        }

        return $result;
    }
}
