<?php
/**
 * Wompi Payment Gateway Integration
 * 
 * Endpoints REST para integración con Wompi
 * - Generación de firma de integridad
 * - Verificación de transacciones
 * - Webhooks de eventos
 * 
 * @package Starter
 * @since 1.0.0
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Configuración de Wompi
 * Las credenciales se obtienen de las opciones de WordPress
 */
class Starter_Wompi_Config {
    
    /**
     * Obtener configuración de Wompi
     */
    public static function get_config() {
        // Usar '1' y '0' en lugar de true/false para evitar problemas con get_option
        $sandbox_option = get_option('starter_wompi_sandbox', '1');
        $is_sandbox = ($sandbox_option === true || $sandbox_option === '1' || $sandbox_option === 1);
        
        return [
            'sandbox' => $is_sandbox,
            'public_key' => $is_sandbox 
                ? get_option('starter_wompi_public_key_sandbox', '')
                : get_option('starter_wompi_public_key_prod', ''),
            'private_key' => $is_sandbox
                ? get_option('starter_wompi_private_key_sandbox', '')
                : get_option('starter_wompi_private_key_prod', ''),
            'integrity_secret' => $is_sandbox
                ? get_option('starter_wompi_integrity_sandbox', '')
                : get_option('starter_wompi_integrity_prod', ''),
            'events_secret' => $is_sandbox
                ? get_option('starter_wompi_events_sandbox', '')
                : get_option('starter_wompi_events_prod', ''),
            'api_url' => $is_sandbox
                ? 'https://sandbox.wompi.co/v1'
                : 'https://production.wompi.co/v1',
            'currency' => function_exists('site_get_currency_code') ? site_get_currency_code() : 'COP',
        ];
    }
    
    /**
     * Obtener solo la llave pública (seguro para frontend)
     */
    public static function get_public_config() {
        $config = self::get_config();
        
        return [
            'public_key' => $config['public_key'],
            'sandbox' => $config['sandbox'],
            'currency' => $config['currency'],
        ];
    }
}

/**
 * Registrar estado de orden personalizado: Verificando Pago con Wompi
 * Se usa cuando el pago queda en PENDING (PSE, Nequi, etc.) y se está esperando confirmación
 */
add_action('init', function() {
    register_post_status('wc-wompi-verifying', [
        'label'                     => 'Verificando Pago con Wompi',
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop(
            'Verificando Pago con Wompi <span class="count">(%s)</span>',
            'Verificando Pago con Wompi <span class="count">(%s)</span>'
        ),
    ]);
});

// Agregar el estado personalizado a la lista de estados de WooCommerce
add_filter('wc_order_statuses', function($order_statuses) {
    $new_statuses = [];
    
    foreach ($order_statuses as $key => $label) {
        $new_statuses[$key] = $label;
        // Insertar después de "Pendiente de pago"
        if ($key === 'wc-pending') {
            $new_statuses['wc-wompi-verifying'] = 'Verificando Pago con Wompi';
        }
    }
    
    return $new_statuses;
});

// Estilo visual para el estado en el admin de WooCommerce
add_action('admin_head', function() {
    echo '<style>
        .order-status.status-wompi-verifying {
            background: #FEF3C7;
            color: #92400E;
        }
    </style>';
});

/**
 * Registrar endpoints REST de Wompi
 */
