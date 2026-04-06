<?php
/**
 * Endpoint y funciones para enviar correos personalizados de confirmación de pedido
 *
 * Este archivo deshabilita los correos predeterminados de WooCommerce para el cliente
 * y envía un correo HTML personalizado con los detalles del pedido.
 * La lógica se expone también vía REST para poder reenviar el correo si es necesario.
 * 
 * Incluye generación de PDF con Certificado de Retribución de Cosecha Colectiva.
 */

// Prevenir acceso directo
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'STARTER_IDEMP_ORDER_EMAIL_TTL' ) ) {
    define( 'STARTER_IDEMP_ORDER_EMAIL_TTL', 86400 );
}

// Cargar clase generadora de certificados PDF
require_once __DIR__ . '/order-certificate/class-order-certificate-pdf.php';

/**
 * Deshabilitar los correos predeterminados de WooCommerce dirigidos al cliente
 * Mantiene los correos administrativos intactos.
 */
add_filter( 'woocommerce_email_enabled_customer_processing_order', '__return_false' );
add_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false' );
add_filter( 'woocommerce_email_enabled_customer_on_hold_order', '__return_false' );
add_filter( 'woocommerce_email_enabled_customer_partially_refunded_order', '__return_false' );
add_filter( 'woocommerce_email_enabled_customer_refunded_order', '__return_false' );
add_filter( 'woocommerce_email_enabled_customer_invoice', '__return_false' );
add_filter( 'woocommerce_email_enabled_customer_note', '__return_false' );

/**
 * Hook para enviar el correo justo después de generarse el pedido.
 * 
 * woocommerce_checkout_order_processed: se dispara en el checkout nativo de WooCommerce.
 * woocommerce_rest_insert_shop_order_object: se dispara cuando se crea una orden vía REST API
 * (que es como el frontend headless crea pedidos a través del proxy WC).
 */
add_action( 'woocommerce_checkout_order_processed', 'starter_send_order_email_hook', 20, 1 );

/**
 * Hook para REST API: enviar correo cuando se crea una orden vía WC REST API.
 * Recibe ($order, $request, $creating) — solo enviar al crear, no al actualizar.
 */
add_action( 'woocommerce_rest_insert_shop_order_object', 'starter_send_order_email_rest_hook', 20, 3 );
function starter_send_order_email_rest_hook( $order, $request, $creating ) {
    // Solo enviar al crear una orden nueva, no al actualizar
    if ( ! $creating ) {
        return;
    }
    $order_id = $order->get_id();
    starter_send_order_email_hook( $order_id );
}

function starter_send_order_email_hook( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }
    // Evitar múltiples envíos si ya se ejecutó (compatible con HPOS)
    if ( $order->get_meta( '_starter_order_email_sent', true ) === 'yes' ) {
        return;
    }
    $sent = starter_send_order_email( $order );
    if ( $sent ) {
        $order->update_meta_data( '_starter_order_email_sent', 'yes' );
        $order->save();
    }
}

/**
 * Registrar endpoint REST para reenviar/forzar el correo de confirmación de pedido.
 */
add_action( 'rest_api_init', function () {
    register_rest_route( 'starter/v1', '/order-email', array(
        'methods'             => 'POST',
        'callback'            => 'starter_order_email_endpoint_callback',
        'permission_callback' => 'starter_order_email_permission',
        'args'                => array(
            'order_id' => array(
                'required'          => true,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
            ),
        ),
    ) );
} );

function starter_order_email_permission( WP_REST_Request $request ) {
    if ( ! is_user_logged_in() ) {
        return new WP_Error( 'rest_forbidden', 'Debes iniciar sesión', array( 'status' => 401 ) );
    }

    $order_id = $request->get_param( 'order_id' );
    if ( ! $order_id ) {
        return new WP_Error( 'bad_request', 'order_id es obligatorio', array( 'status' => 400 ) );
    }

    if ( ! function_exists( 'wc_get_order' ) ) {
        return new WP_Error( 'server_error', 'WooCommerce no está activo', array( 'status' => 500 ) );
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return new WP_Error( 'not_found', 'Pedido no encontrado', array( 'status' => 404 ) );
    }

    $current_user_id = get_current_user_id();
    $customer_id     = intval( $order->get_customer_id() );
    $can_manage      = current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );

    if ( $customer_id === 0 ) {
        return $can_manage ? true : new WP_Error( 'rest_forbidden', 'No tienes permisos para este pedido', array( 'status' => 403 ) );
    }

    if ( $customer_id === $current_user_id || $can_manage ) {
        return true;
    }

    return new WP_Error( 'rest_forbidden', 'No tienes permisos para este pedido', array( 'status' => 403 ) );
}

