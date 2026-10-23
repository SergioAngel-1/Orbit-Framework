<?php
/**
 * Ajuste manual de saldo (spec §12): abono o cargo explícito con nota obligatoria.
 *
 * Reglas que sostiene esta clase:
 *  · **Capacidad**: toda entrada exige `hwe_loyalty_manage`, también la que llega por POST
 *    (además del nonce). La comprobación vive aquí, no solo en la pantalla, para que un
 *    formulario fabricado no salte la guarda.
 *  · **Nota obligatoria**: identifica el motivo ante una auditoría; sin ella no se escribe.
 *  · **Cargo limitado al saldo**: se rechaza antes de llamar al libro si no alcanza y, si el
 *    saldo cambió entre la comprobación y el cargo (otra pestaña), el propio libro vuelve a
 *    rechazarlo. Nunca queda saldo negativo.
 *  · **Idempotencia por envío**: la clave la genera la pantalla una vez por formulario y viaja
 *    en un campo oculto; el índice único del libro frena un doble clic. Un reenvío devuelve
 *    `duplicate`, no un error.
 *  · **Actor**: se guarda `admin` y el id del administrador; la nota va a su columna.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Loyalty_Admin_Adjustments {

	public const ACTION   = 'hwe_loyalty_adjust';
	public const NONCE    = 'hwe_loyalty_adjust';
	public const FIELD    = 'hwe_loyalty_adjust_nonce';
	/** Longitud máxima de la nota: suficiente para explicar el motivo, acotada para la columna. */
	private const NOTE_MAX = 500;
	/** Longitud mínima/máxima de la clave de idempotencia aceptada desde el formulario. */
	private const KEY_MIN = 8;
	private const KEY_MAX = 64;

	/** Engancha el POST del formulario. La llama `HWE_Loyalty_Admin::register()`. */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handleRequest' ) );
	}

	/**
	 * Aplica un ajuste y devuelve el resultado para que la pantalla (o el test) decida el aviso.
	 *
	 * Códigos: `ok`, `duplicate`, `forbidden`, `invalid_user`, `invalid_points`, `invalid_note`,
	 * `invalid_key`, `insufficient` y `failed`.
	 *
	 * @return array{ok:bool,code:string,entry:?int}
	 */
	public static function apply( int $userId, string $direction, int $points, string $note, string $key ): array {
		if ( ! HWE_Loyalty_Admin_Capabilities::canManage() ) {
			return self::result( false, 'forbidden' );
		}

		$user = function_exists( 'get_userdata' ) ? get_userdata( $userId ) : false;
		if ( ! is_object( $user ) || $userId <= 0 ) {
			return self::result( false, 'invalid_user' );
		}

		$direction = strtolower( trim( $direction ) );
		if ( ! in_array( $direction, array( 'credit', 'debit' ), true ) || $points <= 0 ) {
			return self::result( false, 'invalid_points' );
		}

		$note = self::cleanNote( $note );
		if ( '' === $note ) {
			return self::result( false, 'invalid_note' );
		}

		$key = self::cleanKey( $key );
		if ( '' === $key ) {
			return self::result( false, 'invalid_key' );
		}

		$idempotencyKey = 'admin:manual:' . $key;
		$actorId        = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$data           = array(
			'actor'    => 'admin',
			'actor_id' => $actorId,
			'note'     => $note,
			'source'   => 'wp-admin',
		);
		$ref            = array( 'type' => 'user', 'id' => $userId );

		if ( 'debit' === $direction ) {
			$balance = HWE_Loyalty_Ledger::balance( $userId );
			if ( (int) $balance['balance'] < $points ) {
				return self::result( false, 'insufficient' );
			}

			$entryId = HWE_Loyalty_Ledger::debit( $userId, $points, 'admin_debit', $idempotencyKey, $ref, $data );
		} else {
			$entryId = HWE_Loyalty_Ledger::credit( $userId, $points, 'admin_credit', $idempotencyKey, $ref, null, $data );
		}

		if ( null !== $entryId ) {
			return self::result( true, 'ok', $entryId );
		}

		// El libro devuelve `null` tanto por clave repetida como por un fallo de escritura. La
		// diferencia importa para el aviso: un doble clic no es un error.
		if ( self::keyExists( $idempotencyKey ) ) {
			return self::result( true, 'duplicate' );
		}

		// Otra petición pudo gastar el saldo entre la comprobación y el cargo: el libro lo
		// rechazó por insuficiente, no por un fallo de escritura.
		if ( 'debit' === $direction && (int) HWE_Loyalty_Ledger::balance( $userId )['balance'] < $points ) {
			return self::result( false, 'insufficient' );
		}

		error_log( '[hwe] loyalty: no se pudo aplicar el ajuste manual del usuario ' . $userId . '.' );
		return self::result( false, 'failed' );
	}

	/** POST de `admin-post.php`: capacidad + nonce, saneado, ajuste y redirección con aviso. */
	public static function handleRequest(): void {
		if ( ! HWE_Loyalty_Admin_Capabilities::canManage() ) {
			wp_die( 'No autorizado.', 403 );
			return;
		}

		$nonce = isset( $_POST[ self::FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			wp_die( 'Nonce no válido.', 403 );
			return;
		}

		$term      = isset( $_POST['hwe_loyalty_user'] ) ? sanitize_text_field( wp_unslash( $_POST['hwe_loyalty_user'] ) ) : '';
		$direction = isset( $_POST['hwe_loyalty_direction'] ) ? sanitize_key( wp_unslash( $_POST['hwe_loyalty_direction'] ) ) : '';
		$points    = isset( $_POST['hwe_loyalty_points'] ) ? absint( wp_unslash( $_POST['hwe_loyalty_points'] ) ) : 0;
		$note      = isset( $_POST['hwe_loyalty_note'] ) ? sanitize_text_field( wp_unslash( $_POST['hwe_loyalty_note'] ) ) : '';
		$key       = isset( $_POST['hwe_loyalty_key'] ) ? sanitize_text_field( wp_unslash( $_POST['hwe_loyalty_key'] ) ) : '';

		$userId = HWE_Loyalty_Admin_Users::resolve( $term );
		if ( null === $userId ) {
			HWE_Loyalty_Admin::flash( 'invalid_user' );
			self::redirect();
			return;
		}

		$result = self::apply( $userId, $direction, $points, $note, $key );
		HWE_Loyalty_Admin::flash( $result['code'], array( 'points' => $points ) );
		self::redirect();
	}

	/** Nota limpia y acotada; se rechaza si queda vacía. */
	public static function cleanNote( string $note ): string {
		$note = trim( sanitize_text_field( $note ) );
		if ( '' === $note ) {
			return '';
		}

		return function_exists( 'mb_substr' ) ? mb_substr( $note, 0, self::NOTE_MAX ) : substr( $note, 0, self::NOTE_MAX );
	}

	/** Clave del formulario: saneada, con longitud acotada. Un UUID cumple el mínimo. */
	public static function cleanKey( string $key ): string {
		$key = sanitize_key( $key );
		if ( strlen( $key ) < self::KEY_MIN || strlen( $key ) > self::KEY_MAX ) {
			return '';
		}

		return $key;
	}

	private static function keyExists( string $key ): bool {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . HWE_Loyalty_Schema::ledgerTable() . ' WHERE idempotency_key = %s LIMIT 1',
				$key
			)
		);

		return null !== $found;
	}

	private static function redirect(): void {
		if ( function_exists( 'wp_safe_redirect' ) ) {
			wp_safe_redirect( HWE_Loyalty_Admin_Menu::url( HWE_Loyalty_Admin_Menu::ADJUST ) );
		}
	}

	/** @return array{ok:bool,code:string,entry:?int} */
	private static function result( bool $ok, string $code, ?int $entry = null ): array {
		return array( 'ok' => $ok, 'code' => $code, 'entry' => $entry );
	}
}
