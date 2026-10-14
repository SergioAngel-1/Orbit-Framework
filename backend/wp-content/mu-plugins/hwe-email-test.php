<?php
/**
 * Plugin Name: HWE Email Test
 * Description: Envía, desde HWE Control Center → Integraciones → «Probar entrega
 *              de email», cualquier plantilla de correo del sitio (WooCommerce
 *              y los propios de hwe-auth) a una dirección de prueba, para ver
 *              su diseño y su entregabilidad sin esperar a que ocurra el evento.
 * Author:      Sergio Jauregui
 * Version:     2.0.0
 *
 * Datos: por defecto, un pedido de EJEMPLO construido en memoria (nunca se
 * guarda: id ficticio, sin notas, sin tocar stock) con productos reales del
 * catálogo si los hay. Opcionalmente, el último pedido real. En ambos casos:
 *   - El correo se manda SOLO a la dirección de prueba: se arma con la API
 *     del WC_Email y se envía con `send()`, sin `trigger()` —que es lo que
 *     mira destinatarios, CC/BCC y efectos del pedido—, y además un filtro
 *     de `wp_mail` fuerza el destinatario mientras dura el envío.
 *   - El asunto lleva «[Prueba]» delante.
 * Los enlaces con clave (restablecer, verificar) llevan una clave de ejemplo:
 * sirven para ver a dónde apuntan, no para usarse.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plantillas que se pueden probar, agrupadas para el <select> del panel.
 * Clave: `basic` | `wc:<id del WC_Email>` | `auth:<reset|verify>:<idioma>`.
 *
 * @return array<string,array<string,string>> grupo => [clave => rótulo]
 */
function hwe_email_test_catalog(): array {
	$wc_labels = array(
		'customer_processing_order' => 'Pedido recibido (procesando)',
		'customer_on_hold_order'    => 'Pedido en espera de pago',
		'customer_completed_order'  => 'Pedido completado',
		'customer_refunded_order'   => 'Pedido reembolsado',
		'customer_failed_order'     => 'Pago fallido',
		'customer_cancelled_order'  => 'Pedido cancelado',
		'customer_note'             => 'Nota en el pedido',
		'customer_invoice'          => 'Detalle del pedido / link de pago',
		'customer_new_account'      => 'Bienvenida (cuenta nueva)',
		'customer_reset_password'   => 'Restablecer contraseña (WooCommerce)',
		'new_order'                 => 'Nuevo pedido',
		'cancelled_order'           => 'Pedido cancelado',
		'failed_order'              => 'Pedido fallido',
	);

	$customer = array();
	$store    = array();
	if ( function_exists( 'WC' ) ) {
		$available = array();
		foreach ( WC()->mailer()->get_emails() as $email ) {
			$available[ $email->id ] = $email->is_customer_email();
		}
		foreach ( $wc_labels as $id => $label ) {
			if ( ! array_key_exists( $id, $available ) ) {
				continue;
			}
			if ( $available[ $id ] ) {
				$customer[ 'wc:' . $id ] = $label;
			} else {
				$store[ 'wc:' . $id ] = $label;
			}
		}
	}

	$catalog = array(
		'Transporte' => array( 'basic' => 'Prueba simple (sin plantilla)' ),
	);
	if ( $customer ) {
		$catalog['Pedidos y cuenta — al cliente'] = $customer;
	}
	if ( $store ) {
		$catalog['Avisos a la tienda'] = $store;
	}
	if ( class_exists( 'HWE_Auth_Endpoints' ) ) {
		// Un par de pruebas por cada idioma del sitio: los correos de acceso salen en el
		// del visitante.
		$locales = function_exists( 'hwe_email_site_locales' ) ? hwe_email_site_locales() : array( 'es' );
		$auth    = array();
		foreach ( array( 'reset' => 'Recuperar contraseña', 'verify' => 'Verificar email' ) as $type => $label ) {
			foreach ( $locales as $locale ) {
				$auth[ 'auth:' . $type . ':' . strtolower( $locale ) ] = $label . ' — ' . $locale;
			}
		}
		$catalog['Acceso (web)'] = $auth;
	}
	return $catalog;
}

/** Rótulo de una clave del catálogo (vacío si no existe). */
function hwe_email_test_label( string $key ): string {
	foreach ( hwe_email_test_catalog() as $items ) {
		if ( isset( $items[ $key ] ) ) {
			return $items[ $key ];
		}
	}
	return '';
}

/** Número que muestra el pedido de ejemplo. */
const HWE_EMAIL_TEST_ORDER_NUMBER = 'EJEMPLO-1001';

