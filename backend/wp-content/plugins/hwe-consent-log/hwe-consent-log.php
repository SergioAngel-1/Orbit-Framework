<?php
/**
 * Plugin Name: HWE Consent Log
 * Description: Registro de consentimientos de cookies: la PRUEBA de qué eligió
 *              cada persona en el banner y cuándo, anónima o ligada a su cuenta,
 *              y la medición anónima de la respuesta al banner.
 * Author:      Sergio Jauregui
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
require_once __DIR__ . '/includes/Rest.php';
require_once __DIR__ . '/includes/Retention.php';
require_once __DIR__ . '/includes/Privacy.php';
require_once __DIR__ . '/includes/Admin.php';

final class HWE_Consent_Log {
	use HWE_Consent_Log_Rest;
	use HWE_Consent_Log_Retention;
	use HWE_Consent_Log_Privacy;
	use HWE_Consent_Log_Admin;

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
}

HWE_Consent_Log::register();
