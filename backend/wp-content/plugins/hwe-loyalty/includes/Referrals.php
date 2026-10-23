<?php
/**
 * Referidos: códigos, atribución y confirmación del alta (spec §5.4, §6.5 y §6.6).
 *
 * Una fila por participante en `hwe_loyalty_referrals` (usuario único, código único,
 * referidor opcional, estado `pending`/`confirmed` y fechas). Las reglas que aplica:
 *
 *  · El código es aleatorio (8 caracteres de un alfabeto sin ambiguos, `random_int`) y nunca
 *    se deriva del login ni del email. Se asigna al crear la cuenta y, para las cuentas que ya
 *    existían, de forma perezosa al primer uso. El lote masivo es una acción de administración.
 *  · La atribución nace SIEMPRE `pending`. Un código inválido no rompe el registro: se ignora
 *    y se anota (sin PII). Se impiden el autorreferido, los ciclos y cambiar de referidor.
 *  · Pasa a `confirmed` cuando la cuenta está confirmada: email verificado
 *    (`hwe_email_verified`) y, si el futuro «login privado» lo pide, cuenta aprobada
 *    (`hwe_account_is_pending` / `hwe_account_approved`). Lo que llegue último dispara la
 *    confirmación. Una cuenta creada por un administrador en wp-admin se considera confirmada
 *    sin el email del sitio; la aprobación del «login privado», si existe, se consulta aparte.
 *  · Al confirmar se abonan `referral_signup_l1` (al referidor), `referral_signup_l2` (al
 *    referidor de este, si el nivel 2 está activo) y `registration` al propio usuario (aunque
 *    no tenga referidor), con una clave de idempotencia por usuario: confirmar dos veces no
 *    duplica nada.
 *
 * Los cambios administrativos (`setCode`, `setReferrer`) no mueven puntos ya pagados: dejan
 * constancia en la meta `_hwe_loyalty_referrer_change` y en el log (solo ids). Las claves de
 * idempotencia de los abonos garantizan que un cambio de red no vuelva a pagar el alta.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Loyalty_Referrals {

	public const STATUS_PENDING   = 'pending';
	public const STATUS_CONFIRMED = 'confirmed';

	/** Acción estable al confirmarse una atribución (ids, sin PII). */
	public const ACTION_CONFIRMED = 'hwe_loyalty_attribution_confirmed';

	/** Marca de una cuenta creada en wp-admin: se considera confirmada sin verificar el email. */
	public const META_ADMIN_CREATED = '_hwe_loyalty_admin_created';

	/** Constancia del último cambio administrativo de referidor. */
	public const META_REFERRER_CHANGE = '_hwe_loyalty_referrer_change';

	/** Longitud y alfabeto del código: sin 0/O/1/I/L ni caracteres que se confundan al dictarlo. */
	private const CODE_LENGTH = 8;
	private const ALPHABET    = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
	private const CODE_PATTERN = '/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/';

	/**
	 * Prefijo del código que deja el eliminador de privacidad: no encaja con `CODE_PATTERN`,
	 * así que `userByCode`/`attribute` lo rechazan y no sirve para atribuir a nadie.
	 */
	private const ANON_CODE_PREFIX = 'ERASED-';

	/** Reintentos ante una colisión del índice único de `code`. */
	private const MAX_CODE_ATTEMPTS = 10;

	/** Tope de la cadena que se recorre al buscar ciclos (defensa ante datos ya corruptos). */
	private const MAX_CHAIN = 50;

	/**
	 * Abonos que cuentan como «generados por la red». Una transferencia también lleva `ref` de
	 * tipo `user` (quien la envía), así que filtrar solo por la referencia la contaría.
	 */
	private const NETWORK_EARNING_TYPES = array(
		'referral_signup_l1',
		'referral_signup_l2',
		'referral_commission_l1',
		'referral_commission_l2',
		'referral_product_bonus',
	);

	/** Tope de elementos por página de la red, igual que el historial del libro. */
	private const NETWORK_MAX = 100;

	/** Engancha el alta y las dos señales de confirmación. La llama `HWE_Loyalty::boot()`. */
	public static function register(): void {
		add_action( 'user_register', array( self::class, 'onUserRegistered' ) );
		add_action( 'hwe_email_verified', array( self::class, 'confirmIfReady' ) );
		add_action( 'hwe_account_approved', array( self::class, 'confirmIfReady' ) );
	}

	// ------------------------------------------------------------------------
	//  Códigos
	// ------------------------------------------------------------------------

	/**
	 * Código del usuario, creándolo si falta (perezoso).
	 *
	 * Devuelve `''` si el usuario no existe, no participa (rol no permitido o programa
	 * apagado) o no se pudo escribir la fila. Nunca se deriva de datos de la cuenta.
	 */
	public static function codeFor( int $userId ): string {
		if ( ! HWE_Loyalty_Settings::referralsActive() || ! HWE_Loyalty_Settings::canParticipate( $userId ) ) {
			return '';
		}

		$row = self::ensureRow( $userId );

		return null === $row ? '' : (string) $row->code;
	}

	/**
	 * Código guardado del usuario, sin crearlo, sin comprobar ajustes y sin resolver la cuenta.
	 * Es la lectura que usa el exportador de privacidad: exportar datos no debe escribir nada.
	 * Un código ya anonimizado (`ERASED-…`) se devuelve como vacío: no es un dato de la persona.
	 */
	public static function storedCode( int $userId ): string {
		$row = self::rowForUser( $userId );
		if ( null === $row ) {
			return '';
		}

		$code = (string) ( $row->code ?? '' );

		return 0 === strpos( $code, self::ANON_CODE_PREFIX ) ? '' : $code;
	}

	/**
	 * Usuario dueño de un código, o `null` si el formato es inválido, no existe o su dueño ya
	 * fue eliminado. Normaliza mayúsculas y espacios antes de validar.
	 */
	public static function userByCode( string $code ): ?int {
		$code = self::normalizeCode( $code );
		if ( ! self::isValidFormat( $code ) ) {
			return null;
		}

		$row = self::rowByCode( $code );
		if ( null === $row ) {
			return null;
		}

		$userId = (int) $row->user_id;
		if ( ! self::userExists( $userId ) ) {
			self::log( 'un código de referido apunta al usuario ' . $userId . ', que ya no existe.' );
			return null;
		}

		return $userId;
	}

	/**
	 * Cambia el código de un usuario (administración). Devuelve `false` si el usuario no
	 * existe, el formato no es válido o el código ya pertenece a otra cuenta.
	 */
	public static function setCode( int $userId, string $code ): bool {
		if ( ! self::userExists( $userId ) ) {
			self::log( 'no se puede asignar un código: el usuario ' . $userId . ' no existe.' );
			return false;
		}

		$code = self::normalizeCode( $code );
		if ( ! self::isValidFormat( $code ) ) {
			self::log( 'código de referido rechazado para el usuario ' . $userId . ': formato inválido.' );
			return false;
		}

		$owner = self::rowByCode( $code );
		if ( null !== $owner && (int) $owner->user_id !== $userId ) {
			self::log( 'código de referido rechazado para el usuario ' . $userId . ': ya pertenece a otro usuario.' );
			return false;
		}

		$row = self::rowForUser( $userId );
		if ( null === $row ) {
			global $wpdb;
			$inserted = $wpdb->insert(
				self::table(),
				array(
					'user_id'       => $userId,
					'code'          => $code,
					'status'        => self::STATUS_PENDING,
					'attributed_at' => self::now(),
				)
			);
			if ( ! $inserted ) {
				$row = self::rowForUser( $userId ); // Otra petición pudo crearla a la vez.
				if ( null === $row || (string) $row->code !== $code ) {
					self::log( 'no se pudo asignar el código al usuario ' . $userId . '.' );
					return false;
				}
			}
			return true;
		}

		if ( (string) $row->code === $code ) {
			return true;
		}

		global $wpdb;
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET code = %s WHERE user_id = %d',
				$code,
				$userId
			)
		);
		if ( false === $updated ) {
			self::log( 'no se pudo cambiar el código del usuario ' . $userId . '.' );
			return false;
		}

		return true;
	}

	// ------------------------------------------------------------------------
	//  Atribución
	// ------------------------------------------------------------------------

	/**
	 * Atribuye el usuario al dueño de un código.
	 *
	 * Devuelve `attributed` o `ignored:<motivo>` con motivos estables: `disabled`,
	 * `invalid_code`, `self`, `not_participant`, `cycle`, `already_referred` y `storage`.
	 * Un ignorado nunca escribe ni rompe el registro; queda en el log con ids, sin PII.
	 */
	public static function attribute( int $userId, string $code ): string {
		if ( ! HWE_Loyalty_Settings::referralsActive() ) {
			return self::ignored( 'disabled', $userId );
		}

		if ( ! self::userExists( $userId ) || ! HWE_Loyalty_Settings::canParticipate( $userId ) ) {
			return self::ignored( 'not_participant', $userId );
		}

		$referrerId = self::userByCode( $code );
		if ( null === $referrerId ) {
			return self::ignored( 'invalid_code', $userId );
		}

		if ( $referrerId === $userId ) {
			return self::ignored( 'self', $userId );
		}

		if ( ! HWE_Loyalty_Settings::canParticipate( $referrerId ) ) {
			return self::ignored( 'not_participant', $userId, $referrerId );
		}

		$row = self::rowForUser( $userId );
		if ( null === $row && '' !== self::codeFor( $userId ) ) {
			$row = self::rowForUser( $userId );
		}
		if ( null === $row ) {
			return self::ignored( 'storage', $userId, $referrerId );
		}

		if ( null !== $row->referrer_id && (int) $row->referrer_id > 0 ) {
			return self::ignored( 'already_referred', $userId, $referrerId );
		}

		if ( self::createsCycle( $userId, $referrerId ) ) {
			return self::ignored( 'cycle', $userId, $referrerId );
		}

		global $wpdb;
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table()
				. ' SET referrer_id = %d, status = %s, attributed_at = %s, confirmed_at = NULL'
				. ' WHERE user_id = %d AND referrer_id IS NULL',
				$referrerId,
				self::STATUS_PENDING,
				self::now(),
				$userId
			)
		);

		if ( 1 !== (int) $updated ) {
			$row = self::rowForUser( $userId );
			if ( null !== $row && null !== $row->referrer_id && (int) $row->referrer_id > 0 ) {
				return self::ignored( 'already_referred', $userId, $referrerId );
			}
			return self::ignored( 'storage', $userId, $referrerId );
		}

		// La cuenta pudo confirmarse antes de que llegara el código: lo que llega último confirma.
		self::confirmIfReady( $userId );

		return 'attributed';
	}

	// ------------------------------------------------------------------------
	//  Confirmación del alta
	// ------------------------------------------------------------------------

	/**
	 * Confirma la atribución si la cuenta ya está confirmada y abona las primas de alta.
	 *
	 * Idempotente: devuelve `true` solo la primera vez que la fila pasa a `confirmed`; los
	 * abonos usan claves únicas por usuario, así que repetir no duplica puntos.
	 */
	public static function confirmIfReady( int $userId ): bool {
		if ( ! HWE_Loyalty_Settings::canParticipate( $userId ) ) {
			return false;
		}

		if ( ! self::accountConfirmed( $userId ) ) {
			return false;
		}

		/** @var mixed $pending */
		$pending = apply_filters( 'hwe_account_is_pending', false, $userId );
		if ( $pending ) {
			return false;
		}

		$row = self::rowForUser( $userId );
		if ( null === $row && '' !== self::codeFor( $userId ) ) {
			$row = self::rowForUser( $userId );
		}

		$confirmedNow = false;
		if ( null !== $row && self::STATUS_CONFIRMED !== (string) $row->status ) {
			global $wpdb;
			$updated = $wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . self::table()
					. ' SET status = %s, confirmed_at = %s WHERE user_id = %d AND status <> %s',
					self::STATUS_CONFIRMED,
					self::now(),
					$userId,
					self::STATUS_CONFIRMED
				)
			);
			if ( false === $updated ) {
				// Sin la confirmación escrita no se paga nada: los abonos de alta son la
				// contrapartida de una atribución confirmada, no de un intento fallido.
				self::log( 'no se pudo confirmar la atribución del usuario ' . $userId . '; no se abona el alta.' );
				return false;
			}
			$confirmedNow = 1 === (int) $updated;
		}

		$referrerId = self::referrerOf( $userId );
		self::paySignup( $userId, $referrerId );

		if ( $confirmedNow ) {
			do_action( self::ACTION_CONFIRMED, $userId, $referrerId );
		}

		return $confirmedNow;
	}

	/**
	 * Referidor del usuario (o `null`). Con `$confirmedOnly`, solo si la atribución ya está
	 * confirmada. Nunca devuelve un id de usuario que ya no exista (Foco de revisión 4).
	 */
	public static function referrerOf( int $userId, bool $confirmedOnly = false ): ?int {
		if ( $userId <= 0 ) {
			return null;
		}

		$row = self::rowForUser( $userId );
		if ( null === $row || null === $row->referrer_id || (int) $row->referrer_id <= 0 ) {
			return null;
		}

		if ( $confirmedOnly && self::STATUS_CONFIRMED !== (string) $row->status ) {
			return null;
		}

		$referrerId = (int) $row->referrer_id;
		if ( ! self::userExists( $referrerId ) ) {
			self::log( 'el referidor ' . $referrerId . ' del usuario ' . $userId . ' ya no existe.' );
			return null;
		}

		return $referrerId;
	}

	/**
	 * Atribución del usuario para cálculos internos, sin resolver si el referidor sigue
	 * existiendo (para eso está `referrerOf`). Devuelve `null` si el usuario no tiene fila.
	 *
	 * Las fechas van en UTC con el formato de la tabla (`Y-m-d H:i:s`), que es el que usan los
	 * cálculos de duración de la comisión (tarea 6). No es un contrato del frontend: no expone
	 * nombres, emails ni login.
	 *
	 * @return array{referrer_id:?int,status:string,attributed_at:?string,confirmed_at:?string}|null
	 */
	public static function attributionOf( int $userId ): ?array {
		if ( $userId <= 0 ) {
			return null;
		}

		$row = self::rowForUser( $userId );
		if ( null === $row ) {
			return null;
		}

		return array(
			'referrer_id'   => empty( $row->referrer_id ) ? null : (int) $row->referrer_id,
			'status'        => (string) ( $row->status ?? self::STATUS_PENDING ),
			'attributed_at' => empty( $row->attributed_at ) ? null : (string) $row->attributed_at,
			'confirmed_at'  => empty( $row->confirmed_at ) ? null : (string) $row->confirmed_at,
		);
	}

	/**
	 * Cambia (o quita, con `null`) el referidor de un usuario desde la administración.
	 *
	 * Respeta autorreferido y ciclos, no mueve puntos ya pagados y deja constancia en la meta
	 * `_hwe_loyalty_referrer_change` y en el log. Si la cuenta ya estaba confirmada, el nuevo
	 * referidor cobra el alta solo si esa clave no se usó antes.
	 */
	public static function setReferrer( int $userId, ?int $referrerId ): bool {
		if ( ! self::userExists( $userId ) ) {
			self::log( 'no se puede cambiar el referidor: el usuario ' . $userId . ' no existe.' );
			return false;
		}

		$referrerId = null === $referrerId ? null : (int) $referrerId;
		if ( null !== $referrerId ) {
			if ( $referrerId === $userId ) {
				self::log( 'referidor rechazado para el usuario ' . $userId . ': autorreferido.' );
				return false;
			}
			if ( ! self::userExists( $referrerId ) ) {
				self::log( 'referidor rechazado para el usuario ' . $userId . ': el usuario ' . $referrerId . ' no existe.' );
				return false;
			}
			if ( self::createsCycle( $userId, $referrerId ) ) {
				self::log( 'referidor rechazado para el usuario ' . $userId . ': crearía un ciclo.' );
				return false;
			}
		}

		$row = self::ensureRow( $userId );
		if ( null === $row ) {
			return false;
		}

		$previous = null !== $row->referrer_id ? (int) $row->referrer_id : null;
		if ( $previous === $referrerId ) {
			return true;
		}

		$status      = null === $referrerId ? self::STATUS_PENDING : (string) $row->status;
		$confirmedAt = null === $referrerId ? null : $row->confirmed_at;

		global $wpdb;
		if ( null === $referrerId ) {
			$sql = $wpdb->prepare(
				'UPDATE ' . self::table()
				. ' SET referrer_id = NULL, status = %s, attributed_at = %s, confirmed_at = NULL'
				. ' WHERE user_id = %d',
				self::STATUS_PENDING,
				self::now(),
				$userId
			);
		} else {
			$sql = $wpdb->prepare(
				'UPDATE ' . self::table()
				. ' SET referrer_id = %d, status = %s, attributed_at = %s, confirmed_at = %s'
				. ' WHERE user_id = %d',
				$referrerId,
				$status,
				self::now(),
				$confirmedAt,
				$userId
			);
		}

		$updated = $wpdb->query( $sql );
		if ( false === $updated ) {
			self::log( 'no se pudo cambiar el referidor del usuario ' . $userId . '.' );
			return false;
		}

		self::recordReferrerChange( $userId, $previous, $referrerId );

		if ( null !== $referrerId ) {
			self::confirmIfReady( $userId );
		}

		return true;
	}

	// ------------------------------------------------------------------------
	//  Privacidad
	// ------------------------------------------------------------------------

	/**
	 * Anonimiza la fila del usuario **sin borrarla**: el código pasa a uno inservible y la
	 * atribución deja de existir. La cuenta puede seguir viva (eliminador de WordPress), así
	 * que la fila se conserva para no romper el `UNIQUE` de `code` ni el histórico de la red.
	 *
	 * Idempotente: si ya está anonimizada no vuelve a cambiar el código. Devuelve `true` solo
	 * cuando ha escrito algo.
	 */
	public static function anonymize( int $userId ): bool {
		if ( $userId <= 0 ) {
			return false;
		}

		$row = self::rowForUser( $userId );
		if ( null === $row ) {
			return false;
		}

		$code       = (string) ( $row->code ?? '' );
		$referrerId = empty( $row->referrer_id ) ? null : (int) $row->referrer_id;
		$status     = (string) ( $row->status ?? self::STATUS_PENDING );
		$confirmed  = empty( $row->confirmed_at ) ? null : (string) $row->confirmed_at;
		$already    = 0 === strpos( $code, self::ANON_CODE_PREFIX );

		if ( $already && null === $referrerId && self::STATUS_PENDING === $status && null === $confirmed ) {
			return false;
		}

		global $wpdb;
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table()
				. ' SET code = %s, referrer_id = NULL, status = %s, confirmed_at = NULL WHERE user_id = %d',
				$already ? $code : self::anonymousCode( $userId ),
				self::STATUS_PENDING,
				$userId
			)
		);

		if ( false === $updated ) {
			self::log( 'no se pudo anonimizar la fila de referidos del usuario ' . $userId . '.' );
			return false;
		}

		self::log( 'fila de referidos del usuario ' . $userId . ' anonimizada.' );
		return true;
	}

	/**
	 * Desvincula la red del usuario: sus referidos quedan sin referidor y su atribución vuelve
	 * a `pending`. No borra filas ni mueve puntos ya pagados. Devuelve cuántas filas cambiaron.
	 */
	public static function unlinkNetwork( int $userId ): int {
		if ( $userId <= 0 ) {
			return 0;
		}

		global $wpdb;
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table()
				. ' SET referrer_id = NULL, status = %s, confirmed_at = NULL WHERE referrer_id = %d',
				self::STATUS_PENDING,
				$userId
			)
		);

		if ( false === $updated ) {
			self::log( 'no se pudo desvincular la red del usuario ' . $userId . '.' );
			return 0;
		}

		if ( $updated > 0 ) {
			self::log( 'red del usuario ' . $userId . ' desvinculada (' . (int) $updated . ' filas).' );
		}

		return (int) $updated;
	}

	/**
	 * Elimina la fila de referidos de una cuenta que WordPress va a borrar. Idempotente: sin
	 * fila también devuelve `true`; solo `false` si la consulta falla.
	 */
	public static function forget( int $userId ): bool {
		if ( $userId <= 0 ) {
			return false;
		}

		global $wpdb;
		$deleted = $wpdb->query(
			$wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE user_id = %d', $userId )
		);

		if ( false === $deleted ) {
			self::log( 'no se pudo borrar la fila de referidos del usuario ' . $userId . '.' );
			return false;
		}

		return true;
	}

	/** Código aleatorio que nunca pasa la validación de formato. */
	private static function anonymousCode( int $userId ): string {
		$suffix = strtoupper( bin2hex( random_bytes( 6 ) ) );
		return substr( self::ANON_CODE_PREFIX . $userId . '-' . $suffix, 0, 32 );
	}

	// ------------------------------------------------------------------------
	//  Red
	// ------------------------------------------------------------------------

	/**
	 * Red N1/N2 paginada de un referidor.
	 *
	 * Cada elemento: `user_id`, `name` (nombre visible; vacío si la cuenta fue eliminada),
	 * `date` (ISO 8601 UTC de la atribución), `status` y `points_generated` (abonos que ese
	 * referido generó para el referidor). Nunca email ni login. Tolera filas huérfanas.
	 *
	 * `points_generated` suma los abonos de referido (`NETWORK_EARNING_TYPES`) cuyo `ref` es
	 * `['type' => 'user', 'id' => <referido>]`; las ganancias por pedido (tarea 6) usan esa
	 * referencia para que la red cuadre. Una transferencia del referido no cuenta.
	 *
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int}
	 */
	public static function network( int $userId, int $level, int $page, int $perPage ): array {
		$level   = 2 === (int) $level ? 2 : 1;
		$page    = max( 1, (int) $page );
		$perPage = min( self::NETWORK_MAX, max( 1, (int) $perPage ) );
		$offset  = ( $page - 1 ) * $perPage;

		$result = array(
			'items'    => array(),
			'total'    => 0,
			'page'     => $page,
			'per_page' => $perPage,
		);

		if ( $userId <= 0 ) {
			return $result;
		}

		global $wpdb;
		$table = self::table();

		if ( 1 === $level ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE referrer_id = %d", $userId )
			);
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT user_id, status, attributed_at, confirmed_at FROM {$table}"
					. ' WHERE referrer_id = %d ORDER BY attributed_at DESC, user_id DESC LIMIT %d OFFSET %d',
					$userId,
					$perPage,
					$offset
				)
			);
		} else {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} r INNER JOIN {$table} p ON p.user_id = r.referrer_id"
					. ' WHERE p.referrer_id = %d',
					$userId
				)
			);
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT r.user_id, r.status, r.attributed_at, r.confirmed_at FROM {$table} r"
					. " INNER JOIN {$table} p ON p.user_id = r.referrer_id"
					. ' WHERE p.referrer_id = %d ORDER BY r.attributed_at DESC, r.user_id DESC LIMIT %d OFFSET %d',
					$userId,
					$perPage,
					$offset
				)
			);
		}

		$result['total'] = $total;

		foreach ( (array) $rows as $row ) {
			$referredId = (int) ( $row->user_id ?? 0 );
			if ( $referredId <= 0 ) {
				continue;
			}

			$name = HWE_Loyalty_PublicName::of( $referredId );

			$points = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COALESCE(SUM(points), 0) FROM ' . HWE_Loyalty_Schema::ledgerTable()
					. ' WHERE user_id = %d AND points > 0 AND ref_type = %s AND ref_id = %s'
					. ' AND type IN (' . self::typePlaceholders() . ')',
					$userId,
					'user',
					(string) $referredId,
					...self::NETWORK_EARNING_TYPES
				)
			);

			$result['items'][] = array(
				'user_id'          => $referredId,
				'name'             => $name,
				'date'             => self::iso( $row->attributed_at ?? null ),
				'status'           => (string) ( $row->status ?? self::STATUS_PENDING ),
				'points_generated' => $points,
			);
		}

		return $result;
	}

	/**
	 * Totales de la red de un referidor para el resumen de referidos del BFF.
	 *
	 * `level1` y `level2` cuentan las cuentas atribuidas en cada nivel; `pending` cuenta las
	 * atribuciones sin confirmar de **toda** la red (los dos niveles); `earned` suma los abonos
	 * que la red ha generado al referidor (referencias `user` a un miembro de su red), la misma
	 * definición que usa `points_generated` en `network()`.
	 *
	 * Un usuario sin red devuelve todo a cero. Los abonos con referencia a un pedido (cashback)
	 * no cuentan como «generado por la red».
	 *
	 * @return array{level1:int,level2:int,pending:int,earned:int}
	 */
	public static function networkTotals( int $userId ): array {
		$totals = array( 'level1' => 0, 'level2' => 0, 'pending' => 0, 'earned' => 0 );

		if ( $userId <= 0 ) {
			return $totals;
		}

		global $wpdb;
		$table = self::table();

		$totals['level1'] = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE referrer_id = %d", $userId )
		);

		$totals['level2'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} r INNER JOIN {$table} p ON p.user_id = r.referrer_id"
				. ' WHERE p.referrer_id = %d',
				$userId
			)
		);

		$totals['pending'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} r WHERE r.status = %s"
				. " AND (r.referrer_id = %d OR r.referrer_id IN (SELECT user_id FROM {$table} WHERE referrer_id = %d))",
				self::STATUS_PENDING,
				$userId,
				$userId
			)
		);

		$totals['earned'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(l.points), 0) FROM ' . HWE_Loyalty_Schema::ledgerTable() . ' l'
				. " INNER JOIN {$table} r ON r.user_id = CAST(l.ref_id AS UNSIGNED)"
				. ' WHERE l.user_id = %d AND l.points > 0 AND l.ref_type = %s'
				. " AND (r.referrer_id = %d OR r.referrer_id IN (SELECT user_id FROM {$table} WHERE referrer_id = %d))"
				. ' AND l.type IN (' . self::typePlaceholders() . ')',
				$userId,
				'user',
				$userId,
				$userId,
				...self::NETWORK_EARNING_TYPES
			)
		);

		return $totals;
	}

	// ------------------------------------------------------------------------
	//  Hooks
	// ------------------------------------------------------------------------

	/**
	 * Alta de una cuenta: asigna el código y, si la creó un administrador en wp-admin, la
	 * considera confirmada (no pasa por la verificación de email del sitio).
	 */
	public static function onUserRegistered( int $userId ): void {
		if ( $userId <= 0 || ! self::userExists( $userId ) ) {
			return;
		}

		$createdByAdmin = self::createdByAdmin();
		if ( $createdByAdmin ) {
			update_user_meta( $userId, self::META_ADMIN_CREATED, '1' );
		}

		$code = self::codeFor( $userId );
		if ( '' === $code && HWE_Loyalty_Settings::canParticipate( $userId ) ) {
			self::log( 'no se pudo asignar el código al usuario ' . $userId . ' al crear la cuenta.' );
		}

		if ( $createdByAdmin ) {
			self::confirmIfReady( $userId );
		}
	}

	// ------------------------------------------------------------------------
	//  Piezas privadas
	// ------------------------------------------------------------------------

	/** ¿La cuenta está confirmada? Email verificado o creada por un administrador. */
	private static function accountConfirmed( int $userId ): bool {
		$verified = (string) get_user_meta( $userId, 'hwe_email_verified', true );
		if ( '' !== $verified && '0' !== $verified ) {
			return true;
		}

		return '1' === (string) get_user_meta( $userId, self::META_ADMIN_CREATED, true );
	}

	/** Abonos del alta. Idempotentes por usuario mediante las claves del libro. */
	private static function paySignup( int $userId, ?int $referrerId ): void {
		if ( ! HWE_Loyalty_Settings::pointsActive() ) {
			return;
		}

		$settings = HWE_Loyalty_Settings::get();

		if ( null !== $referrerId && HWE_Loyalty_Settings::canParticipate( $referrerId ) ) {
			$l1 = (int) $settings['referral_signup_points_l1'];
			if ( $l1 > 0 ) {
				self::credit( $referrerId, $l1, 'referral_signup_l1', 'signup:l1:' . $userId, $userId );
			}

			if ( ! empty( $settings['level2_enabled'] ) ) {
				$second = self::referrerOf( $referrerId );
				$l2     = (int) $settings['referral_signup_points_l2'];
				if ( null !== $second && $second !== $userId && $l2 > 0 && HWE_Loyalty_Settings::canParticipate( $second ) ) {
					self::credit( $second, $l2, 'referral_signup_l2', 'signup:l2:' . $userId, $userId );
				}
			}
		}

		$registration = (int) ( $settings['events']['registration'] ?? 0 );
		if ( $registration > 0 ) {
			self::credit( $userId, $registration, 'registration', 'registration:' . $userId, $userId );
		}
	}

	private static function credit( int $userId, int $points, string $type, string $key, int $sourceUserId ): void {
		$entry = HWE_Loyalty_Ledger::credit(
			$userId,
			$points,
			$type,
			$key,
			array(
				'type' => 'user',
				'id'   => $sourceUserId,
			)
		);

		// `null` es el resultado normal de una clave ya usada; el libro ya registra los fallos.
		if ( null === $entry ) {
			return;
		}
	}

	/**
	 * ¿Una cuenta creada desde wp-admin por un administrador?
	 *
	 * Se decide en el hook de alta (`user_register`): el alta por wp-admin
	 * (`wp-admin/user-new.php`) corre con `is_admin()` y un usuario que puede `create_users`,
	 * mientras que el registro del sitio (REST/GraphQL) y WP-CLI no cumplen esas condiciones.
	 * Se excluyen AJAX, cron y REST para no confundir una petición del BFF con el panel.
	 */
	private static function createdByAdmin(): bool {
		if ( ! function_exists( 'is_admin' ) || ! is_admin() ) {
			return false;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return false;
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		return function_exists( 'current_user_can' ) && current_user_can( 'create_users' );
	}

	/**
	 * Recorre la cadena de referidores del candidato: si llega al usuario, o a un ciclo ya
	 * existente, la atribución se rechaza (falla cerrado).
	 */
	private static function createsCycle( int $userId, int $referrerId ): bool {
		$seen    = array();
		$current = $referrerId;

		for ( $depth = 0; $depth < self::MAX_CHAIN; $depth++ ) {
			if ( $current === $userId ) {
				return true;
			}
			if ( isset( $seen[ $current ] ) ) {
				return true;
			}
			$seen[ $current ] = true;

			$row = self::rowForUser( $current );
			if ( null === $row || null === $row->referrer_id || (int) $row->referrer_id <= 0 ) {
				return false;
			}
			$current = (int) $row->referrer_id;
		}

		return true;
	}

	/** Fila del usuario, creándola con un código nuevo si falta. Devuelve `null` si no pudo. */
	private static function ensureRow( int $userId ): ?object {
		$row = self::rowForUser( $userId );
		if ( null !== $row ) {
			return $row;
		}

		global $wpdb;

		for ( $attempt = 0; $attempt < self::MAX_CODE_ATTEMPTS; $attempt++ ) {
			$code     = self::generateCode();
			$inserted = $wpdb->insert(
				self::table(),
				array(
					'user_id'       => $userId,
					'code'          => $code,
					'status'        => self::STATUS_PENDING,
					'attributed_at' => self::now(),
				)
			);

			$row = self::rowForUser( $userId );
			if ( null !== $row ) {
				return $row; // Inserta o resuelve la carrera con otra petición.
			}

			if ( ! $inserted ) {
				if ( null !== self::rowByCode( $code ) ) {
					self::log( 'colisión al asignar un código al usuario ' . $userId . '; se reintenta.' );
				} else {
					self::log( 'no se pudo insertar el código del usuario ' . $userId . '; se reintenta.' );
				}
			}
		}

		self::log( 'no se pudo crear la fila de referidos del usuario ' . $userId . ' tras ' . self::MAX_CODE_ATTEMPTS . ' intentos.' );
		return null;
	}

	private static function generateCode(): string {
		$max  = strlen( self::ALPHABET ) - 1;
		$code = '';
		for ( $i = 0; $i < self::CODE_LENGTH; $i++ ) {
			$code .= self::ALPHABET[ random_int( 0, $max ) ];
		}
		return $code;
	}

	private static function normalizeCode( string $code ): string {
		return strtoupper( (string) preg_replace( '/\s+/', '', $code ) );
	}

	private static function isValidFormat( string $code ): bool {
		return 1 === preg_match( self::CODE_PATTERN, $code );
	}

	private static function rowForUser( int $userId ): ?object {
		global $wpdb;
		if ( $userId <= 0 ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE user_id = %d', $userId )
		);
		return is_object( $row ) ? $row : null;
	}

	private static function rowByCode( string $code ): ?object {
		global $wpdb;
		if ( '' === $code ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE code = %s LIMIT 1', $code )
		);
		return is_object( $row ) ? $row : null;
	}

	private static function userExists( int $userId ): bool {
		if ( $userId <= 0 ) {
			return false;
		}
		if ( function_exists( 'get_userdata' ) ) {
			return false !== get_userdata( $userId );
		}
		return true;
	}

	private static function recordReferrerChange( int $userId, ?int $previous, ?int $current ): void {
		if ( function_exists( 'update_user_meta' ) ) {
			update_user_meta(
				$userId,
				self::META_REFERRER_CHANGE,
				array(
					'from' => $previous,
					'to'   => $current,
					'by'   => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
					'at'   => self::now(),
				)
			);
		}
		self::log( 'el referidor del usuario ' . $userId . ' cambió de ' . (int) ( $previous ?? 0 ) . ' a ' . (int) ( $current ?? 0 ) . '.' );
	}

	private static function ignored( string $reason, int $userId, ?int $referrerId = null ): string {
		$line = 'atribución ignorada (' . $reason . ') para el usuario ' . $userId;
		if ( null !== $referrerId ) {
			$line .= '; referidor ' . $referrerId;
		}
		self::log( $line . '.' );

		return 'ignored:' . $reason;
	}

	/** `%s, %s, …` para el `IN` de {@see self::NETWORK_EARNING_TYPES}. */
	private static function typePlaceholders(): string {
		return implode( ', ', array_fill( 0, count( self::NETWORK_EARNING_TYPES ), '%s' ) );
	}

	private static function table(): string {
		return HWE_Loyalty_Schema::referralsTable();
	}

	private static function now(): string {
		return gmdate( 'Y-m-d H:i:s', time() );
	}

	private static function iso( mixed $mysql ): ?string {
		if ( ! is_string( $mysql ) || '' === $mysql ) {
			return null;
		}
		$timestamp = strtotime( $mysql . ' UTC' );
		return false === $timestamp ? null : gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}

	private static function log( string $message ): void {
		error_log( '[hwe] loyalty: ' . $message );
	}
}
