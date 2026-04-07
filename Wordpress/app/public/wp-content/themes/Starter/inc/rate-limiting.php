<?php
/**
 * Rate Limiting para Endpoints de Autenticación
 * 
 * Previene ataques de fuerza bruta limitando el número de intentos
 * de login y registro por IP en un período de tiempo.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Configuración de rate limiting
 */
define('STARTER_RATE_LIMIT_LOGIN_ATTEMPTS', 5);      // Máximo 5 intentos de login
define('STARTER_RATE_LIMIT_LOGIN_WINDOW', 900);      // En 15 minutos (900 segundos)
define('STARTER_RATE_LIMIT_REGISTER_ATTEMPTS', 3);   // Máximo 3 registros
define('STARTER_RATE_LIMIT_REGISTER_WINDOW', 3600);  // En 1 hora (3600 segundos)
define('STARTER_RATE_LIMIT_BLOCK_DURATION', 1800);   // Bloquear por 30 minutos
define('STARTER_RATE_LIMIT_PWREQ_ATTEMPTS', 5);
define('STARTER_RATE_LIMIT_PWREQ_WINDOW', 3600);
define('STARTER_RATE_LIMIT_PWRESET_ATTEMPTS', 10);
define('STARTER_RATE_LIMIT_PWRESET_WINDOW', 3600);
define('STARTER_RATE_LIMIT_CONTACT_ATTEMPTS', 8);
define('STARTER_RATE_LIMIT_CONTACT_WINDOW', 3600);
define('STARTER_RATE_LIMIT_ORDEREMAIL_ATTEMPTS', 3);
define('STARTER_RATE_LIMIT_ORDEREMAIL_WINDOW', 3600);
define('STARTER_RATE_LIMIT_POINTS_TRANSFER_ATTEMPTS', 5);
define('STARTER_RATE_LIMIT_POINTS_TRANSFER_WINDOW', 3600);
define('STARTER_RATE_LIMIT_REFERRAL_VALIDATE_ATTEMPTS', 60);
define('STARTER_RATE_LIMIT_REFERRAL_VALIDATE_WINDOW', 3600);
define('STARTER_RATE_LIMIT_MEMBERSHIP_VERIFY_ATTEMPTS', 20); // 20 verificaciones por hora
define('STARTER_RATE_LIMIT_MEMBERSHIP_VERIFY_WINDOW', 3600);
define('STARTER_RATE_LIMIT_REVIEW_ATTEMPTS', 30);        // Máximo 30 reseñas por hora
define('STARTER_RATE_LIMIT_REVIEW_WINDOW', 3600);        // En 1 hora
define('STARTER_RATE_LIMIT_REVIEW_REPLY_ATTEMPTS', 10);  // Máximo 10 respuestas por hora
define('STARTER_RATE_LIMIT_REVIEW_REPLY_WINDOW', 3600);  // En 1 hora
define('STARTER_RATE_LIMIT_ORDER_CONFIRM_ATTEMPTS', 10); // Máximo 10 confirmaciones por hora
define('STARTER_RATE_LIMIT_ORDER_CONFIRM_WINDOW', 3600); // En 1 hora
define('STARTER_RATE_LIMIT_PENDING_ORDERS_ATTEMPTS', 30); // Máximo 30 consultas de pedidos pendientes por hora
define('STARTER_RATE_LIMIT_PENDING_ORDERS_WINDOW', 3600);  // En 1 hora
define('STARTER_RATE_LIMIT_WOMPI_PURCHASE_ATTEMPTS', 10);  // Máximo 10 compras pendientes por hora (FC + membresías + checkout)
define('STARTER_RATE_LIMIT_WOMPI_PURCHASE_WINDOW', 3600);  // En 1 hora
define('STARTER_RATE_LIMIT_WOMPI_CONFIRM_ATTEMPTS', 15);   // Máximo 15 confirmaciones por hora
define('STARTER_RATE_LIMIT_WOMPI_CONFIRM_WINDOW', 3600);   // En 1 hora
define('STARTER_RATE_LIMIT_WOMPI_WEBHOOK_ATTEMPTS', 120);  // Máximo 120 webhooks por hora (alto pero protege contra flood)
define('STARTER_RATE_LIMIT_WOMPI_WEBHOOK_WINDOW', 3600);   // En 1 hora

/**
 * Obtener la IP del cliente de forma segura
 * 
 * REMOTE_ADDR es la única fuente confiable por defecto.
 * Los headers X-Forwarded-For / X-Real-IP solo se usan si la petición
 * proviene de un proxy de confianza definido en STARTER_TRUSTED_PROXIES.
 * 
 * Para configurar proxies de confianza (Cloudflare, Nginx, etc.),
 * añadir en wp-config.php:
 *   define('STARTER_TRUSTED_PROXIES', ['127.0.0.1', '::1', '172.17.0.0/16']);
 */