add_action('rest_api_init', function() {
    $namespace = 'starter/v1';
    
    // Endpoint para obtener configuración pública
    register_rest_route($namespace, '/wompi/config', [
        'methods' => 'GET',
        'callback' => 'starter_wompi_get_config',
        'permission_callback' => 'is_user_logged_in',
    ]);
    
    // Endpoint para generar firma de integridad
    register_rest_route($namespace, '/wompi/signature', [
        'methods' => 'POST',
        'callback' => 'starter_wompi_generate_signature',
        'permission_callback' => 'is_user_logged_in',
        'args' => [
            'reference' => [
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'amount_in_cents' => [
                'required' => true,
                'type' => 'integer',
                'sanitize_callback' => 'absint',
            ],
            'expiration_time' => [
                'required' => false,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ],
    ]);
    
    // Endpoint para verificar estado de transacción
    register_rest_route($namespace, '/wompi/transaction/(?P<id>[a-zA-Z0-9-]+)', [
        'methods' => 'GET',
        'callback' => 'starter_wompi_get_transaction',
        'permission_callback' => 'is_user_logged_in',
        'args' => [
            'id' => [
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ],
    ]);
    
    // Webhook para eventos de Wompi (público pero verificado)
    register_rest_route($namespace, '/wompi/webhook', [
        'methods' => 'POST',
        'callback' => 'starter_wompi_webhook',
        'permission_callback' => '__return_true',
    ]);
    
});

/**
 * Obtener configuración pública de Wompi
 */
function starter_wompi_get_config(WP_REST_Request $request) {
    $config = Starter_Wompi_Config::get_public_config();
    
    if (empty($config['public_key'])) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Wompi no está configurado',
        ], 500);
    }
    
    return new WP_REST_Response([
        'success' => true,
        'data' => $config,
    ], 200);
}

/**
 * Helper interno: construir firma de integridad SHA256
 * 
 * @param string $reference       Referencia de la transacción
 * @param int    $amount_in_cents Monto en centavos
 * @param string $currency        Moneda (ej: COP)
 * @param string $integrity_secret Secreto de integridad de Wompi
 * @param string $expiration_time  Tiempo de expiración (opcional)
 * @return string Hash SHA256
 */
function starter_wompi_build_signature($reference, $amount_in_cents, $currency, $integrity_secret, $expiration_time = '') {
    $string_to_hash = $reference . $amount_in_cents . $currency;
    
    if (!empty($expiration_time)) {
        $string_to_hash .= $expiration_time;
    }
    
    $string_to_hash .= $integrity_secret;
    
    return hash('sha256', $string_to_hash);
}

/**
 * Generar firma de integridad para Wompi
 * 
 * La firma se genera concatenando: referencia + monto + moneda + [expiracion] + secreto
 * y aplicando SHA256
 */
function starter_wompi_generate_signature(WP_REST_Request $request) {
    $reference = $request->get_param('reference');
    $amount_in_cents = $request->get_param('amount_in_cents');
    $expiration_time = $request->get_param('expiration_time');
    
    // Validaciones
    if (empty($reference)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'La referencia es obligatoria',
        ], 400);
    }
    
    if ($amount_in_cents <= 0) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'El monto debe ser mayor a 0',
        ], 400);
    }
    
    $config = Starter_Wompi_Config::get_config();
    
    if (empty($config['public_key'])) {
        error_log('[Starter Wompi] Llave pública no configurada');
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Wompi no está configurado: falta la llave pública',
        ], 500);
    }
    
    if (empty($config['integrity_secret'])) {
        error_log('[Starter Wompi] Secreto de integridad no configurado');
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Wompi no está configurado: falta el secreto de integridad',
        ], 500);
    }
    
    // Generar firma usando helper centralizado
    $signature = starter_wompi_build_signature(
        $reference, $amount_in_cents, $config['currency'],
        $config['integrity_secret'], $expiration_time
    );
    
    // Log para debugging (solo en desarrollo)
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[Starter Wompi] Generando firma para referencia: ' . $reference);
    }
    
    return new WP_REST_Response([
        'success' => true,
        'data' => [
            'signature' => $signature,
            'reference' => $reference,
            'amount_in_cents' => $amount_in_cents,
            'currency' => $config['currency'],
            'public_key' => $config['public_key'],
        ],
    ], 200);
}

