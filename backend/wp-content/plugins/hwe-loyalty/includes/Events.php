<?php
/**
 * Ganancias por eventos: reseñas y cumpleaños (spec §6.3 y §5.5).
 *
 * **Reseña de producto.** En el framework las reseñas nacen aprobadas y exigen un pedido
 * completado que contenga el producto, pero la reseña no lleva número de pedido: el BFF la
 * permite sobre el pedido COMPLETADO más reciente del cliente que contiene el producto
 * (`findProductPurchase` en `frontend/src/lib/account/data.ts`). Aquí se resuelve el pedido
 * igual (pedidos `completed` del autor, del más reciente al más antiguo, por páginas acotadas)
 * y se abona con la clave `review:order:<id>`: **un pedido reseñado = un abono**, aunque la
 * misma reseña se publique en varios productos y aunque llegue por la reseña del sitio.
 *
 * **Autor.** WooCommerce inserta la reseña con `wp_insert_comment` SIN `user_id` (queda 0) y
 * fija el estado después; el autor se resuelve por `comment_author_email` con
 * `get_user_by('email')`. Si no hay usuario es una reseña de invitado y no abona (se registra
 * con los ids, sin PII).
 *
 * **Hooks elegidos.** `wp_insert_comment` cubre la reseña que nace aprobada y
 * `transition_comment_status` cubre la que nace pendiente y se aprueba después (incluida una
 * que vuelve de spam o papelera). Se comprueban las dos en vez de una acción específica de
 * WooCommerce: el flujo de inserción cambia con la versión y `wp_insert_comment` es el punto
 * estable donde ya existe el comentario. La idempotencia por clave hace inofensiva una
 * doble notificación.
 *
 * **Reseña del sitio.** `hwe-site-reviews` dispara `hwe_site_review_published( reviewId,
 * userId, orderId )` cuando una reseña con autor pasa a publicada (al crearla o al
 * republicarla). Se abona con la MISMA clave por pedido: un pedido reseñado abona una vez,
 * venga por producto o por sitio.
 *
 * **Cumpleaños.** Callback propio de `hwe_loyalty_daily`: una vez por año natural del sitio
 * (`current_datetime()` / `wp_timezone()`) para los usuarios con meta `hwe_birthday` igual a
 * hoy. Acepta `MM-DD` y `AAAA-MM-DD`; un valor inválido se ignora y se registra. La consulta
 * va por lotes acotados con `meta_query` (nunca se cargan todos los usuarios en memoria) y la
 * clave `birthday:<usuario>:<año>` hace idempotente la pasada.
 *
 * **29 de febrero.** En un año no bisiesto, quien cumple el 29-02 se abona el 28-02 (decisión
 * documentada): el 28 se consideran los dos días y el 29 de un año bisiesto solo se paga ese
 * día. El 28 de un año bisiesto NO adelanta a los del 29.
 *
 * Todo exige `pointsActive()` y una cuenta participante (`canParticipate`); un importe 0 en
 * `events.review` / `events.birthday` apaga el evento sin abonar ni dejar rastro. El abono de
 * reseña deja una nota privada en el pedido (trazabilidad para el equipo) con el nombre de la
 * unidad de los ajustes, nunca escrito a mano.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Loyalty_Events {

	/** Meta del usuario con su cumpleaños (`MM-DD` o `AAAA-MM-DD`). */
	public const BIRTHDAY_META = 'hwe_birthday';

	/** Usuarios que se piden por lote en la consulta de cumpleaños. */
	public const BIRTHDAY_BATCH = 200;

	/** Lotes por ejecución: acota una pasada diaria a 5.000 usuarios. */
	public const BIRTHDAY_MAX_BATCHES = 25;

	/** Pedidos por página y páginas máximas al resolver el pedido de una reseña. */
	private const ORDER_PAGE_SIZE = 100;
	private const ORDER_MAX_PAGES = 20;

	/** Meta de línea que la excluye de las reseñas de producto (la respeta el BFF). */
	private const NOT_REVIEWABLE_LINE_META = '_hwe_not_reviewable';

	/** Engancha los eventos. Lo llama `HWE_Loyalty::boot()`. */
	public static function register(): void {
		add_action( 'wp_insert_comment', array( self::class, 'onCommentInserted' ), 10, 2 );
		add_action( 'transition_comment_status', array( self::class, 'onCommentTransition' ), 10, 3 );
		add_action( 'hwe_site_review_published', array( self::class, 'onSiteReviewPublished' ), 10, 3 );
		add_action( HWE_Loyalty_Scheduler::HOOK, array( self::class, 'runBirthdays' ) );
	}

	// ------------------------------------------------------------------------
	//  Reseñas de producto
	// ------------------------------------------------------------------------

	/**
	 * Reseña recién insertada: si nace aprobada, abona; si nace pendiente, espera a la
	 * transición a aprobada. `$comment` llega como objeto desde WordPress.
	 *
	 * @param mixed $comment
	 */
	public static function onCommentInserted( int $commentId, $comment ): void {
		$comment = self::comment( $comment, $commentId );
		if ( null === $comment || ! self::isProductReview( $comment ) || ! self::isApproved( $comment->comment_approved ) ) {
			return;
		}

		self::creditProductReview( $commentId, $comment );
	}

	/**
	 * Transición de estado de un comentario: solo interesa la que termina en `approved`
	 * (pendiente, spam o papelera → aprobada). `$comment` trae el estado ANTERIOR.
	 *
	 * @param mixed $comment
	 */
	public static function onCommentTransition( string $newStatus, string $oldStatus, $comment ): void {
		if ( 'approved' !== $newStatus || 'approved' === $oldStatus ) {
			return;
		}

		$comment = self::comment( $comment, is_object( $comment ) && isset( $comment->comment_ID ) ? (int) $comment->comment_ID : 0 );
		if ( null === $comment || ! self::isProductReview( $comment ) ) {
			return;
		}

		self::creditProductReview( (int) ( $comment->comment_ID ?? 0 ), $comment );
	}

	/**
	 * Resuelve autor y pedido de una reseña de producto y abona.
	 *
	 * @param object $comment
	 */
	private static function creditProductReview( int $commentId, object $comment ): void {
		// Evento apagado: no se resuelve ni el autor ni el pedido (ni se deja nota).
		$points = self::reviewPoints();
		if ( $points <= 0 ) {
			return;
		}

		$userId = (int) ( $comment->user_id ?? 0 );
		if ( $userId <= 0 ) {
			$email = is_string( $comment->comment_author_email ?? null )
				? trim( (string) $comment->comment_author_email )
				: '';
			$user  = '' !== $email && function_exists( 'get_user_by' ) ? get_user_by( 'email', $email ) : false;
			if ( ! is_object( $user ) || empty( $user->ID ) ) {
				self::log( 'la reseña de producto ' . $commentId . ' no tiene usuario con ese correo; no se abona.' );
				return;
			}
			$userId = (int) $user->ID;
		}

		$productId = (int) ( $comment->comment_post_ID ?? 0 );
		$orderId   = self::orderForProduct( $userId, $productId );
		if ( null === $orderId ) {
			self::log(
				'la reseña de producto ' . $commentId . ' del usuario ' . $userId
				. ' y el producto ' . $productId . ' no tiene un pedido completado resoluble; no se abona.'
			);
			return;
		}

		self::creditReview( $userId, $commentId, $orderId, 'product', $points );
	}

	/**
	 * Pedido completado más reciente del cliente que contiene el producto, del más nuevo al
	 * más antiguo y por páginas acotadas (no se mira todo el historial si no hace falta).
	 * Devuelve el id o `null` si no hay ninguno.
	 */
	private static function orderForProduct( int $userId, int $productId ): ?int {
		if ( $userId <= 0 || $productId <= 0 ) {
			return null;
		}
		if ( ! function_exists( 'wc_get_orders' ) ) {
			self::log( 'wc_get_orders no está disponible; no se puede resolver el pedido de la reseña.' );
			return null;
		}

		for ( $page = 1; $page <= self::ORDER_MAX_PAGES; $page++ ) {
			$orders = wc_get_orders(
				array(
					'customer_id' => $userId,
					'status'      => array( 'completed' ),
					'limit'       => self::ORDER_PAGE_SIZE,
					'page'        => $page,
					'orderby'     => 'date',
					'order'       => 'DESC',
					'return'      => 'objects',
				)
			);

			if ( ! is_array( $orders ) || array() === $orders ) {
				return null;
			}

			foreach ( $orders as $order ) {
				if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) || ! method_exists( $order, 'get_items' ) ) {
					continue;
				}
				foreach ( (array) $order->get_items( 'line_item' ) as $item ) {
					if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
						continue;
					}
					if ( (int) $item->get_product_id() !== $productId ) {
						continue;
					}
					if ( self::lineIsNotReviewable( $item ) ) {
						continue;
					}

					return (int) $order->get_id();
				}
			}

			// Una página incompleta significa que ya no quedan pedidos que mirar.
			if ( count( $orders ) < self::ORDER_PAGE_SIZE ) {
				return null;
			}
		}

		self::log(
			'la búsqueda del pedido de la reseña del usuario ' . $userId
			. ' y el producto ' . $productId . ' alcanzó el tope de páginas.'
		);

		return null;
	}

	/**
	 * ¿La línea está excluida de las reseñas de producto? (misma marca que respeta el BFF: basta
	 * con que la meta exista, aunque su valor sea vacío).
	 */
	private static function lineIsNotReviewable( object $item ): bool {
		if ( ! method_exists( $item, 'get_meta' ) ) {
			return false;
		}

		$values = $item->get_meta( self::NOT_REVIEWABLE_LINE_META, false );

		return is_array( $values ) ? array() !== $values : (bool) $values;
	}

	// ------------------------------------------------------------------------
	//  Reseña del sitio
	// ------------------------------------------------------------------------

	/**
	 * Acción estable de `hwe-site-reviews`: la reseña del sitio ya está publicada y tiene
	 * autor y pedido. Abona con la misma clave por pedido que la reseña de producto.
	 */
	public static function onSiteReviewPublished( int $reviewId, int $userId, int $orderId ): void {
		// Evento apagado: silencio total, como en la reseña de producto.
		$points = self::reviewPoints();
		if ( $points <= 0 ) {
			return;
		}

		if ( $reviewId <= 0 || $userId <= 0 || $orderId <= 0 ) {
			self::log(
				'la reseña del sitio ' . $reviewId . ' del usuario ' . $userId
				. ' y el pedido ' . $orderId . ' no tiene los ids necesarios; no se abona.'
			);
			return;
		}

		self::creditReview( $userId, $reviewId, $orderId, 'site', $points );
	}

	// ------------------------------------------------------------------------
	//  Abono de una reseña
	// ------------------------------------------------------------------------

	/**
	 * Abona `events.review` una vez por pedido. La clave compartida entre la reseña de producto
	 * y la del sitio es la que garantiza que un pedido reseñado pague una sola vez.
	 */
	private static function creditReview( int $userId, int $reviewId, int $orderId, string $source, int $points ): void {
		if ( ! HWE_Loyalty_Settings::canParticipate( $userId ) ) {
			return;
		}

		$entryId = HWE_Loyalty_Ledger::credit(
			$userId,
			$points,
			'review',
			'review:order:' . $orderId,
			array( 'type' => 'order', 'id' => $orderId ),
			null,
			array( 'review_id' => $reviewId, 'source' => $source )
		);

		// `null` = la clave ya existía (otra reseña del mismo pedido) o el libro la rechazó.
		if ( null === $entryId ) {
			return;
		}

		self::noteReview( $orderId, $userId, $points, HWE_Loyalty_Settings::get() );
	}

	/** Puntos del evento de reseña; 0 = apagado (ni con el programa de puntos activo). */
	private static function reviewPoints(): int {
		if ( ! HWE_Loyalty_Settings::pointsActive() ) {
			return 0;
		}

		$settings = HWE_Loyalty_Settings::get();

		return (int) ( $settings['events']['review'] ?? 0 );
	}

	/**
	 * Nota privada en el pedido con la trazabilidad del abono. La unidad sale de los ajustes.
	 *
	 * @param array<string,mixed> $settings
	 */
	private static function noteReview( int $orderId, int $userId, int $points, array $settings ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $orderId );
		if ( ! is_object( $order ) || ! method_exists( $order, 'add_order_note' ) ) {
			self::log( 'no se pudo anotar en el pedido ' . $orderId . ' el abono por reseña.' );
			return;
		}

		$order->add_order_note(
			sprintf(
				'Fidelización: %d %s al usuario #%d por una reseña (pedido #%d).',
				$points,
				self::unitName( $settings ),
				$userId,
				$orderId
			),
			false
		);
	}

	// ------------------------------------------------------------------------
	//  Cumpleaños
	// ------------------------------------------------------------------------

	/**
	 * Abona `events.birthday` a los usuarios que cumplen años hoy en la zona del sitio.
	 * Callback de `hwe_loyalty_daily`; devuelve cuántos abonos nuevos hizo (WordPress ignora
	 * el valor, se devuelve para probarlo).
	 */
	public static function runBirthdays(): int {
		if ( ! HWE_Loyalty_Settings::pointsActive() ) {
			return 0;
		}

		$settings = HWE_Loyalty_Settings::get();
		$points   = (int) ( $settings['events']['birthday'] ?? 0 );
		if ( $points <= 0 ) {
			return 0;
		}

		if ( ! function_exists( 'get_users' ) || ! function_exists( 'get_user_meta' ) ) {
			self::log( 'no se puede consultar el cumpleaños: faltan las funciones de usuarios de WordPress.' );
			return 0;
		}

		$today   = self::siteNow();
		$year    = (int) $today->format( 'Y' );
		$targets = self::birthdayTargets( $today );
		$query   = self::birthdayQuery( $targets );
		$total   = 0;
		$offset  = 0;

		for ( $batch = 0; $batch < self::BIRTHDAY_MAX_BATCHES; $batch++ ) {
			$users = get_users(
				array(
					'fields'      => 'ID',
					'number'      => self::BIRTHDAY_BATCH,
					'offset'      => $offset,
					'orderby'     => 'ID',
					'order'       => 'ASC',
					'count_total' => false,
					'meta_query'  => $query,
				)
			);

			if ( ! is_array( $users ) || array() === $users ) {
				return $total;
			}

			foreach ( $users as $rawUserId ) {
				$userId = is_object( $rawUserId ) && isset( $rawUserId->ID )
					? (int) $rawUserId->ID
					: (int) $rawUserId;
				if ( $userId <= 0 ) {
					continue;
				}
				if ( self::creditBirthday( $userId, $year, $targets, $points ) ) {
					$total++;
				}
			}

			if ( count( $users ) < self::BIRTHDAY_BATCH ) {
				return $total;
			}

			$offset += self::BIRTHDAY_BATCH;
		}

		self::log(
			'el cumpleaños diario alcanzó el tope de '
			. ( self::BIRTHDAY_BATCH * self::BIRTHDAY_MAX_BATCHES )
			. ' usuarios en una ejecución; queda trabajo para la siguiente pasada.'
		);

		return $total;
	}

	/**
	 * Abona el cumpleaños de un usuario si su meta es válida y coincide con hoy, y si
	 * participa. Idempotente por año con `birthday:<usuario>:<año>`.
	 *
	 * @param list<string> $targets Días `MM-DD` que se abonan hoy (el 28-02 incluye el 29-02
	 *                              en años no bisiestos).
	 */
	private static function creditBirthday( int $userId, int $year, array $targets, int $points ): bool {
		$values = get_user_meta( $userId, self::BIRTHDAY_META, false );
		if ( is_scalar( $values ) ) {
			$values = array( $values );
		}
		if ( ! is_array( $values ) ) {
			$values = array();
		}

		$match   = false;
		$invalid = false;

		foreach ( $values as $value ) {
			$monthDay = self::birthdayMonthDay( $value );
			if ( null === $monthDay ) {
				$invalid = true;
				continue;
			}
			if ( in_array( $monthDay, $targets, true ) ) {
				$match = true;
				break;
			}
		}

		if ( ! $match ) {
			if ( $invalid ) {
				self::log( 'el cumpleaños del usuario ' . $userId . ' no tiene un formato válido; se ignora.' );
			}
			return false;
		}

		if ( ! HWE_Loyalty_Settings::canParticipate( $userId ) ) {
			return false;
		}

		$entryId = HWE_Loyalty_Ledger::credit(
			$userId,
			$points,
			'birthday',
			'birthday:' . $userId . ':' . $year,
			array(),
			null,
			array( 'year' => $year )
		);

		return null !== $entryId;
	}

	/**
	 * Días `MM-DD` que se abonan hoy. El 28-02 de un año no bisiesto incluye también el 29-02
	 * (decisión documentada); el 28 de un bisiesto, no.
	 *
	 * @return list<string>
	 */
	private static function birthdayTargets( \DateTimeInterface $today ): array {
		$monthDay = $today->format( 'm-d' );
		$targets  = array( $monthDay );

		if ( '02-28' === $monthDay && '1' !== $today->format( 'L' ) ) {
			$targets[] = '02-29';
		}

		return $targets;
	}

	/**
	 * `meta_query` de la consulta por lotes: valor exacto `MM-DD` o fecha completa que termina
	 * en `-MM-DD`. Los valores raros que el LIKE pueda colar se validan después.
	 *
	 * @param list<string> $targets
	 * @return array<string,mixed>
	 */
	private static function birthdayQuery( array $targets ): array {
		$query = array( 'relation' => 'OR' );

		foreach ( $targets as $monthDay ) {
			$query[] = array(
				'key'   => self::BIRTHDAY_META,
				'value' => $monthDay,
			);
			$query[] = array(
				'key'     => self::BIRTHDAY_META,
				'value'   => '-' . $monthDay,
				'compare' => 'LIKE',
			);
		}

		return $query;
	}

	/**
	 * Normaliza la meta `hwe_birthday` a `MM-DD`, o `null` si no es `MM-DD` ni `AAAA-MM-DD`
	 * con una fecha real. El año de nacimiento no importa: solo el día del año.
	 */
	private static function birthdayMonthDay( mixed $raw ): ?string {
		if ( ! is_scalar( $raw ) ) {
			return null;
		}

		$value = trim( (string) $raw );

		if ( 1 === preg_match( '/^(\d{2})-(\d{2})$/', $value, $matches ) ) {
			$month = (int) $matches[1];
			$day   = (int) $matches[2];
			// 2000 es bisiesto: así el 29-02 es un valor válido por sí mismo.
			if ( ! checkdate( $month, $day, 2000 ) ) {
				return null;
			}

			return sprintf( '%02d-%02d', $month, $day );
		}

		if ( 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches ) ) {
			$year  = (int) $matches[1];
			$month = (int) $matches[2];
			$day   = (int) $matches[3];
			if ( $year < 1 || ! checkdate( $month, $day, $year ) ) {
				return null;
			}

			return sprintf( '%02d-%02d', $month, $day );
		}

		return null;
	}

	/** «Ahora» en la zona del sitio, con la hora y el año naturales que usa el negocio. */
	private static function siteNow(): \DateTimeInterface {
		if ( function_exists( 'current_datetime' ) ) {
			$now = current_datetime();
			if ( $now instanceof \DateTimeInterface ) {
				return $now;
			}
		}

		if ( function_exists( 'wp_timezone' ) ) {
			$timezone = wp_timezone();
			if ( $timezone instanceof \DateTimeZone ) {
				return new \DateTimeImmutable( 'now', $timezone );
			}
		}

		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}

	// ------------------------------------------------------------------------
	//  Piezas privadas
	// ------------------------------------------------------------------------

	/** @param mixed $comment Comentario o id. */
	private static function comment( mixed $comment, int $commentId ): ?object {
		if ( is_object( $comment ) ) {
			return $comment;
		}
		if ( $commentId > 0 && function_exists( 'get_comment' ) ) {
			$loaded = get_comment( $commentId );

			return is_object( $loaded ) ? $loaded : null;
		}

		return null;
	}

	/** Solo comentarios de tipo `review` sobre un producto. */
	private static function isProductReview( object $comment ): bool {
		if ( 'review' !== (string) ( $comment->comment_type ?? '' ) ) {
			return false;
		}

		$postId = (int) ( $comment->comment_post_ID ?? 0 );
		if ( $postId <= 0 ) {
			return false;
		}

		if ( function_exists( 'get_post_type' ) && 'product' !== get_post_type( $postId ) ) {
			return false;
		}

		return true;
	}

	/** `comment_approved` puede llegar como `1`, `'1'` o `'approved'` según el hook. */
	private static function isApproved( mixed $approved ): bool {
		if ( is_bool( $approved ) ) {
			return $approved;
		}
		if ( is_int( $approved ) ) {
			return 1 === $approved;
		}

		return in_array( strtolower( trim( (string) ( is_scalar( $approved ) ? $approved : '' ) ) ), array( '1', 'approved', 'approve' ), true );
	}

	/** @param array<string,mixed> $settings */
	private static function unitName( array $settings ): string {
		$unit = $settings['currency_name_plural'] ?? '';

		return is_scalar( $unit ) && '' !== trim( (string) $unit )
			? trim( (string) $unit )
			: (string) HWE_Loyalty_Settings::DEFAULTS['currency_name_plural'];
	}

	private static function log( string $message ): void {
		error_log( '[hwe] loyalty: ' . $message );
	}
}
