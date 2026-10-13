<?php
/**
 * Cuerpo de los correos de WooCommerce y de los de cuenta, con el marco de marca.
 *
 * Parte de `hwe-email-chrome.php`, que lo carga: las funciones son globales y se reparten por
 * tema para que cada archivo se lea de una vez.
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Cuerpo de los correos de WooCommerce
// ---------------------------------------------------------------------------

/** Saludo con el nombre de facturación, o genérico. */
function hwe_email_greeting( $order ): string {
	$name = is_object( $order ) && method_exists( $order, 'get_billing_first_name' ) ? (string) $order->get_billing_first_name() : '';
	return '<p>' . esc_html( '' !== $name ? hwe_email_copy( 'greeting_named', null, $name ) : hwe_email_copy( 'greeting' ) ) . '</p>';
}

/**
 * Pinta un correo de pedido de WooCommerce con el marco de marca. Lo llaman las
 * plantillas de `themes/hwe-headless-base/woocommerce/emails/` con `get_defined_vars()`:
 * cada una solo dice qué correo es; el texto vive en el diccionario.
 *
 * @param array<string,mixed> $vars Variables de la plantilla de WooCommerce.
 */
function hwe_email_render_order_email( string $id, array $vars ): void {
	$order         = $vars['order'] ?? null;
	$email         = $vars['email'] ?? null;
	$heading       = (string) ( $vars['email_heading'] ?? '' );
	$sent_to_admin = (bool) ( $vars['sent_to_admin'] ?? false );
	$plain_text    = (bool) ( $vars['plain_text'] ?? false );
	$blogname      = (string) ( $vars['blogname'] ?? wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
	$number        = is_object( $order ) && method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : '';
	$customer      = is_object( $order ) && method_exists( $order, 'get_formatted_billing_full_name' ) ? (string) $order->get_formatted_billing_full_name() : '';

	// El H1 de los correos de cliente lo pone el cuerpo (con insignia); los avisos a la
	// tienda lo reciben de email-header.php.
	$badges = array(
		'customer_processing_order' => array( 'badge_processing', 'brand' ),
		'customer_on_hold_order'    => array( 'badge_on_hold', 'accent' ),
		'customer_completed_order'  => array( 'badge_completed', 'secondary' ),
		'customer_cancelled_order'  => array( 'badge_cancelled', 'brand_dark' ),
		'customer_failed_order'     => array( 'badge_failed', 'accent' ),
		'customer_refunded_order'   => array( 'badge_refunded', 'brand_dark' ),
		'customer_note'             => array( 'badge_note', 'brand' ),
		'customer_invoice'          => array( 'badge_invoice', 'brand' ),
	);
	if ( isset( $badges[ $id ] ) ) {
		$GLOBALS['hwe_email_heading_handled'] = true;
	}

	do_action( 'woocommerce_email_header', $heading, $email );

	if ( isset( $badges[ $id ] ) ) {
		echo hwe_email_badge( hwe_email_copy( $badges[ $id ][0] ), $badges[ $id ][1] ) . hwe_email_heading( $heading ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	echo '<div class="email-introduction">';
	switch ( $id ) {
		case 'new_order':
			echo '<p>' . esc_html( hwe_email_copy( 'admin_new_order', null, $customer ) ) . '</p>';
			break;
		case 'cancelled_order':
			echo '<p>' . esc_html( hwe_email_copy( 'admin_cancelled_order', null, $number, $customer ) ) . '</p>';
			break;
		case 'failed_order':
			echo '<p>' . esc_html( hwe_email_copy( 'admin_failed_order', null, $number, $customer ) ) . '</p>';
			break;
		case 'customer_processing_order':
		case 'customer_on_hold_order':
		case 'customer_completed_order':
			$intro = array(
				'customer_processing_order' => 'processing_intro',
				'customer_on_hold_order'    => 'on_hold_intro',
				'customer_completed_order'  => 'completed_intro',
			)[ $id ];
			echo hwe_email_greeting( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<p>' . esc_html( hwe_email_copy( $intro ) ) . '</p>';
			echo '<p>' . esc_html( hwe_email_copy( 'summary' ) ) . '</p>';
			break;
		case 'customer_cancelled_order':
			echo hwe_email_greeting( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<p>' . esc_html( hwe_email_copy( 'cancelled_intro', null, $number ) ) . '</p>';
			echo '<p>' . esc_html( hwe_email_copy( 'cancelled_help' ) ) . '</p>';
			break;
		case 'customer_failed_order':
			$link = '<a href="' . esc_url( hwe_email_frontend_url( '/' ) ) . '">' . esc_html( $blogname ) . '</a>';
			echo hwe_email_greeting( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<p>' . esc_html( hwe_email_copy( 'failed_intro' ) ) . '</p>';
			echo '<p>' . wp_kses_post( hwe_email_copy( 'failed_retry_html', null, $link ) ) . '</p>';
			echo '<p>' . esc_html( hwe_email_copy( 'details' ) ) . '</p>';
			break;
		case 'customer_refunded_order':
			echo hwe_email_greeting( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<p>' . esc_html( hwe_email_copy( ! empty( $vars['partial_refund'] ) ? 'refunded_partial' : 'refunded_full', null, $blogname ) ) . '</p>';
			echo '<p>' . esc_html( hwe_email_copy( 'summary' ) ) . '</p>';
			break;
		case 'customer_note':
			echo hwe_email_greeting( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<p>' . esc_html( hwe_email_copy( 'note_intro' ) ) . '</p>';
			echo '<blockquote>' . wpautop( make_clickable( wc_wptexturize_order_note( (string) ( $vars['customer_note'] ?? '' ) ) ) ) . '</blockquote>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<p>' . esc_html( hwe_email_copy( 'note_reminder' ) ) . '</p>';
			break;
		case 'customer_invoice':
			echo hwe_email_greeting( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<p>' . wp_kses_post( hwe_email_invoice_intro( $order ) ) . '</p>';
			break;
	}
	echo '</div>';

	do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
	do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
	do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

	$additional = (string) ( $vars['additional_content'] ?? '' );
	if ( '' !== $additional ) {
		echo '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content">'
			. wp_kses_post( wpautop( wptexturize( $additional ) ) )
			. '</td></tr></table>';
	}

	do_action( 'woocommerce_email_footer', $email );
}

/**
 * Introducción del «detalle del pedido» (factura / enlace de pago). El pago se retoma
 * desde la ficha del pedido en la cuenta del FRONTEND, no desde /checkout/order-pay/ de
 * WordPress; un pedido de invitado no tiene cuenta a la que llevarlo: se le pide que
 * responda.
 */
function hwe_email_invoice_intro( $order ): string {
	$site = esc_html( (string) get_bloginfo( 'name', 'display' ) );
	if ( ! is_object( $order ) || ! method_exists( $order, 'needs_payment' ) || ! $order->needs_payment() ) {
		$date = is_object( $order ) && method_exists( $order, 'get_date_created' ) && function_exists( 'wc_format_datetime' )
			? (string) wc_format_datetime( $order->get_date_created() )
			: '';
		return esc_html( hwe_email_copy( 'invoice_paid', null, $date ) );
	}
	$pay = (int) $order->get_customer_id() > 0
		? '<a href="' . esc_url( hwe_email_frontend_url( '/account/orders/' . (int) $order->get_id() ) ) . '">' . esc_html( hwe_email_copy( 'invoice_pay_link' ) ) . '</a>'
		: esc_html( hwe_email_copy( 'invoice_pay_guest' ) );
	$key = method_exists( $order, 'has_status' ) && $order->has_status( 'failed' ) ? 'invoice_failed_html' : 'invoice_created_html';
	return hwe_email_copy( $key, null, $site, $pay );
}

/**
 * Pinta los correos de cuenta de WooCommerce (bienvenida y restablecer contraseña),
 * con enlaces al frontend. El de «crea tu contraseña» reutiliza la pareja key/login que
 * WooCommerce generó.
 *
 * @param 'customer_new_account'|'customer_reset_password' $id
 * @param array<string,mixed> $vars
 */
function hwe_email_render_account_email( string $id, array $vars ): void {
	$email      = $vars['email'] ?? null;
	$heading    = (string) ( $vars['email_heading'] ?? '' );
	$user_login = (string) ( $vars['user_login'] ?? '' );
	$blogname   = (string) ( $vars['blogname'] ?? get_bloginfo( 'name' ) );

	$GLOBALS['hwe_email_heading_handled'] = true;
	do_action( 'woocommerce_email_header', $heading, $email );

	if ( 'customer_new_account' === $id ) {
		echo hwe_email_badge( hwe_email_copy( 'badge_welcome' ), 'brand' ) . hwe_email_heading( $heading ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p>' . esc_html( hwe_email_copy( 'greeting_named', null, $user_login ) ) . '</p>';
		echo '<p>' . esc_html( hwe_email_copy( 'new_account_thanks', null, $blogname ) ) . '</p>';
		echo '<p>' . esc_html( hwe_email_copy( 'username', null, $user_login ) ) . '</p>';

		$set_password_url = '';
		if ( ! empty( $vars['password_generated'] ) && ! empty( $vars['set_password_url'] ) ) {
			parse_str( (string) wp_parse_url( (string) $vars['set_password_url'], PHP_URL_QUERY ), $query );
			if ( ! empty( $query['key'] ) && ! empty( $query['login'] ) ) {
				$set_password_url = hwe_email_frontend_url( '/reset-password', array( 'key' => (string) $query['key'], 'login' => (string) $query['login'] ) );
			}
		}
		if ( '' !== $set_password_url ) {
			echo hwe_email_button( hwe_email_copy( 'set_password' ), $set_password_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '<p>' . esc_html( hwe_email_copy( 'account_intro' ) ) . '</p>';
		echo '<p><a href="' . esc_url( hwe_email_frontend_url( '/account' ) ) . '">' . esc_html( hwe_email_copy( 'my_account' ) ) . '</a></p>';
	} else {
		echo hwe_email_heading( $heading ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p>' . esc_html( hwe_email_copy( 'greeting_named', null, $user_login ) ) . '</p>';
		echo '<p>' . esc_html( hwe_email_copy( 'reset_wc_intro', null, $blogname ) ) . '</p>';
		echo '<p>' . esc_html( hwe_email_copy( 'username', null, $user_login ) ) . '</p>';
		echo '<p>' . esc_html( hwe_email_copy( 'reset_wc_ignore' ) ) . '</p>';
		// Página de restablecer del FRONTEND (misma pareja key/login que valida hwe-auth).
		echo hwe_email_button( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			hwe_email_copy( 'reset_wc_link' ),
			hwe_email_frontend_url( '/reset-password', array( 'key' => (string) ( $vars['reset_key'] ?? '' ), 'login' => $user_login ) )
		);
	}

	$additional = (string) ( $vars['additional_content'] ?? '' );
	if ( '' !== $additional ) {
		echo wp_kses_post( wpautop( wptexturize( $additional ) ) );
	}

	do_action( 'woocommerce_email_footer', $email );
}
