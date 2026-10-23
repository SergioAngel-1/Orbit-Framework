<?php
/**
 * Rutas internas de lectura del monedero y de la red de referidos (`hwe/v1/loyalty/…`).
 *
 * Solo las consume el BFF, con `X-HWE-Internal-Secret`; el `user_id` lo pone el BFF desde la
 * sesión verificada y el navegador nunca las alcanza (`hwe-bff-only` cierra todo
 * `/hwe/v1/loyalty/`). Tres lecturas:
 *  · `GET loyalty/summary`       → saldo, ganado, usado, próximo a caducar y participación.
 *  · `GET loyalty/transactions`  → movimientos paginados (nuevo primero), con `type` + `data`.
 *  · `GET loyalty/referrals`     → red N1/N2 paginada y totales; nombre visible, nunca email,
 *                                  login ni `user_id` del referido.
 *
 * Con el módulo o el interruptor de la función apagados responden 404 como una ruta
 * inexistente. Un `user_id` que no existe o que no participa **no** produce un error: el
 * resumen responde `participant: false` con ceros y las listas van vacías, para que la ruta no
 * sirva para enumerar cuentas. Sin `user_id` válido, 400.
 *
 * **Texto de los movimientos.** El backend no guarda descripciones con el nombre de la unidad
 * (spec §5.1): guarda `type` y `data` estructurada. El texto se compone en el BFF (Tarea 13)
 * porque sigue el idioma de la petición; estas rutas solo exponen un `data` estable y sin PII de
 * terceros. Las claves por tipo están documentadas en el README del plugin.
 *
 * Las fechas salen en ISO 8601 UTC (las del libro ya vienen así) y `pages` es
 * `ceil(total / per_page)`, con `0` cuando no hay movimientos.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Loyalty_ReadRoutes {

	public const NAMESPACE = 'hwe/v1';

	/** Días del aviso «caduca pronto» (spec §5.3: 30, decisión propia). */
	public const EXPIRING_DAYS = 30;

	/** Página por defecto y tope duro de las tres lecturas. */
	public const PER_PAGE_DEFAULT = 20;
	public const PER_PAGE_MAX     = 100;

	/**
	 * Claves de `data` que viajan al BFF por tipo de movimiento, con su conversión.
	 *
	 * Es una lista blanca: un tipo desconocido (añadido por el filtro
	 * `hwe_loyalty_entry_types`) viaja con `data` vacía en lugar de arrastrar datos que el BFF no
	 * sabe interpretar. Un traslado entre usuarios solo lleva `counterpart_name` (nombre visible) y
	 * la comisión: nunca su id, email ni login. La nota libre de un ajuste manual pertenece al
	 * propio usuario y se añade aparte, solo para `admin_credit` / `admin_debit`.
	 *
	 * @var array<string,array<string,string>>
	 */
	private const DATA_FIELDS = array(
		'cashback'                => array( 'order' => 'int' ),
		'referral_commission_l1'  => array( 'order' => 'int' ),
		'referral_commission_l2'  => array( 'order' => 'int' ),
		'referral_product_bonus'  => array( 'order' => 'int' ),
		'referral_signup_l1'      => array(),
		'referral_signup_l2'      => array(),
		'registration'            => array(),
		'review'                  => array( 'source' => 'string' ),
		'birthday'                => array( 'year' => 'int' ),
		'transfer_in'             => array( 'fee' => 'int' ),
		'transfer_out'            => array( 'fee' => 'int' ),
		'transfer_fee'            => array( 'fee' => 'int' ),
		'redeem'                  => array( 'order' => 'int', 'discount' => 'string' ),
		'redeem_refund'           => array( 'order' => 'int' ),
		'expire'                  => array(),
		'earn_reversal'           => array( 'order' => 'int', 'original_type' => 'string' ),
		'admin_credit'            => array(),
		'admin_debit'             => array(),
	);

	/** Tipos cuyo `data` debe llevar el número de pedido (se toma de `ref` si falta). */
	private const ORDER_TYPES = array(
		'cashback',
		'referral_commission_l1',
		'referral_commission_l2',
		'referral_product_bonus',
		'redeem',
		'redeem_refund',
		'earn_reversal',
	);

	/** Tipos que mueven puntos entre dos usuarios. */
	private const TRANSFER_TYPES = array( 'transfer_in', 'transfer_out', 'transfer_fee' );

	/** Engancha el registro de rutas. La llama `HWE_Loyalty::boot()`. */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'registerRoutes' ) );
	}

	public static function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/loyalty/summary',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( self::class, 'permissionPoints' ),
				'callback'            => array( self::class, 'handleSummary' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/loyalty/transactions',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( self::class, 'permissionPoints' ),
				'callback'            => array( self::class, 'handleTransactions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/loyalty/referrals',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( self::class, 'permissionReferrals' ),
				'callback'            => array( self::class, 'handleReferrals' ),
			)
		);
	}

	// ------------------------------------------------------------------------
	//  Puertas
	// ------------------------------------------------------------------------

	/**
	 * Secreto interno + «Puntos» activos.
	 *
	 * Sin el secreto devuelve `false`; con la función apagada devuelve el mismo `WP_Error` que
	 * una ruta inexistente (404), para no revelar que existe.
	 *
	 * @return bool|\WP_Error
	 */
	public static function permissionPoints( $request ) {
		return self::permission( $request, 'points' );
	}

	/**
	 * Secreto interno + «Referidos» activos. Sin Puntos, `referralsActive()` ya es falso.
	 *
	 * @return bool|\WP_Error
	 */
	public static function permissionReferrals( $request ) {
		return self::permission( $request, 'referrals' );
	}

	/**
	 * @param string $feature `points` o `referrals`.
	 * @return bool|\WP_Error
	 */
	private static function permission( $request, string $feature ) {
		if ( ! function_exists( 'hwe_has_internal_secret' ) || ! hwe_has_internal_secret( $request ) ) {
			return false;
		}

		$active = 'referrals' === $feature
			? HWE_Loyalty_Settings::referralsActive()
			: HWE_Loyalty_Settings::pointsActive();

		if ( ! $active ) {
			return new WP_Error(
				'rest_no_route',
				__( 'No route was found matching the URL and request method.' ),
				array( 'status' => 404 )
			);
		}

		return true;
	}

	// ------------------------------------------------------------------------
	//  Lecturas
	// ------------------------------------------------------------------------

	/**
	 * Resumen del monedero: participación, saldo, totales históricos y caducidad próxima.
	 * Un usuario inexistente o sin rol participante responde `participant: false` y ceros.
	 */
	public static function handleSummary( $request ): WP_REST_Response {
		$userId = self::userId( $request );
		if ( $userId <= 0 ) {
			return new WP_REST_Response( array( 'error' => 'invalid_request' ), 400 );
		}

		if ( ! HWE_Loyalty_Settings::canParticipate( $userId ) ) {
			return new WP_REST_Response(
				array(
					'participant' => false,
					'balance'     => 0,
					'earned'      => 0,
					'used'        => 0,
					'expiring'    => array( 'points' => 0, 'date' => null ),
				),
				200
			);
		}

		$balance  = HWE_Loyalty_Ledger::balance( $userId );
		$expiring = HWE_Loyalty_Ledger::expiringSoon( $userId, self::EXPIRING_DAYS );

		return new WP_REST_Response(
			array(
				'participant' => true,
				'balance'     => (int) ( $balance['balance'] ?? 0 ),
				'earned'      => (int) ( $balance['earned'] ?? 0 ),
				'used'        => (int) ( $balance['used'] ?? 0 ),
				'expiring'    => array(
					'points' => (int) ( $expiring['points'] ?? 0 ),
					'date'   => isset( $expiring['date'] ) && is_string( $expiring['date'] ) ? $expiring['date'] : null,
				),
			),
			200
		);
	}

	/**
	 * Movimientos paginados, del más nuevo al más antiguo.
	 *
	 * `items[].data` es la lista blanca de `DATA_FIELDS`; basta para componer el texto en el BFF
	 * con el idioma de la petición. Un usuario que no participa recibe una página vacía.
	 */
	public static function handleTransactions( $request ): WP_REST_Response {
		$userId = self::userId( $request );
		if ( $userId <= 0 ) {
			return new WP_REST_Response( array( 'error' => 'invalid_request' ), 400 );
		}

		if ( ! HWE_Loyalty_Settings::canParticipate( $userId ) ) {
			return new WP_REST_Response(
				array( 'items' => array(), 'total' => 0, 'pages' => 0 ),
				200
			);
		}

		list( $page, $perPage ) = self::pagination( $request );
		$history = HWE_Loyalty_Ledger::history( $userId, $page, $perPage );

		$items = array();
		foreach ( (array) ( $history['items'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$items[] = array(
				'id'         => (int) ( $item['id'] ?? 0 ),
				'type'       => is_string( $item['type'] ?? null ) ? $item['type'] : '',
				'points'     => (int) ( $item['points'] ?? 0 ),
				'data'       => self::publicData( $item ),
				'created_at' => isset( $item['created_at'] ) && is_string( $item['created_at'] ) ? $item['created_at'] : null,
				'expires_at' => isset( $item['expires_at'] ) && is_string( $item['expires_at'] ) ? $item['expires_at'] : null,
			);
		}

		$total = max( 0, (int) ( $history['total'] ?? 0 ) );

		return new WP_REST_Response(
			array(
				'items' => $items,
				'total' => $total,
				'pages' => self::pages( $total, $perPage ),
			),
			200
		);
	}

	/**
	 * Red N1/N2 paginada más los totales. Solo viajan nombre visible, fecha, estado y puntos
	 * generados: ni `user_id`, ni email, ni login.
	 *
	 * Con `level=2` y `level2_enabled` apagado se devuelve una página vacía (200), no un 404: la
	 * ruta sigue existiendo y los totales conservan el histórico de la red. Decisión documentada
	 * en el README del plugin.
	 */
	public static function handleReferrals( $request ): WP_REST_Response {
		$userId = self::userId( $request );
		if ( $userId <= 0 ) {
			return new WP_REST_Response( array( 'error' => 'invalid_request' ), 400 );
		}

		$level = self::level( $request );
		if ( 0 === $level ) {
			return new WP_REST_Response( array( 'error' => 'invalid_level' ), 400 );
		}

		if ( ! HWE_Loyalty_Settings::canParticipate( $userId ) ) {
			return new WP_REST_Response(
				array(
					'items'  => array(),
					'total'  => 0,
					'pages'  => 0,
					'totals' => self::zeroTotals(),
				),
				200
			);
		}

		list( $page, $perPage ) = self::pagination( $request );

		$settings = HWE_Loyalty_Settings::get();
		if ( 2 === $level && empty( $settings['level2_enabled'] ) ) {
			$network = array( 'items' => array(), 'total' => 0 );
		} else {
			$network = HWE_Loyalty_Referrals::network( $userId, $level, $page, $perPage );
		}

		$items = array();
		foreach ( (array) ( $network['items'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$items[] = array(
				'name'             => is_string( $item['name'] ?? null ) ? $item['name'] : '',
				'date'             => isset( $item['date'] ) && is_string( $item['date'] ) ? $item['date'] : null,
				'status'           => is_string( $item['status'] ?? null ) ? $item['status'] : '',
				'points_generated' => (int) ( $item['points_generated'] ?? 0 ),
			);
		}

		$total = max( 0, (int) ( $network['total'] ?? 0 ) );

		return new WP_REST_Response(
			array(
				'items'  => $items,
				'total'  => $total,
				'pages'  => self::pages( $total, $perPage ),
				'totals' => HWE_Loyalty_Referrals::networkTotals( $userId ),
			),
			200
		);
	}

	// ------------------------------------------------------------------------
	//  Saneado de la entrada y de la salida
	// ------------------------------------------------------------------------

	/** `user_id` obligatorio y > 0; 0 si falta o no es numérico. */
	private static function userId( $request ): int {
		$raw = $request->get_param( 'user_id' );

		return is_scalar( $raw ) && is_numeric( $raw ) ? (int) $raw : 0;
	}

	/** `level=1|2`; sin parámetro, 1. Un valor fuera de rango devuelve 0 (entrada inválida). */
	private static function level( $request ): int {
		$raw = $request->get_param( 'level' );
		if ( null === $raw || '' === $raw ) {
			return 1;
		}
		if ( ! is_scalar( $raw ) ) {
			return 0;
		}

		$value = trim( (string) $raw );

		return in_array( $value, array( '1', '2' ), true ) ? (int) $value : 0;
	}

	/**
	 * Página ≥ 1 y tamaño entre 1 y `PER_PAGE_MAX`. Sin `per_page`, `PER_PAGE_DEFAULT`.
	 *
	 * @return array{0:int,1:int}
	 */
	private static function pagination( $request ): array {
		$rawPage = $request->get_param( 'page' );
		$page    = is_scalar( $rawPage ) && is_numeric( $rawPage ) ? (int) $rawPage : 1;
		$page    = max( 1, $page );

		$rawPerPage = $request->get_param( 'per_page' );
		if ( null === $rawPerPage || '' === $rawPerPage ) {
			$perPage = self::PER_PAGE_DEFAULT;
		} else {
			$perPage = is_scalar( $rawPerPage ) && is_numeric( $rawPerPage )
				? (int) $rawPerPage
				: self::PER_PAGE_DEFAULT;
			$perPage = max( 1, min( self::PER_PAGE_MAX, $perPage ) );
		}

		return array( $page, $perPage );
	}

	/** Total de páginas; 0 sin resultados. */
	private static function pages( int $total, int $perPage ): int {
		return $total > 0 ? (int) ceil( $total / max( 1, $perPage ) ) : 0;
	}

	/** Totales a cero para quien no participa. @return array{level1:int,level2:int,pending:int,earned:int} */
	private static function zeroTotals(): array {
		return array( 'level1' => 0, 'level2' => 0, 'pending' => 0, 'earned' => 0 );
	}

	/**
	 * `data` público de un movimiento: solo las claves de `DATA_FIELDS` para su tipo, el pedido
	 * tomado de la referencia y, en las transferencias, el nombre visible de la otra parte.
	 *
	 * @param array<string,mixed> $item Fila de `HWE_Loyalty_Ledger::history()`.
	 * @return array<string,mixed>
	 */
	private static function publicData( array $item ): array {
		$type = is_string( $item['type'] ?? null ) ? $item['type'] : '';
		$raw  = is_array( $item['data'] ?? null ) ? $item['data'] : array();
		$ref  = is_array( $item['ref'] ?? null ) ? $item['ref'] : array();
		$out  = array();

		if ( in_array( $type, self::TRANSFER_TYPES, true ) ) {
			$counterpart = 'transfer_in' === $type ? ( $raw['from'] ?? null ) : ( $raw['to'] ?? null );
			$name        = is_numeric( $counterpart ) ? self::displayName( (int) $counterpart ) : '';
			if ( '' !== $name ) {
				$out['counterpart_name'] = $name;
			}
		}

		foreach ( self::DATA_FIELDS[ $type ] ?? array() as $key => $cast ) {
			if ( ! isset( $raw[ $key ] ) || ! is_scalar( $raw[ $key ] ) ) {
				continue;
			}
			$out[ $key ] = 'int' === $cast ? (int) $raw[ $key ] : (string) $raw[ $key ];
		}

		if ( in_array( $type, self::ORDER_TYPES, true ) && ! isset( $out['order'] ) ) {
			$orderRef = isset( $ref['type'], $ref['id'] ) && 'order' === $ref['type'] ? $ref['id'] : null;
			if ( is_numeric( $orderRef ) ) {
				$out['order'] = (int) $orderRef;
			}
		}

		if ( in_array( $type, array( 'admin_credit', 'admin_debit' ), true ) ) {
			$note = isset( $item['note'] ) && is_scalar( $item['note'] ) ? trim( (string) $item['note'] ) : '';
			if ( '' !== $note ) {
				$out['note'] = $note;
			}
		}

		return $out;
	}

	/** Nombre visible de un usuario; vacío si la cuenta ya no existe. Nunca email ni login. */
	private static function displayName( int $userId ): string {
		if ( $userId <= 0 || ! function_exists( 'get_userdata' ) ) {
			return '';
		}

		$user = get_userdata( $userId );

		return is_object( $user ) && isset( $user->display_name ) ? trim( (string) $user->display_name ) : '';
	}
}