function starter_get_client_ip() {
    $remote_addr = isset($_SERVER['REMOTE_ADDR']) ? trim($_SERVER['REMOTE_ADDR']) : '';
    
    // Solo confiar en headers de proxy si REMOTE_ADDR es un proxy conocido
    $trusted_proxies = defined('STARTER_TRUSTED_PROXIES') ? STARTER_TRUSTED_PROXIES : [];
    
    if (!empty($trusted_proxies) && starter_ip_in_ranges($remote_addr, $trusted_proxies)) {
        // La petición viene de un proxy de confianza: leer IP real del header
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // Tomar la primera IP (IP del cliente original)
            $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = trim($_SERVER['HTTP_X_REAL_IP']);
        } else {
            $ip = $remote_addr;
        }
    } else {
        // Sin proxy de confianza: REMOTE_ADDR es la única fuente segura
        $ip = $remote_addr;
    }
    
    // Sanitizar la IP
    $ip = filter_var($ip, FILTER_VALIDATE_IP);
    
    return $ip ? $ip : '0.0.0.0';
}

/**
 * Verificar si una IP está dentro de un conjunto de rangos CIDR o IPs exactas
 */
function starter_ip_in_ranges($ip, array $ranges) {
    foreach ($ranges as $range) {
        if (strpos($range, '/') !== false) {
            // CIDR notation
            list($subnet, $bits) = explode('/', $range, 2);
            $subnet_long = ip2long($subnet);
            $ip_long = ip2long($ip);
            $mask = -1 << (32 - (int)$bits);
            if ($subnet_long !== false && $ip_long !== false && ($ip_long & $mask) === ($subnet_long & $mask)) {
                return true;
            }
        } elseif ($ip === $range) {
            return true;
        }
    }
    return false;
}

/**
 * Verificar si una IP está bloqueada
 */
function starter_is_ip_blocked($ip, $action = 'login') {
    $transient_key = 'starter_blocked_' . $action . '_' . md5($ip);
    $block_data = get_transient($transient_key);
    
    // Soporte para formato antiguo (solo timestamp) y nuevo (array con datos)
    if ($block_data) {
        $blocked_until = is_array($block_data) ? $block_data['blocked_until'] : $block_data;
        
        if ($blocked_until > time()) {
            $remaining_time = $blocked_until - time();
            $minutes = ceil($remaining_time / 60);
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("IP bloqueada: {$ip} para acción: {$action}. Tiempo restante: {$minutes} minutos");
            }
            
            return array(
                'blocked' => true,
                'remaining_time' => $remaining_time,
                'minutes' => $minutes
            );
        }
        
        // Si el transient expiró, eliminarlo
        delete_transient($transient_key);
    }
    
    return array('blocked' => false);
}

/**
 * Registrar un intento fallido
 */
