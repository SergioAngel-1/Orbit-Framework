<?php
/**
 * Plugin Name: HWE Email Chrome
 * Description: Marco de marca de los correos transaccionales (cabecera, pie, insignias,
 *              botón, tipografía y colores del Control Center), el texto de cada correo
 *              por idioma y los enlaces al frontend headless. Lo usan las plantillas de
 *              WooCommerce del tema base (themes/hwe-headless-base/woocommerce/emails/) y
 *              los correos propios de hwe-auth.php.
 * Author:      Headless Web Ecosystem
 * Version:     2.0.0
 *
 * TEXTO POR IDIOMA
 * ----------------
 * El texto NO sale de `__( …, 'woocommerce' )`: los paquetes de idioma de WooCommerce no
 * traducen las frases nuevas de `email_improvements`, y en producción (DISALLOW_FILE_MODS)
 * ni siquiera se pueden instalar desde wp-admin. Sale de un diccionario por idioma
 * (`hwe_email_copy()`): español e inglés de serie; una instancia añade idiomas o cambia
 * frases con el filtro `hwe_email_copy`. Los correos de WooCommerce salen en el idioma de
 * la tienda (`brand.locale`); los de acceso (recuperar contraseña, verificar email), en el
 * del visitante que los pidió. Un idioma sin diccionario usa el de la tienda.
 *
 * ENLACES
 * -------
 * WooCommerce enlaza a sus páginas de WordPress (/my-account/, /checkout/order-pay/…), que
 * en headless no existen. Los enlaces van al frontend (`hwe_email_frontend_url()`), a las
 * rutas del starter (`/account`, `/reset-password`…), con el prefijo del idioma si no es el
 * de la tienda.
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Ajustes del Control Center
// ---------------------------------------------------------------------------

/**
 * Valor de un campo del Control Center: el guardado o, si no hay, el valor por defecto
 * del esquema. Sin el Control Center, `$fallback`.
 *
 * @param list<string> $path
 */
function hwe_email_setting( array $path, string $fallback = '' ): string {
	if ( ! class_exists( 'HWE\\ControlCenter\\Storage' ) ) {
		return $fallback;
	}
	$value = HWE\ControlCenter\Storage::get( $path );
	if ( is_scalar( $value ) && '' !== (string) $value ) {
		return (string) $value;
	}
	$default = hwe_email_schema_default( $path );
	return '' !== $default ? $default : $fallback;
}

/** Valor por defecto del esquema para un campo (memoizado por petición). */
function hwe_email_schema_default( array $path ): string {
	static $defaults = null;
	if ( null === $defaults ) {
		$defaults = class_exists( 'HWE\\ControlCenter\\Walkers\\DefaultsWalker' ) && class_exists( 'HWE\\ControlCenter\\Schema' )
			? ( new HWE\ControlCenter\Walkers\DefaultsWalker() )->walk( HWE\ControlCenter\Schema::core() )
			: array();
	}
	$current = $defaults;
	foreach ( $path as $key ) {
		if ( ! is_array( $current ) || ! array_key_exists( $key, $current ) ) {
			return '';
		}
		$current = $current[ $key ];
	}
	return is_scalar( $current ) ? (string) $current : '';
}

/**
 * Color de diseño (`design.colors.<nombre>`) como `#rrggbb`. Un valor que no sea un hex
 * válido (va dentro de un atributo `style`) cae al del esquema.
 */
function hwe_email_color( string $name ): string {
	$value = hwe_email_setting( array( 'design', 'colors', $name ) );
	if ( preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ) {
		return $value;
	}
	$default = hwe_email_schema_default( array( 'design', 'colors', $name ) );
	return preg_match( '/^#[0-9a-fA-F]{6}$/', $default ) ? $default : '#000000';
}

/**
 * Pila tipográfica para el correo: la fuente del Control Center (solo letras, cifras,
 * espacios y guiones: va dentro de un `style`) y reservas seguras.
 *
 * @param 'body'|'heading' $which
 */
