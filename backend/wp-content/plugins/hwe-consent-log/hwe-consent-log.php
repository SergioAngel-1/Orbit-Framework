<?php
/**
 * Plugin Name: HWE Consent Log
 * Description: Registro de consentimientos de cookies: la PRUEBA de qué eligió
 *              cada persona en el banner y cuándo, anónima o ligada a su cuenta,
 *              y la medición anónima de la respuesta al banner.
 * Author:      Headless Web Ecosystem
 * Version:     1.0.0
 * Requires PHP: 8.0
 * Text Domain: hwe
 *
 * Lo escribe SOLO el BFF (`lib/analytics/consent-ledger.ts` en el frontend),
 * con el secreto interno; el navegador nunca habla con WordPress. Es distinto
 * de la medición anónima del banner (`includes/ConsentStats.php`, contadores
 * en Redis del BFF): aquello no identifica a nadie; esto es un registro por
 * decisión. Las dos se ven en la misma pantalla de wp-admin, la medición
 * arriba y el registro debajo.
 *
 * Qué se guarda por fila (`wp_hwe_consent_log`):
 *   - comprobante (UUID de la cookie `hwe_consent` del dispositivo) y, si había
 *     sesión, `user_id`;
 *   - acción: `first` (primera respuesta), `change` (desde «Preferencias de
 *     cookies»), `revoke` (un cambio que RETIRA una aceptación: lo decide este
 *     plugin con su propio registro) o `linked` (el comprobante se vinculó a la
 *     cuenta al iniciar sesión);
 *   - decisión, categorías concedidas y servicios que el banner pedía;
 *   - hash del texto enseñado (el texto, una vez, en `wp_hwe_consent_texts`) y
 *     versión de la política de cookies (fecha de modificación de la página
 *     `cookies`), fijada AQUÍ al insertar;
 *   - generación del consentimiento con la que se decidió (`CONSENT_GENERATION`
 *     del frontend): subirla retira TODAS las decisiones anteriores en el
 *     navegador, así que una fila de una generación menor que la más alta del
 *     registro ya no es la vigente aunque sea la última de su comprobante
 *     (NULL en las filas anteriores a la columna);
 *   - IP TRUNCADA (/24, /48), user-agent, ruta, idioma y variante del banner;
 *   - hora del servidor (UTC) y, informativa, la que declaró el navegador.
 *
 * Reglas:
 *   - El registro solo crece. La única escritura sobre filas existentes es
 *     DESVINCULAR (`user_id` a NULL) al borrar una cuenta o atender una
 *     solicitud de supresión: la prueba se conserva sin la persona.
 *   - Un comprobante ya ligado a OTRA cuenta no se vincula (dispositivo
 *     compartido): las decisiones de una persona no se atribuyen a otra.
 *   - Retención: Control Center → Integraciones → «Conservar el registro de
 *     consentimientos» (años, 5 por defecto; 0 = no borrar). Se borran los
 *     comprobantes cuya ÚLTIMA fila es más antigua: una decisión vigente nunca
 *     se borra por vieja que sea.
 *
 * wp-admin: Ajustes → «Consentimientos de cookies» (con la medición anónima
 * encima), historial en el perfil de cada usuario y el exportador / eliminador
 * de datos personales de WordPress (Herramientas → Exportar / Borrar datos
 * personales).
 *
 * Es un MÓDULO del framework (`consent-log`): el frontend sabe si está activo
 * por `config.modules` y, sin él, ni muestra el comprobante ni llama aquí
 * (ver README.md y MANUAL.md de esta carpeta).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/ConsentStats.php';

final class HWE_Consent_Log {
	private const DB_VERSION        = '3';
	private const DB_VERSION_OPTION = 'hwe_consent_log_db_version';
	private const CRON_HOOK         = 'hwe_consent_log_purge';
	private const DEFAULT_YEARS     = 5;
	private const PURGE_BATCH       = 500;
	private const ADMIN_SLUG        = 'hwe-consent-log';
	private const EXPORT_ACTION     = 'hwe_consent_log_export';
	private const PER_PAGE          = 50;

	private const DECISIONS       = array( 'accepted', 'declined' );
	private const CLIENT_ACTIONS  = array( 'first', 'change' );
	private const VARIANTS        = array( 'bar', 'modal' );
	private const SERVICES        = array( 'ga4', 'plausible', 'gtm', 'meta', 'tiktok', 'hubspot' );
	private const RECEIPT_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

	private const ACTION_LABELS = array(
		'first'  => 'Primera respuesta',
		'change' => 'Cambio',
		'revoke' => 'Retirada',
		'linked' => 'Vinculado a la cuenta',
	);

	public static function register(): void {
		add_action( 'init', array( self::class, 'maybeInstall' ), 5 );
		add_action( 'init', array( self::class, 'scheduleCron' ) );
		add_action( self::CRON_HOOK, array( self::class, 'purgeExpired' ) );
		add_action( 'rest_api_init', array( self::class, 'registerRoutes' ) );

		add_action( 'admin_menu', array( self::class, 'registerAdminPage' ) );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( self::class, 'exportCsv' ) );
		add_action( 'show_user_profile', array( self::class, 'renderProfileHistory' ) );
		add_action( 'edit_user_profile', array( self::class, 'renderProfileHistory' ) );

		add_action( 'delete_user', array( self::class, 'detachUser' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'registerExporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'registerEraser' ) );
	}

	private static function logTable(): string {
		global $wpdb;
		return $wpdb->prefix . 'hwe_consent_log';
	}

	private static function textsTable(): string {
		global $wpdb;
		return $wpdb->prefix . 'hwe_consent_texts';
	}

	// ------------------------------------------------------------------------
	//  Tablas
	// ------------------------------------------------------------------------

	/**
	 * Corre en el `init` de CADA petición a WordPress (GraphQL incluido), así
	 * que la opción va con autoload: llega en `alloptions` sin una lectura
	 * propia. Hasta la v2 iba sin él y costaba una consulta (o un GET a la
	 * caché de objetos) por petición.
	 */
	public static function maybeInstall(): void {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$log     = self::logTable();
		$texts   = self::textsTable();

		// dbDelta exige este formato: un campo por línea y dos espacios tras PRIMARY KEY.
		dbDelta(
			"CREATE TABLE {$log} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  receipt char(36) NOT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  action varchar(10) NOT NULL,
  decision varchar(10) NOT NULL,
  analytics tinyint(1) NOT NULL DEFAULT 0,
  advertising tinyint(1) NOT NULL DEFAULT 0,
  services varchar(64) NOT NULL DEFAULT '',
  text_hash char(64) NOT NULL,
  policy_version varchar(32) DEFAULT NULL,
  generation smallint(5) unsigned DEFAULT NULL,
  variant varchar(10) NOT NULL DEFAULT '',
  locale varchar(10) NOT NULL DEFAULT '',
  path varchar(255) DEFAULT NULL,
  ip varchar(45) DEFAULT NULL,
  user_agent varchar(255) DEFAULT NULL,
  client_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY receipt_created (receipt,created_at),
  KEY user_id (user_id),
  KEY created_at (created_at)
) {$charset};
CREATE TABLE {$texts} (
  hash char(64) NOT NULL,
  locale varchar(10) NOT NULL DEFAULT '',
  text text NOT NULL,
  first_seen datetime NOT NULL,
  PRIMARY KEY  (hash)
) {$charset};"
		);
		// v1 → v2: el índice de `receipt` pasa a `(receipt, created_at)`, que
		// sirve igual a toda búsqueda por comprobante y además resuelve la
		// purga sin leer filas. dbDelta añade índices pero nunca los quita:
		// el viejo, que ya es prefijo del nuevo, se retira a mano.
		$suppress = $wpdb->suppress_errors();
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$log} WHERE Key_name = %s", 'receipt' ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$log} DROP INDEX receipt" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$wpdb->suppress_errors( $suppress );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
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

	// ------------------------------------------------------------------------
	//  Retención
	// ------------------------------------------------------------------------

	public static function scheduleCron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/** Años de conservación de Control Center; 0 = no borrar nunca. */
	public static function retentionYears(): int {
		$value = null;
		if ( class_exists( '\HWE\ControlCenter\Storage' ) ) {
			$all   = \HWE\ControlCenter\Storage::getAll();
			$value = $all['integrations']['consent_retention_years'] ?? null;
		}
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return self::DEFAULT_YEARS;
		}
		return max( 0, (int) $value );
	}

	public static function purgeExpired(): void {
		$years = self::retentionYears();
		if ( 0 === $years ) {
			return;
		}
		global $wpdb;
		$log    = self::logTable();
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$years} years" ) );

		// Por lotes y en dos pasos (leer comprobantes, luego borrar): un DELETE
		// que se lee a sí mismo en una subconsulta no lo admite MySQL/MariaDB.
		//
		// La agrupación la resuelve SOLO el índice `(receipt, created_at)`, sin
		// leer filas. Con el índice de `receipt` a secas (v1) tenía que ir a la
		// fila por cada `created_at`: medido sobre 200.000 filas, ~3 s por lote
		// frente a 2–45 ms. (Partir del rango de `created_at` con un
		// `NOT EXISTS` se probó y es peor: MariaDB materializa la subconsulta.)
		for ( $i = 0; $i < 200; $i++ ) {
			$receipts = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT receipt FROM {$log} GROUP BY receipt HAVING MAX(created_at) < %s LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$cutoff,
					self::PURGE_BATCH
				)
			);
			if ( empty( $receipts ) ) {
				return;
			}
			$placeholders = implode( ',', array_fill( 0, count( $receipts ), '%s' ) );
			$wpdb->query(
				$wpdb->prepare( "DELETE FROM {$log} WHERE receipt IN ({$placeholders})", $receipts ) // phpcs:ignore WordPress.DB.PreparedSQL
			);
		}
	}

	// ------------------------------------------------------------------------
	//  Datos personales: desvincular, exportar, suprimir
	// ------------------------------------------------------------------------

	/** Desvincula las filas de la cuenta sin borrarlas: la prueba se conserva. */
	public static function detachUser( int $userId ): int {
		global $wpdb;
		$log = self::logTable();
		return (int) $wpdb->query(
			$wpdb->prepare( "UPDATE {$log} SET user_id = NULL WHERE user_id = %d", $userId ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	public static function registerExporter( array $exporters ): array {
		$exporters['hwe-cookie-consent'] = array(
			'exporter_friendly_name' => 'Consentimientos de cookies',
			'callback'               => array( self::class, 'exportPersonalData' ),
		);
		return $exporters;
	}

	public static function registerEraser( array $erasers ): array {
		$erasers['hwe-cookie-consent'] = array(
			'eraser_friendly_name' => 'Consentimientos de cookies',
			'callback'             => array( self::class, 'erasePersonalData' ),
		);
		return $erasers;
	}

	public static function exportPersonalData( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array( 'data' => array(), 'done' => true );
		}
		$items = array();
		foreach ( self::historyForUser( (int) $user->ID, 500 ) as $row ) {
			$items[] = array(
				'group_id'    => 'hwe-cookie-consent',
				'group_label' => 'Consentimientos de cookies',
				'item_id'     => 'hwe-cookie-consent-' . $row->id,
				'data'        => array(
					array( 'name' => 'Fecha (UTC)', 'value' => $row->created_at ),
					array( 'name' => 'Acción', 'value' => self::ACTION_LABELS[ $row->action ] ?? $row->action ),
					array( 'name' => 'Decisión', 'value' => self::decisionLabel( $row ) ),
					array( 'name' => 'Generación', 'value' => self::generationLabel( $row ) ),
					array( 'name' => 'Comprobante', 'value' => $row->receipt ),
					array( 'name' => 'IP (truncada)', 'value' => (string) $row->ip ),
					array( 'name' => 'Navegador', 'value' => (string) $row->user_agent ),
				),
			);
		}
		return array( 'data' => $items, 'done' => true );
	}

	public static function erasePersonalData( string $email, int $page = 1 ): array {
		$user     = get_user_by( 'email', $email );
		$detached = $user ? self::detachUser( (int) $user->ID ) : 0;
		return array(
			'items_removed'  => $detached > 0,
			'items_retained' => $detached > 0,
			'messages'       => $detached > 0
				? array( 'Los consentimientos de cookies se conservan como prueba legal, desvinculados de la cuenta.' )
				: array(),
			'done'           => true,
		);
	}

	// ------------------------------------------------------------------------
	//  wp-admin
	// ------------------------------------------------------------------------

	private static function decisionLabel( $row ): string {
		if ( 'declined' === $row->decision ) {
			return 'Solo necesarias';
		}
		$granted = array();
		if ( (int) $row->analytics ) {
			$granted[] = 'analítica';
		}
		if ( (int) $row->advertising ) {
			$granted[] = 'publicidad';
		}
		return 'Aceptó' . ( $granted ? ' (' . implode( ' + ', $granted ) . ')' : '' );
	}

	/**
	 * Filas de la cuenta y las anónimas de sus mismos comprobantes (lo que
	 * decidió en ese dispositivo antes de iniciar sesión).
	 *
	 * @return object[]
	 */
	private static function historyForUser( int $userId, int $limit ): array {
		global $wpdb;
		$log = self::logTable();
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$log} WHERE user_id = %d OR (user_id IS NULL AND receipt IN (SELECT receipt FROM (SELECT DISTINCT receipt FROM {$log} WHERE user_id = %d) r)) ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$userId,
				$userId,
				$limit
			)
		);
	}

	/** Ajustes → «Consentimientos de cookies». */
	public static function registerAdminPage(): void {
		add_options_page(
			'Consentimientos de cookies',
			'Consentimientos de cookies',
			'manage_options',
			self::ADMIN_SLUG,
			array( self::class, 'renderAdminPage' )
		);
	}

	/**
	 * URL de la pantalla. Cuelga de Ajustes (`options-general.php`): una página
	 * de Ajustes NO se abre por `admin.php?page=`, así que ningún enlace se
	 * construye a mano.
	 */
	public static function pageUrl(): string {
		return admin_url( 'options-general.php?page=' . self::ADMIN_SLUG );
	}

	/**
	 * Filtro de búsqueda: comprobante, email o id de usuario. `$col` antepone
	 * el alias de la tabla principal (la subconsulta lleva el suyo).
	 *
	 * @return array{0:string,1:array} Cláusula WHERE y sus argumentos.
	 */
	private static function searchClause( string $search, string $col = '' ): array {
		$search = trim( $search );
		if ( '' === $search ) {
			return array( '1=1', array() );
		}
		if ( preg_match( self::RECEIPT_PATTERN, strtolower( $search ) ) ) {
			return array( "{$col}receipt = %s", array( strtolower( $search ) ) );
		}
		$user = is_email( $search ) ? get_user_by( 'email', $search ) : ( ctype_digit( $search ) ? get_userdata( (int) $search ) : false );
		if ( ! $user ) {
			return array( '1=0', array() );
		}
		$log = self::logTable();
		return array(
			"({$col}user_id = %d OR ({$col}user_id IS NULL AND {$col}receipt IN (SELECT receipt FROM (SELECT DISTINCT receipt FROM {$log} WHERE user_id = %d) r)))",
			array( (int) $user->ID, (int) $user->ID ),
		);
	}

	/** @return object[] */
	private static function query( string $search, int $limit, int $offset ): array {
		global $wpdb;
		[ $where, $args ] = self::searchClause( $search, 'l.' );
		$log              = self::logTable();
		$texts            = self::textsTable();
		$sql              = "SELECT l.*, t.text FROM {$log} l LEFT JOIN {$texts} t ON t.hash = l.text_hash WHERE {$where} ORDER BY l.id DESC LIMIT %d OFFSET %d";
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( $limit, $offset ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function count( string $search ): int {
		global $wpdb;
		[ $where, $args ] = self::searchClause( $search );
		$log              = self::logTable();
		$sql              = "SELECT COUNT(*) FROM {$log} WHERE {$where}";
		return (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_var( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function userLabel( $row ): string {
		if ( null === $row->user_id ) {
			return 'Anónimo';
		}
		$user = get_userdata( (int) $row->user_id );
		return $user ? $user->user_email : '#' . (int) $row->user_id;
	}

	private static function formatDate( string $utc ): string {
		return wp_date( 'Y-m-d H:i:s', strtotime( $utc . ' UTC' ) );
	}

	public static function renderAdminPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$paged  = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 ); // phpcs:ignore WordPress.Security.NonceVerification
		$total  = self::count( $search );
		$rows   = self::query( $search, self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );
		$pages  = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$years  = self::retentionYears();
		$days   = HWE_Consent_Stats::days();
		// URL de ESTA vista (búsqueda, página y periodo de la medición): cada
		// enlace cambia solo lo suyo y conserva lo demás.
		$current = add_query_arg(
			array_filter(
				array(
					's'                          => '' !== $search ? $search : null,
					'paged'                      => $paged > 1 ? $paged : null,
					HWE_Consent_Stats::QUERY_ARG => isset( $_GET[ HWE_Consent_Stats::QUERY_ARG ] ) ? $days : null, // phpcs:ignore WordPress.Security.NonceVerification
				)
			),
			self::pageUrl()
		);

		echo '<div class="wrap hwe-consent-admin"><h1>Consentimientos de cookies</h1>';
		self::renderStyles();

		echo '<h2>Respuesta al banner <span class="hwe-consent-tag">medición anónima</span></h2>';
		echo '<p class="description">De las sesiones que vieron el banner de primera visita, cuántas aceptaron, cuántas eligieron solo las necesarias y cuántas no respondieron. Totales por sesión, sin IP ni cookies y sin robots: no identifica a nadie ni sirve como prueba.</p>';
		echo HWE_Consent_Stats::render( $current ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML ya escapado al construirlo.

		echo '<h2>Registro de decisiones <span class="hwe-consent-tag">log</span></h2>';
		printf(
			'<p class="description">Log de cada decisión tomada en el banner de cookies: qué se eligió, cuándo y con qué texto. Es la evidencia legal del consentimiento. IP truncada. Conservación: %s (Control Center → Integraciones).</p>',
			esc_html( 0 === $years ? 'sin borrado automático' : sprintf( '%d años desde la última decisión de cada comprobante', $years ) )
		);

		echo '<form method="get" style="margin:1em 0">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::ADMIN_SLUG ) );
		if ( isset( $_GET[ HWE_Consent_Stats::QUERY_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			printf( '<input type="hidden" name="%s" value="%d" />', esc_attr( HWE_Consent_Stats::QUERY_ARG ), (int) $days );
		}
		printf(
			'<input type="search" name="s" value="%s" placeholder="Comprobante, email o ID de usuario" style="min-width:320px" /> ',
			esc_attr( $search )
		);
		submit_button( 'Buscar', 'secondary', '', false );
		printf(
			' <a class="button" href="%s">Exportar CSV</a>',
			esc_url(
				wp_nonce_url(
					add_query_arg( array( 'action' => self::EXPORT_ACTION, 's' => $search ), admin_url( 'admin-post.php' ) ),
					self::EXPORT_ACTION
				)
			)
		);
		echo '</form>';

		printf( '<p>%s registros.</p>', esc_html( number_format_i18n( $total ) ) );
		self::renderRows( $rows, true );

		if ( $pages > 1 ) {
			$link = static fn( int $page ): string => esc_url( add_query_arg( 'paged', $page, $current ) );
			echo '<p>';
			if ( $paged > 1 ) {
				printf( '<a class="button" href="%s">« Anterior</a> ', $link( $paged - 1 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			printf( 'Página %s de %s ', esc_html( number_format_i18n( $paged ) ), esc_html( number_format_i18n( $pages ) ) );
			if ( $paged < $pages ) {
				printf( '<a class="button" href="%s">Siguiente »</a>', $link( $paged + 1 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</p>';
		}
		echo '</div>';
	}

	/** Estilos de la pantalla: no carga el CSS de Control Center, que es de su página. */
	private static function renderStyles(): void {
		echo '<style>
			.hwe-consent-admin h2 { margin-top: 1.6em; }
			.hwe-consent-tag { margin-left: 6px; padding: 1px 8px; border-radius: 10px; background: #f0f0f1; color: #50575e; font-size: 12px; font-weight: 400; vertical-align: middle; }
			.hwe-consent-periods { margin: 8px 0; color: #646970; }
			.hwe-consent-figures { display: flex; flex-wrap: wrap; gap: 6px 22px; margin: 0 0 6px; }
			.hwe-consent-figures li { margin: 0; color: #646970; }
			.hwe-consent-figures strong { color: #1d2327; font-variant-numeric: tabular-nums; }
			.hwe-consent-percent { font-variant-numeric: tabular-nums; }
			.hwe-consent-error { margin: 0 0 6px; color: #b45309; }
			.hwe-consent-empty { margin: 0 0 6px; color: #646970; }
		</style>';
	}

	/**
	 * La generación más alta del registro, que es la vigente en el frontend:
	 * cada decisión nueva llega con la suya. Null sin ninguna fila que la lleve.
	 */
	private static function currentGeneration(): ?int {
		static $current = false;
		if ( false === $current ) {
			global $wpdb;
			$log     = self::logTable();
			$value   = $wpdb->get_var( "SELECT MAX(generation) FROM {$log}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$current = null === $value ? null : (int) $value;
		}
		return $current;
	}

	/** «4», «3 (retirada por un reinicio)» o «—» si la fila es anterior a la columna. */
	private static function generationLabel( $row ): string {
		if ( ! isset( $row->generation ) || null === $row->generation ) {
			return '—';
		}
		$gen     = (int) $row->generation;
		$current = self::currentGeneration();
		return null !== $current && $gen < $current ? $gen . ' (anterior: ya no vale)' : (string) $gen;
	}

	/** @param object[] $rows */
	private static function renderRows( array $rows, bool $withUser ): void {
		if ( empty( $rows ) ) {
			echo '<p><em>Sin registros.</em></p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		$heads = array( 'Fecha', 'Acción', 'Decisión', 'Generación', 'Servicios pedidos' );
		if ( $withUser ) {
			$heads[] = 'Usuario';
		}
		array_push( $heads, 'Comprobante', 'IP (truncada)', 'Idioma / ruta', 'Texto y política' );
		foreach ( $heads as $head ) {
			printf( '<th>%s</th>', esc_html( $head ) );
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			printf( '<td>%s</td>', esc_html( self::formatDate( $row->created_at ) ) );
			printf( '<td>%s</td>', esc_html( self::ACTION_LABELS[ $row->action ] ?? $row->action ) );
			printf( '<td>%s</td>', esc_html( self::decisionLabel( $row ) ) );
			printf( '<td>%s</td>', esc_html( self::generationLabel( $row ) ) );
			printf( '<td>%s</td>', esc_html( '' === $row->services ? '—' : $row->services ) );
			if ( $withUser ) {
				printf( '<td>%s</td>', esc_html( self::userLabel( $row ) ) );
			}
			printf( '<td><code>%s</code></td>', esc_html( $row->receipt ) );
			printf( '<td>%s</td>', esc_html( (string) $row->ip ) );
			printf( '<td>%s %s</td>', esc_html( $row->locale ), esc_html( (string) $row->path ) );
			$text = property_exists( $row, 'text' ) ? (string) $row->text : '';
			printf(
				'<td><details><summary>%s</summary><p>%s</p><p><small>Política de cookies: %s · Banner: %s · %s</small></p></details></td>',
				esc_html( substr( $row->text_hash, 0, 10 ) ),
				esc_html( $text ),
				esc_html( $row->policy_version ? $row->policy_version : '—' ),
				esc_html( $row->variant ),
				esc_html( (string) $row->user_agent )
			);
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Celda de CSV inerte: el user-agent y la ruta los escribe quien visita el
	 * sitio, y una celda que empiece por `=`, `+`, `-`, `@` (o tabulador /
	 * retorno) Excel la ejecuta como fórmula al abrir el archivo.
	 */
	private static function csvCell( $value ): string {
		$value = (string) $value;
		return '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ? "'" . $value : $value;
	}

	public static function exportCsv(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No autorizado.', 403 );
		}
		check_admin_referer( self::EXPORT_ACTION );
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="consentimientos-cookies-' . gmdate( 'Ymd-His' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM: Excel abre el UTF-8 sin romper las tildes.
		fputcsv( $out, array( 'fecha_utc', 'accion', 'decision', 'analitica', 'publicidad', 'servicios', 'usuario', 'comprobante', 'ip_truncada', 'user_agent', 'idioma', 'ruta', 'variante', 'politica_version', 'texto_hash', 'texto', 'hora_navegador_utc', 'generacion' ) );
		$offset = 0;
		do {
			$rows = self::query( $search, 1000, $offset );
			foreach ( $rows as $row ) {
				fputcsv(
					$out,
					array_map(
						static fn( $value ): string => self::csvCell( $value ),
						array(
							$row->created_at,
							$row->action,
							$row->decision,
							$row->analytics,
							$row->advertising,
							$row->services,
							self::userLabel( $row ),
							$row->receipt,
							$row->ip,
							$row->user_agent,
							$row->locale,
							$row->path,
							$row->variant,
							$row->policy_version,
							$row->text_hash,
							$row->text ?? '',
							$row->client_at,
							$row->generation ?? '',
						)
					)
				);
			}
			$offset += 1000;
		} while ( count( $rows ) === 1000 );
		fclose( $out );
		exit;
	}

	public static function renderProfileHistory( WP_User $user ): void {
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}
		echo '<h2>Consentimiento de cookies</h2>';
		echo '<p class="description">Decisiones de esta persona en el banner de cookies, incluidas las que tomó sin sesión en un dispositivo que luego vinculó a la cuenta. La más reciente de cada comprobante es la vigente en ese dispositivo, salvo que sea de una generación anterior: un reinicio del consentimiento la retiró y el banner volvió a preguntar.</p>';
		self::renderRows( self::historyForUser( (int) $user->ID, 50 ), false );
	}
}

HWE_Consent_Log::register();
