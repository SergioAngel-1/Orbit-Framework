<?php
/**
 * Plugin Name: HWE Customer Identity
 * Description: Documento de identidad del cliente (tipo + número, único entre cuentas y de
 *              escritura única) y aceptación de términos, en la cuenta y en el pedido. Los
 *              tipos de documento y sus formatos salen del paquete de país de la tienda
 *              (mu-plugin hwe-geo): un país sin tipos de documento no pide documento.
 * Author:      Sergio Jauregui
 * Version:     1.0.0
 * Requires PHP: 8.0
 * Text Domain: hwe-customer-identity
 *
 * Dónde vive cada dato:
 *   - Usuario: user meta `billing_document_type`, `billing_document` y `hwe_terms_accepted_at`
 *     (ISO 8601). Salen en el `meta_data` del cliente de wc/v3, que es por donde los lee el BFF.
 *   - Pedido: order meta `_hwe_document_type`, `_hwe_document_number` y `_hwe_terms_accepted_at`
 *     (también en los de invitado). Llegan DENTRO de la creación del pedido, como datos de
 *     extensión de la Store API (`extensions.hwe-identity` del `POST /checkout`), y no después:
 *     con efectivo (`cod`) el pedido pasa a «Procesando» en esa misma petición y el correo de
 *     «Nuevo pedido» sale antes de que el BFF pueda escribir nada. El BFF los vuelve a escribir
 *     por wc/v3 como salvaguarda. Se pintan bajo la facturación en wp-admin y en los correos del
 *     pedido: completo en los de la tienda, enmascarado (`***050`) en los del cliente.
 *
 * Reglas que no dependen de la UI:
 *   - El documento es de ESCRITURA ÚNICA desde el sitio: `attach-identity` solo lo escribe si el
 *     usuario no tiene uno (con otro distinto responde 409 `document_locked`). Corregirlo es
 *     cosa de un administrador, desde el perfil del usuario en wp-admin.
 *   - Tipo + número es ÚNICO entre cuentas (409 `document_taken`), también al editarlo en
 *     wp-admin. Los pedidos de invitado no cuentan: un pedido no es una cuenta, y el mismo
 *     invitado puede comprar muchas veces.
 *   - La aceptación de términos se registra una vez y no se reescribe.
 *
 * Los endpoints cuelgan de `/hwe/v1/auth/`, así que `hwe-bff-only.php` ya los cierra al
 * público; además exigen el secreto interno del BFF.
 *
 * Es un MÓDULO del framework (`customer-identity`): el frontend sabe si está activo por
 * `config.modules` (ver README.md y MANUAL.md de esta carpeta).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Customer_Identity {
	public const META_TYPE   = 'billing_document_type';
	public const META_NUMBER = 'billing_document';
	public const META_TERMS  = 'hwe_terms_accepted_at';

	public const ORDER_META_TYPE   = '_hwe_document_type';
	public const ORDER_META_NUMBER = '_hwe_document_number';
	public const ORDER_META_TERMS  = '_hwe_terms_accepted_at';

	/** Espacio de nombres de los datos de extensión del checkout de la Store API. */
	public const CHECKOUT_NAMESPACE = 'hwe-identity';

	/** Caracteres del documento que deja ver el correo del cliente. */
	private const EMAIL_VISIBLE_CHARS = 3;

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'registerRoutes' ) );
		add_filter( 'woocommerce_customer_meta_fields', array( self::class, 'profileFields' ) );
		add_action( 'personal_options_update', array( self::class, 'guardProfileSave' ), 5 );
		add_action( 'edit_user_profile_update', array( self::class, 'guardProfileSave' ), 5 );
		add_action( 'user_profile_update_errors', array( self::class, 'validateProfileUpdate' ) );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( self::class, 'renderOrderIdentity' ) );
		add_action( 'woocommerce_blocks_loaded', array( self::class, 'registerCheckoutData' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'applyCheckoutData' ), 10, 2 );
		add_action( 'woocommerce_email_customer_address_section', array( self::class, 'renderEmailIdentity' ), 10, 4 );
	}

	// ------------------------------------------------------------------------
	//  Tipos de documento: los del paquete de país de la tienda
	// ------------------------------------------------------------------------

	/**
	 * Tipos admitidos: código → etiqueta y patrón (con delimitadores) del número YA normalizado
	 * (sin espacios, puntos ni guiones, en mayúsculas). Salen del paquete de país de `hwe-geo`;
	 * sin él, o con un paquete sin documentos, la lista está vacía y el módulo no pide documento.
	 *
	 * @return array<string,array{label:string,pattern:string}>
	 */
	public static function types(): array {
		if ( ! function_exists( 'hwe_geo_resolve' ) || ! function_exists( 'hwe_geo_store_country' ) ) {
			return array();
		}
		$resolved = hwe_geo_resolve( hwe_geo_store_country() );
		$types    = array();
		foreach ( (array) ( $resolved['pack']['document_types'] ?? array() ) as $type ) {
			$types[ (string) $type['code'] ] = array(
				'label'   => (string) $type['label'],
				'pattern' => '~' . str_replace( '~', '\~', (string) $type['pattern'] ) . '~',
			);
		}
		return $types;
	}

	/** Número sin separadores y en mayúsculas: la forma que se guarda y se compara. */
	public static function normalizeNumber( string $number ): string {
		return strtoupper( (string) preg_replace( '/[\s.\-]+/', '', trim( $number ) ) );
	}

	public static function isValid( string $type, string $number ): bool {
		$types = self::types();
		return isset( $types[ $type ] ) && 1 === preg_match( $types[ $type ]['pattern'], $number );
	}

	/** Id de otra cuenta con ese tipo + número, o 0. */
	private static function ownerOf( string $type, string $number, int $exclude_user_id = 0 ): int {
		$ids = get_users(
			array(
				'meta_query' => array(
					'relation' => 'AND',
					array( 'key' => self::META_TYPE, 'value' => $type ),
					array( 'key' => self::META_NUMBER, 'value' => $number ),
				),
				'exclude'    => $exclude_user_id > 0 ? array( $exclude_user_id ) : array(),
				'number'     => 1,
				'fields'     => 'ID',
			)
		);
		return empty( $ids ) ? 0 : (int) $ids[0];
	}

	// ------------------------------------------------------------------------
	//  REST (solo BFF)
	// ------------------------------------------------------------------------

	public static function registerRoutes(): void {
		$internal = static function ( WP_REST_Request $request ): bool {
			return function_exists( 'hwe_has_internal_secret' ) && hwe_has_internal_secret( $request );
		};

		register_rest_route(
			'hwe/v1',
			'/auth/document-available',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handleDocumentAvailable' ),
				'permission_callback' => $internal,
				'args'                => array(
					'document_type'   => array( 'required' => true, 'type' => 'string' ),
					'document_number' => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			'hwe/v1',
			'/auth/attach-identity',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handleAttachIdentity' ),
				'permission_callback' => $internal,
				'args'                => array(
					'user_id'         => array( 'required' => true, 'type' => 'integer' ),
					'document_type'   => array( 'required' => false, 'type' => 'string' ),
					'document_number' => array( 'required' => false, 'type' => 'string' ),
					'terms_accepted'  => array( 'required' => false, 'type' => 'boolean' ),
				),
			)
		);
	}

	public static function handleDocumentAvailable( WP_REST_Request $request ): WP_REST_Response {
		$type   = strtoupper( trim( (string) $request->get_param( 'document_type' ) ) );
		$number = self::normalizeNumber( (string) $request->get_param( 'document_number' ) );
		if ( ! self::isValid( $type, $number ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_document' ), 400 );
		}
		return new WP_REST_Response( array( 'available' => 0 === self::ownerOf( $type, $number ) ), 200 );
	}

	public static function handleAttachIdentity( WP_REST_Request $request ): WP_REST_Response {
		$user_id = (int) $request->get_param( 'user_id' );
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return new WP_REST_Response( array( 'error' => 'user_not_found' ), 404 );
		}

		$raw_type   = $request->get_param( 'document_type' );
		$raw_number = $request->get_param( 'document_number' );
		if ( null !== $raw_type || null !== $raw_number ) {
			$type   = strtoupper( trim( (string) $raw_type ) );
			$number = self::normalizeNumber( (string) $raw_number );
			if ( ! self::isValid( $type, $number ) ) {
				return new WP_REST_Response( array( 'error' => 'invalid_document' ), 400 );
			}

			$current_type   = (string) get_user_meta( $user_id, self::META_TYPE, true );
			$current_number = (string) get_user_meta( $user_id, self::META_NUMBER, true );
			if ( '' !== $current_number ) {
				// Escritura única: repetir el mismo es idempotente; otro, no.
				if ( $current_type !== $type || $current_number !== $number ) {
					return new WP_REST_Response( array( 'error' => 'document_locked' ), 409 );
				}
			} else {
				if ( 0 !== self::ownerOf( $type, $number, $user_id ) ) {
					return new WP_REST_Response( array( 'error' => 'document_taken' ), 409 );
				}
				update_user_meta( $user_id, self::META_TYPE, $type );
				update_user_meta( $user_id, self::META_NUMBER, $number );
			}
		}

		if ( true === $request->get_param( 'terms_accepted' )
			&& '' === (string) get_user_meta( $user_id, self::META_TERMS, true ) ) {
			update_user_meta( $user_id, self::META_TERMS, gmdate( 'c' ) );
		}

		return new WP_REST_Response(
			array(
				'document_type'     => (string) get_user_meta( $user_id, self::META_TYPE, true ),
				'document_number'   => (string) get_user_meta( $user_id, self::META_NUMBER, true ),
				'terms_accepted_at' => (string) get_user_meta( $user_id, self::META_TERMS, true ),
			),
			200
		);
	}

	// ------------------------------------------------------------------------
	//  Store API: el pedido nace con su documento
	// ------------------------------------------------------------------------

	/**
	 * Declara `extensions.hwe-identity` en el `POST /checkout`. Todos los campos son opcionales
	 * para la Store API: qué documento y qué aceptación lleva el pedido lo decide el BFF
	 * (`resolveCheckoutIdentity`), y la Store API solo la llama el BFF (`hwe-bff-only.php`).
	 */
	public static function registerCheckoutData(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}
		$string = static function ( string $description ): array {
			return array(
				'description' => $description,
				'type'        => array( 'string', 'null' ),
				'context'     => array(),
			);
		};
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema::IDENTIFIER,
				'namespace'       => self::CHECKOUT_NAMESPACE,
				'schema_callback' => static function () use ( $string ): array {
					return array(
						'document_type'     => $string( 'Tipo de documento del comprador.' ),
						'document_number'   => $string( 'Número de documento del comprador.' ),
						'terms_accepted_at' => $string( 'Aceptación de términos (ISO 8601).' ),
					);
				},
			)
		);
	}

	/**
	 * Escribe el documento y la aceptación en el pedido ANTES del cobro: la Store API dispara
	 * esto antes de `process_payment()`, que es donde el efectivo pasa el pedido a «Procesando» y
	 * salen los correos. Un valor inválido se descarta sin romper la compra (el BFF ya validó).
	 *
	 * @param WC_Order         $order   Pedido borrador.
	 * @param \WP_REST_Request $request Petición del checkout.
	 */
	public static function applyCheckoutData( $order, $request ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$data = $request['extensions'][ self::CHECKOUT_NAMESPACE ] ?? null;
		if ( ! is_array( $data ) ) {
			return;
		}

		$type   = strtoupper( trim( (string) ( $data['document_type'] ?? '' ) ) );
		$number = self::normalizeNumber( (string) ( $data['document_number'] ?? '' ) );
		if ( self::isValid( $type, $number ) ) {
			$order->update_meta_data( self::ORDER_META_TYPE, $type );
			$order->update_meta_data( self::ORDER_META_NUMBER, $number );
		}

		$terms = trim( (string) ( $data['terms_accepted_at'] ?? '' ) );
		if ( '' !== $terms && false !== strtotime( $terms ) ) {
			$order->update_meta_data( self::ORDER_META_TERMS, $terms );
		}

		$order->save_meta_data();
	}

	// ------------------------------------------------------------------------
	//  wp-admin: perfil del usuario
	// ------------------------------------------------------------------------

	/** Campos en la sección «Dirección de facturación» del perfil (solo admin). */
	public static function profileFields( array $fields ): array {
		$types = self::types();
		if ( array() === $types || ! isset( $fields['billing']['fields'] ) ) {
			return $fields;
		}
		$options = array( '' => '—' );
		foreach ( $types as $key => $type ) {
			$options[ $key ] = $type['label'];
		}
		$fields['billing']['fields'][ self::META_TYPE ]   = array(
			'label'   => 'Tipo de documento',
			'type'    => 'select',
			'options' => $options,
		);
		$fields['billing']['fields'][ self::META_NUMBER ] = array(
			'label'       => 'Número de documento',
			'description' => 'Único entre cuentas. El cliente no puede cambiarlo desde el sitio.',
		);
		return $fields;
	}

	/** Error de la guarda de wp-admin, pendiente de mostrarse en `user_profile_update_errors`. */
	private static ?string $profileError = null;

	/**
	 * Guarda de la edición en wp-admin. WooCommerce guarda los campos de arriba tal cual llegan en
	 * `edit_user_profile_update` (prioridad 10), ANTES de que `edit_user()` dispare
	 * `user_profile_update_errors`: un rechazo solo en ese hook llegaría tarde, con el dato ya
	 * escrito. Por eso esto corre antes (prioridad 5): normaliza el número y, si es inválido o de
	 * otra cuenta, repone en `$_POST` los valores guardados para que Woo no cambie nada.
	 */
	public static function guardProfileSave( int $user_id ): void {
		if ( ! isset( $_POST[ self::META_NUMBER ], $_POST[ self::META_TYPE ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$type   = strtoupper( sanitize_text_field( wp_unslash( $_POST[ self::META_TYPE ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$number = self::normalizeNumber( sanitize_text_field( wp_unslash( $_POST[ self::META_NUMBER ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification

		// Vaciar los dos es válido: el administrador puede retirar el documento.
		if ( '' === $type && '' === $number ) {
			$_POST[ self::META_TYPE ] = '';
			return;
		}

		$error = null;
		if ( ! self::isValid( $type, $number ) ) {
			$error = 'El tipo o el número de documento no son válidos. No se guardó.';
		} elseif ( 0 !== self::ownerOf( $type, $number, $user_id ) ) {
			$error = 'Ese documento ya pertenece a otra cuenta. No se guardó.';
		}

		if ( null !== $error ) {
			self::$profileError         = $error;
			$_POST[ self::META_TYPE ]   = (string) get_user_meta( $user_id, self::META_TYPE, true );
			$_POST[ self::META_NUMBER ] = (string) get_user_meta( $user_id, self::META_NUMBER, true );
			return;
		}
		$_POST[ self::META_TYPE ]   = $type;
		$_POST[ self::META_NUMBER ] = $number; // Se guarda normalizado.
	}

	public static function validateProfileUpdate( WP_Error $errors ): void {
		if ( null !== self::$profileError ) {
			$errors->add( 'hwe_document', self::$profileError );
		}
	}

	// ------------------------------------------------------------------------
	//  wp-admin: ficha del pedido
	// ------------------------------------------------------------------------

	public static function renderOrderIdentity( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$type   = (string) $order->get_meta( self::ORDER_META_TYPE );
		$number = (string) $order->get_meta( self::ORDER_META_NUMBER );
		$terms  = (string) $order->get_meta( self::ORDER_META_TERMS );
		if ( '' === $number && '' === $terms ) {
			return;
		}
		echo '<p>';
		if ( '' !== $number ) {
			printf( '<strong>%s:</strong> %s<br/>', esc_html( self::typeLabel( $type ) ), esc_html( $number ) );
		}
		if ( '' !== $terms ) {
			$time = strtotime( $terms );
			printf(
				'<strong>%s:</strong> %s',
				esc_html( 'Términos aceptados' ),
				esc_html( false !== $time ? wp_date( 'Y-m-d H:i', $time ) : $terms )
			);
		}
		echo '</p>';
	}

	/** Etiqueta del tipo; uno que el paquete de país ya no tiene se muestra tal cual. */
	private static function typeLabel( string $type ): string {
		return self::types()[ $type ]['label'] ?? $type;
	}

	// ------------------------------------------------------------------------
	//  Correos del pedido
	// ------------------------------------------------------------------------

	/**
	 * El documento, bajo el teléfono y el correo de la dirección de facturación, en todos los
	 * correos que la pintan. El de la tienda («Nuevo pedido», cancelado, fallido) lo lleva
	 * COMPLETO; los del cliente, ENMASCARADO (`***050`): el correo sale del servidor y queda en
	 * buzones de terceros, así que no viaja el documento entero. Un `$sent_to_admin` ausente
	 * cuenta como correo del cliente. La aceptación de términos no va: es prueba para wp-admin,
	 * no dato de facturación.
	 *
	 * @param string   $type          'billing' | 'shipping'.
	 * @param WC_Order $order         Pedido.
	 * @param bool     $sent_to_admin Si va a la tienda.
	 * @param bool     $plain_text    Versión de texto plano.
	 */
	public static function renderEmailIdentity( $type, $order, $sent_to_admin = false, $plain_text = false ): void {
		if ( 'billing' !== $type || ! $order instanceof WC_Order ) {
			return;
		}
		$number = (string) $order->get_meta( self::ORDER_META_NUMBER );
		if ( '' === $number ) {
			return;
		}
		$shown = true === $sent_to_admin ? $number : self::maskNumber( $number );
		$line  = self::typeLabel( (string) $order->get_meta( self::ORDER_META_TYPE ) ) . ': ' . $shown;
		if ( $plain_text ) {
			echo esc_html( $line ) . "\n";
			return;
		}
		echo '<br/>' . esc_html( $line );
	}

	/** `1020304050` → `***050`; uno de 3 caracteres o menos no deja ver nada. */
	public static function maskNumber( string $number ): string {
		$visible = mb_strlen( $number ) > self::EMAIL_VISIBLE_CHARS
			? mb_substr( $number, -self::EMAIL_VISIBLE_CHARS )
			: '';
		return '***' . $visible;
	}
}

HWE_Customer_Identity::register();