/**
 * Id ficticio del pedido de ejemplo. No puede ser 0: WooCommerce busca los
 * reembolsos con `parent = id`, y con 0 traía reembolsos huérfanos de la BD
 * (salía una línea «Reembolso» en el correo). Nunca se guarda con este id.
 */
const HWE_EMAIL_TEST_ORDER_ID = 999999999;

/**
 * Pedido de ejemplo, SOLO en memoria. Usa hasta dos productos publicados del
 * catálogo (nombre, precio e imagen reales) y, si no hay, líneas genéricas.
 * Los totales se fijan a mano: `calculate_totals()` guardaría el pedido.
 */
function hwe_email_test_sample_order(): WC_Order {
	$order = new WC_Order();
	$order->set_id( HWE_EMAIL_TEST_ORDER_ID );
	$order->set_currency( get_woocommerce_currency() );
	$order->set_date_created( time() );
	$order->set_payment_method( 'hwe_offline' );
	$order->set_payment_method_title( 'Pago en línea' );
	$order->set_customer_note( '' );

	$address = array(
		'first_name' => 'Cliente',
		'last_name'  => 'de Ejemplo',
		'address_1'  => 'Calle Ejemplo 123',
		'address_2'  => '',
		'city'       => 'Ciudad',
		'state'      => '',
		'postcode'   => '00000',
		'country'    => (string) get_option( 'woocommerce_default_country', '' ),
	);
	$order->set_address( $address + array( 'email' => 'cliente@example.com', 'phone' => '' ), 'billing' );
	$order->set_address( $address, 'shipping' );

	$subtotal = 0.0;
	$products = wc_get_products(
		array(
			'status'  => 'publish',
			'limit'   => 2,
			'orderby' => 'date',
			'order'   => 'DESC',
			'type'    => array( 'simple' ),
		)
	);
	$lines = array();
	foreach ( $products as $i => $product ) {
		$price = (float) $product->get_price();
		if ( $price <= 0 ) {
			continue;
		}
		$lines[] = array( 'name' => $product->get_name(), 'qty' => $i + 1, 'price' => $price, 'product_id' => $product->get_id() );
	}
	if ( ! $lines ) {
		$lines = array(
			array( 'name' => 'Producto de ejemplo', 'qty' => 2, 'price' => 25.0, 'product_id' => 0 ),
			array( 'name' => 'Otro producto de ejemplo', 'qty' => 1, 'price' => 40.0, 'product_id' => 0 ),
		);
	}
	foreach ( $lines as $line ) {
		$item  = new WC_Order_Item_Product();
		$total = $line['price'] * $line['qty'];
		$item->set_name( $line['name'] );
		$item->set_quantity( $line['qty'] );
		$item->set_product_id( $line['product_id'] );
		$item->set_subtotal( $total );
		$item->set_total( $total );
		$order->add_item( $item );
		$subtotal += $total;
	}

	$shipping_total = 5.0;
	$shipping       = new WC_Order_Item_Shipping();
	$shipping->set_method_title( 'Envío estándar' );
	$shipping->set_total( $shipping_total );
	$order->add_item( $shipping );

	$order->set_shipping_total( $shipping_total );
	$order->set_total( $subtotal + $shipping_total );
	return $order;
}

/** Último pedido real con productos (o null). Solo se LEE. */
function hwe_email_test_real_order(): ?WC_Order {
	$ids = wc_get_orders(
		array(
			'limit'   => 5,
			'return'  => 'ids',
			'status'  => array( 'processing', 'completed', 'on-hold' ),
			'orderby' => 'date',
			'order'   => 'DESC',
		)
	);
	foreach ( $ids as $id ) {
		$order = wc_get_order( $id );
		if ( $order instanceof WC_Order && count( $order->get_items() ) > 0 ) {
			return $order;
		}
	}
	return null;
}

/**
 * Arma la plantilla `$key` SIN enviarla: asunto y cuerpo, más el WC_Email si
 * es de WooCommerce (su `send()` y su `style_inline()` hacen falta después).
 * Única fuente de la prueba de envío Y de la vista previa: lo que se ve es
 * lo que se manda.
 *
 * @param string $to Solo para los correos de cuenta, que lo muestran.
 * @return array{subject:string,body:string,email:?WC_Email}|null
 */
