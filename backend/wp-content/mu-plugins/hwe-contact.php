<?php
/**
 * Plugin Name: HWE Contact
 * Description: Recibe el formulario de contacto reenviado por el BFF y lo entrega
 *              por email (usa el SMTP de hwe-smtp si está activo). Solo el BFF
 *              puede llamarlo (X-HWE-Internal-Secret = HWE_REVALIDATION_SECRET),
 *              igual que el resto de llamadas servidor a servidor.
 * Author:      Sergio Jauregui
 * Version:     2.0.0
 *
 * La validación de verdad (formato, longitud, rate-limit, CSRF, identidad desde la
 * sesión) la hace el BFF en `/api/contact`; aquí se vuelve a sanear porque es la
 * frontera de WordPress y lo que llega acaba en una cabecera de correo.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'hwe/v1', '/contact', [
		'methods'             => 'POST',
		'permission_callback' => 'hwe_contact_permitted',
		'callback'            => 'hwe_contact_handle',
	] );
} );

/** Solo el BFF (con el secreto interno) puede invocar el endpoint. */
function hwe_contact_permitted( WP_REST_Request $request ): bool {
	return function_exists( 'hwe_has_internal_secret' ) && hwe_has_internal_secret( $request );
}

/** Destinatario de los mensajes: email legal de la config → fallback admin_email. */
function hwe_contact_recipient(): string {
	if ( class_exists( 'HWE\\ControlCenter\\Storage' ) ) {
		$email = (string) HWE\ControlCenter\Storage::get( [ 'legal', 'email' ] );
		if ( $email !== '' && is_email( $email ) ) {
			return $email;
		}
	}
	return (string) get_option( 'admin_email' );
}

function hwe_contact_handle( WP_REST_Request $request ) {
	$b = (array) $request->get_json_params();

	// `sanitize_text_field` quita saltos de línea: nada de lo que va a una
	// cabecera (`Reply-To`, asunto) puede inyectar otra.
	$name        = mb_substr( sanitize_text_field( (string) ( $b['name'] ?? '' ) ), 0, 120 );
	$email       = sanitize_email( (string) ( $b['email'] ?? '' ) );
	$phone       = mb_substr( sanitize_text_field( (string) ( $b['phone'] ?? '' ) ), 0, 40 );
	$requestType = sanitize_key( (string) ( $b['requestType'] ?? 'otro' ) );
	$description = mb_substr( sanitize_textarea_field( (string) ( $b['description'] ?? '' ) ), 0, 5000 );

	if ( $name === '' || ! is_email( $email ) || $description === '' ) {
		return new WP_REST_Response( [ 'ok' => false, 'error' => 'invalid' ], 422 );
	}

	$to       = hwe_contact_recipient();
	$blogname = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

	$subject = sprintf( '[%s · Contacto] %s', $blogname, $requestType !== '' ? $requestType : 'otro' );
	$body    = sprintf(
		"Nombre: %s\nEmail: %s\nTeléfono: %s\nTipo: %s\n\n%s",
		$name,
		$email,
		$phone !== '' ? $phone : '—',
		$requestType,
		$description
	);

	$headers = [ 'Reply-To: ' . $name . ' <' . $email . '>' ];
	$sent    = wp_mail( $to, $subject, $body, $headers );
	if ( ! $sent ) {
		error_log( '[hwe] contact: wp_mail no pudo entregar el mensaje de contacto.' );
	}

	return new WP_REST_Response( [ 'ok' => (bool) $sent ], $sent ? 200 : 502 );
}