function starter_record_failed_attempt($ip, $action = 'login') {
    // Si la IP ya está bloqueada, no renovar el bloqueo ni contar el intento
    $block_status = starter_is_ip_blocked($ip, $action);
    if ($block_status['blocked']) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Intento durante bloqueo: {$ip} para acción: {$action}. Tiempo restante: " . (isset($block_status['minutes']) ? $block_status['minutes'] : 0) . " minutos");
        }
        return true; // Mantener estado de bloqueo sin renovarlo
    }

    $transient_key = 'starter_attempts_' . $action . '_' . md5($ip);
    $attempts = get_transient($transient_key);
    
    if (!$attempts) {
        $attempts = array(
            'count' => 0,
            'first_attempt' => time()
        );
    }
    
    $attempts['count']++;
    $attempts['last_attempt'] = time();
    
    // Determinar límites según la acción
    if ($action === 'login') {
        $max_attempts = STARTER_RATE_LIMIT_LOGIN_ATTEMPTS;
        $window = STARTER_RATE_LIMIT_LOGIN_WINDOW;
    } elseif ($action === 'register') {
        $max_attempts = STARTER_RATE_LIMIT_REGISTER_ATTEMPTS;
        $window = STARTER_RATE_LIMIT_REGISTER_WINDOW;
    } elseif ($action === 'pwreq') {
        $max_attempts = STARTER_RATE_LIMIT_PWREQ_ATTEMPTS;
        $window = STARTER_RATE_LIMIT_PWREQ_WINDOW;
    } elseif ($action === 'pwreset') {
        $max_attempts = STARTER_RATE_LIMIT_PWRESET_ATTEMPTS;
        $window = STARTER_RATE_LIMIT_PWRESET_WINDOW;
    } elseif ($action === 'contact') {
        $max_attempts = STARTER_RATE_LIMIT_CONTACT_ATTEMPTS;
        $window = STARTER_RATE_LIMIT_CONTACT_WINDOW;
    } elseif ($action === 'orderemail') {
        $max_attempts = STARTER_RATE_LIMIT_ORDEREMAIL_ATTEMPTS;
        $window = STARTER_RATE_LIMIT_ORDEREMAIL_WINDOW;
    } elseif ($action === 'points_transfer') {
        $max_attempts = STARTER_RATE_LIMIT_POINTS_TRANSFER_ATTEMPTS;
        $window = STARTER_RATE_LIMIT_POINTS_TRANSFER_WINDOW;
    } elseif ($action === 'referral_validate') {
        $max_attempts = STARTER_RATE_LIMIT_REFERRAL_VALIDATE_ATTEMPTS;
        $window = STARTER_RATE_LIMIT_REFERRAL_VALIDATE_WINDOW;
    } elseif ($action === 'membership_verify') {
        $max_attempts = STARTER_RATE_LIMIT_MEMBERSHIP_VERIFY_ATTEMPTS;
        $window = STARTER_RATE_LIMIT_MEMBERSHIP_VERIFY_WINDOW;
    } elseif ($action === 'avatar') {
        $max_attempts = defined('STARTER_RATE_LIMIT_AVATAR_ATTEMPTS') ? STARTER_RATE_LIMIT_AVATAR_ATTEMPTS : 10;
        $window = defined('STARTER_RATE_LIMIT_AVATAR_WINDOW') ? STARTER_RATE_LIMIT_AVATAR_WINDOW : 3600;
    } elseif ($action === 'review') {
        $max_attempts = STARTER_RATE_LIMIT_REVIEW_ATTEMPTS;
        $window = STARTER_RATE_LIMIT_REVIEW_WINDOW;
    } elseif ($action === 'review_reply') {
        $max_attempts = STARTER_RATE_LIMIT_REVIEW_REPLY_ATTEMPTS;
        $window = STARTER_RATE_LIMIT_REVIEW_REPLY_WINDOW;
    } elseif ($action === 'order_confirm') {
        $max_attempts = STARTER_RATE_LIMIT_ORDER_CONFIRM_ATTEMPTS;
        $window = STARTER_RATE_LIMIT_ORDER_CONFIRM_WINDOW;
    } elseif ($action === 'pending_orders_query') {
        $max_attempts = STARTER_RATE_LIMIT_PENDING_ORDERS_ATTEMPTS;
        $window = STARTER_RATE_LIMIT_PENDING_ORDERS_WINDOW;
    } elseif ($action === 'wompi_purchase') {
        $max_attempts = STARTER_RATE_LIMIT_WOMPI_PURCHASE_ATTEMPTS;
        $window = STARTER_RATE_LIMIT_WOMPI_PURCHASE_WINDOW;
    } elseif ($action === 'wompi_confirm') {
        $max_attempts = STARTER_RATE_LIMIT_WOMPI_CONFIRM_ATTEMPTS;
        $window = STARTER_RATE_LIMIT_WOMPI_CONFIRM_WINDOW;
    } elseif ($action === 'wompi_webhook') {
        $max_attempts = STARTER_RATE_LIMIT_WOMPI_WEBHOOK_ATTEMPTS;
        $window = STARTER_RATE_LIMIT_WOMPI_WEBHOOK_WINDOW;
    } else {
        $max_attempts = STARTER_RATE_LIMIT_LOGIN_ATTEMPTS;
        $window = STARTER_RATE_LIMIT_LOGIN_WINDOW;
    }
    
    // Guardar intentos con expiración de la ventana de tiempo
    set_transient($transient_key, $attempts, $window);
    
    // Si se excedió el límite, bloquear la IP (solo si aún no estaba bloqueada)
    if ($attempts['count'] >= $max_attempts) {
        $block_transient_key = 'starter_blocked_' . $action . '_' . md5($ip);
        $block_until = time() + STARTER_RATE_LIMIT_BLOCK_DURATION;
        
        // Guardar información del bloqueo incluyendo la IP real
        $block_data = array(
            'blocked_until' => $block_until,
            'ip' => $ip,
            'action' => $action,
            'blocked_at' => time()
        );
        
        set_transient($block_transient_key, $block_data, STARTER_RATE_LIMIT_BLOCK_DURATION);
        
        error_log("IP bloqueada: {$ip} para acción: {$action} por " . (STARTER_RATE_LIMIT_BLOCK_DURATION / 60) . " minutos");
        
        return true; // IP bloqueada
    }
    
    return false; // No bloqueada aún
}

/**
 * Verificar si estamos en entorno local
 * 
 * @return bool True si es entorno local
 */
function starter_is_local_environment() {
    $ip = starter_get_client_ip();
    
    // IPs locales comunes
    $local_ips = array(
        '127.0.0.1',
        '::1',
        'localhost',
        '0.0.0.0'
    );
    
    // Verificar si la IP es local
    if (in_array($ip, $local_ips)) {
        return true;
    }
    
    // Verificar rangos de IP privadas
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return true;
    }
    
    // Verificar si estamos en desarrollo (WP_DEBUG)
    if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE === 'local') {
        return true;
    }
    
    return false;
}

/**
 * Limpiar intentos después de un login exitoso
 */
