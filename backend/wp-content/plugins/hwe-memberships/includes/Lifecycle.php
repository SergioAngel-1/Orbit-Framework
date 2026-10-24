<?php
/**
 * Ciclo de vida del módulo al activar o desactivar el plugin (A-3).
 *
 * Con el plugin inactivo no queda ninguna compuerta de compra: `Products::gatePurchasable`, las
 * validaciones de `Purchase` y la activación al cobrar viven solo aquí. Para que un producto de
 * membresía no siga a la venta y se cobre sin conceder nada, la desactivación lo retira de la
 * venta (`draft`) y recuerda en una opción su estado anterior; la activación restaura
 * exactamente esos —si nadie los cambió a mano— y concilia los pedidos cobrados durante el
 * apagón con las claves de idempotencia por línea que ya usa `Purchase`.
 *
 * Reglas:
 *  · Desactivar **no borra** el vínculo producto ↔ nivel: al encender el módulo el producto
 *    vuelve a venderse.
 *  · Restaurar solo toca los productos que siguen en el estado que puso este código (`draft`);
 *    un cambio manual del administrador (publicado, privado, papelera…) se respeta.
 *  · La conciliación usa `wc_get_orders` (compatible con HPOS), filtra por fecha de cobro y va
 *    en lotes con un techo por activación. `Purchase::processOrder` ya es idempotente por línea,
 *    así que repetir la conciliación no concede dos veces.
 *  · Ningún paso bloquea la activación ni la desactivación: un fallo se registra con
 *    `error_log('[hwe] memberships: …')` y se continúa con el resto.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Lifecycle {

	/** Opción con los productos retirados y su estado anterior (`id => estado`). */
	public const OPTION_HIDDEN = 'hwe_memberships_hidden_products';

	/** Opción con el instante (timestamp UTC) de la última desactivación registrada. */
	public const OPTION_DEACTIVATED_AT = 'hwe_memberships_deactivated_at';

	/** Estado al que se llevan los productos de membresía mientras el plugin está apagado. */
	private const HIDDEN_STATUS = 'draft';

	/** Pedidos que se piden por lote en la conciliación. */
	private const ORDER_BATCH = 50;

	/** Lotes máximos por activación: acota el tiempo de la petición de activación. */
	private const MAX_ORDER_BATCHES = 10;

	// ------------------------------------------------------------------------
	//  Hooks de activación y desactivación
	// ------------------------------------------------------------------------

	/**
	 * Desactivación: retira de la venta los productos de membresía publicados y recuerda el
	 * instante para conciliar después los pedidos cobrados en el apagón.
	 */
	public static function onDeactivate(): void {
		// El instante se anota ANTES de tocar productos: cualquier cobro que ocurra mientras se
		// apagan (o justo después) entra en la ventana de conciliación.
		self::safely(
			'recordar la desactivación',
			static function (): void {
				self::rememberDeactivation();
			}
		);
		self::safely(
			'retirar de la venta los productos de membresía',
			static function (): void {
				self::retireProducts();
			}
		);
		self::revalidate();
	}

	/**
	 * Activación: devuelve a la venta los productos que retiró este código y concilia los
	 * pedidos cobrados desde la desactivación registrada.
	 */
	public static function onActivate(): void {
		self::safely(
			'restaurar los productos de membresía',
			static function (): void {
				self::restoreProducts();
			}
		);

		$since = (int) get_option( self::OPTION_DEACTIVATED_AT, 0 );
		if ( $since > 0 ) {
			self::safely(
				'conciliar los pedidos cobrados durante el apagón',
				static function () use ( $since ): void {
					self::reconcileOrders( $since );
				}
			);
		}

		self::revalidate();
	}

	// ------------------------------------------------------------------------
	//  Productos
	// ------------------------------------------------------------------------

	/**
	 * Pasa a borrador los productos de membresía publicados y guarda su estado anterior. Si ya
	 * había una desactivación registrada (doble aviso sin activación de por medio) se conserva
	 * el estado original y el instante más antiguo.
	 */
	private static function retireProducts(): void {
		if ( ! class_exists( 'HWE_Memberships_Products' ) ) {
			self::log( 'no se pudieron retirar los productos de membresía: falta la clase Products.' );
			return;
		}

		$ids    = self::publishedMembershipProductIds();
		$hidden = self::hiddenProducts();

		foreach ( $ids as $productId ) {
			try {
				$product = self::product( $productId );
				if ( null === $product ) {
					continue;
				}
				if ( 'publish' !== (string) $product->get_status() ) {
					continue;
				}

				$product->set_status( self::HIDDEN_STATUS );
				$product->save();

				if ( ! isset( $hidden[ $productId ] ) ) {
					$hidden[ $productId ] = 'publish';
				}
			} catch ( \Throwable $e ) {
				self::log( 'no se pudo retirar de la venta el producto ' . $productId . ': ' . $e->getMessage() );
			}
		}

		if ( [] !== $hidden && ! self::storeOption( self::OPTION_HIDDEN, $hidden ) ) {
			self::log( 'no se pudo guardar la lista de productos de membresía retirados.' );
		}
	}

	/**
	 * Devuelve a su estado anterior los productos de la lista, solo si siguen en el estado que
	 * puso este código. Un producto borrado o cambiado a mano no se toca.
	 */
	private static function restoreProducts(): void {
		$hidden = self::hiddenProducts();
		if ( [] === $hidden ) {
			self::deleteOption( self::OPTION_HIDDEN );
			return;
		}

		$failed = false;

		foreach ( $hidden as $productId => $previousStatus ) {
			try {
				$product = self::product( $productId );
				if ( null === $product ) {
					// Producto borrado mientras el módulo estaba apagado: no hay nada que restaurar.
					continue;
				}
				if ( self::HIDDEN_STATUS !== (string) $product->get_status() ) {
					// El administrador lo cambió a mano: no se pisa su decisión.
					continue;
				}

				$product->set_status( (string) $previousStatus );
				$product->save();
			} catch ( \Throwable $e ) {
				$failed = true;
				self::log( 'no se pudo restaurar el producto ' . $productId . ': ' . $e->getMessage() );
			}
		}

		if ( ! $failed ) {
			self::deleteOption( self::OPTION_HIDDEN );
		}
	}

	/**
	 * Ids de los productos publicados con vínculo de membresía. La consulta no carga los objetos:
	 * solo devuelve ids para no recorrer el catálogo entero.
	 *
	 * @return list<int>
	 */
	private static function publishedMembershipProductIds(): array {
		if ( ! function_exists( 'get_posts' ) ) {
			self::log( 'no se pudieron buscar los productos de membresía: falta get_posts.' );
			return array();
		}

		try {
			$ids = get_posts( array(
				'post_type'        => 'product',
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'meta_key'         => HWE_Memberships_Products::LEVEL_META,
				'meta_compare'     => 'EXISTS',
				'no_found_rows'    => true,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,
			) );
		} catch ( \Throwable $e ) {
			self::log( 'la búsqueda de productos de membresía falló: ' . $e->getMessage() );
			return array();
		}

		if ( ! is_array( $ids ) ) {
			self::log( 'la búsqueda de productos de membresía no devolvió una lista; no se retira ninguno.' );
			return array();
		}

		$out = array();
		foreach ( $ids as $id ) {
			$id = is_scalar( $id ) ? (int) $id : 0;
			if ( $id > 0 ) {
				$out[] = $id;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/** @return array<int,string> */
	private static function hiddenProducts(): array {
		$raw = get_option( self::OPTION_HIDDEN, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $productId => $status ) {
			$productId = (int) $productId;
			$status    = is_scalar( $status ) ? (string) $status : '';
			if ( $productId > 0 && '' !== $status ) {
				$out[ $productId ] = $status;
			}
		}

		return $out;
	}

	/** Evita repetir el aviso de WooCommerce ausente en cada producto. */
	private static bool $loggedMissingWooCommerce = false;

	/** Producto de WooCommerce, o `null` si no existe o no se puede cargar. */
	private static function product( int $productId ): ?object {
		if ( $productId <= 0 || ! function_exists( 'wc_get_product' ) ) {
			if ( ! self::$loggedMissingWooCommerce ) {
				self::$loggedMissingWooCommerce = true;
				self::log( 'WooCommerce no está disponible: no se pueden tocar los productos de membresía.' );
			}
			return null;
		}

		$product = wc_get_product( $productId );

		return is_object( $product )
			&& method_exists( $product, 'get_status' )
			&& method_exists( $product, 'set_status' )
			&& method_exists( $product, 'save' )
			? $product
			: null;
	}

	// ------------------------------------------------------------------------
	//  Conciliación de pedidos
	// ------------------------------------------------------------------------

	/** Guarda el instante de la desactivación. Si ya había uno, conserva el más antiguo. */
	private static function rememberDeactivation(): void {
		$now      = function_exists( 'time' ) ? time() : 0;
		$previous = (int) get_option( self::OPTION_DEACTIVATED_AT, 0 );

		if ( $previous > 0 && ( $now <= 0 || $previous <= $now ) ) {
			return;
		}

		if ( $now <= 0 ) {
			self::log( 'no se pudo registrar el instante de la desactivación.' );
			return;
		}

		if ( ! self::storeOption( self::OPTION_DEACTIVATED_AT, $now ) ) {
			self::log( 'no se pudo guardar el instante de la desactivación.' );
		}
	}

	/**
	 * Recorre los pedidos cobrados desde `$since` en lotes y deja que `Purchase::processOrder`
	 * conceda lo que falte. Es idempotente: los pedidos ya procesados se saltan por su estado y
	 * las claves por línea no crean una segunda concesión.
	 */
	private static function reconcileOrders( int $since ): void {
		if ( $since <= 0 ) {
			return;
		}
		if ( ! function_exists( 'wc_get_orders' ) || ! class_exists( 'HWE_Memberships_Purchase' ) ) {
			self::log( 'no se pudo conciliar pedidos: falta wc_get_orders o la clase Purchase.' );
			return;
		}

		// En la petición de activación el plugin acaba de activarse y `plugins_loaded` ya pasó:
		// el arranque normal no ha registrado los beneficios. Sin esto, la concesión se crearía
		// pero los puntos por activación no se abonarían (ni quedaría `points_skipped`).
		self::ensureBenefitHooks();

		$statuses = function_exists( 'wc_get_is_paid_statuses' )
			? (array) wc_get_is_paid_statuses()
			: array( 'processing', 'completed' );
		$statuses = array_values( array_filter( array_map(
			static fn ( mixed $status ): string => is_scalar( $status ) ? (string) $status : '',
			$statuses
		) ) );
		if ( [] === $statuses ) {
			$statuses = array( 'processing', 'completed' );
		}

		/** @var array<int,true> $seen */
		$seen      = array();
		$batches   = 0;
		$lastCount = 0;
		$ok        = true;

		do {
			try {
				$ids = self::orderIds( $since, $statuses, $seen );
			} catch ( \Throwable $e ) {
				self::log( 'la consulta de pedidos cobrados para conciliar falló: ' . $e->getMessage() );
				$ok = false;
				break;
			}

			$lastCount = count( $ids );
			if ( 0 === $lastCount ) {
				break;
			}

			foreach ( $ids as $orderId ) {
				$seen[ $orderId ] = true;
				try {
					HWE_Memberships_Purchase::processOrder( $orderId );
				} catch ( \Throwable $e ) {
					$ok = false;
					self::log( 'la conciliación del pedido ' . $orderId . ' falló: ' . $e->getMessage() );
				}
			}

			++$batches;
		} while ( $batches < self::MAX_ORDER_BATCHES && $lastCount >= self::ORDER_BATCH );

		$truncated = $batches >= self::MAX_ORDER_BATCHES && $lastCount >= self::ORDER_BATCH;

		if ( $truncated ) {
			self::log( 'la conciliación alcanzó el tope de ' . ( self::MAX_ORDER_BATCHES * self::ORDER_BATCH ) . ' pedidos en la activación; quedan pedidos cobrados por conciliar.' );
		}

		if ( $ok && ! $truncated ) {
			self::deleteOption( self::OPTION_DEACTIVATED_AT );
			return;
		}

		if ( $ok ) {
			self::log( 'se conserva el instante de la desactivación para conciliar en una próxima activación los pedidos que quedaron fuera del tope.' );
			return;
		}

		self::log( 'la conciliación de pedidos terminó con errores; se conserva el instante de la desactivación para reintentarla.' );
	}

	/**
	 * Ids del siguiente lote: pedidos con fecha de cobro o de creación dentro de la ventana,
	 * en estados pagados y sin repetir los ya vistos. Dos consultas acotadas cubren tanto las
	 * compras nuevas como un pedido anterior cobrado durante el apagón.
	 *
	 * @param list<string>    $statuses
	 * @param array<int,true> $seen
	 * @return list<int>
	 */
	private static function orderIds( int $since, array $statuses, array $seen ): array {
		$base = array(
			'status'  => $statuses,
			'limit'   => self::ORDER_BATCH,
			'return'  => 'ids',
			'orderby' => 'date',
			'order'   => 'ASC',
			'exclude' => array_keys( $seen ),
		);

		$paid = wc_get_orders( $base + array( 'date_paid' => '>=' . $since ) );
		$new  = wc_get_orders( $base + array( 'date_created' => '>=' . $since ) );

		$ids = array();
		foreach ( array( $paid, $new ) as $result ) {
			if ( ! is_array( $result ) ) {
				continue;
			}
			foreach ( $result as $id ) {
				$id = is_scalar( $id ) ? (int) $id : 0;
				if ( $id > 0 && ! isset( $seen[ $id ] ) ) {
					$ids[ $id ] = true;
				}
			}
		}

		return array_keys( $ids );
	}

	// ------------------------------------------------------------------------
	//  Revalidación y utilidades
	// ------------------------------------------------------------------------

	/**
	 * Revalida los tags del catálogo con el helper del Control Center. En la activación el
	 * plugin aún no ha arrancado y puede que solo esté registrado `products`; se envía lo que
	 * acepte la lista de tags y no se inventa nada.
	 */
	private static function revalidate(): void {
		if ( ! class_exists( '\HWE\ControlCenter\Revalidation' ) ) {
			self::log( 'no se pudo revalidar el catálogo tras el cambio de ciclo de vida: falta el Control Center.' );
			return;
		}

		try {
			$allowed = \HWE\ControlCenter\Revalidation::allowedTags();
			foreach ( array( 'products', 'memberships' ) as $tag ) {
				if ( in_array( $tag, $allowed, true ) ) {
					\HWE\ControlCenter\Revalidation::trigger( $tag );
				}
			}
		} catch ( \Throwable $e ) {
			self::log( 'la revalidación del catálogo tras el cambio de ciclo de vida falló: ' . $e->getMessage() );
		}
	}

	/** Ejecuta un paso del ciclo de vida sin dejar que su fallo aborte los demás. */
	private static function safely( string $what, callable $step ): void {
		try {
			$step();
		} catch ( \Throwable $e ) {
			self::log( 'no se pudo ' . $what . ': ' . $e->getMessage() );
		}
	}

	/**
	 * Registra los hooks de beneficios si el arranque normal no se ha ejecutado (activación).
	 * Con el plugin ya arrancado no se toca nada: registrar dos veces duplicaría abonos.
	 */
	private static function ensureBenefitHooks(): void {
		if ( class_exists( 'HWE_Memberships' ) && HWE_Memberships::booted() ) {
			return;
		}

		try {
			if ( class_exists( 'HWE_Memberships_LoyaltyBridge' ) ) {
				HWE_Memberships_LoyaltyBridge::register();
			}
			if ( class_exists( 'HWE_Memberships_Benefits' ) ) {
				HWE_Memberships_Benefits::register();
			}
		} catch ( \Throwable $e ) {
			self::log( 'no se pudieron registrar los beneficios para conciliar los pedidos: ' . $e->getMessage() );
		}
	}

	private static function storeOption( string $name, mixed $value ): bool {
		if ( ! function_exists( 'update_option' ) ) {
			return false;
		}

		// `update_option()` devuelve `false` cuando el valor no cambia; no es un fallo.
		if ( function_exists( 'get_option' ) && get_option( $name, null ) === $value ) {
			return true;
		}

		return (bool) update_option( $name, $value, false );
	}

	private static function deleteOption( string $name ): void {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( $name );
		}
	}

	private static function log( string $message ): void {
		error_log( '[hwe] memberships: ' . $message );
	}
}
