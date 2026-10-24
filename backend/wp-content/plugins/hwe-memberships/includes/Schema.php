<?php
/**
 * Esquema de datos de `hwe-memberships` (spec §5).
 *
 * Cinco tablas InnoDB, porque toda escritura del módulo va en una transacción con bloqueo de
 * la fila del usuario:
 *  · `hwe_memberships_grants`: cada trozo de tiempo de un nivel que tiene un usuario. Guarda la
 *    clave de idempotencia (ÚNICA: un pedido repetido no puede conceder dos veces), el origen y
 *    su referencia, la duración pagada, el inicio y el fin efectivos y el estado. Nace con las
 *    columnas que necesitarán las tareas siguientes para no migrar en cada entrega:
 *    `queued_seconds` (tiempo exacto que espera una concesión sin fecha, §5.4),
 *    `shipping_quota_used` / `shipping_quota_reserved` (envíos gratis consumidos y reservados por
 *    esta concesión, §7.3), `referral_gifts` (regalos de referido pagados por esta concesión,
 *    §7.5), `expiry_notified_at` (aviso de caducidad ya enviado, §5.6) y `frozen_seconds`
 *    (desplazamiento acumulado por las congelaciones, §5.5).
 *  · `hwe_memberships_members`: una fila por usuario con la caché transaccional del nivel
 *    efectivo, el fin de cobertura, la congelación, el número de socio (UNIQUE) y «socio desde».
 *    Toda operación bloquea esta fila con `SELECT … FOR UPDATE`.
 *  · `hwe_memberships_events`: una fila por cambio con el nivel anterior y el nuevo, el actor y
 *    datos estructurados. Solo crece: es el historial del socio.
 *  · `hwe_memberships_card_tokens`: tokens del carné guardados como hash, con su caducidad y su
 *    revocación. Nunca se guarda el token en claro.
 *  · `hwe_memberships_batches`: el estado de una operación en masa (§11.3): operación,
 *    selección (el criterio; la lista pegada se resuelve a ids al arrancar, nunca las filas ya
 *    procesadas), parámetros, totales, progreso y cursor (`último user_id` procesado). El lote
 *    es reanudable: al reprocesar se retoma por el cursor y las claves de idempotencia evitan
 *    duplicar. No se guarda aquí ninguna lista de usuarios pendientes ni en `wp_options`.
 *
 * Columnas de `grants` (todas las fechas son UTC):
 *  · `id`: clave primaria.
 *  · `user_id`: dueño de la concesión; se consulta con `status` y con `level_id`.
 *  · `level_id`: id del nivel (CPT); se consulta con `status`.
 *  · `origin`: `order`, `admin`, `bulk`, `referral_gift` o uno añadido por el filtro de orígenes.
 *  · `ref_type` / `ref_id`: referencia tipada del origen (pedido, línea, lote, referidor…).
 *  · `idempotency_key`: clave ÚNICA; la garantía contra duplicados la da el índice.
 *  · `duration_days`: días pagados; `0` = indefinida.
 *  · `queued_seconds`: segundos exactos que espera una concesión sin fecha (cola tras una
 *    indefinida); `NULL` en las fechadas.
 *  · `starts_at` / `ends_at`: periodo efectivo; `starts_at NULL` = en cola sin fecha y
 *    `ends_at NULL` = indefinida o en cola.
 *  · `status`: `scheduled`, `active`, `ended` o `revoked`.
 *  · `actor` / `actor_id`: quién provocó la concesión (`system`, `admin`, `customer`…).
 *  · `note` / `data`: texto libre y datos estructurados del origen (sin PII innecesaria).
 *  · `shipping_quota_used` / `shipping_quota_reserved`: envíos gratis consumidos y reservados
 *    por esta concesión (§7.3).
 *  · `referral_gifts`: regalos de referido pagados por esta concesión (§7.5).
 *  · `expiry_notified_at`: cuándo se envió el aviso de caducidad; `NULL` = pendiente (§5.6).
 *  · `frozen_seconds`: segundos de congelación ya desplazados en esta concesión (§5.5).
 *  · `revoked_at`: cuándo se revocó; `NULL` si no se revocó.
 *  · `created_at` / `updated_at`: alta y último cambio.
 *
 * `members` guarda una fila por usuario: `user_id` (PK), `level_id` efectivo, `covers_until`,
 * `frozen_at` y `frozen_seconds` de la congelación en curso o pasada, `member_number` (UNIQUE),
 * `member_since` y `updated_at`. `events` guarda `user_id`, `grant_id`, `type`, `level_from`,
 * `level_to`, `actor`, `actor_id`, `data` y `created_at`. `card_tokens` guarda `user_id`,
 * `token_hash` (SHA-256, UNIQUE; nunca el token en claro), `expires_at`, `revoked_at`,
 * `last_used_at` y `created_at`. `batches` guarda `operation`, `selection` y `params` (JSON),
 * `total`, `processed`, `applied`, `last_user_id`, `status`, `actor_id`, `created_at`,
 * `updated_at` y `finished_at`; `last_user_id` es el cursor (el último id de usuario procesado),
 * no una lista de ids.
 *
 * La versión vive en la opción `hwe_memberships_db_version`, con autoload: se lee en cada
 * petición y, cuando no coincide, se vuelve a ejecutar `dbDelta` (migración perezosa al detectar
 * una versión vieja, también fuera de la activación). Mismo patrón que `HWE_Loyalty_Schema`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Schema {

	/** Subir esta versión vuelve a ejecutar `dbDelta` en la siguiente petición. */
	public const DB_VERSION = '2';

	/** Opción donde se guarda la versión instalada. */
	public const DB_VERSION_OPTION = 'hwe_memberships_db_version';

	/** Engancha la instalación perezosa. La llama `HWE_Memberships::boot()`. */
	public static function register(): void {
		add_action( 'init', array( self::class, 'maybeInstall' ), 5 );
	}

	/**
	 * Instalación forzada de la activación: crea o repara las tablas aunque la opción ya tenga
	 * la versión actual (una tabla borrada a mano no debe dejar el módulo a medias).
	 */
	public static function install(): void {
		self::run();
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

		self::run();
	}

	/** Ejecuta `dbDelta` y guarda la versión. */
	private static function run(): void {
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

		$grants  = self::grantsTable();
		$members = self::membersTable();
		$events  = self::eventsTable();
		$tokens  = self::cardTokensTable();
		$batches = self::batchesTable();

		// dbDelta exige este formato: un campo por línea y dos espacios tras PRIMARY KEY.
		dbDelta(
			"CREATE TABLE {$grants} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  level_id bigint(20) unsigned NOT NULL,
  origin varchar(32) NOT NULL,
  ref_type varchar(32) DEFAULT NULL,
  ref_id varchar(64) DEFAULT NULL,
  idempotency_key varchar(191) NOT NULL,
  duration_days int(10) unsigned NOT NULL DEFAULT 0,
  queued_seconds int(10) unsigned DEFAULT NULL,
  starts_at datetime DEFAULT NULL,
  ends_at datetime DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'scheduled',
  actor varchar(20) NOT NULL DEFAULT 'system',
  actor_id bigint(20) unsigned DEFAULT NULL,
  note text NULL,
  data longtext NULL,
  shipping_quota_used int(10) unsigned NOT NULL DEFAULT 0,
  shipping_quota_reserved int(10) unsigned NOT NULL DEFAULT 0,
  referral_gifts int(10) unsigned NOT NULL DEFAULT 0,
  expiry_notified_at datetime DEFAULT NULL,
  frozen_seconds bigint(20) unsigned NOT NULL DEFAULT 0,
  revoked_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY user_status (user_id,status),
  KEY status_ends (status,ends_at),
  KEY status_starts (status,starts_at),
  KEY user_level (user_id,level_id),
  KEY level_status (level_id,status)
) ENGINE=InnoDB {$charset};
CREATE TABLE {$members} (
  user_id bigint(20) unsigned NOT NULL,
  level_id bigint(20) unsigned DEFAULT NULL,
  covers_until datetime DEFAULT NULL,
  frozen_at datetime DEFAULT NULL,
  frozen_seconds bigint(20) unsigned NOT NULL DEFAULT 0,
  member_number varchar(32) DEFAULT NULL,
  member_since datetime DEFAULT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (user_id),
  UNIQUE KEY member_number (member_number),
  KEY level_id (level_id)
) ENGINE=InnoDB {$charset};
CREATE TABLE {$events} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  grant_id bigint(20) unsigned DEFAULT NULL,
  type varchar(32) NOT NULL,
  level_from bigint(20) unsigned DEFAULT NULL,
  level_to bigint(20) unsigned DEFAULT NULL,
  actor varchar(20) NOT NULL DEFAULT 'system',
  actor_id bigint(20) unsigned DEFAULT NULL,
  data longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_created (user_id,created_at),
  KEY grant_id (grant_id),
  KEY type_created (type,created_at)
) ENGINE=InnoDB {$charset};
CREATE TABLE {$tokens} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  token_hash char(64) NOT NULL,
  expires_at datetime NOT NULL,
  revoked_at datetime DEFAULT NULL,
  last_used_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  KEY user_expires (user_id,expires_at),
  KEY expires_at (expires_at)
) ENGINE=InnoDB {$charset};
CREATE TABLE {$batches} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  operation varchar(32) NOT NULL,
  selection longtext NULL,
  params longtext NULL,
  total bigint(20) unsigned NOT NULL DEFAULT 0,
  processed bigint(20) unsigned NOT NULL DEFAULT 0,
  applied bigint(20) unsigned NOT NULL DEFAULT 0,
  last_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'pending',
  actor_id bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  finished_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY status_created (status,created_at),
  KEY actor_created (actor_id,created_at)
) ENGINE=InnoDB {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}

	public static function grantsTable(): string {
		global $wpdb;
		return $wpdb->prefix . 'hwe_memberships_grants';
	}

	public static function membersTable(): string {
		global $wpdb;
		return $wpdb->prefix . 'hwe_memberships_members';
	}

	public static function eventsTable(): string {
		global $wpdb;
		return $wpdb->prefix . 'hwe_memberships_events';
	}

	public static function cardTokensTable(): string {
		global $wpdb;
		return $wpdb->prefix . 'hwe_memberships_card_tokens';
	}

	public static function batchesTable(): string {
		global $wpdb;
		return $wpdb->prefix . 'hwe_memberships_batches';
	}

	private static function log( string $message ): void {
		error_log( '[hwe] memberships: ' . $message );
	}
}
