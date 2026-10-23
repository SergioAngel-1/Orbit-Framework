<?php
/**
 * Esquema del libro mayor de `hwe-loyalty` (spec §5).
 *
 * Cuatro tablas InnoDB, porque el libro usa transacciones y bloqueos de fila:
 *  · `hwe_loyalty_ledger`: una fila por movimiento; solo crece. Los abonos guardan su
 *    remanente y su caducidad; los cargos, no. La clave de idempotencia es ÚNICA: un evento
 *    repetido no puede acreditar ni descontar dos veces, y la garantía la da el índice, no una
 *    consulta previa.
 *  · `hwe_loyalty_balances`: una fila por usuario con el saldo y los acumulados, como caché
 *    transaccional. Toda operación bloquea esta fila con `SELECT … FOR UPDATE`.
 *  · `hwe_loyalty_ledger_consumptions`: qué abonos consumió cada cargo y cuánto de cada uno.
 *    Es lo que permite devolver exactamente lo consumido al revertir.
 *  · `hwe_loyalty_referrals`: una fila por participante (usuario, código, referidor, estado y
 *    fechas). La usa la tarea de referidos, pero nace con el esquema del libro para que la
 *    versión de base de datos sea una sola.
 *
 * La versión vive en la opción `hwe_loyalty_db_version`, con autoload: se lee en cada petición
 * y, cuando no coincide, se vuelve a ejecutar `dbDelta` (migración al detectar versión vieja,
 * también fuera de la activación). Mismo patrón que `hwe-consent-log`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Loyalty_Schema {

	/** Subir esta versión vuelve a ejecutar `dbDelta` en la siguiente petición. */
	public const DB_VERSION = '2';

	/** Opción donde se guarda la versión instalada. */
	public const DB_VERSION_OPTION = 'hwe_loyalty_db_version';

	/** Engancha la instalación perezosa. La llama `HWE_Loyalty::boot()`. */
	public static function register(): void {
		add_action( 'init', array( self::class, 'maybeInstall' ), 5 );
	}

	/**
	 * Instala o migra las tablas cuando la versión guardada no es la actual.
	 *
	 * No usa `dbDelta` a ciegas: si WordPress no lo tiene cargado (peticiones de frontend) y el
	 * archivo de administración no está disponible, lo registra y deja la versión sin avanzar
	 * para intentarlo en la siguiente petición. Nunca rompe el sitio por esto.
	 */
	public static function maybeInstall(): void {
		if ( self::DB_VERSION === (string) get_option( self::DB_VERSION_OPTION, '' ) ) {
			return;
		}

		if ( ! function_exists( 'dbDelta' ) ) {
			$upgrade = ABSPATH . 'wp-admin/includes/upgrade.php';
			if ( is_readable( $upgrade ) ) {
				require_once $upgrade;
			}
		}

		if ( ! function_exists( 'dbDelta' ) ) {
			self::log( 'no se pudo cargar dbDelta; las tablas se instalarán en la próxima petición.' );
			return;
		}

		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		$ledger       = self::ledgerTable();
		$balances     = self::balancesTable();
		$consumptions = self::consumptionsTable();
		$referrals    = self::referralsTable();

		// dbDelta exige este formato: un campo por línea y dos espacios tras PRIMARY KEY.
		dbDelta(
			"CREATE TABLE {$ledger} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  points int(11) NOT NULL,
  type varchar(32) NOT NULL,
  ref_type varchar(32) DEFAULT NULL,
  ref_id varchar(64) DEFAULT NULL,
  idempotency_key varchar(191) NOT NULL,
  remaining_points int(11) DEFAULT NULL,
  expires_at datetime DEFAULT NULL,
  actor varchar(20) NOT NULL DEFAULT 'system',
  actor_id bigint(20) unsigned DEFAULT NULL,
  note text NULL,
  data longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY user_created (user_id,created_at),
  KEY user_expiry (user_id,expires_at),
  KEY user_ref (user_id,ref_type,ref_id),
  KEY type_created (type,created_at)
) ENGINE=InnoDB {$charset};
CREATE TABLE {$balances} (
  user_id bigint(20) unsigned NOT NULL,
  balance bigint(20) NOT NULL DEFAULT 0,
  earned bigint(20) NOT NULL DEFAULT 0,
  used bigint(20) NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (user_id)
) ENGINE=InnoDB {$charset};
CREATE TABLE {$consumptions} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  debit_id bigint(20) unsigned NOT NULL,
  credit_id bigint(20) unsigned NOT NULL,
  points int(11) NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY debit_credit (debit_id,credit_id),
  KEY credit_id (credit_id)
) ENGINE=InnoDB {$charset};
CREATE TABLE {$referrals} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  code varchar(32) NOT NULL,
  referrer_id bigint(20) unsigned DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  attributed_at datetime NOT NULL,
  confirmed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_id (user_id),
  UNIQUE KEY code (code),
  KEY referrer_status (referrer_id,status)
) ENGINE=InnoDB {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}

	public static function ledgerTable(): string {
		global $wpdb;
		return $wpdb->prefix . 'hwe_loyalty_ledger';
	}

	public static function balancesTable(): string {
		global $wpdb;
		return $wpdb->prefix . 'hwe_loyalty_balances';
	}

	public static function consumptionsTable(): string {
		global $wpdb;
		return $wpdb->prefix . 'hwe_loyalty_ledger_consumptions';
	}

	public static function referralsTable(): string {
		global $wpdb;
		return $wpdb->prefix . 'hwe_loyalty_referrals';
	}

	private static function log( string $message ): void {
		error_log( '[hwe] loyalty: ' . $message );
	}
}