function hwe_email_font_stack( string $which ): string {
	$field = 'heading' === $which ? 'font_heading' : 'font_sans';
	$name  = trim( (string) preg_replace( '/[^A-Za-z0-9 \-]/', '', hwe_email_setting( array( 'design', 'typography', $field ) ) ) );
	$tail  = 'heading' === $which
		? "Georgia, 'Times New Roman', serif"
		: "'Segoe UI', 'Helvetica Neue', Arial, sans-serif";
	return '' !== $name ? "'" . $name . "', " . $tail : $tail;
}

function hwe_email_brand_name(): string {
	return hwe_email_setting( array( 'brand', 'name' ), (string) get_bloginfo( 'name' ) );
}

/** Logo del correo (`brand.email_logo`, si no `brand.logo`) como URL absoluta. */
function hwe_email_logo_url(): string {
	$url = hwe_email_setting( array( 'brand', 'email_logo' ) );
	if ( '' === $url ) {
		$url = hwe_email_setting( array( 'brand', 'logo' ) );
	}
	if ( '' === $url ) {
		return '';
	}
	// La medioteca guarda rutas relativas a uploads (`/wp-content/uploads/…`): un cliente
	// de correo necesita la URL completa.
	$pos = strpos( $url, 'wp-content/uploads/' );
	if ( false !== $pos ) {
		return home_url( '/' . substr( $url, $pos ) );
	}
	return $url;
}

function hwe_email_legal( string $field ): string {
	return hwe_email_setting( array( 'legal', $field ) );
}

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

// ---------------------------------------------------------------------------
// Enlaces al frontend
// ---------------------------------------------------------------------------

/**
 * Origen público del frontend, sin barra final.
 *
 * Orden: HEADLESS_FRONTEND_URL → `brand.url` del Control Center → primer origen de la
 * allowlist headless → home_url(). `brand.url` va antes que la allowlist porque ésta cae
 * a http://localhost:3000 cuando el proceso no define las constantes (p. ej. el cron, que
 * también manda correos: cancelaciones automáticas, Action Scheduler).
 */
function hwe_email_frontend_base(): string {
	$base = '';
	if ( defined( 'HEADLESS_FRONTEND_URL' ) && HEADLESS_FRONTEND_URL ) {
		$base = (string) HEADLESS_FRONTEND_URL;
	} elseif ( class_exists( 'HWE\\ControlCenter\\Storage' ) && '' !== (string) HWE\ControlCenter\Storage::get( array( 'brand', 'url' ) ) ) {
		$base = (string) HWE\ControlCenter\Storage::get( array( 'brand', 'url' ) );
	} elseif ( function_exists( 'hwe_allowed_origins' ) ) {
		$base = (string) ( hwe_allowed_origins()[0] ?? '' );
	}
	if ( '' === $base ) {
		$base = (string) home_url();
	}
	return rtrim( $base, '/' );
}

/**
 * URL absoluta del frontend para un enlace de correo: `$route` es la ruta del starter
 * (`/account`, `/reset-password`, `/account/orders/42`), y el idioma, si no es el de la
 * tienda, va de prefijo (`/en/account`).
 *
 * @param array<string,string> $query Parámetros (se codifican aquí).
 */
function hwe_email_frontend_url( string $route, array $query = array(), ?string $locale = null ): string {
	$locale = hwe_email_resolve_locale( $locale );
	$prefix = strtolower( $locale ) === strtolower( hwe_email_default_locale() ) ? '' : '/' . $locale;
	$path   = '/' . ltrim( $route, '/' );
	$url    = hwe_email_frontend_base() . $prefix . ( '/' === $path ? ( '' === $prefix ? '/' : '' ) : $path );
	return $query ? add_query_arg( array_map( 'rawurlencode', $query ), $url ) : $url;
}

// ---------------------------------------------------------------------------
// Piezas del marco
// ---------------------------------------------------------------------------