function starter_order_email_endpoint_callback( WP_REST_Request $request ) {
    $order_id = $request->get_param( 'order_id' );

    if ( ! $order_id ) {
        $resp = new WP_REST_Response( array( 'success' => false, 'message' => 'order_id es obligatorio' ), 400 );
        return $resp;
    }

    if ( ! function_exists( 'wc_get_order' ) ) {
        $resp = new WP_REST_Response( array( 'success' => false, 'message' => 'WooCommerce no está activo' ), 500 );
        return $resp;
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        $resp = new WP_REST_Response( array( 'success' => false, 'message' => 'Pedido no encontrado' ), 404 );
        return $resp;
    }

    $idemp_key = $request->get_header( 'x-idempotency-key' );
    if ( empty( $idemp_key ) ) {
        $idemp_key = $request->get_header( 'idempotency-key' );
    }
    if ( empty( $idemp_key ) ) {
        $resp = new WP_REST_Response( array( 'success' => false, 'message' => 'Falta header X-Idempotency-Key' ), 400 );
        return $resp;
    }
    if ( ! preg_match( '/^[A-Za-z0-9._-]{8,128}$/', $idemp_key ) ) {
        $resp = new WP_REST_Response( array( 'success' => false, 'message' => 'Idempotency-Key inválida' ), 400 );
        $resp->header( 'X-Idempotency-Key', $idemp_key );
        return $resp;
    }

    $transient_key = 'starter_idemp_oe_' . $order_id . '_' . md5( $idemp_key );
    $stored        = get_transient( $transient_key );
    if ( $stored && is_array( $stored ) ) {
        $resp = new WP_REST_Response( $stored['data'], intval( $stored['status'] ) );
        $resp->header( 'X-Idempotency-Key', $idemp_key );
        $resp->header( 'X-Idempotency-Replayed', 'true' );
        return $resp;
    }

    $sent = starter_send_order_email( $order );

    if ( $sent ) {
        $order->update_meta_data( '_starter_order_email_sent', 'yes' );
        $order->save();
        $data = array( 'success' => true, 'message' => 'Correo enviado' );
        $resp = new WP_REST_Response( $data, 200 );
        $store = array(
            'status'  => 200,
            'data'    => $data,
            'sent_at' => time(),
            'user_id' => get_current_user_id(),
        );
        set_transient( $transient_key, $store, STARTER_IDEMP_ORDER_EMAIL_TTL );
        $resp->header( 'X-Idempotency-Key', $idemp_key );
        return $resp;
    } else {
        $resp = new WP_REST_Response( array( 'success' => false, 'message' => 'No se pudo enviar el correo' ), 500 );
        $resp->header( 'X-Idempotency-Key', $idemp_key );
        return $resp;
    }
}

/**
 * Función principal que construye y envía el correo con los datos del retiro.
 * Incluye generación y adjunto del certificado PDF.
 *
 * @param WC_Order $order Instancia de retiro
 * @return bool True si el correo se envía correctamente
 */
