<?php
/**
 * Plugin Name: HWE Site Reviews
 * Description: Reseñas del SITIO: la calificación que el cliente da a un pedido
 *              (estrellas + texto), distinta de las reseñas de producto de
 *              WooCommerce. Una por pedido, sin moderación (la compra es el
 *              filtro, igual que en las de producto).
 * Author:      Headless Web Ecosystem
 * Version:     1.0.0
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Text Domain: hwe-site-reviews
 *
 * Dónde vive cada dato:
 *   - CPT `hwe_site_review` (WooCommerce → «Reseñas del sitio»): la reseña en
 *     sí, con su pedido, su cliente y la nota. Es lo que lee el equipo.
 *   - Order meta `_hwe_site_review` (`id`, `rating`, `content`, `date`): un
 *     ESPEJO para que la cuenta sepa si el pedido ya se calificó sin otra
 *     petición —llega en el `meta_data` de wc/v3 con el resto del pedido—.
 *     Lo escriben solo este plugin al crear la reseña y los ganchos de
 *     papelera: mandar una reseña a la papelera retira el espejo (el cliente
 *     puede volver a calificar) y restaurarla lo repone.
 *
 * El BFF crea la reseña con `POST /hwe/v1/site-reviews` (secreto interno):
 * aquí se vuelve a comprobar que el pedido es de ese usuario y admite reseña,
 * y un cerrojo por pedido impide dos reseñas por un doble envío. Las reseñas
 * de los productos del pedido (opcionales, mismo texto) las crea el BFF por
 * wc/v3 después de esta: no son cosa de este plugin.
 *
 * Es un MÓDULO del framework (`site-reviews`): el frontend sabe si está activo por
 * `config.modules` (ver README.md y MANUAL.md de esta carpeta).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Site_Reviews {

	public const POST_TYPE  = 'hwe_site_review';
	public const ORDER_META = '_hwe_site_review';

	/**
	 * Estados que admiten reseña: solo «Completado», el pedido que el cliente ya
	 * tiene. Uno «Procesando» lo completa él mismo desde su cuenta («Recibí mi
	 * pedido»). Debe coincidir con `canRateOrder` de `lib/account/order-review.ts`.
	 */
	private const RATEABLE_STATUSES = array( 'completed' );

	private const MIN_LENGTH = 10;
	private const MAX_LENGTH = 3000;

	public static function register(): void {
		add_action( 'init', array( self::class, 'registerPostType' ) );
		add_action( 'rest_api_init', array( self::class, 'registerRoutes' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( self::class, 'columnContent' ), 10, 2 );
		add_filter( 'views_edit-' . self::POST_TYPE, array( self::class, 'averageView' ) );
		add_filter( 'post_row_actions', array( self::class, 'rowActions' ), 10, 2 );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( self::class, 'addMetaBox' ) );
		add_action( 'wp_trash_post', array( self::class, 'onTrash' ) );
		add_action( 'before_delete_post', array( self::class, 'onTrash' ) );
		add_filter( 'wp_untrash_post_status', array( self::class, 'untrashStatus' ), 10, 2 );
		add_action( 'untrashed_post', array( self::class, 'onUntrash' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( self::class, 'renderOrderReview' ) );
		add_filter( 'hwe_revalidation_tags', array( self::class, 'registerTag' ) );
		add_action( 'transition_post_status', array( self::class, 'onStatusChange' ), 10, 3 );
	}

	/** Tag ISR del módulo: lo declara también el frontend (`registry.ts` de la instancia o su lector). */
	public const REVALIDATION_TAG = 'site-reviews';

	/** @param array<int,string> $tags */
	public static function registerTag( $tags ): array {
		$tags   = is_array( $tags ) ? $tags : array();
		$tags[] = self::REVALIDATION_TAG;
		return array_values( array_unique( $tags ) );
	}

	/** Avisa al frontend (si hay un lector con ISR) de que las reseñas del sitio cambiaron. */
	private static function revalidate(): void {
		if ( class_exists( '\HWE\ControlCenter\Revalidation' ) ) {
			\HWE\ControlCenter\Revalidation::trigger( self::REVALIDATION_TAG );
		}
	}

	public static function onStatusChange( $new, $old, $post ): void {
		if ( $post instanceof WP_Post && $post->post_type === self::POST_TYPE && $new !== $old ) {
			self::revalidate();
		}
	}

	// ------------------------------------------------------------------------
	//  CPT
	// ------------------------------------------------------------------------

	public static function registerPostType(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'               => 'Reseñas del sitio',
					'singular_name'      => 'Reseña del sitio',
					'menu_name'          => 'Reseñas del sitio',
					'all_items'          => 'Reseñas del sitio',
					'edit_item'          => 'Reseña del sitio',
					'search_items'       => 'Buscar reseñas',
					'not_found'          => 'Todavía nadie ha calificado un pedido.',
					'not_found_in_trash' => 'Ninguna reseña en la papelera.',
				),
				'description'     => 'Calificaciones que los clientes dan a sus pedidos.',
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'woocommerce',
				'capability_type' => 'shop_order',
				'map_meta_cap'    => true,
				// Las crea el cliente desde el sitio, nunca el panel.
				'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
				'hierarchical'    => false,
				'supports'        => array( 'title' ),
				'has_archive'     => false,
				'rewrite'         => false,
				'query_var'       => false,
				'show_in_rest'    => false,
			)
		);
	}

	/** @param array<string,string> $columns */
	public static function columns( array $columns ): array {
		return array(
			'cb'           => $columns['cb'] ?? '',
			'title'        => 'Pedido',
			'hwe_rating'   => 'Calificación',
			'hwe_review'   => 'Reseña',
			'hwe_customer' => 'Cliente',
			'date'         => $columns['date'] ?? 'Fecha',
		);
	}

	public static function columnContent( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'hwe_rating':
				echo esc_html( self::stars( (int) get_post_meta( $post_id, '_hwe_rating', true ) ) );
				break;
			case 'hwe_review':
				echo esc_html( wp_trim_words( (string) get_post_field( 'post_content', $post_id ), 30 ) );
				break;
			case 'hwe_customer':
				$name  = (string) get_post_meta( $post_id, '_hwe_reviewer_name', true );
				$email = (string) get_post_meta( $post_id, '_hwe_reviewer_email', true );
				echo esc_html( $name );
				if ( $email !== '' ) {
					echo '<br><span class="description">' . esc_html( $email ) . '</span>';
				}
				break;
		}
	}

	/** Promedio y total encima del listado. */
	public static function averageView( array $views ): array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total, AVG(CAST(m.meta_value AS UNSIGNED)) AS average
				 FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_hwe_rating'
				 WHERE p.post_type = %s AND p.post_status = 'publish'",
				self::POST_TYPE
			)
		);
		if ( $row && (int) $row->total > 0 ) {
			$views['hwe_average'] = sprintf(
				'<span>Promedio: <strong>%s ★</strong> en %d reseñas</span>',
				esc_html( number_format_i18n( (float) $row->average, 1 ) ),
				(int) $row->total
			);
		}
		return $views;
	}

	/** Sin «Edición rápida»: la reseña es del cliente. */
	public static function rowActions( array $actions, WP_Post $post ): array {
		if ( $post->post_type === self::POST_TYPE ) {
			unset( $actions['inline hide-if-no-js'] );
		}
		return $actions;
	}

	public static function addMetaBox(): void {
		add_meta_box(
			'hwe-site-review',
			'Reseña del cliente',
			array( self::class, 'renderMetaBox' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	public static function renderMetaBox( WP_Post $post ): void {
		$order_id = (int) get_post_meta( $post->ID, '_hwe_order_id', true );
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : null;
		echo '<p style="font-size:18px;margin:0 0 8px">' . esc_html( self::stars( (int) get_post_meta( $post->ID, '_hwe_rating', true ) ) ) . '</p>';
		echo '<blockquote style="margin:0 0 12px;white-space:pre-line">' . esc_html( $post->post_content ) . '</blockquote>';
		echo '<p>' . esc_html( (string) get_post_meta( $post->ID, '_hwe_reviewer_name', true ) ) . ' · ' . esc_html( (string) get_post_meta( $post->ID, '_hwe_reviewer_email', true ) ) . '</p>';
		if ( $order instanceof WC_Order ) {
			printf(
				'<p><a href="%s">Ver pedido #%s</a></p>',
				esc_url( $order->get_edit_order_url() ),
				esc_html( $order->get_order_number() )
			);
		}
		echo '<p class="description">Para retirarla, muévela a la papelera: el cliente podrá volver a calificar ese pedido.</p>';
	}

	/** La reseña en la ficha del pedido de wp-admin. */
	public static function renderOrderReview( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$review = $order->get_meta( self::ORDER_META );
		if ( ! is_array( $review ) || empty( $review['rating'] ) ) {
			return;
		}
		echo '<p class="form-field form-field-wide"><strong>Calificación del cliente:</strong> ';
		echo esc_html( self::stars( (int) $review['rating'] ) );
		echo '<br>' . esc_html( wp_trim_words( (string) ( $review['content'] ?? '' ), 40 ) ) . '</p>';
	}

	// ------------------------------------------------------------------------
	//  Papelera: el espejo del pedido sigue a la reseña
	// ------------------------------------------------------------------------

	public static function onTrash( $post_id ): void {
		$post_id = (int) $post_id;
		if ( get_post_type( $post_id ) !== self::POST_TYPE ) {
			return;
		}
		$order = wc_get_order( (int) get_post_meta( $post_id, '_hwe_order_id', true ) );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$review = $order->get_meta( self::ORDER_META );
		if ( is_array( $review ) && (int) ( $review['id'] ?? 0 ) === $post_id ) {
			$order->delete_meta_data( self::ORDER_META );
			$order->save();
		}
	}

	/** WordPress restaura a «borrador» desde la 5.6: estas vuelven publicadas. */
	public static function untrashStatus( string $status, $post_id ): string {
		return get_post_type( (int) $post_id ) === self::POST_TYPE ? 'publish' : $status;
	}

	public static function onUntrash( $post_id ): void {
		$post_id = (int) $post_id;
		if ( get_post_type( $post_id ) !== self::POST_TYPE ) {
			return;
		}
		$order = wc_get_order( (int) get_post_meta( $post_id, '_hwe_order_id', true ) );
		if ( $order instanceof WC_Order && ! is_array( $order->get_meta( self::ORDER_META ) ) ) {
			$order->update_meta_data( self::ORDER_META, self::mirror( get_post( $post_id ) ) );
			$order->save();
		}
	}

	// ------------------------------------------------------------------------
	//  REST (solo BFF)
	// ------------------------------------------------------------------------

	public static function registerRoutes(): void {
		register_rest_route(
			'hwe/v1',
			'/site-reviews',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handleCreate' ),
				// Cierra en falso si el mu-plugin que valida el secreto no está cargado.
				'permission_callback' => static fn ( WP_REST_Request $request ): bool => function_exists( 'hwe_has_internal_secret' ) && hwe_has_internal_secret( $request ),
				'args'                => array(
					'order_id' => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ),
					'user_id'  => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ),
					'rating'   => array( 'required' => true, 'type' => 'integer', 'minimum' => 1, 'maximum' => 5 ),
					'content'  => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);
	}

	public static function handleCreate( WP_REST_Request $request ): WP_REST_Response {
		$order_id = (int) $request->get_param( 'order_id' );
		$user_id  = (int) $request->get_param( 'user_id' );
		$rating   = (int) $request->get_param( 'rating' );
		$content  = trim( sanitize_textarea_field( (string) $request->get_param( 'content' ) ) );

		$length = mb_strlen( $content );
		if ( $length < self::MIN_LENGTH || $length > self::MAX_LENGTH ) {
			return new WP_REST_Response( array( 'error' => 'invalid_content' ), 422 );
		}
		// La REST ya valida el rango (`args`); se repite aquí porque este método es la regla.
		if ( $rating < 1 || $rating > 5 ) {
			return new WP_REST_Response( array( 'error' => 'invalid_rating' ), 422 );
		}

		$order = wc_get_order( $order_id );
		// Ajeno o inexistente: la misma respuesta, no confirma que exista.
		if ( ! $order instanceof WC_Order || (int) $order->get_customer_id() !== $user_id ) {
			return new WP_REST_Response( array( 'error' => 'order_not_found' ), 404 );
		}
		if ( ! in_array( $order->get_status(), self::RATEABLE_STATUSES, true ) ) {
			return new WP_REST_Response( array( 'error' => 'order_not_rateable' ), 422 );
		}

		// Cerrojo por pedido: `add_option` falla si la fila ya existe (índice
		// único), así que dos peticiones simultáneas no crean dos reseñas.
		$lock = 'hwe_site_review_lock_' . $order_id;
		if ( ! add_option( $lock, time(), '', false ) ) {
			// Un cerrojo huérfano (petición muerta a medias) caduca al minuto.
			if ( (int) get_option( $lock ) > time() - MINUTE_IN_SECONDS ) {
				return new WP_REST_Response( array( 'error' => 'already_reviewed' ), 409 );
			}
			update_option( $lock, time(), false );
		}

		try {
			$existing = $order->get_meta( self::ORDER_META );
			if ( is_array( $existing ) && ! empty( $existing['id'] ) ) {
				return new WP_REST_Response( array( 'error' => 'already_reviewed', 'review' => $existing ), 409 );
			}

			$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
			if ( $name === '' ) {
				$user = get_userdata( $user_id );
				$name = $user ? (string) $user->display_name : '';
			}

			$post_id = wp_insert_post(
				array(
					'post_type'    => self::POST_TYPE,
					'post_status'  => 'publish',
					'post_author'  => $user_id,
					'post_title'   => sprintf( 'Pedido #%1$s — %2$s', $order->get_order_number(), $name ),
					'post_content' => $content,
					'meta_input'   => array(
						'_hwe_rating'         => $rating,
						'_hwe_order_id'       => $order_id,
						'_hwe_user_id'        => $user_id,
						'_hwe_reviewer_name'  => $name,
						'_hwe_reviewer_email' => $order->get_billing_email(),
					),
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				error_log( '[hwe] site-reviews: no se pudo crear la reseña del pedido ' . $order_id );
				return new WP_REST_Response( array( 'error' => 'create_failed' ), 500 );
			}

			$mirror = self::mirror( get_post( $post_id ) );
			$order->update_meta_data( self::ORDER_META, $mirror );
			$order->save();

			return new WP_REST_Response( array( 'review' => $mirror ), 201 );
		} finally {
			delete_option( $lock );
		}
	}

	// ------------------------------------------------------------------------

	/** @return array{id:int,rating:int,content:string,date:string} */
	private static function mirror( ?WP_Post $post ): array {
		if ( ! $post instanceof WP_Post ) {
			return array( 'id' => 0, 'rating' => 0, 'content' => '', 'date' => '' );
		}
		return array(
			'id'      => (int) $post->ID,
			'rating'  => (int) get_post_meta( $post->ID, '_hwe_rating', true ),
			'content' => (string) $post->post_content,
			'date'    => (string) get_post_time( 'c', true, $post ),
		);
	}

	private static function stars( int $rating ): string {
		$rating = max( 0, min( 5, $rating ) );
		return str_repeat( '★', $rating ) . str_repeat( '☆', 5 - $rating );
	}
}

HWE_Site_Reviews::register();
