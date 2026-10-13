<?php
/**
 * Idiomas de los correos: idioma de la tienda, idioma pedido, diccionario por idioma (`hwe_email_dictionary`) y `hwe_email_copy()`.
 *
 * Parte de `hwe-email-chrome.php`, que lo carga: las funciones son globales y se reparten por
 * tema para que cada archivo se lea de una vez.
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Idiomas
// ---------------------------------------------------------------------------

/** Idioma de la tienda (`brand.locale`), p. ej. `es`. */
function hwe_email_default_locale(): string {
	$locale = trim( hwe_email_setting( array( 'brand', 'locale' ), 'es' ) );
	return '' !== $locale ? $locale : 'es';
}

/**
 * Idiomas del sitio tal como se escriben en las rutas del frontend: el de la tienda y
 * los secundarios de `i18n.locales`.
 *
 * @return list<string>
 */
function hwe_email_site_locales(): array {
	$locales = array( hwe_email_default_locale() );
	foreach ( preg_split( '/[\s,]+/', hwe_email_setting( array( 'i18n', 'locales' ) ) ) ?: array() as $code ) {
		if ( preg_match( '/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})?$/', $code ) && ! in_array( strtolower( $code ), array_map( 'strtolower', $locales ), true ) ) {
			$locales[] = $code;
		}
	}
	return $locales;
}

/** Un idioma del sitio (con la grafía de las rutas) o, si no lo es, el de la tienda. */
function hwe_email_resolve_locale( ?string $locale ): string {
	if ( null !== $locale && '' !== $locale ) {
		foreach ( hwe_email_site_locales() as $site_locale ) {
			if ( strtolower( $site_locale ) === strtolower( $locale ) ) {
				return $site_locale;
			}
		}
	}
	return hwe_email_default_locale();
}

/**
 * Diccionario de textos por idioma (claves en minúsculas: `es`, `en`, `pt-br`…).
 *
 * Filtro `hwe_email_copy`: recibe el diccionario completo y puede añadir idiomas o
 * cambiar frases. Marcadores `sprintf` (`%s`, `%1$s`) donde la frase los lleva; las
 * frases con HTML están marcadas en su clave con `_html` y reciben los argumentos ya
 * escapados.
 *
 * @return array<string,array<string,string>>
 */