/**
 * Verificar una transacción con la API de Wompi (función utilitaria centralizada)
 * 
 * Usada por los endpoints de confirmación de FC, membresía y card-payment
 * para validar transacciones directamente con Wompi antes de procesarlas.
 *
 * @param string $transaction_id  ID de la transacción de Wompi
 * @return array|WP_Error         Datos de la transacción o error
 */
function starter_wompi_verify_transaction($transaction_id) {
    $config = Starter_Wompi_Config::get_config();

    $response = wp_remote_get($config['api_url'] . '/transactions/' . $transaction_id, [
        'headers' => ['Authorization' => 'Bearer ' . $config['private_key']],
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        error_log('[Starter Wompi] Error al verificar transacción: ' . $response->get_error_message());
        return new WP_Error('wompi_error', 'Error al verificar la transacción');
    }

    $body        = json_decode(wp_remote_retrieve_body($response), true);
    $status_code = wp_remote_retrieve_response_code($response);

    if ($status_code !== 200) {
        error_log('[Starter Wompi] API retornó error: ' . json_encode($body));
        return new WP_Error('wompi_error', 'No se pudo verificar la transacción');
    }

    return $body['data'] ?? $body;
}

/**
 * Obtener estado de una transacción
 */
function starter_wompi_get_transaction(WP_REST_Request $request) {
    $transaction_id = $request->get_param('id');
    
    $config = Starter_Wompi_Config::get_config();
    
    $response = wp_remote_get($config['api_url'] . '/transactions/' . $transaction_id, [
        'headers' => [
            'Authorization' => 'Bearer ' . $config['private_key'],
        ],
        'timeout' => 30,
    ]);
    
    if (is_wp_error($response)) {
        error_log('[Starter Wompi] Error al consultar transacción: ' . $response->get_error_message());
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Error al consultar la transacción',
        ], 500);
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    $status_code = wp_remote_retrieve_response_code($response);
    
    if ($status_code !== 200) {
        return new WP_REST_Response([
            'success' => false,
            'message' => $body['error']['message'] ?? 'Error al consultar la transacción',
        ], $status_code);
    }
    
    return new WP_REST_Response([
        'success' => true,
        'data' => $body['data'] ?? $body,
    ], 200);
}

/**
 * Webhook para recibir eventos de Wompi
 */