function starter_clear_attempts($ip, $action = 'login') {
    $transient_key = 'starter_attempts_' . $action . '_' . md5($ip);
    delete_transient($transient_key);
    
}

/**
 * Hook para verificar rate limiting en login
 */
function starter_check_login_rate_limit($request) {
    // Saltar rate limiting en entorno local
    if (starter_is_local_environment()) {
        return null;
    }
    
    $ip = starter_get_client_ip();
    
    // Verificar si la IP está bloqueada
    $block_status = starter_is_ip_blocked($ip, 'login');
    
    if ($block_status['blocked']) {
        $resp = new WP_REST_Response(array(
            'success' => false,
            'code' => 'rate_limit_exceeded',
            'message' => sprintf(
                'Demasiados intentos de inicio de sesión. Por favor, intenta de nuevo en %d minutos.',
                $block_status['minutes']
            ),
            'retry_after' => $block_status['remaining_time']
        ), 429); // 429 Too Many Requests
        if (!empty($block_status['remaining_time'])) {
            $resp->header('Retry-After', $block_status['remaining_time']);
        }
        return $resp;
    }
    
    return null; // Permitir continuar
}

/**
 * Hook para verificar rate limiting en registro
 */
function starter_check_register_rate_limit($request) {
    $ip = starter_get_client_ip();
    
    // Verificar si la IP está bloqueada
    $block_status = starter_is_ip_blocked($ip, 'register');
    
    if ($block_status['blocked']) {
        $resp = new WP_REST_Response(array(
            'success' => false,
            'code' => 'rate_limit_exceeded',
            'message' => sprintf(
                'Demasiados intentos de registro. Por favor, intenta de nuevo en %d minutos.',
                $block_status['minutes']
            ),
            'retry_after' => $block_status['remaining_time']
        ), 429); // 429 Too Many Requests
        if (!empty($block_status['remaining_time'])) {
            $resp->header('Retry-After', $block_status['remaining_time']);
        }
        return $resp;
    }
    
    return null; // Permitir continuar
}

/**
 * Registrar intento fallido de login
 */
function starter_handle_failed_login($error, $request) {
    // Solo registrar si es un error de autenticación
    if (is_wp_error($error) && in_array($error->get_error_code(), array('incorrect_password', 'invalid_username', 'invalid_email'))) {
        $ip = starter_get_client_ip();
        starter_record_failed_attempt($ip, 'login');
    }
}

/**
 * Limpiar intentos después de login exitoso
 */
function starter_handle_successful_login($user) {
    $ip = starter_get_client_ip();
    starter_clear_attempts($ip, 'login');
}

/**
 * Integrar rate limiting con el endpoint de autenticación personalizado
 */
