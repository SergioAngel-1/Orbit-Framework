<?php
/**
 * Rutas REST del registro de consentimientos (solo las llama el BFF con el secreto interno): registrar una decisión, leer la última de un comprobante y vincularlo a la cuenta.
 *
 * Es un trait de `HWE_Consent_Log` (hwe-consent-log.php): el plugin es una sola clase con
 * estado estático y se parte por responsabilidad para que cada archivo se lea de una vez.
 * Los métodos usan `self::` y las constantes de la clase que lo incluye.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait HWE_Consent_Log_Rest {
	public static function registerRoutes(): void {
		$internal = static function ( WP_REST_Request $request ): bool {
			return function_exists( 'hwe_has_internal_secret' ) && hwe_has_internal_secret( $request );
		};

		register_rest_route(
			'hwe/v1',
			'/consent/record',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handleRecord' ),
				'permission_callback' => $internal,
			)
		);
		register_rest_route(
			'hwe/v1',
			'/consent/latest',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handleLatest' ),
				'permission_callback' => $internal,
			)
		);
		register_rest_route(
			'hwe/v1',
			'/consent/link',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handleLink' ),
				'permission_callback' => $internal,
			)
		);
	}

	private static function clip( $value, int $max ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( wp_check_invalid_utf8( $value ) );
		return '' === $value ? null : mb_substr( $value, 0, $max );
	}

	private static function cleanIp( $value ): ?string {
		return is_string( $value ) && false !== filter_var( $value, FILTER_VALIDATE_IP ) ? $value : null;
	}

	private static function validUserId( $value ): ?int {
		$id = is_numeric( $value ) ? (int) $value : 0;
		return $id > 0 && false !== get_userdata( $id ) ? $id : null;
	}

	/** Versión de la política vigente: última modificación de la página `cookies`. */
	private static function policyVersion(): ?string {
		$page = get_page_by_path( 'cookies', OBJECT, 'page' );
		if ( ! $page instanceof WP_Post || '0000-00-00 00:00:00' === $page->post_modified_gmt ) {
			return null;
		}
		return gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $page->post_modified_gmt . ' UTC' ) );
	}

	/**
	 * La última fila del comprobante. Ordena por `created_at, id` y no solo
	 * por `id`: es el orden del índice `(receipt, created_at)` (InnoDB añade
	 * la PK al final), así que se lee UNA entrada. Con `ORDER BY id DESC
	 * LIMIT 1` el optimizador puede preferir recorrer la PK hacia atrás.
	 *
	 * @return object|null
	 */
	private static function lastRow( string $receipt ) {
		global $wpdb;
		$log = self::logTable();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$log} WHERE receipt = %s ORDER BY created_at DESC, id DESC LIMIT 1", $receipt ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	public static function handleRecord( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$p = (array) $request->get_json_params();

		$receipt  = is_string( $p['receipt'] ?? null ) ? $p['receipt'] : '';
		$decision = $p['decision'] ?? '';
		$action   = $p['action'] ?? '';
		$variant  = $p['variant'] ?? '';
		$text     = is_string( $p['text'] ?? null ) ? wp_check_invalid_utf8( $p['text'] ) : '';
		$hash     = is_string( $p['text_hash'] ?? null ) ? $p['text_hash'] : '';

		if ( ! preg_match( self::RECEIPT_PATTERN, $receipt )
			|| ! in_array( $decision, self::DECISIONS, true )
			|| ! in_array( $action, self::CLIENT_ACTIONS, true )
			|| ! in_array( $variant, self::VARIANTS, true )
			|| '' === $text || mb_strlen( $text ) > 2000
			|| ! hash_equals( hash( 'sha256', $text ), $hash ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_record' ), 422 );
		}

		$services = array_values(
			array_intersect( self::SERVICES, array_map( 'strval', (array) ( $p['services'] ?? array() ) ) )
		);
		$locale   = self::clip( $p['locale'] ?? null, 10 ) ?? '';
		$genRaw   = $p['generation'] ?? null;
		$gen      = is_int( $genRaw ) && $genRaw > 0 && $genRaw < 1000 ? $genRaw : null;
		$client   = is_string( $p['client_at'] ?? null ) ? strtotime( $p['client_at'] ) : false;
		$clientAt = false !== $client ? gmdate( 'Y-m-d H:i:s', $client ) : null;
		$log      = self::logTable();

		// Reenvío de un registro que ya llegó (la página se recargó antes de
		// leer la respuesta y el navegador lo mandó otra vez): no se duplica.
		if ( null !== $clientAt ) {
			$dup = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$log} WHERE receipt = %s AND client_at = %s AND decision = %s AND action <> 'linked' LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$receipt,
					$clientAt,
					$decision
				)
			);
			if ( $dup > 0 ) {
				return new WP_REST_Response( array( 'ok' => true, 'duplicate' => true ), 200 );
			}
		}

		$last = self::lastRow( $receipt );
		if ( 'change' === $action && $last && 'accepted' === $last->decision && 'declined' === $decision ) {
			$action = 'revoke';
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . self::textsTable() . ' (hash, locale, text, first_seen) VALUES (%s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$hash,
				$locale,
				$text,
				$now
			)
		);

		$accepted = 'accepted' === $decision;
		$inserted = $wpdb->insert(
			$log,
			array(
				'receipt'        => $receipt,
				'user_id'        => self::validUserId( $p['user_id'] ?? null ),
				'action'         => $action,
				'decision'       => $decision,
				'analytics'      => $accepted && ! empty( $p['analytics'] ) ? 1 : 0,
				'advertising'    => $accepted && ! empty( $p['advertising'] ) ? 1 : 0,
				'services'       => implode( ',', $services ),
				'text_hash'      => $hash,
				'policy_version' => self::policyVersion(),
				'generation'     => $gen,
				'variant'        => $variant,
				'locale'         => $locale,
				'path'           => self::clip( $p['path'] ?? null, 255 ),
				'ip'             => self::cleanIp( $p['ip'] ?? null ),
				'user_agent'     => self::clip( $p['user_agent'] ?? null, 255 ),
				'client_at'      => $clientAt,
				'created_at'     => $now,
			)
		);
		if ( false === $inserted ) {
			return new WP_REST_Response( array( 'error' => 'storage_failed' ), 500 );
		}
		return new WP_REST_Response( array( 'ok' => true, 'action' => $action ), 201 );
	}

	/**
	 * La decisión VIGENTE de un comprobante: la de su última fila. La consulta
	 * el BFF antes de mandar una compra a la Conversions API de Meta, porque
	 * entre el checkout y la confirmación del pago la persona pudo retirar el
	 * consentimiento. `decision: null` si el comprobante no tiene filas.
	 */
	public static function handleLatest( WP_REST_Request $request ): WP_REST_Response {
		$receipt = (string) $request->get_param( 'receipt' );
		if ( ! preg_match( self::RECEIPT_PATTERN, $receipt ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_receipt' ), 422 );
		}
		$last = self::lastRow( $receipt );
		return new WP_REST_Response(
			array(
				'decision'   => $last ? $last->decision : null,
				'generation' => $last && null !== $last->generation ? (int) $last->generation : null,
				'created_at' => $last ? gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $last->created_at . ' UTC' ) ) : null,
			),
			200
		);
	}

	public static function handleLink( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$p       = (array) $request->get_json_params();
		$receipt = is_string( $p['receipt'] ?? null ) ? $p['receipt'] : '';
		$userId  = self::validUserId( $p['user_id'] ?? null );
		if ( ! preg_match( self::RECEIPT_PATTERN, $receipt ) || null === $userId ) {
			return new WP_REST_Response( array( 'error' => 'invalid_link' ), 422 );
		}

		$last = self::lastRow( $receipt );
		if ( ! $last ) {
			return new WP_REST_Response( array( 'linked' => false, 'reason' => 'unknown_receipt' ), 200 );
		}
		if ( (int) $last->user_id === $userId ) {
			return new WP_REST_Response( array( 'linked' => false, 'reason' => 'already_linked' ), 200 );
		}

		$log   = self::logTable();
		$other = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$log} WHERE receipt = %s AND user_id IS NOT NULL AND user_id <> %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$receipt,
				$userId
			)
		);
		if ( $other > 0 ) {
			return new WP_REST_Response( array( 'linked' => false, 'reason' => 'other_account' ), 200 );
		}

		$inserted = $wpdb->insert(
			$log,
			array(
				'receipt'        => $receipt,
				'user_id'        => $userId,
				'action'         => 'linked',
				'decision'       => $last->decision,
				'analytics'      => (int) $last->analytics,
				'advertising'    => (int) $last->advertising,
				'services'       => $last->services,
				'text_hash'      => $last->text_hash,
				'policy_version' => $last->policy_version,
				'generation'     => null === $last->generation ? null : (int) $last->generation,
				'variant'        => $last->variant,
				'locale'         => $last->locale,
				'path'           => null,
				'ip'             => self::cleanIp( $p['ip'] ?? null ),
				'user_agent'     => self::clip( $p['user_agent'] ?? null, 255 ),
				'client_at'      => null,
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		if ( false === $inserted ) {
			return new WP_REST_Response( array( 'error' => 'storage_failed' ), 500 );
		}
		return new WP_REST_Response( array( 'linked' => true ), 201 );
	}
}
