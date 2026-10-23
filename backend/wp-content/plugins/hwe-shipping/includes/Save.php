<?php

namespace HWE\Shipping;

use HWE\Shipping\Rates\Cities;
use HWE\Shipping\Rates\City;
use HWE\Shipping\Rates\DeliveryOption;
use HWE\Shipping\Rates\GeoPoint;

/**
 * Guardado de ambos CPTs. La ciudad se valida y sanea aquí, en el servidor: si algo no cuadra, no
 * se publica (queda en borrador), no se escribe su configuración y el editor ve el motivo en un
 * aviso de admin.
 */
class Save {

	public const RATE_NONCE_FIELD  = 'hwe_shipping_rate_nonce';
	public const RATE_NONCE_ACTION = 'hwe_shipping_rate_meta';

	/** Motivos de la última guardada rechazada (JSON, lista de mensajes). Se borra al guardar bien. */
	public const ERRORS_META = '_hwe_shipping_city_errors';

	/** Metas del modelo anterior: se borran al guardar la ciudad con el nuevo. */
	private const LEGACY_META = [ '_hwe_shipping_rate_amount', '_hwe_shipping_rate_free_eligible' ];

	/**
	 * Cierto mientras forzamos el borrador: `wp_update_post` vuelve a disparar `save_post`, y sin
	 * esta marca el guardado se repetiría sin fin.
	 */
	private static bool $writing = false;

	public static function register(): void {
		add_action( 'save_post_' . PostTypes::METHOD_SLUG, [ self::class, 'saveMethod' ], 10, 1 );
		add_action( 'save_post_' . PostTypes::RATE_SLUG, [ self::class, 'saveRate' ], 10, 1 );
	}

	public static function saveMethod( int $postId ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		$nonce = isset( $_POST['hwe_shipping_method_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['hwe_shipping_method_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'hwe_shipping_method_meta' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $postId ) ) {
			return;
		}

		$enabled = ! empty( $_POST['hwe_shipping_method_enabled'] ) && $_POST['hwe_shipping_method_enabled'] === '1';
		update_post_meta( $postId, '_hwe_shipping_method_enabled', $enabled ? '1' : '0' );

		$added = isset( $_POST['hwe_shipping_method_added_value'] ) ? self::sanitizeAmount( (string) $_POST['hwe_shipping_method_added_value'] ) : '';
		if ( $added === '' ) {
			delete_post_meta( $postId, '_hwe_shipping_method_added_value' );
		} else {
			update_post_meta( $postId, '_hwe_shipping_method_added_value', $added );
		}
	}