add_filter('rest_pre_dispatch', function($result, $server, $request) {
    $route = $request->get_route();
    $method = $request->get_method();

    // ── RATE LIMITING CRÍTICO (siempre se ejecuta, ignora $result previo) ────
    // El plugin jwt-auth (prioridad 10) puede propagar un WP_Error jwt_auth_*
    // ANTES de este filtro cuando hay un JWT expirado en el header. Nuestro
    // limpiador (prioridad 11) lo limpia después para rutas públicas, pero si
    // el guard "$result !== null" está primero, el rate-limiting nunca verifica
    // si la IP está bloqueada. Esto permitiría brute-force con JWT expirado.
    //
    // Solución: verificar bloqueo de IP para rutas de seguridad ANTES del guard.
    if (in_array($route, array('/starter/v1/auth', '/jwt-auth/v1/token'), true)) {
        $rate_limit_check = starter_check_login_rate_limit($request);
        if ($rate_limit_check) {
            return $rate_limit_check;
        }
    }
    if (in_array($route, array('/starter/v1/register', '/wp/v2/users/register'), true)) {
        $rate_limit_check = starter_check_register_rate_limit($request);
        if ($rate_limit_check) {
            return $rate_limit_check;
        }
    }
    if (in_array($route, array('/starter/v1/request-password-reset', '/starter/v1/reset-password'), true)) {
        $ip = starter_get_client_ip();
        $block_status = starter_is_ip_blocked($ip, 'pwreq');
        if ($block_status['blocked']) {
            $resp = new WP_REST_Response(array(
                'success' => false,
                'code' => 'rate_limit_exceeded',
                'message' => sprintf(
                    'Demasiados intentos de restablecimiento. Intenta de nuevo en %d minutos.',
                    $block_status['minutes']
                ),
                'retry_after' => $block_status['remaining_time']
            ), 429);
            if (!empty($block_status['remaining_time'])) {
                $resp->header('Retry-After', $block_status['remaining_time']);
            }
            return $resp;
        }
    }
    if (in_array($route, array('/starter/v1/validate-password-reset', '/starter/v1/complete-password-reset'), true)) {
        $ip = starter_get_client_ip();
        $block_status = starter_is_ip_blocked($ip, 'pwreset');
        if ($block_status['blocked']) {
            $resp = new WP_REST_Response(array(
                'success' => false,
                'code' => 'rate_limit_exceeded',
                'message' => sprintf(
                    'Demasiados intentos de validación. Intenta de nuevo en %d minutos.',
                    $block_status['minutes']
                ),
                'retry_after' => $block_status['remaining_time']
            ), 429);
            if (!empty($block_status['remaining_time'])) {
                $resp->header('Retry-After', $block_status['remaining_time']);
            }
            return $resp;
        }
    }
    // ── GUARD GENERAL (para rutas no-críticas) ─────────────────────────
    // Para el resto de rutas, si ya hay un error previo (ej. JWT inválido
    // del plugin jwt-auth que será limpiado por el filtro de prioridad 11),
    // no continuar procesando rate-limiting innecesariamente.
    if ($result !== null) {
        return $result;
    }
    if ($method !== 'POST' && !in_array($route, array('/starter/v1/referrals/validate-code'), true) && strpos($route, '/starter/v1/membership/verify/') !== 0) {
        return $result;
    }

    if (in_array($route, array('/starter/v1/contact'), true)) {
        $ip = starter_get_client_ip();
        $block_status = starter_is_ip_blocked($ip, 'contact');
        if ($block_status['blocked']) {
            $resp = new WP_REST_Response(array(
                'success' => false,
                'code' => 'rate_limit_exceeded',
                'message' => sprintf(
                    'Demasiados envíos de contacto. Intenta de nuevo en %d minutos.',
                    $block_status['minutes']
                ),
                'retry_after' => $block_status['remaining_time']
            ), 429);
            if (!empty($block_status['remaining_time'])) {
                $resp->header('Retry-After', $block_status['remaining_time']);
            }
            return $resp;
        }
    }
    if (in_array($route, array('/starter/v1/order-email'), true)) {
        $ip = starter_get_client_ip();
        $block_status = starter_is_ip_blocked($ip, 'orderemail');
        if ($block_status['blocked']) {
            $resp = new WP_REST_Response(array(
                'success' => false,
                'code' => 'rate_limit_exceeded',
                'message' => sprintf(
                    'Demasiados reenvíos de correo de pedido. Intenta de nuevo en %d minutos.',
                    $block_status['minutes']
                ),
                'retry_after' => $block_status['remaining_time']
            ), 429);
            if (!empty($block_status['remaining_time'])) {
                $resp->header('Retry-After', $block_status['remaining_time']);
            }
            return $resp;
        }
    }
    // Wallet transfer
    if (in_array($route, array('/starter/v1/points/transfer'), true)) {
        $ip = starter_get_client_ip();
        $block_status = starter_is_ip_blocked($ip, 'points_transfer');
        if ($block_status['blocked']) {
            $resp = new WP_REST_Response(array(
                'success' => false,
                'code' => 'rate_limit_exceeded',
                'message' => sprintf(
                    'Demasiadas transferencias de wallet. Intenta de nuevo en %d minutos.',
                    $block_status['minutes']
                ),
                'retry_after' => $block_status['remaining_time']
            ), 429);
            if (!empty($block_status['remaining_time'])) {
                $resp->header('Retry-After', $block_status['remaining_time']);
            }
            return $resp;
        }
    }
    // Membership verification (GET, public)
    if (strpos($route, '/starter/v1/membership/verify/') === 0) {
        $ip = starter_get_client_ip();
        $block_status = starter_is_ip_blocked($ip, 'membership_verify');
        if ($block_status['blocked']) {
            $resp = new WP_REST_Response(array(
                'success' => false,
                'code' => 'rate_limit_exceeded',
                'message' => sprintf(
                    'Demasiadas verificaciones. Intenta de nuevo en %d minutos.',
                    $block_status['minutes']
                ),
                'retry_after' => $block_status['remaining_time']
            ), 429);
            if (!empty($block_status['remaining_time'])) {
                $resp->header('Retry-After', $block_status['remaining_time']);
            }
            return $resp;
        }
        // Registrar intento (cada verificación cuenta)
        starter_record_failed_attempt($ip, 'membership_verify');
    }
    // Reviews rate limiting (POST /starter/v1/reviews)
    if ($route === '/starter/v1/reviews' && $method === 'POST') {
        $ip = starter_get_client_ip();
        $block_status = starter_is_ip_blocked($ip, 'review');
        if ($block_status['blocked']) {
            $resp = new WP_REST_Response(array(
                'success' => false,
                'code' => 'rate_limit_exceeded',
                'message' => sprintf(
                    'Demasiadas reseñas. Intenta de nuevo en %d minutos.',
                    $block_status['minutes']
                ),
                'retry_after' => $block_status['remaining_time']
            ), 429);
            if (!empty($block_status['remaining_time'])) {
                $resp->header('Retry-After', $block_status['remaining_time']);
            }
            return $resp;
        }
        // Cada intento de reseña cuenta
        starter_record_failed_attempt($ip, 'review');
    }
    // Review reply rate limiting (POST /starter/v1/reviews/{id}/reply)
    if (preg_match('#^/starter/v1/reviews/\d+/reply$#', $route) && $method === 'POST') {
        $ip = starter_get_client_ip();
        $block_status = starter_is_ip_blocked($ip, 'review_reply');
        if ($block_status['blocked']) {
            $resp = new WP_REST_Response(array(
                'success' => false,
                'code' => 'rate_limit_exceeded',
                'message' => sprintf(
                    'Demasiadas respuestas. Intenta de nuevo en %d minutos.',
                    $block_status['minutes']
                ),
                'retry_after' => $block_status['remaining_time']
            ), 429);
            if (!empty($block_status['remaining_time'])) {
                $resp->header('Retry-After', $block_status['remaining_time']);
            }
            return $resp;
        }
        // Cada intento de respuesta cuenta
        starter_record_failed_attempt($ip, 'review_reply');
    }
    // Pending orders query rate limiting (GET /starter/v1/reviews/pending-orders)
    if ($route === '/starter/v1/reviews/pending-orders' && $method === 'GET') {
        $ip = starter_get_client_ip();
        $block_status = starter_is_ip_blocked($ip, 'pending_orders_query');
        if ($block_status['blocked']) {
            $resp = new WP_REST_Response(array(
                'success' => false,
                'code' => 'rate_limit_exceeded',
                'message' => sprintf(
                    'Demasiadas consultas. Intenta de nuevo en %d minutos.',
                    $block_status['minutes']
                ),
                'retry_after' => $block_status['remaining_time']
            ), 429);
            if (!empty($block_status['remaining_time'])) {
                $resp->header('Retry-After', $block_status['remaining_time']);
            }
            return $resp;
        }
        starter_record_failed_attempt($ip, 'pending_orders_query');
    }
    // Order confirm rate limiting (POST /starter/v1/reviews/confirm-order)
    if ($route === '/starter/v1/reviews/confirm-order' && $method === 'POST') {
        $ip = starter_get_client_ip();
        $block_status = starter_is_ip_blocked($ip, 'order_confirm');
        if ($block_status['blocked']) {
            $resp = new WP_REST_Response(array(
                'success' => false,
                'code' => 'rate_limit_exceeded',
                'message' => sprintf(
                    'Demasiadas confirmaciones. Intenta de nuevo en %d minutos.',
                    $block_status['minutes']
                ),
                'retry_after' => $block_status['remaining_time']
            ), 429);
            if (!empty($block_status['remaining_time'])) {
                $resp->header('Retry-After', $block_status['remaining_time']);
            }
            return $resp;
        }
        starter_record_failed_attempt($ip, 'order_confirm');
    }
    // Order rating rate limiting (POST /starter/v1/reviews/rate-order)
    if ($route === '/starter/v1/reviews/rate-order' && $method === 'POST') {
        $ip = starter_get_client_ip();
        $block_status = starter_is_ip_blocked($ip, 'review');
        if ($block_status['blocked']) {
            $resp = new WP_REST_Response(array(
                'success' => false,
                'code' => 'rate_limit_exceeded',
                'message' => sprintf(
                    'Demasiadas calificaciones. Intenta de nuevo en %d minutos.',
                    $block_status['minutes']
                ),
                'retry_after' => $block_status['remaining_time']
            ), 429);
            if (!empty($block_status['remaining_time'])) {
                $resp->header('Retry-After', $block_status['remaining_time']);
            }
            return $resp;
        }
        starter_record_failed_attempt($ip, 'review');
    }
    // Wompi purchase pending registration (POST - cada intento cuenta)
    if ($method === 'POST' && in_array($route, array(
        '/starter/v1/virtual-coins/pending-purchase',
        '/starter/v1/membership/pending-purchase',
        '/starter/v1/checkout/card-payment/pending',
    ), true)) {
        $ip = starter_get_client_ip();
        $block_status = starter_is_ip_blocked($ip, 'wompi_purchase');
        if ($block_status['blocked']) {
            $resp = new WP_REST_Response(array(
                'success' => false,
                'code' => 'rate_limit_exceeded',
                'message' => sprintf(
                    'Demasiados intentos de compra. Intenta de nuevo en %d minutos.',
                    $block_status['minutes']
                ),
                'retry_after' => $block_status['remaining_time']
            ), 429);
            if (!empty($block_status['remaining_time'])) {
                $resp->header('Retry-After', $block_status['remaining_time']);
            }
            return $resp;
        }
        starter_record_failed_attempt($ip, 'wompi_purchase');
    }
    // Wompi purchase confirmation + link-order (POST - cada intento cuenta)
    if ($method === 'POST' && in_array($route, array(
        '/starter/v1/virtual-coins/confirm-purchase',
        '/starter/v1/membership/confirm-purchase',
        '/starter/v1/checkout/card-payment/confirm',
        '/starter/v1/checkout/card-payment/link-order',
    ), true)) {
        $ip = starter_get_client_ip();
        $block_status = starter_is_ip_blocked($ip, 'wompi_confirm');
        if ($block_status['blocked']) {
            $resp = new WP_REST_Response(array(
                'success' => false,
                'code' => 'rate_limit_exceeded',
                'message' => sprintf(
                    'Demasiados intentos de confirmación. Intenta de nuevo en %d minutos.',
                    $block_status['minutes']
                ),
                'retry_after' => $block_status['remaining_time']
            ), 429);
            if (!empty($block_status['remaining_time'])) {
                $resp->header('Retry-After', $block_status['remaining_time']);
            }
            return $resp;
        }
        starter_record_failed_attempt($ip, 'wompi_confirm');
    }
    // Wompi webhook rate limiting (POST - protege contra flood de webhooks falsos)
    if ($method === 'POST' && $route === '/starter/v1/wompi/webhook') {
        $ip = starter_get_client_ip();
        $block_status = starter_is_ip_blocked($ip, 'wompi_webhook');
        if ($block_status['blocked']) {
            $resp = new WP_REST_Response(array(
                'success' => false,
                'code' => 'rate_limit_exceeded',
                'message' => 'Too many requests',
                'retry_after' => $block_status['remaining_time']
            ), 429);
            if (!empty($block_status['remaining_time'])) {
                $resp->header('Retry-After', $block_status['remaining_time']);
            }
            return $resp;
        }
        starter_record_failed_attempt($ip, 'wompi_webhook');
    }
    // Referral code validation (GET)
    if (in_array($route, array('/starter/v1/referrals/validate-code'), true)) {
        $ip = starter_get_client_ip();
        $block_status = starter_is_ip_blocked($ip, 'referral_validate');
        if ($block_status['blocked']) {
            $resp = new WP_REST_Response(array(
                'success' => false,
                'code' => 'rate_limit_exceeded',
                'message' => sprintf(
                    'Demasiadas validaciones de código. Intenta de nuevo en %d minutos.',
                    $block_status['minutes']
                ),
                'retry_after' => $block_status['remaining_time']
            ), 429);
            if (!empty($block_status['remaining_time'])) {
                $resp->header('Retry-After', $block_status['remaining_time']);
            }
            return $resp;
        }
    }
    return $result;
}, 10, 3);