/**
 * Aclara un color hex mezclándolo con blanco (fondo de una insignia).
 * `$amount` 0 = blanco, 1 = el color original.
 */
function hwe_email_tint( string $hex, float $amount ): string {
	$hex = ltrim( $hex, '#' );
	if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
		return '#f3f4f6';
	}
	$mix = static fn ( int $c ): int => (int) round( $c + ( 255 - $c ) * ( 1 - $amount ) );
	return sprintf( '#%02x%02x%02x', $mix( (int) hexdec( substr( $hex, 0, 2 ) ) ), $mix( (int) hexdec( substr( $hex, 2, 2 ) ) ), $mix( (int) hexdec( substr( $hex, 4, 2 ) ) ) );
}

/** Insignia del tipo de correo. `$color` = nombre de un color de diseño. */
function hwe_email_badge( string $label, string $color = 'brand' ): string {
	$bg = hwe_email_tint( hwe_email_color( $color ), 0.12 );
	// Texto en brand_dark sobre cualquier fondo aclarado: los tonos de marca crudos no
	// llegan al contraste AA sobre su propio tinte; el oscuro sí.
	return sprintf(
		'<span style="display:inline-block;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;padding:6px 12px;border-radius:999px;background:%s;color:%s;margin-bottom:18px;">%s</span>',
		esc_attr( $bg ),
		esc_attr( hwe_email_color( 'brand_dark' ) ),
		esc_html( $label )
	);
}

function hwe_email_heading( string $text ): string {
	return sprintf(
		'<h1 style="font-family:%s;font-size:22px;line-height:1.3;margin:0 0 10px;color:%s;">%s</h1>',
		esc_attr( hwe_email_font_stack( 'heading' ) ),
		esc_attr( hwe_email_color( 'foreground' ) ),
		esc_html( $text )
	);
}

function hwe_email_button( string $label, string $url ): string {
	return sprintf(
		'<p style="text-align:center;margin:26px 0 22px;"><a href="%s" style="display:inline-block;background:%s;color:#ffffff;font-weight:700;font-size:14px;padding:13px 30px;border-radius:8px;text-decoration:none;font-family:%s;">%s</a></p>',
		esc_url( $url ),
		esc_attr( hwe_email_color( 'brand' ) ),
		esc_attr( hwe_email_font_stack( 'body' ) ),
		esc_html( $label )
	);
}

/**
 * Reglas de móvil del marco: la caja es `width:100%` + `max-width:560px` (con un ancho
 * fijo, en un móvil el correo se salía de la pantalla) y en pantallas estrechas se
 * recortan los márgenes. Solo @media: lo demás va inline.
 */
function hwe_email_responsive_css(): string {
	return '@media only screen and (max-width: 600px) {'
		. '.hwe-email-outer { padding: 16px 8px !important; }'
		. '.hwe-email-logo { padding: 24px 20px 18px !important; }'
		. '.hwe-email-body { padding: 26px 20px 6px !important; }'
		. '.hwe-email-footer { padding: 22px 20px 26px !important; }'
		. '}';
}