function starter_wompi_webhook(WP_REST_Request $request) {
    $config = Starter_Wompi_Config::get_config();
    
    // Obtener el cuerpo raw para verificar firma
    $raw_body = $request->get_body();
    $body = json_decode($raw_body, true);
    
    if (!$body) {
        error_log('[Starter Wompi Webhook] Body inválido');
        return new WP_REST_Response(['status' => 'error'], 400);
    }
    
    // Verificar checksum del evento (OBLIGATORIO)
    if (empty($config['events_secret'])) {
        error_log('[Starter Wompi Webhook] CRITICAL: events_secret no configurado. Webhook rechazado.');
        return new WP_REST_Response(['status' => 'configuration_error'], 500);
    }
    
    $checksum_header = $request->get_header('x-event-checksum');
    
    if (empty($checksum_header)) {
        error_log('[Starter Wompi Webhook] Checksum header ausente. Webhook rechazado.');
        return new WP_REST_Response(['status' => 'missing_checksum'], 401);
    }
    
    // Construir string para verificar: properties.transaction.id + properties.transaction.status + properties.transaction.amount_in_cents + timestamp + events_secret
    $transaction_data = $body['data']['transaction'] ?? [];
    $timestamp = $body['timestamp'] ?? '';
    
    $checksum_string = ($transaction_data['id'] ?? '') . 
                      ($transaction_data['status'] ?? '') . 
                      ($transaction_data['amount_in_cents'] ?? '') . 
                      $timestamp . 
                      $config['events_secret'];
    
    $calculated_checksum = hash('sha256', $checksum_string);
    
    if (!hash_equals($calculated_checksum, $checksum_header)) {
        error_log('[Starter Wompi Webhook] Checksum inválido');
        return new WP_REST_Response(['status' => 'invalid_checksum'], 401);
    }
    
    // Procesar evento
    $event_type = $body['event'] ?? '';
    $transaction = $body['data']['transaction'] ?? [];
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[Starter Wompi Webhook] Evento recibido: ' . $event_type);
        error_log('[Starter Wompi Webhook] Transacción: ' . json_encode([
            'id' => $transaction['id'] ?? '',
            'reference' => $transaction['reference'] ?? '',
            'status' => $transaction['status'] ?? '',
            'status_message' => $transaction['status_message'] ?? '',
            'amount_in_cents' => $transaction['amount_in_cents'] ?? '',
            'payment_method_type' => $transaction['payment_method_type'] ?? '',
        ]));
    }
    
    // Disparar acción para que otros módulos puedan procesar el evento
    do_action('starter_wompi_event', $event_type, $transaction, $body);
    
    // Procesar según tipo de evento
    switch ($event_type) {
        case 'transaction.updated':
            $status = $transaction['status'] ?? '';
            $reference = $transaction['reference'] ?? '';
            
            if ($status === 'APPROVED' && !empty($reference)) {
                // Disparar acción específica para pagos aprobados
                do_action('starter_wompi_payment_approved', $reference, $transaction);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[Starter Wompi Webhook] Pago aprobado para referencia: ' . $reference);
                }
            } elseif ($status === 'DECLINED') {
                do_action('starter_wompi_payment_declined', $reference, $transaction);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[Starter Wompi Webhook] Pago declinado para referencia: ' . $reference);
                }
            } elseif ($status === 'VOIDED') {
                do_action('starter_wompi_payment_voided', $reference, $transaction);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[Starter Wompi Webhook] Pago anulado para referencia: ' . $reference);
                }
            } elseif ($status === 'ERROR') {
                do_action('starter_wompi_payment_error', $reference, $transaction);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[Starter Wompi Webhook] Error de pago para referencia: ' . $reference);
                }
            }
            break;
            
        case 'nequi_token.updated':
            // Manejar actualización de token Nequi si es necesario
            do_action('starter_wompi_nequi_token_updated', $body['data']);
            break;
    }
    
    return new WP_REST_Response(['status' => 'ok'], 200);
}

/**
 * Registrar página de configuración en el admin
 */
add_action('admin_menu', function() {
    add_submenu_page(
        'woocommerce',
        'Configuración Wompi',
        'Wompi',
        'manage_woocommerce',
        'starter-wompi',
        'starter_wompi_admin_page'
    );
});

/**
 * Página de administración de Wompi
 */