function hwe_email_test_render( string $key, bool $use_real_order = false, string $to = '' ): ?array {
	if ( 'basic' === $key && function_exists( 'hwe_smtp_basic_test_mail' ) ) {
		$mail = hwe_smtp_basic_test_mail();
		return array( 'subject' => $mail['subject'], 'body' => $mail['html'], 'email' => null );
	}
	if ( str_starts_with( $key, 'auth:' ) && class_exists( 'HWE_Auth_Endpoints' ) ) {
		$mail = hwe_email_test_render_auth( $key );
		return array( 'subject' => $mail['subject'], 'body' => $mail['html'], 'email' => null );
	}
	if ( str_starts_with( $key, 'wc:' ) ) {
		return hwe_email_test_render_wc( substr( $key, 3 ), $use_real_order, $to );
	}
	return null;
}

/**
 * HTML final tal como llega al buzón: a los de WooCommerce se les aplica el
 * mismo inlining de estilos que hace `WC_Email::send()`.
 */
function hwe_email_test_final_html( array $rendered ): string {
	if ( $rendered['email'] instanceof WC_Email ) {
		return (string) apply_filters( 'woocommerce_mail_content', $rendered['email']->style_inline( $rendered['body'] ) );
	}
	return $rendered['body'];
}

/**
 * Filtro de `wp_mail` que fuerza el destinatario `$to` y quita CC/BCC.
 *
 * @return Closure(array):array
 */
function hwe_email_test_force_recipient( string $to ): Closure {
	return static function ( $args ) use ( $to ) {
		$args            = (array) $args;
		$args['to']      = $to;
		$args['headers'] = array_values(
			array_filter(
				(array) ( $args['headers'] ?? array() ),
				static fn( $h ) => ! preg_match( '/^\s*(cc|bcc)\s*:/i', (string) $h )
			)
		);
		return $args;
	};
}

/**
 * Envía la plantilla `$key` a `$to`. Devuelve si wp_mail() lo aceptó.
 *
 * @param bool $use_real_order Usar el último pedido real en vez del de ejemplo.
 */
function hwe_email_send_test( string $key, string $to, bool $use_real_order = false ): bool {
	$rendered = hwe_email_test_render( $key, $use_real_order, $to );
	if ( ! $rendered ) {
		return false;
	}

	// Red de seguridad: nada de lo que salga durante la prueba va a otra
	// dirección que la de prueba, ni con copia.
	$force_to = hwe_email_test_force_recipient( $to );
	add_filter( 'wp_mail', $force_to, PHP_INT_MAX );

	$subject = '[Prueba] ' . $rendered['subject'];
	try {
		if ( $rendered['email'] instanceof WC_Email ) {
			// Sin las cabeceras del WC_Email: pueden llevar CC/BCC o un
			// Reply-To del cliente configurados en WooCommerce.
			return (bool) $rendered['email']->send(
				$to,
				$subject,
				$rendered['body'],
				array( 'Content-Type: ' . $rendered['email']->get_content_type() . '; charset=UTF-8' ),
				array()
			);
		}
		return wp_mail( $to, $subject, $rendered['body'], array( 'Content-Type: text/html; charset=UTF-8' ) );
	} finally {
		remove_filter( 'wp_mail', $force_to, PHP_INT_MAX );
	}
}

/**
 * Correos propios de hwe-auth, con el mismo armado que los handlers REST.
 *
 * @return array{subject:string,html:string}
 */
function hwe_email_test_render_auth( string $key ): array {
	$parts  = explode( ':', $key );
	$type   = ( $parts[1] ?? '' ) === 'verify' ? 'verify' : 'reset';
	$locale = function_exists( 'hwe_email_resolve_locale' ) ? hwe_email_resolve_locale( $parts[2] ?? '' ) : 'es';

	$query = 'verify' === $type
		? array( 'token' => 'token-de-ejemplo' )
		: array( 'key' => 'clave-de-ejemplo', 'login' => wp_get_current_user()->user_login );
	$route = 'verify' === $type ? '/verify-email' : '/reset-password';
	$link  = function_exists( 'hwe_email_frontend_url' ) ? hwe_email_frontend_url( $route, $query, $locale ) : home_url( $route );
	$name  = wp_get_current_user()->display_name ?: 'Cliente de Ejemplo';

	$mail = HWE_Auth_Endpoints::buildEmail( $type, $name, $link, $locale );
	return array( 'subject' => $mail['subject'], 'html' => $mail['body'] );
}

/**
 * Correos de WooCommerce: se preparan con su API (sin `trigger()`) y se
 * arman con el locale del sitio y, si hace falta, el número del ejemplo.
 *
 * @return array{subject:string,body:string,email:WC_Email}|null
 */