/** Apertura del documento y de la tarjeta, hasta el inicio del cuerpo. */
function hwe_email_open( string $title, string $lang ): string {
	$brand_name = hwe_email_brand_name();
	$logo_url   = hwe_email_logo_url();
	$background = hwe_email_color( 'background' );
	$font_urls  = array_filter( array( hwe_email_setting( array( 'design', 'typography', 'font_url' ) ), hwe_email_setting( array( 'design', 'typography', 'font_heading_url' ) ) ) );

	$logo_html = '' !== $logo_url
		? '<img src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( $brand_name ) . '" width="110" style="display:block;margin:0 auto;height:auto;">'
		: '<p style="margin:0;font-family:' . esc_attr( hwe_email_font_stack( 'heading' ) ) . ';font-size:19px;font-weight:600;color:' . esc_attr( hwe_email_color( 'brand_dark' ) ) . ';">' . esc_html( $brand_name ) . '</p>';

	$imports = '';
	foreach ( $font_urls as $font_url ) {
		$imports .= "@import url('" . esc_url_raw( $font_url ) . "');";
	}

	return '<!DOCTYPE html><html lang="' . esc_attr( $lang ) . '"><head>'
		. '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />'
		. '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
		. '<title>' . esc_html( $title ) . '</title>'
		. ( '' !== $imports ? '<style>' . $imports . '</style>' : '' )
		. '<style>' . hwe_email_responsive_css() . '</style>'
		. '</head><body style="margin:0;padding:0;background:' . esc_attr( $background ) . ';">'
		. '<table role="presentation" class="hwe-email-outer" width="100%" cellpadding="0" cellspacing="0" style="background:' . esc_attr( $background ) . ';padding:36px 20px;"><tr><td align="center">'
		. '<!--[if mso]><table role="presentation" width="560" align="center" cellpadding="0" cellspacing="0"><tr><td><![endif]-->'
		. '<table role="presentation" class="hwe-email-card" width="100%" cellpadding="0" cellspacing="0" style="width:100%;max-width:560px;background:' . esc_attr( hwe_email_color( 'surface' ) ) . ';border-radius:10px;overflow:hidden;">'
		. '<tr><td align="center" class="hwe-email-logo" style="background:' . esc_attr( hwe_email_tint( hwe_email_color( 'brand_light' ), 0.25 ) ) . ';padding:30px 32px 22px;">' . $logo_html . '</td></tr>'
		. '<tr><td style="height:3px;background:linear-gradient(90deg,' . esc_attr( hwe_email_color( 'brand' ) ) . ',' . esc_attr( hwe_email_color( 'secondary' ) ) . ' 60%,' . esc_attr( hwe_email_color( 'accent' ) ) . ');line-height:3px;font-size:0;">&nbsp;</td></tr>'
		. '<tr><td class="hwe-email-body" style="padding:34px 32px 8px;font-family:' . esc_attr( hwe_email_font_stack( 'body' ) ) . ';color:' . esc_attr( hwe_email_color( 'foreground' ) ) . ';">';
}

/** Cierre del cuerpo, pie de marca y cierre del documento. */
function hwe_email_close( ?string $locale = null ): string {
	$muted   = 'color:#6b7280;';
	$company = hwe_email_legal( 'company' );
	$address = hwe_email_legal( 'address' );
	$contact = hwe_email_legal( 'email' );
	if ( '' === $contact ) {
		$contact = (string) get_option( 'admin_email', '' );
	}

	$footer = '<p style="margin:0 0 4px;font-size:12px;font-weight:600;color:' . esc_attr( hwe_email_color( 'brand_dark' ) ) . ';">' . esc_html( hwe_email_brand_name() ) . '</p>';
	if ( '' !== $company ) {
		$footer .= '<p style="margin:0 0 4px;font-size:11.5px;' . $muted . '">' . esc_html( $company ) . '</p>';
	}
	if ( '' !== $address ) {
		$footer .= '<p style="margin:0 0 4px;font-size:11.5px;' . $muted . '">' . esc_html( $address ) . '</p>';
	}
	if ( '' !== $contact ) {
		$footer .= '<p style="margin:0;font-size:11.5px;' . $muted . '">' . esc_html( hwe_email_copy( 'footer_contact', $locale, $contact ) ) . '</p>';
	}

	return '</td></tr>'
		. '<tr><td align="center" class="hwe-email-footer" style="background:' . esc_attr( hwe_email_color( 'background' ) ) . ';padding:26px 32px 30px;font-family:' . esc_attr( hwe_email_font_stack( 'body' ) ) . ';">' . $footer . '</td></tr>'
		. '</table><!--[if mso]></td></tr></table><![endif]--></td></tr></table></body></html>';
}

/**
 * Documento completo (cabecera + cuerpo + pie) para los correos propios de hwe-auth.php,
 * que no pasan por WC_Emails. `$body_html` ya llega redactado y escapado en `$locale`.
 */