function hwe_email_dictionary(): array {
	static $dictionary = null;
	if ( null !== $dictionary ) {
		return $dictionary;
	}
	$base = array(
		'es' => array(
			'greeting_named'          => 'Hola %s,',
			'greeting'                => 'Hola,',
			'summary'                 => 'Este es el resumen de lo que pediste:',
			'details'                 => 'Este es el detalle de tu pedido:',
			'footer_contact'          => '¿Dudas? Escríbenos a %s',

			'badge_processing'        => 'Pedido confirmado',
			'processing_intro'        => 'Recibimos tu pedido y ya lo estamos preparando.',
			'badge_on_hold'           => 'Pago pendiente',
			'on_hold_intro'           => 'Recibimos tu pedido. Queda en espera hasta que confirmemos tu pago.',
			'badge_completed'         => 'Pedido completado',
			'completed_intro'         => 'Ya terminamos de preparar tu pedido.',
			'badge_cancelled'         => 'Pedido cancelado',
			'cancelled_intro'         => 'Te escribimos para contarte que tu pedido #%s fue cancelado.',
			'cancelled_help'          => 'Si no esperabas este cambio, escríbenos y lo revisamos contigo.',
			'badge_failed'            => 'Pago no completado',
			'failed_intro'            => 'No pudimos completar tu pedido por un problema con el medio de pago.',
			'failed_retry_html'       => 'Si quieres seguir con tu compra, vuelve a %s e intenta con otro medio de pago.',
			'badge_refunded'          => 'Reembolso procesado',
			'refunded_full'           => 'Te reembolsamos tu pedido en %s.',
			'refunded_partial'        => 'Te reembolsamos parte de tu pedido en %s.',
			'badge_note'              => 'Actualización de tu pedido',
			'note_intro'              => 'Añadimos esta nota a tu pedido:',
			'note_reminder'           => 'Como recordatorio, este es el detalle de tu pedido:',
			'badge_invoice'           => 'Detalle de tu pedido',
			'invoice_pay_link'        => 'Pagar este pedido',
			'invoice_pay_guest'       => 'responde a este correo y te enviamos el enlace de pago',
			'invoice_failed_html'     => 'No pudimos completar tu pedido en %1$s. Abajo tienes el detalle; para intentar el pago de nuevo: %2$s.',
			'invoice_created_html'    => 'Creamos un pedido para ti en %1$s. Abajo tienes el detalle; para pagarlo cuando quieras: %2$s.',
			'invoice_paid'            => 'Este es el detalle de tu pedido del %s:',

			'admin_new_order'         => 'Recibiste un pedido nuevo de %s:',
			'admin_cancelled_order'   => 'El pedido #%1$s de %2$s fue cancelado:',
			'admin_failed_order'      => 'El pago del pedido #%1$s de %2$s no se completó:',

			'badge_welcome'           => 'Bienvenida',
			'new_account_thanks'      => 'Gracias por crear tu cuenta en %s.',
			'username'                => 'Usuario: %s',
			'set_password'            => 'Crea tu contraseña',
			'account_intro'           => 'Desde tu cuenta puedes ver tus pedidos, cambiar tu contraseña y más.',
			'my_account'              => 'Ir a mi cuenta',
			'reset_wc_intro'          => 'Alguien pidió una contraseña nueva para esta cuenta en %s.',
			'reset_wc_ignore'         => 'Si no fuiste tú, ignora este correo. Si quieres continuar, crea tu contraseña nueva con este enlace:',
			'reset_wc_link'           => 'Restablecer contraseña',

			'auth_reset_subject'      => 'Restablecer contraseña',
			'auth_reset_heading'      => 'Restablece tu contraseña',
			'auth_reset_intro'        => 'Recibimos una solicitud para cambiar la contraseña de tu cuenta. Este enlace vence en 24 horas.',
			'auth_reset_button'       => 'Crear nueva contraseña',
			'auth_reset_ignore'       => 'Si no fuiste tú, ignora este correo: tu contraseña actual sigue siendo válida.',
			'auth_verify_subject'     => 'Verifica tu email',
			'auth_verify_heading'     => 'Verifica tu email',
			'auth_verify_intro'       => 'Gracias por crear tu cuenta. Confirma tu dirección de email para activarla del todo.',
			'auth_verify_button'      => 'Verificar mi email',
			'auth_verify_ignore'      => 'Este enlace vence en 24 horas. Si no creaste esta cuenta, ignora este correo.',

			'subject_new_order'                 => '[{site_title}]: Nuevo pedido #{order_number}',
			'heading_new_order'                 => 'Nuevo pedido: #{order_number}',
			'subject_cancelled_order'           => '[{site_title}]: Pedido #{order_number} cancelado',
			'heading_cancelled_order'           => 'Pedido cancelado: #{order_number}',
			'subject_failed_order'              => '[{site_title}]: Falló el pago del pedido #{order_number}',
			'heading_failed_order'              => 'Pago fallido: #{order_number}',
			'subject_customer_processing_order' => '¡Recibimos tu pedido en {site_title}!',
			'heading_customer_processing_order' => 'Gracias por tu pedido',
			'subject_customer_on_hold_order'    => 'Recibimos tu pedido #{order_number} en {site_title}',
			'heading_customer_on_hold_order'    => 'Gracias por tu pedido',
			'subject_customer_completed_order'  => 'Tu pedido #{order_number} en {site_title} está completo',
			'heading_customer_completed_order'  => '¡Tu pedido está completo!',
			'subject_customer_cancelled_order'  => 'Tu pedido #{order_number} en {site_title} fue cancelado',
			'heading_customer_cancelled_order'  => 'Tu pedido fue cancelado',
			'subject_customer_failed_order'     => 'No pudimos completar tu pedido en {site_title}',
			'heading_customer_failed_order'     => 'No pudimos completar tu pedido',
			'subject_customer_refunded_order'   => 'Te reembolsamos el pedido #{order_number} en {site_title}',
			'heading_customer_refunded_order'   => 'Reembolso del pedido #{order_number}',
			'subject_partial_customer_refunded_order' => 'Te reembolsamos parte del pedido #{order_number} en {site_title}',
			'heading_partial_customer_refunded_order' => 'Reembolso parcial del pedido #{order_number}',
			'subject_customer_invoice'          => 'Detalle de tu pedido #{order_number} en {site_title}',
			'heading_customer_invoice'          => 'Tu pedido #{order_number}',
			'subject_customer_note'             => 'Tenemos una nota sobre tu pedido en {site_title}',
			'heading_customer_note'             => 'Una nota sobre tu pedido',
			'subject_customer_reset_password'   => 'Restablece tu contraseña en {site_title}',
			'heading_customer_reset_password'   => 'Restablece tu contraseña',
			'subject_customer_new_account'      => 'Tu cuenta en {site_title} está lista',
			'heading_customer_new_account'      => 'Te damos la bienvenida a {site_title}',
		),
		'en' => array(
			'greeting_named'          => 'Hi %s,',
			'greeting'                => 'Hi,',
			'summary'                 => 'Here is a summary of your order:',
			'details'                 => 'Here are your order details:',
			'footer_contact'          => 'Questions? Write to us at %s',

			'badge_processing'        => 'Order confirmed',
			'processing_intro'        => 'We received your order and are getting it ready.',
			'badge_on_hold'           => 'Payment pending',
			'on_hold_intro'           => 'We received your order. It is on hold until we confirm your payment.',
			'badge_completed'         => 'Order complete',
			'completed_intro'         => 'We have finished preparing your order.',
			'badge_cancelled'         => 'Order cancelled',
			'cancelled_intro'         => 'We are writing to let you know that your order #%s was cancelled.',
			'cancelled_help'          => 'If you did not expect this, write to us and we will look into it.',
			'badge_failed'            => 'Payment not completed',
			'failed_intro'            => 'We could not complete your order because of a problem with the payment method.',
			'failed_retry_html'       => 'If you want to continue, go back to %s and try another payment method.',
			'badge_refunded'          => 'Refund processed',
			'refunded_full'           => 'We have refunded your order at %s.',
			'refunded_partial'        => 'We have refunded part of your order at %s.',
			'badge_note'              => 'Order update',
			'note_intro'              => 'We added this note to your order:',
			'note_reminder'           => 'As a reminder, here are your order details:',
			'badge_invoice'           => 'Your order details',
			'invoice_pay_link'        => 'Pay for this order',
			'invoice_pay_guest'       => 'reply to this email and we will send you a payment link',
			'invoice_failed_html'     => 'We could not complete your order at %1$s. Details are below; to try the payment again: %2$s.',
			'invoice_created_html'    => 'We created an order for you at %1$s. Details are below; to pay whenever you like: %2$s.',
			'invoice_paid'            => 'Here are the details of your order from %s:',

			'admin_new_order'         => 'You have received a new order from %s:',
			'admin_cancelled_order'   => 'Order #%1$s from %2$s was cancelled:',
			'admin_failed_order'      => 'Payment for order #%1$s from %2$s failed:',

			'badge_welcome'           => 'Welcome',
			'new_account_thanks'      => 'Thanks for creating an account at %s.',
			'username'                => 'Username: %s',
			'set_password'            => 'Set your password',
			'account_intro'           => 'From your account you can see your orders, change your password and more.',
			'my_account'              => 'Go to my account',
			'reset_wc_intro'          => 'Someone asked for a new password for this account at %s.',
			'reset_wc_ignore'         => 'If this was not you, ignore this email. To continue, set your new password with this link:',
			'reset_wc_link'           => 'Reset password',

			'auth_reset_subject'      => 'Reset your password',
			'auth_reset_heading'      => 'Reset your password',
			'auth_reset_intro'        => 'We received a request to change your account password. This link expires in 24 hours.',
			'auth_reset_button'       => 'Create a new password',
			'auth_reset_ignore'       => 'If this wasn’t you, ignore this email: your current password still works.',
			'auth_verify_subject'     => 'Verify your email',
			'auth_verify_heading'     => 'Verify your email',
			'auth_verify_intro'       => 'Thanks for creating your account. Confirm your email address to finish activating it.',
			'auth_verify_button'      => 'Verify my email',
			'auth_verify_ignore'      => 'This link expires in 24 hours. If you didn’t create this account, ignore this email.',
		),
	);
	$filtered   = apply_filters( 'hwe_email_copy', $base );
	$dictionary = is_array( $filtered ) ? $filtered : $base;
	return $dictionary;
}

/**
 * Frase `$key` en `$locale` (o en el idioma de la tienda), con los argumentos de `sprintf`.
 *
 * Cadena de reserva: el idioma pedido → el de la tienda → español. Una clave que no
 * exista en ninguno devuelve la propia clave y se registra.
 *
 * @param mixed ...$args
 */
function hwe_email_copy( string $key, ?string $locale = null, ...$args ): string {
	$dictionary = hwe_email_dictionary();
	$chain      = array_unique(
		array(
			strtolower( hwe_email_resolve_locale( $locale ) ),
			strtolower( hwe_email_default_locale() ),
			'es',
		)
	);
	foreach ( $chain as $candidate ) {
		$text = $dictionary[ $candidate ][ $key ] ?? null;
		if ( is_string( $text ) ) {
			return $args ? vsprintf( $text, $args ) : $text;
		}
	}
	error_log( sprintf( '[hwe] email: no hay texto para «%s».', $key ) );
	return $key;
}

/** ¿Tiene el diccionario la frase `$key` en ese idioma, sin reservas? */
function hwe_email_has_copy( string $key, string $locale ): bool {
	return is_string( hwe_email_dictionary()[ strtolower( $locale ) ][ $key ] ?? null );
}