/**
 * Modificar el endpoint de auth para registrar intentos fallidos
 */
add_filter('rest_post_dispatch', function($response, $server, $request) {
    $route = $request->get_route();
    $method = $request->get_method();
    if ($method !== 'POST' && !in_array($route, array('/starter/v1/referrals/validate-code'), true)) {
        return $response;
    }
    $action = null;
    if (in_array($route, array('/starter/v1/auth', '/jwt-auth/v1/token'), true)) {
        $action = 'login';
    }    
    if (in_array($route, array('/starter/v1/register', '/wp/v2/users/register'), true)) {
        $action = 'register';
    } elseif (in_array($route, array('/starter/v1/request-password-reset', '/starter/v1/reset-password'), true)) {
        $action = 'pwreq';
    } elseif (in_array($route, array('/starter/v1/validate-password-reset', '/starter/v1/complete-password-reset'), true)) {
        $action = 'pwreset';
    } elseif (in_array($route, array('/starter/v1/contact'), true)) {
        $action = 'contact';
    } elseif (in_array($route, array('/starter/v1/order-email'), true)) {
        $action = 'orderemail';
    } elseif (in_array($route, array('/starter/v1/points/transfer'), true)) {
        $action = 'points_transfer';
    } elseif (in_array($route, array('/starter/v1/referrals/validate-code'), true)) {
        $action = 'referral_validate';
    }
    if (!$action) {
        return $response;
    }
    $data = $response->get_data();
    $status = $response->get_status();
    $success = false;
    if ($action === 'login') {
        // Exitoso si trae token o si es un caso de pendiente/rechazado (no contar como fallo)
        $has_token = (isset($data['success']) && $data['success'] && isset($data['token']));
        $is_pending_or_rejected = (!empty($data['pending']) || !empty($data['rejected'])) && ($status >= 200 && $status < 300);
        $success = ($has_token || $is_pending_or_rejected);
    } elseif ($action === 'referral_validate') {
        // Siempre contar la validación como intento para prevenir enumeración
        $success = false;
    } else {
        $success = ($status >= 200 && $status < 400);
    }

    // Obtener IP una sola vez
    $ip = starter_get_client_ip();

    if ($success) {
        starter_clear_attempts($ip, $action);
        return $response;
    }

    // Si la IP ya está bloqueada, no renovar el bloqueo ni contar el intento.
    $block_status = starter_is_ip_blocked($ip, $action);
    if ($block_status['blocked']) {
        if (!empty($block_status['remaining_time'])) {
            $response->header('Retry-After', $block_status['remaining_time']);
        }
        return $response;
    }

    $blocked = starter_record_failed_attempt($ip, $action);
    if ($blocked) {
        if ($action === 'login') {
            $response->set_data(array(
                'success' => false,
                'code' => 'rate_limit_exceeded',
                'message' => 'Demasiados intentos fallidos. Tu IP ha sido bloqueada temporalmente.'
            ));
        } else {
            $response->set_data(array(
                'success' => false,
                'code' => 'rate_limit_exceeded',
                'message' => 'Se excedió el límite de solicitudes. Tu IP ha sido bloqueada temporalmente.'
            ));
        }
        $response->set_status(429);
        $block_status = starter_is_ip_blocked($ip, $action);
        if (!empty($block_status['remaining_time'])) {
            $response->header('Retry-After', $block_status['remaining_time']);
        }
    }
    return $response;
}, 10, 3);