function hwe_render_auth_email( string $heading, string $body_html, string $locale = '' ): string {
	$locale = hwe_email_resolve_locale( $locale );
	return hwe_email_open( hwe_email_brand_name(), $locale )
		. hwe_email_heading( $heading )
		. $body_html
		. hwe_email_close( $locale );
}

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

// ---------------------------------------------------------------------------
// Ajustes globales de los correos de WooCommerce
// ---------------------------------------------------------------------------

/*
 * Sin datos estructurados en los correos de pedido: WooCommerce incrusta un JSON-LD
 * `Order` cuyas URLs (pedido en /my-account/view-order/, fichas en /product/) son las de
 * WordPress, que en headless no existen.
 */
add_action(
	'init',
	static function () {
		if ( function_exists( 'WC' ) && isset( WC()->structured_data ) ) {
			remove_action( 'woocommerce_email_order_details', array( WC()->structured_data, 'output_email_structured_data' ), 30 );
		}
	},
	20
);

/**
 * ¿Escribió el admin un valor propio para `$field` (subject/heading/additional_content)
 * en WooCommerce → Ajustes → Correos electrónicos? Cuenta también las variantes
 * `subject_full`/`subject_partial`/`subject_paid` de reembolso y factura.
 */
function hwe_email_has_custom( $email, string $field ): bool {
	if ( ! is_object( $email ) || ! method_exists( $email, 'get_option_key' ) ) {
		return false;
	}
	$stored = get_option( $email->get_option_key(), array() );
	if ( ! is_array( $stored ) ) {
		return false;
	}
	foreach ( $stored as $key => $value ) {
		if ( ( $key === $field || str_starts_with( (string) $key, $field . '_' ) ) && '' !== trim( (string) $value ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Asunto y encabezado de cada correo de WooCommerce desde el diccionario, en el idioma
 * de la tienda, salvo que el admin haya escrito los suyos. Si el idioma de la tienda no
 * tiene esas frases en el diccionario, se dejan los de WooCommerce.
 *
 * «Contenido adicional» por defecto → vacío en los correos AL CLIENTE: el texto de
 * fábrica de WooCommerce apunta al remitente técnico y repite lo que ya dice el pie de
 * marca. Solo se anula el DEFECTO: un texto propio del admin se respeta.
 */
add_action(
	'woocommerce_email',
	static function ( $mailer ) {
		$locale = hwe_email_default_locale();

		foreach ( $mailer->get_emails() as $email ) {
			$id = $email->id;

			// «Nuevo pedido» trae al pie una promo de la app móvil de WooCommerce, ajena
			// a la marca.
			if ( 'new_order' === $id ) {
				remove_action( 'woocommerce_email_footer', array( $email, 'mobile_messaging' ), 9 );
			}

			foreach ( array( 'subject', 'heading' ) as $field ) {
				if ( ! hwe_email_has_copy( $field . '_' . $id, $locale ) ) {
					continue;
				}
				add_filter(
					"woocommerce_email_{$field}_{$id}",
					static function ( $value, $object, $instance = null ) use ( $id, $field, $locale ) {
						if ( ! is_object( $instance ) || ! method_exists( $instance, 'format_string' ) || hwe_email_has_custom( $instance, $field ) ) {
							return $value;
						}
						$key = ! empty( $instance->partial_refund ) && hwe_email_has_copy( $field . '_partial_' . $id, $locale )
							? $field . '_partial_' . $id
							: $field . '_' . $id;
						return $instance->format_string( hwe_email_copy( $key, $locale ) );
					},
					10,
					3
				);
			}

			if ( $email->is_customer_email() ) {
				add_filter(
					'woocommerce_email_additional_content_' . $id,
					static function ( $content, $object, $instance = null ) {
						return hwe_email_has_custom( $instance, 'additional_content' ) ? $content : '';
					},
					10,
					3
				);
			}
		}
	}
);
