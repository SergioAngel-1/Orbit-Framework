<?php
/**
 * Puntos por activación o renovación de una compra (spec §7.4).
 *
 * El nivel configura cuántos puntos se abonan (`points`, ≥ 0). Se abonan **cuando se cobra**
 * una concesión de origen `order` —el hook de alta se dispara tras el COMMIT de la concesión—
 * y solo entonces: los regalos de referido y las asignaciones de administrador no pasan por
 * aquí (sus acciones no están enganchadas), y el lote futuro podrá pedirlo explícitamente.
 *
 * Idempotencia: la clave del abono se deriva de la clave única de la concesión
 * (`order:<pedido>:item:<línea>` → `membership:order:<pedido>:item:<línea>`), así que el mismo
 * pedido notificado varias veces (`processing` → `completed`, `payment_complete`, webhook
 * repetido o ida y vuelta de estado) nunca abona dos veces (Foco de revisión 1).
 *
 * Reversión: revocar la concesión (reembolso total o cancelación, que `Purchase` resuelve con
 * `Grants::revokeByKey()`) revierte el abono con la contraparte de `loyalty`, limitada al saldo
 * e idempotente por su propia clave.
 *
 * Si `loyalty` no está cargado cuando llega la revocación, no hay contraparte a la que llamar:
 * la reversión se deja en una cola local con la misma clave de la concesión y se procesa cuando
 * `loyalty` vuelve a estar disponible (al cargar y en la tarea diaria). La cola es idempotente:
 * la clave de reversión evita aplicarla dos veces y un intento sin abono que revertir se
 * descarta tras unos pocos reintentos, con constancia en el log.
 *
 * Si «Puntos» no está encendido en el cobro, no se abona, no se reintenta después y queda
 * constancia en el historial del socio (`points_skipped`) y en el log. Para poder dejar esa
 * constancia, el tipo escucha sus propios eventos con el plugin de `loyalty` cargado aunque la
 * función esté apagada; nunca engancha nada en los hooks de `loyalty`.
 *
 * Una compra aplazada se cobra en el acto (y abona u omite entonces) aunque su concesión se
 * active más tarde. La activación que dispara la tarea diaria llega con
 * `HWE_Memberships_Events::CONTEXT_DAILY` y se ignora: no es un segundo cobro.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Benefit_Activation_Points extends HWE_Memberships_Benefit {

	/** Clave estable del tipo. */
	public const KEY = 'activation_points';

	/** Tipo de movimiento con el que se abona en `loyalty`. */
	public const ENTRY_TYPE = 'membership_activation';

	/** Prefijo de la clave del abono, para no colisionar con otras entradas del libro. */
	private const CREDIT_PREFIX = 'membership:';

	/** Prefijo de la clave de la reversión (loyalty añade el suyo por delante). */
	private const REVERSAL_PREFIX = 'membership:reversal:';

	/** Opción con las reversiones pendientes de aplicar mientras `loyalty` no está disponible. */
	public const PENDING_OPTION = 'hwe_memberships_pending_point_reversals';

	/** Intentos antes de descartar una reversión que `loyalty` no puede aplicar. */
	private const MAX_REVERSAL_ATTEMPTS = 3;

	/** Reversiones que se procesan por pasada. */
	private const REVERSAL_BATCH = 50;

	public function key(): string {
		return self::KEY;
	}

	public function dependsOn(): ?string {
		return 'loyalty:points';
	}

	public function fields(): array {
		return array(
			'points' => array(
				'type'    => self::FIELD_NUMBER,
				'label'   => function_exists( '__' ) ? __( 'Puntos por activación', 'hwe' ) : 'Puntos por activación',
				'default' => 0,
				'min'     => 0,
				'max'     => 1000000,
			),
		);
	}

	public function publicData( int $levelId ): array {
		$config = $this->configFor( $levelId );

		return array(
			'key'    => $this->key(),
			'points' => null === $config ? 0 : max( 0, (int) ( $config['points'] ?? 0 ) ),
		);
	}

	public function stateFor( int $userId ): array {
		$state  = parent::stateFor( $userId );
		$config = null;

		if ( $state['active'] && (int) $state['level_id'] > 0 ) {
			$config = $this->configFor( (int) $state['level_id'] );
		}

		$state['points'] = null === $config ? 0 : max( 0, (int) ( $config['points'] ?? 0 ) );

		return $state;
	}

	/**
	 * Engancha los eventos propios del ciclo. Con `loyalty` cargado engancha todos (aunque
	 * «Puntos» esté apagado: es la única forma de registrar en el historial que el cobro no
	 * abonó). Sin `loyalty` engancha **solo la revocación**, para dejar la reversión en cola en
	 * vez de perderla. La cola se procesa al cargar y en la tarea diaria. No engancha nada en los
	 * hooks de `loyalty`.
	 */
	public function register(): void {
		if (
			! class_exists( 'HWE_Memberships_Settings' )
			|| ! HWE_Memberships_Settings::moduleActive()
		) {
			return;
		}

		try {
			if ( ! class_exists( 'HWE_Memberships_LoyaltyBridge' ) || ! HWE_Memberships_LoyaltyBridge::loaded() ) {
				add_action( 'hwe_membership_revoked', array( $this, 'onRevoked' ), 10, 3 );
				return;
			}

			$this->registerHooks();

			// M-3: si la cola quedó de un apagón de `loyalty`, se vacía en cuanto vuelve.
			add_action( 'init', array( self::class, 'flushPendingIfAny' ), 20 );
		} catch ( \Throwable $e ) {
			self::log( 'no se pudieron registrar los hooks del tipo «' . $this->key() . '»: ' . $e->getMessage() );
		}
	}

	protected function registerHooks(): void {
		add_action( 'hwe_membership_activated', array( $this, 'onGranted' ), 10, 3 );
		add_action( 'hwe_membership_renewed', array( $this, 'onGranted' ), 10, 3 );
		add_action( 'hwe_membership_upgraded', array( $this, 'onGranted' ), 10, 3 );
		add_action( 'hwe_membership_deferred', array( $this, 'onGranted' ), 10, 3 );
		add_action( 'hwe_membership_revoked', array( $this, 'onRevoked' ), 10, 3 );
	}

	/**
	 * Alta, renovación, mejora o aplazamiento de una concesión ya confirmada. Solo abona las
	 * compras (`origin = order`).
	 */
	public function onGranted( int $userId, int $grantId, int $levelId = 0 ): void {
		if ( $userId <= 0 || $grantId <= 0 || ! class_exists( 'HWE_Memberships_Grants' ) ) {
			return;
		}

		// La tarea diaria activa una compra que ya se cobró (y por tanto ya se abonó o se
		// omitió) cuando termina la cobertura superior que la aplazaba. No es un cobro nuevo:
		// reintentar aquí abonaría tarde o dejaría un rechazo falso en el log.
		if (
			class_exists( 'HWE_Memberships_Events' )
			&& HWE_Memberships_Events::CONTEXT_DAILY === HWE_Memberships_Events::context()
		) {
			return;
		}

		$grant = HWE_Memberships_Grants::rowById( $grantId );
		if ( null === $grant ) {
			self::log( 'no se pudo leer la concesión ' . $grantId . ' para abonar sus puntos.' );
			return;
		}
		if ( (int) ( $grant['user_id'] ?? 0 ) !== $userId ) {
			self::log( 'la concesión ' . $grantId . ' no pertenece al usuario ' . $userId . '; no se abonan puntos.' );
			return;
		}
		if ( HWE_Memberships_Grants::ORIGIN_ORDER !== (string) ( $grant['origin'] ?? '' ) ) {
			// Regalos, administración y lotes no abonan puntos por activación.
			return;
		}

		$grantLevel = (int) ( $grant['level_id'] ?? $levelId );
		$config     = $this->configFor( $grantLevel );
		if ( null === $config || empty( $config['enabled'] ) ) {
			return;
		}

		$points = max( 0, (int) ( $config['points'] ?? 0 ) );
		if ( $points <= 0 ) {
			return;
		}

		$key = (string) ( $grant['key'] ?? '' );
		if ( '' === $key ) {
			self::log( 'la concesión ' . $grantId . ' no tiene clave de idempotencia; no se abonan puntos.' );
			return;
		}

		if ( ! class_exists( 'HWE_Memberships_LoyaltyBridge' ) || ! HWE_Memberships_LoyaltyBridge::available( 'points' ) ) {
			$this->recordSkipped( $userId, $grantId, $grantLevel, $points, $key );
			return;
		}

		$orderId = 'order' === (string) ( $grant['ref_type'] ?? '' ) ? (int) ( $grant['ref_id'] ?? 0 ) : 0;
		$ref     = $orderId > 0 ? array( 'type' => 'order', 'id' => $orderId ) : array();
		$data    = array(
			'grant'    => $grantId,
			'level_id' => $grantLevel,
			'order'    => $orderId,
		);

		$entryId = HWE_Memberships_LoyaltyBridge::credit(
			$userId,
			$points,
			self::ENTRY_TYPE,
			self::creditKey( $key ),
			$ref,
			$data
		);

		if ( null === $entryId ) {
			self::log(
				'no se pudieron abonar ' . $points . ' puntos al usuario ' . $userId
				. ' por la concesión «' . $key . '»: loyalty rechazó el abono.'
			);
		}
	}

	/**
	 * Revocación de una concesión (reembolso total o cancelación). Revierte el abono por su
	 * clave; `loyalty` limita la recuperación al saldo e ignora una segunda reversión. Sin
	 * `loyalty` disponible, deja la reversión en la cola con la misma clave de idempotencia.
	 */
	public function onRevoked( int $userId, int $grantId, int $levelId = 0 ): void {
		if ( $userId <= 0 || $grantId <= 0 || ! class_exists( 'HWE_Memberships_Grants' ) ) {
			return;
		}

		$grant = HWE_Memberships_Grants::rowById( $grantId );
		if ( null === $grant ) {
			self::log( 'no se pudo leer la concesión ' . $grantId . ' para revertir sus puntos.' );
			return;
		}
		if ( (int) ( $grant['user_id'] ?? 0 ) !== $userId ) {
			return;
		}
		if ( HWE_Memberships_Grants::ORIGIN_ORDER !== (string) ( $grant['origin'] ?? '' ) ) {
			return;
		}

		$key = (string) ( $grant['key'] ?? '' );
		if ( '' === $key ) {
			return;
		}

		if ( ! self::loyaltyCanReverse() ) {
			// M-3: sin `loyalty` no hay contraparte; la revocación no se puede perder.
			self::enqueueReversal( $userId, $grantId, (int) ( $grant['level_id'] ?? $levelId ), $key );
			return;
		}

		$entryId = HWE_Memberships_LoyaltyBridge::reverseCredit( self::creditKey( $key ), self::reversalKey( $key ) );
		if ( null === $entryId ) {
			self::log(
				'no se pudo revertir el abono de puntos del usuario ' . $userId
				. ' por la concesión «' . $key . '»: no había abono o no quedaba saldo.'
			);
		}
	}

	/** Clave del abono derivada de la clave única de la concesión. */
	public static function creditKey( string $grantKey ): string {
		return substr( self::CREDIT_PREFIX . trim( $grantKey ), 0, 191 );
	}

	/** Clave de la reversión, única por concesión. */
	public static function reversalKey( string $grantKey ): string {
		return substr( self::REVERSAL_PREFIX . trim( $grantKey ), 0, 191 );
	}

	// ------------------------------------------------------------------------
	//  Cola de reversiones pendientes (loyalty no disponible)
	// ------------------------------------------------------------------------

	/**
	 * Procesa la cola si hay algo pendiente. Es el callback de `init` y de la tarea diaria;
	 * con la cola vacía no hace ninguna escritura.
	 */
	public static function flushPendingIfAny(): void {
		if ( array() === self::pending() ) {
			return;
		}

		self::flushPending();
	}

	/**
	 * Aplica las reversiones pendientes con el contrato de `loyalty`. Devuelve cuántas se
	 * aplicaron. Es idempotente: la clave de reversión de cada concesión evita repetir el
	 * movimiento, y una entrada que `loyalty` rechaza se reintenta hasta un tope y luego se
	 * descarta con constancia en el log (no había abono que revertir).
	 */
	public static function flushPending( int $limit = self::REVERSAL_BATCH ): int {
		if ( ! self::loyaltyCanReverse() ) {
			return 0;
		}

		$queue = self::pending();
		if ( array() === $queue ) {
			return 0;
		}

		$limit     = max( 1, $limit );
		$processed = 0;
		$applied   = 0;
		$changed   = false;

		foreach ( $queue as $key => $entry ) {
			if ( $processed >= $limit ) {
				break;
			}
			++$processed;

			$entryId = HWE_Memberships_LoyaltyBridge::reverseCredit( self::creditKey( $key ), self::reversalKey( $key ) );

			if ( null !== $entryId ) {
				unset( $queue[ $key ] );
				$changed = true;
				++$applied;
				continue;
			}

			$attempts = (int) ( $entry['attempts'] ?? 0 ) + 1;
			$changed  = true;

			if ( $attempts >= self::MAX_REVERSAL_ATTEMPTS ) {
				unset( $queue[ $key ] );
				self::log(
					'se descarta la reversión pendiente de la concesión «' . $key . '» tras '
					. $attempts . ' intentos sin abono que revertir.'
				);
				continue;
			}

			$queue[ $key ]['attempts'] = $attempts;
		}

		if ( $changed ) {
			self::savePending( $queue );
		}

		return $applied;
	}

	/**
	 * Deja una reversión en la cola. La clave es la de la concesión, así que dos avisos del
	 * mismo hecho no crean dos entradas.
	 */
	private static function enqueueReversal( int $userId, int $grantId, int $levelId, string $grantKey ): void {
		$queue     = self::pending();
		$wasQueued = isset( $queue[ $grantKey ] );
		$entry     = $queue[ $grantKey ] ?? array(
			'attempts'  => 0,
			'queued_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		$entry['user_id']  = $userId;
		$entry['grant_id'] = $grantId;
		$entry['level_id'] = $levelId;

		$queue[ $grantKey ] = $entry;
		self::savePending( $queue );

		if ( ! $wasQueued ) {
			self::log(
				'loyalty no está disponible: la reversión de puntos de la concesión «' . $grantKey
				. '» del usuario ' . $userId . ' queda pendiente.'
			);
		}
	}

	/**
	 * Cola saneada: mapa `clave de concesión → {user_id, grant_id, level_id, attempts, queued_at}`.
	 *
	 * @return array<string,array{user_id:int,grant_id:int,level_id:int,attempts:int,queued_at:string}>
	 */
	private static function pending(): array {
		$raw = get_option( self::PENDING_OPTION, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $key => $entry ) {
			if ( ! is_string( $key ) || '' === trim( $key ) || ! is_array( $entry ) ) {
				continue;
			}
			$out[ $key ] = array(
				'user_id'   => max( 0, (int) ( $entry['user_id'] ?? 0 ) ),
				'grant_id'  => max( 0, (int) ( $entry['grant_id'] ?? 0 ) ),
				'level_id'  => max( 0, (int) ( $entry['level_id'] ?? 0 ) ),
				'attempts'  => max( 0, (int) ( $entry['attempts'] ?? 0 ) ),
				'queued_at' => is_scalar( $entry['queued_at'] ?? null ) ? (string) $entry['queued_at'] : '',
			);
		}

		return $out;
	}

	/**
	 * Guarda la cola. Sin entradas se borra la opción para no dejar basura. Un fallo de escritura
	 * se registra: la reversión se reintentará mientras la opción siga existiendo.
	 *
	 * @param array<string,array<string,mixed>> $queue
	 */
	private static function savePending( array $queue ): void {
		if ( [] === $queue ) {
			if ( function_exists( 'delete_option' ) ) {
				delete_option( self::PENDING_OPTION );
			}
			return;
		}

		$current = get_option( self::PENDING_OPTION, array() );
		if ( $current === $queue ) {
			return;
		}

		if ( ! update_option( self::PENDING_OPTION, $queue, false ) ) {
			self::log( 'no se pudo guardar la cola de reversiones de puntos pendientes.' );
		}
	}

	/** ¿Se puede revertir ahora mismo? Hace falta `loyalty` cargado y su función de reversión. */
	private static function loyaltyCanReverse(): bool {
		return class_exists( 'HWE_Memberships_LoyaltyBridge' )
			&& HWE_Memberships_LoyaltyBridge::loaded()
			&& function_exists( 'hwe_loyalty_reverse_credit' );
	}

	/**
	 * Deja en el historial del socio (y en el log) que el cobro no abonó porque «Puntos» no
	 * estaba encendido. No se reintenta después: es un hecho del cobro, no una cola.
	 */
	private function recordSkipped( int $userId, int $grantId, int $levelId, int $points, string $grantKey ): void {
		self::log(
			'el cobro de la concesión «' . $grantKey . '» del usuario ' . $userId
			. ' no abonó puntos: loyalty no tiene «Puntos» activo.'
		);

		if ( ! class_exists( 'HWE_Memberships_Events' ) ) {
			return;
		}

		$eventId = HWE_Memberships_Events::record(
			$userId,
			$grantId,
			HWE_Memberships_Events::POINTS_SKIPPED,
			$levelId,
			$levelId,
			'system',
			null,
			array(
				'points' => $points,
				'reason' => 'loyalty_points_unavailable',
				'origin' => 'order',
				'key'    => $grantKey,
			),
			gmdate( 'Y-m-d H:i:s' ),
			$levelId
		);

		if ( null === $eventId ) {
			self::log( 'no se pudo registrar en el historial el cobro sin puntos de la concesión «' . $grantKey . '».' );
		}

		// `record()` encola su hook; al no haber transacción abierta, se dispara de inmediato.
		HWE_Memberships_Events::flush();
	}
}