function hwe_email_test_render_wc( string $id, bool $use_real_order, string $to ): ?array {
	if ( ! function_exists( 'WC' ) ) {
		return null;
	}
	$email = null;
	foreach ( WC()->mailer()->get_emails() as $candidate ) {
		if ( $candidate->id === $id ) {
			$email = $candidate;
			break;
		}
	}
	if ( ! $email instanceof WC_Email ) {
		return null;
	}

	$user = wp_get_current_user();
	$email->setup_locale();

	$order_number_filter = null;
	if ( in_array( $id, array( 'customer_new_account', 'customer_reset_password' ), true ) ) {
		$email->object     = $user;
		$email->user_login = $user->user_login;
		$email->user_email = '' !== $to ? $to : $user->user_email;
		if ( 'customer_reset_password' === $id ) {
			$email->user_id   = $user->ID;
			$email->reset_key = 'clave-de-ejemplo';
		} else {
			$email->user_pass          = '';
			$email->password_generated = true;
			$email->set_password_url   = add_query_arg(
				array( 'action' => 'newaccount', 'key' => 'clave-de-ejemplo', 'login' => rawurlencode( $user->user_login ) ),
				wc_get_account_endpoint_url( 'lost-password' )
			);
		}
	} else {
		$order = $use_real_order ? hwe_email_test_real_order() : null;
		if ( ! $order ) {
			$order = hwe_email_test_sample_order();
			// Sin esto se leería el id ficticio como número de pedido.
			$order_number_filter = static fn( $number, $o ) => ( $o instanceof WC_Order && HWE_EMAIL_TEST_ORDER_ID === $o->get_id() ) ? HWE_EMAIL_TEST_ORDER_NUMBER : $number;
			add_filter( 'woocommerce_order_number', $order_number_filter, 10, 2 );
		}
		$email->object                         = $order;
		$email->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
		$email->placeholders['{order_number}'] = $order->get_order_number();

		if ( 'customer_refunded_order' === $id ) {
			$email->partial_refund = false;
			$email->refund         = null;
		}
		if ( 'customer_note' === $id ) {
			$email->customer_note = 'Tu pedido sale mañana. Número de guía: 000000000 (ejemplo).';
		}
	}

	try {
		return array(
			'subject' => $email->get_subject(),
			'body'    => $email->get_content(),
			'email'   => $email,
		);
	} finally {
		if ( $order_number_filter ) {
			remove_filter( 'woocommerce_order_number', $order_number_filter, 10 );
		}
		$email->restore_locale();
	}
}

/*
 * VISTA PREVIA (botón «Ver vista previa» del Control Center): devuelve por
 * AJAX el asunto, el remitente y el HTML final de la plantilla elegida, sin
 * enviar nada. El panel lo pinta en un <iframe sandbox> con `srcdoc`.
 */
add_action(
	'wp_ajax_hwe_email_preview',
	static function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'No autorizado.' ), 403 );
		}
		check_ajax_referer( 'hwe_email_preview' );

		$key = isset( $_GET['template'] )
			? preg_replace( '/[^a-z0-9_:\-]/', '', strtolower( (string) wp_unslash( $_GET['template'] ) ) )
			: 'basic';
		if ( '' === hwe_email_test_label( $key ) ) {
			wp_send_json_error( array( 'message' => 'Plantilla desconocida.' ), 400 );
		}
		$use_real = ! empty( $_GET['real'] );

		$rendered = hwe_email_test_render( $key, $use_real );
		if ( ! $rendered ) {
			wp_send_json_error( array( 'message' => 'No se pudo armar la plantilla.' ), 500 );
		}

		// El remitente efectivo: el del SMTP si está activo (hwe-smtp.php lo
		// impone a WordPress y a WooCommerce), si no el de WooCommerce.
		$from_email = $rendered['email'] instanceof WC_Email ? $rendered['email']->get_from_address() : apply_filters( 'wp_mail_from', get_option( 'admin_email' ) );
		$from_name  = $rendered['email'] instanceof WC_Email ? $rendered['email']->get_from_name() : apply_filters( 'wp_mail_from_name', get_bloginfo( 'name' ) );

		wp_send_json_success(
			array(
				'label'   => hwe_email_test_label( $key ),
				'subject' => wp_specialchars_decode( $rendered['subject'] ),
				'from'    => trim( $from_name . ' <' . $from_email . '>' ),
				'html'    => hwe_email_test_final_html( $rendered ),
			)
		);
	}
);