function starter_wompi_admin_page() {
    // Guardar configuración
    if (isset($_POST['starter_wompi_save']) && check_admin_referer('starter_wompi_settings')) {
        update_option('starter_wompi_sandbox', isset($_POST['wompi_sandbox']) ? '1' : '0');
        update_option('starter_wompi_public_key_sandbox', sanitize_text_field($_POST['wompi_public_key_sandbox'] ?? ''));
        update_option('starter_wompi_private_key_sandbox', sanitize_text_field($_POST['wompi_private_key_sandbox'] ?? ''));
        update_option('starter_wompi_integrity_sandbox', sanitize_text_field($_POST['wompi_integrity_sandbox'] ?? ''));
        update_option('starter_wompi_events_sandbox', sanitize_text_field($_POST['wompi_events_sandbox'] ?? ''));
        update_option('starter_wompi_public_key_prod', sanitize_text_field($_POST['wompi_public_key_prod'] ?? ''));
        update_option('starter_wompi_private_key_prod', sanitize_text_field($_POST['wompi_private_key_prod'] ?? ''));
        update_option('starter_wompi_integrity_prod', sanitize_text_field($_POST['wompi_integrity_prod'] ?? ''));
        update_option('starter_wompi_events_prod', sanitize_text_field($_POST['wompi_events_prod'] ?? ''));
        
        echo '<div class="notice notice-success"><p>Configuración guardada correctamente.</p></div>';
    }
    
    $sandbox_option = get_option('starter_wompi_sandbox', '1');
    $is_sandbox = ($sandbox_option === true || $sandbox_option === '1' || $sandbox_option === 1);
    ?>
    <div class="wrap">
        <h1>Configuración de Wompi</h1>
        
        <form method="post">
            <?php wp_nonce_field('starter_wompi_settings'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">Modo</th>
                    <td>
                        <label>
                            <input type="checkbox" name="wompi_sandbox" value="1" <?php checked($is_sandbox); ?>>
                            Modo Sandbox (pruebas)
                        </label>
                        <p class="description">Desactiva esta opción para usar el ambiente de producción.</p>
                    </td>
                </tr>
            </table>
            
            <h2>Credenciales Sandbox</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">Llave Pública</th>
                    <td>
                        <input type="text" name="wompi_public_key_sandbox" class="regular-text" 
                               value="<?php echo esc_attr(get_option('starter_wompi_public_key_sandbox', '')); ?>"
                               placeholder="pub_test_...">
                    </td>
                </tr>
                <tr>
                    <th scope="row">Llave Privada</th>
                    <td>
                        <input type="password" name="wompi_private_key_sandbox" class="regular-text" 
                               value="<?php echo esc_attr(get_option('starter_wompi_private_key_sandbox', '')); ?>"
                               placeholder="prv_test_...">
                    </td>
                </tr>
                <tr>
                    <th scope="row">Secreto de Integridad</th>
                    <td>
                        <input type="password" name="wompi_integrity_sandbox" class="regular-text" 
                               value="<?php echo esc_attr(get_option('starter_wompi_integrity_sandbox', '')); ?>"
                               placeholder="test_integrity_...">
                    </td>
                </tr>
                <tr>
                    <th scope="row">Secreto de Eventos</th>
                    <td>
                        <input type="password" name="wompi_events_sandbox" class="regular-text" 
                               value="<?php echo esc_attr(get_option('starter_wompi_events_sandbox', '')); ?>"
                               placeholder="test_events_...">
                    </td>
                </tr>
            </table>
            
            <h2>Credenciales Producción</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">Llave Pública</th>
                    <td>
                        <input type="text" name="wompi_public_key_prod" class="regular-text" 
                               value="<?php echo esc_attr(get_option('starter_wompi_public_key_prod', '')); ?>"
                               placeholder="pub_prod_...">
                    </td>
                </tr>
                <tr>
                    <th scope="row">Llave Privada</th>
                    <td>
                        <input type="password" name="wompi_private_key_prod" class="regular-text" 
                               value="<?php echo esc_attr(get_option('starter_wompi_private_key_prod', '')); ?>"
                               placeholder="prv_prod_...">
                    </td>
                </tr>
                <tr>
                    <th scope="row">Secreto de Integridad</th>
                    <td>
                        <input type="password" name="wompi_integrity_prod" class="regular-text" 
                               value="<?php echo esc_attr(get_option('starter_wompi_integrity_prod', '')); ?>"
                               placeholder="prod_integrity_...">
                    </td>
                </tr>
                <tr>
                    <th scope="row">Secreto de Eventos</th>
                    <td>
                        <input type="password" name="wompi_events_prod" class="regular-text" 
                               value="<?php echo esc_attr(get_option('starter_wompi_events_prod', '')); ?>"
                               placeholder="prod_events_...">
                    </td>
                </tr>
            </table>
            
            <h2>URL de Webhook</h2>
            <p>Configura esta URL en el dashboard de Wompi para recibir eventos:</p>
            <code><?php echo esc_url(rest_url('starter/v1/wompi/webhook')); ?></code>
            
            <p class="submit">
                <input type="submit" name="starter_wompi_save" class="button-primary" value="Guardar Configuración">
            </p>
        </form>
    </div>
    <?php
}
