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

// Piezas del marco, repartidas por tema en hwe-email-chrome/ (todas definen funciones globales).
require_once __DIR__ . '/hwe-email-chrome/settings.php';
require_once __DIR__ . '/hwe-email-chrome/language.php';
require_once __DIR__ . '/hwe-email-chrome/links.php';
require_once __DIR__ . '/hwe-email-chrome/layout.php';
require_once __DIR__ . '/hwe-email-chrome/render.php';

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