/**
 * Añadir headers de rate limiting a las respuestas
 */
add_filter('rest_post_dispatch', function($response, $server, $request) {
    $route = $request->get_route();
    $method = $request->get_method();
    if ($method !== 'POST' && !in_array($route, array('/starter/v1/referrals/validate-code'), true)) {
        return $response;
    }
    $action = null;
    if (in_array($route, array('/starter/v1/auth', '/jwt-auth/v1/token'), true)) {
        $action = 'login';
    } elseif (in_array($route, array('/starter/v1/register', '/wp/v2/users/register'), true)) {
        $action = 'register';
    } elseif (in_array($route, array('/starter/v1/request-password-reset', '/starter/v1/reset-password'), true)) {
        $action = 'pwreq';
    } elseif (in_array($route, array('/starter/v1/validate-password-reset', '/starter/v1/complete-password-reset'), true)) {
        $action = 'pwreset';
    } elseif (in_array($route, array('/starter/v1/contact'), true)) {
        $action = 'contact';
    } elseif (in_array($route, array('/starter/v1/order-email'), true)) {
        $action = 'orderemail';
    } elseif (in_array($route, array('/starter/v1/points/transfer'), true)) {
        $action = 'points_transfer';
    } elseif (in_array($route, array('/starter/v1/referrals/validate-code'), true)) {
        $action = 'referral_validate';
    }
    if (!$action) {
        return $response;
    }
    $ip = starter_get_client_ip();
    $transient_key = 'starter_attempts_' . $action . '_' . md5($ip);
    $attempts = get_transient($transient_key);
    if ($action === 'login') {
        $max_attempts = STARTER_RATE_LIMIT_LOGIN_ATTEMPTS;
        $window = STARTER_RATE_LIMIT_LOGIN_WINDOW;
    } elseif ($action === 'register') {
        $max_attempts = STARTER_RATE_LIMIT_REGISTER_ATTEMPTS;
        $window = STARTER_RATE_LIMIT_REGISTER_WINDOW;
    } elseif ($action === 'pwreq') {
        $max_attempts = STARTER_RATE_LIMIT_PWREQ_ATTEMPTS;
        $window = STARTER_RATE_LIMIT_PWREQ_WINDOW;
    } elseif ($action === 'pwreset') {
        $max_attempts = STARTER_RATE_LIMIT_PWRESET_ATTEMPTS;
        $window = STARTER_RATE_LIMIT_PWRESET_WINDOW;
    } elseif ($action === 'contact') {
        $max_attempts = STARTER_RATE_LIMIT_CONTACT_ATTEMPTS;
        $window = STARTER_RATE_LIMIT_CONTACT_WINDOW;
    } elseif ($action === 'orderemail') {
        $max_attempts = STARTER_RATE_LIMIT_ORDEREMAIL_ATTEMPTS;
        $window = STARTER_RATE_LIMIT_ORDEREMAIL_WINDOW;
    } elseif ($action === 'points_transfer') {
        $max_attempts = STARTER_RATE_LIMIT_POINTS_TRANSFER_ATTEMPTS;
        $window = STARTER_RATE_LIMIT_POINTS_TRANSFER_WINDOW;
    } elseif ($action === 'referral_validate') {
        $max_attempts = STARTER_RATE_LIMIT_REFERRAL_VALIDATE_ATTEMPTS;
        $window = STARTER_RATE_LIMIT_REFERRAL_VALIDATE_WINDOW;
    } else {
        $max_attempts = STARTER_RATE_LIMIT_LOGIN_ATTEMPTS;
        $window = STARTER_RATE_LIMIT_LOGIN_WINDOW;
    }
    $remaining = $max_attempts - ($attempts ? $attempts['count'] : 0);
    $response->header('X-RateLimit-Limit', $max_attempts);
    $response->header('X-RateLimit-Remaining', max(0, $remaining));
    if ($attempts && !empty($attempts['first_attempt'])) {
        $reset_time = $attempts['first_attempt'] + $window;
        $response->header('X-RateLimit-Reset', $reset_time);
    }
    return $response;
}, 11, 3);