	/** Guarda la ciudad si la configuración es válida; si no, la deja en borrador con el motivo. */
	public static function saveRate( int $postId ): void {
		if ( self::$writing ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		$nonce = isset( $_POST[ self::RATE_NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::RATE_NONCE_FIELD ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::RATE_NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $postId ) ) {
			return;
		}
		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$name   = Cities::nameOf( $post );
		$result = self::validate( $_POST, $name, Cities::isNameTaken( $name, $postId ) );

		if ( $result['errors'] !== [] ) {
			self::reject( $post, $result['errors'] );
			return;
		}
		self::store( $postId, $result['data'] );
	}

	/**
	 * Valida y sanea el formulario de la ciudad. Función pura: no escribe nada.
	 *
	 * @param array<string,mixed> $form   Campos enviados (`$_POST`).
	 * @param string              $name   Nombre de la ciudad (título del post).
	 * @param bool                $nameTaken ¿Ya hay otra ciudad publicada con ese nombre normalizado?
	 * @return array{errors: list<string>, data: array<string,mixed>}
	 */
	public static function validate( array $form, string $name, bool $nameTaken ): array {
		$errors = [];
		$type   = self::text( $form, 'hwe_shipping_city_type' );

		if ( '' === trim( $name ) ) {
			$errors[] = __( 'El título es el nombre de la ciudad y no puede estar vacío.', 'hwe-shipping' );
		} elseif ( $nameTaken ) {
			$errors[] = __( 'Ya hay una tarifa publicada para esta ciudad. Los nombres se comparan sin tildes ni mayúsculas.', 'hwe-shipping' );
		}

		if ( City::TYPE_FIXED !== $type && City::TYPE_DISTANCE !== $type ) {
			$errors[] = __( 'Elige el tipo de tarifa: fija o por distancia.', 'hwe-shipping' );
			return [ 'errors' => $errors, 'data' => [] ];
		}

		$options = self::validateOptions( $form, $type, $errors );

		$origin       = null;
		$originAddr   = '';
		$originLabel  = '';
		$coverage     = 0.0;
		if ( City::TYPE_DISTANCE === $type ) {
			$origin = GeoPoint::tryFrom(
				self::text( $form, 'hwe_shipping_city_origin_lat' ),
				self::text( $form, 'hwe_shipping_city_origin_lng' )
			);
			if ( null === $origin ) {
				$errors[] = __( 'La ciudad por distancia necesita una dirección de referencia con coordenadas válidas (marca el punto de salida).', 'hwe-shipping' );
			}

			$radius = self::text( $form, 'hwe_shipping_city_coverage_km' );
			if ( is_numeric( $radius ) && is_finite( (float) $radius ) && (float) $radius > 0 ) {
				$coverage = (float) $radius;
			} else {
				$errors[] = __( 'El radio de cobertura debe ser un número mayor que 0 km.', 'hwe-shipping' );
			}

			$originAddr  = self::text( $form, 'hwe_shipping_city_origin_address' );
			$originLabel = self::text( $form, 'hwe_shipping_city_origin_label' );
		}

		return [
			'errors' => $errors,
			'data'   => [
				'type'           => $type,
				'options'        => $options,
				'origin'         => $origin,
				'origin_address' => $originAddr,
				'origin_label'   => $originLabel,
				'coverage'       => $coverage,
			],
		];
	}

	/**
	 * Opciones del formulario. Una fila con errores no produce opción; el id existente se conserva
	 * (es lo que hace estable la opción al renombrarla), y si falta, está repetido o es inválido,
	 * se genera uno nuevo.
	 *
	 * @param array<string,mixed> $form
	 * @param string[]            $errors Se completa por referencia.
	 * @return DeliveryOption[]
	 */
	private static function validateOptions( array $form, string $type, array &$errors ): array {
		$rows    = isset( $form['hwe_shipping_city_options'] ) && is_array( $form['hwe_shipping_city_options'] )
			? array_values( $form['hwe_shipping_city_options'] )
			: [];
		$options = [];
		$seen    = [];

		if ( $rows === [] ) {
			$errors[] = __( 'Añade al menos una opción de entrega.', 'hwe-shipping' );
		}

		foreach ( $rows as $index => $row ) {
			$position = $index + 1;
			if ( ! is_array( $row ) ) {
				$errors[] = sprintf( __( 'La opción %d no es válida.', 'hwe-shipping' ), $position );
				continue;
			}

			$before = count( $errors );
			$label  = self::text( $row, 'name' );
			if ( '' === $label ) {
				$errors[] = sprintf( __( 'La opción %d necesita un nombre.', 'hwe-shipping' ), $position );
			}

			$amount   = null;
			$perKm    = null;
			$minCost  = 0.0;
			$maxCost  = 0.0;
			if ( City::TYPE_FIXED === $type ) {
				$amount = self::money( $row, 'amount', __( 'importe', 'hwe-shipping' ), $position, true, $errors );
			} else {
				$perKm   = self::money( $row, 'price_per_km', __( 'precio por km', 'hwe-shipping' ), $position, true, $errors );
				$minCost = self::money( $row, 'min_cost', __( 'mínimo', 'hwe-shipping' ), $position, false, $errors ) ?? 0.0;
				$maxCost = self::money( $row, 'max_cost', __( 'máximo', 'hwe-shipping' ), $position, false, $errors ) ?? 0.0;
				if ( $minCost > 0 && $maxCost > 0 && $minCost > $maxCost ) {
					$errors[] = sprintf( __( 'En la opción %d, el mínimo no puede ser mayor que el máximo.', 'hwe-shipping' ), $position );
				}
			}
			if ( count( $errors ) > $before ) {
				continue;
			}

			$id = self::text( $row, 'id' );
			if ( 1 !== preg_match( DeliveryOption::ID_PATTERN, $id ) || isset( $seen[ $id ] ) ) {
				do {
					$id = DeliveryOption::newId();
				} while ( isset( $seen[ $id ] ) );
			}
			$seen[ $id ] = true;

			$options[] = new DeliveryOption(
				$id,
				$label,
				self::text( $row, 'free_eligible' ) === '1',
				$amount,
				$perKm,
				$minCost,
				$maxCost
			);
		}

		return $options;
	}

	/**
	 * Importe no negativo y finito. Vacío = null si es obligatorio (con error) o 0 si es opcional.
	 *
	 * @param array<string,mixed> $row
	 * @param string[]            $errors
	 */
	private static function money( array $row, string $key, string $label, int $position, bool $required, array &$errors ): ?float {
		$raw = self::text( $row, $key );
		if ( '' === $raw ) {
			if ( $required ) {
				$errors[] = sprintf( __( 'La opción %1$d necesita un %2$s.', 'hwe-shipping' ), $position, $label );
				return null;
			}
			return 0.0;
		}
		if ( ! is_numeric( $raw ) || ! is_finite( (float) $raw ) ) {
			$errors[] = sprintf( __( 'El %1$s de la opción %2$d no es un número válido.', 'hwe-shipping' ), $label, $position );
			return null;
		}
		if ( (float) $raw < 0 ) {
			$errors[] = sprintf( __( 'El %1$s de la opción %2$d no puede ser negativo.', 'hwe-shipping' ), $label, $position );
			return null;
		}
		return (float) $raw;
	}

	/**
	 * Escribe la configuración validada y limpia el motivo de un rechazo anterior.
	 *
	 * @param array<string,mixed> $data
	 */
	private static function store( int $postId, array $data ): void {
		$distance = City::TYPE_DISTANCE === $data['type'];
		/** @var GeoPoint|null $origin */
		$origin = $data['origin'];

		update_post_meta( $postId, Cities::META_TYPE, $data['type'] );
		update_post_meta(
			$postId,
			Cities::META_OPTIONS,
			wp_json_encode( array_map( static fn ( DeliveryOption $option ): array => $option->toArray(), $data['options'] ) )
		);
		update_post_meta( $postId, Cities::META_ORIGIN_ADDRESS, $distance ? $data['origin_address'] : '' );
		update_post_meta( $postId, Cities::META_ORIGIN_LAT, $distance && null !== $origin ? (string) $origin->lat() : '' );
		update_post_meta( $postId, Cities::META_ORIGIN_LNG, $distance && null !== $origin ? (string) $origin->lng() : '' );
		update_post_meta( $postId, Cities::META_ORIGIN_LABEL, $distance ? $data['origin_label'] : '' );
		update_post_meta( $postId, Cities::META_COVERAGE, $distance ? (string) $data['coverage'] : '' );

		delete_post_meta( $postId, self::ERRORS_META );
		foreach ( self::LEGACY_META as $key ) {
			delete_post_meta( $postId, $key );
		}
	}

	/**
	 * Rechaza la guardada: guarda el motivo y, si el post estaba publicado, lo pasa a borrador.
	 *
	 * @param string[] $errors
	 */
	private static function reject( \WP_Post $post, array $errors ): void {
		update_post_meta( $post->ID, self::ERRORS_META, wp_json_encode( $errors ) );

		if ( 'draft' === $post->post_status ) {
			return;
		}
		self::$writing = true;
		try {
			wp_update_post( [ 'ID' => $post->ID, 'post_status' => 'draft' ] );
		} finally {
			self::$writing = false;
		}
	}

	/** Campo de texto saneado; '' si no es un escalar. @param array<string,mixed> $source */
	private static function text( array $source, string $key ): string {
		$value = $source[ $key ] ?? '';
		return is_scalar( $value ) ? sanitize_text_field( wp_unslash( (string) $value ) ) : '';
	}

	/** Entero no negativo como string; '' si no es numérico. */
	private static function sanitizeAmount( string $raw ): string {
		$raw = trim( wp_unslash( $raw ) );
		if ( $raw === '' || ! is_numeric( $raw ) ) {
			return '';
		}
		$n = max( 0, (int) round( (float) $raw ) );
		return (string) $n;
	}
}