function starter_send_order_email( WC_Order $order ) {
    // Datos básicos
    $order_number   = $order->get_order_number();
    $order_date     = $order->get_date_created() ? $order->get_date_created()->date_i18n( 'd/m/Y H:i' ) : current_time( 'd/m/Y H:i' );
    $billing_email  = $order->get_billing_email();
    $billing_name   = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
    $b              = function_exists( 'site_get_email_branding' ) ? site_get_email_branding() : starter_get_branding();

    // Generar certificado PDF (protegido contra errores fatales)
    $pdf_path = false;
    try {
        $pdf_path = starter_generate_order_certificate( $order );
    } catch ( \Throwable $e ) {
        error_log( '[Starter Order Email] Excepción al generar PDF para retiro #' . $order_number . ': ' . $e->getMessage() );
    }

    // Construir la tabla de beneficios
    $items_rows = '';
    foreach ( $order->get_items() as $item ) {
        $product_name = $item->get_name();
        $quantity     = $item->get_quantity();
        $line_total   = wc_price( $item->get_total(), array( 'currency' => $order->get_currency() ) );

        $items_rows .= '<tr>
            <td style="padding:8px;border-bottom:1px solid #eee;">' . esc_html( $product_name ) . ' × ' . esc_html( $quantity ) . '</td>
            <td style="padding:8px;text-align:right;border-bottom:1px solid #eee;">' . wp_kses_post( $line_total ) . '</td>
        </tr>';
    }

    // Resumen de totales
    $subtotal  = wc_price( $order->get_subtotal(), array( 'currency' => $order->get_currency() ) );
    $shipping  = wc_price( $order->get_shipping_total(), array( 'currency' => $order->get_currency() ) );
    $discount  = wc_price( $order->get_discount_total(), array( 'currency' => $order->get_currency() ) );
    $total     = wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) );

    // Obtener nombre del método de envío
    $shipping_methods = $order->get_shipping_methods();
    $shipping_method_name = 'No especificado';
    if ( ! empty( $shipping_methods ) ) {
        $first_method = reset( $shipping_methods );
        $shipping_method_name = $first_method->get_method_title();
        if ( empty( $shipping_method_name ) ) {
            $shipping_method_name = $first_method->get_name();
        }
    }

    // Construir filas de totales
    $discount_row = '';
    if ( $discount && $discount !== wc_price( 0 ) ) {
        $discount_row = '<tr class="totals"><td style="padding:8px;">Descuento</td><td style="padding:8px;text-align:right;">-' . wp_kses_post( $discount ) . '</td></tr>';
    }

    // Construir bloque de PDF adjunto
    $pdf_html = '';
    if ( $pdf_path && file_exists( $pdf_path ) ) {
        $pdf_html = '
        <div class="highlight-box">
            <p style="margin:0; font-size:14px;">
                <strong>📎 Documento adjunto:</strong> Hemos incluido tu <strong>Certificado de Retribución de Cosecha Colectiva</strong> en formato PDF. 
                Este documento contiene el fundamento jurídico y la información legal de tu retiro.
            </p>
        </div>';
    }

    // Construir body del email
    $body = '
        <p>Hola <strong>' . esc_html( $billing_name ) . '</strong>, hemos recibido tu solicitud de retiro <strong>#' . esc_html( $order_number ) . '</strong> el <strong>' . esc_html( $order_date ) . '</strong>.</p>

        <h3 style="color:' . esc_attr( $b['primary_color'] ) . ';"> Detalles del retiro</h3>
        <table style="width:100%;border-collapse:collapse;margin-bottom:20px;">
            <tbody>' . $items_rows . '</tbody>
            <tfoot>
                <tr class="totals"><td style="padding:8px;">Subtotal</td><td style="padding:8px;text-align:right;">' . wp_kses_post( $subtotal ) . '</td></tr>
                ' . $discount_row . '
                <tr class="totals"><td style="padding:8px;">Envío</td><td style="padding:8px;text-align:right;">' . esc_html( $shipping_method_name ) . '</td></tr>
                <tr class="totals"><td style="padding:8px;">Total</td><td style="padding:8px;text-align:right;">' . wp_kses_post( $total ) . '</td></tr>
            </tfoot>
        </table>

        <p>En breve prepararemos tu retiro y te enviaremos una notificación cuando esté en camino.</p>

        ' . $pdf_html . '
    ';

    $footer = 'Gracias por confiar en <strong>' . esc_html( $b['site_name'] ) . '</strong>';
    $html = starter_email_wrap( $b, '¡Gracias por tu aporte!', $body, $footer );

    $subject = 'Solicitud de retiro #' . $order_number . ' recibida | ' . $b['site_name'];
    $headers = array( 'Content-Type: text/html; charset=UTF-8' );

    // Preparar adjuntos (certificado PDF si existe)
    $attachments = array();
    if ( $pdf_path && file_exists( $pdf_path ) ) {
        $attachments[] = $pdf_path;
        error_log( '[Starter Order Email] Adjuntando certificado PDF: ' . $pdf_path );
    } else {
        error_log( '[Starter Order Email] No se pudo generar el certificado PDF para retiro #' . $order_number );
    }

    // Enviar correo con adjunto
    $sent = wp_mail( $billing_email, $subject, $html, $headers, $attachments );

    if ( $sent ) {
        error_log( 'Correo de retiro enviado a ' . $billing_email . ' (Retiro #' . $order_number . ')' );
    } else {
        error_log( 'Error al enviar correo de retiro a ' . $billing_email . ' (Retiro #' . $order_number . ')' );
    }

    return $sent;
}

/**
 * Generar el certificado PDF para una orden
 *
 * @param WC_Order $order Instancia de la orden
 * @return string|false Ruta del archivo PDF o false si falla
 */
function starter_generate_order_certificate( WC_Order $order ) {
    // Verificar que la clase esté disponible
    if ( ! class_exists( 'Starter_Order_Certificate_PDF' ) ) {
        error_log( '[Starter Certificate] Clase Starter_Order_Certificate_PDF no disponible' );
        return false;
    }
    
    try {
        $generator = new Starter_Order_Certificate_PDF( $order );
        
        // Verificar si ya existe un certificado
        $existing = $generator->get_existing_certificate();
        if ( $existing ) {
            error_log( '[Starter Certificate] Usando certificado existente: ' . $existing );
            return $existing;
        }
        
        // Generar nuevo certificado
        $pdf_path = $generator->generate();
        
        if ( $pdf_path ) {
            error_log( '[Starter Certificate] Certificado generado: ' . $pdf_path );
            return $pdf_path;
        }
        
        return false;
    } catch ( \Throwable $e ) {
        error_log( '[Starter Certificate] Error: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine() );
        return false;
    }
}