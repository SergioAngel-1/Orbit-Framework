<?php
/**
 * Transferencias de puntos entre usuarios (spec §8 y Tarea 9).
 *
 * El emisor es siempre el usuario de la sesión (el BFF pone su id); el receptor se identifica
 * por su **código de referido**, nunca por un id que llegue del navegador. Reglas:
 *
 *  · Puerta: el programa de puntos y el interruptor de transferencias deben estar activos; el
 *    emisor debe participar. El receptor debe existir, tener un rol permitido y no ser el
 *    emisor. Un código inexistente y uno de un usuario que no participa responden lo mismo
 *    (`invalid_recipient`): no se puede enumerar la red.
 *  · Importes: `points` es todo lo que sale del emisor; `fee = ceil(points × %/100)` es la
 *    comisión de la tienda y el receptor recibe `points - fee`. El mínimo se compara con
 *    `points`. Una comisión del 100 % dejaría al receptor sin nada y se trata como
 *    `disabled` (configuración inservible), sin escribir.
 *  · Tope diario: cuenta lo que SALE del emisor (envío + comisión) desde el inicio del día en
 *    la zona del sitio; `0` = sin tope. `HWE_Loyalty_Ledger` lo vuelve a comprobar dentro de
 *    la transacción para que dos peticiones simultáneas no se pasen.
 *  · Atomicidad: el cargo, la comisión y el abono los escribe `HWE_Loyalty_Ledger::transfer()`
 *    en una sola transacción; si algo falla no queda nada escrito. La acción
 *    `hwe_loyalty_transferred` se dispara después del COMMIT.
 *  · Idempotencia: la clave la manda el BFF y el libro la cualifica con el emisor
 *    (`transfer:<from>:<clave>:out|fee|in`), así dos usuarios no colisionan. Repetir la misma
 *    clave con los mismos parámetros devuelve la MISMA respuesta sin mover puntos; repetirla
 *    con otros parámetros no mueve nada y responde `conflict` (decisión propia: es más seguro
 *    que devolver un éxito que no corresponde a lo pedido).
 *  · Privacidad: la respuesta solo lleva el nombre visible del receptor cuando el envío salió
 *    bien; nunca su email ni su login. Los logs solo llevan ids y motivos, jamás el código ni
 *    datos de la cuenta.
 *
 * Códigos de error propios de esta capa (además de los del plan): `invalid_request` (entrada
 * mal formada), `conflict` (misma clave con otros parámetros) y `failed` (fallo de escritura
 * no atribuible a saldo ni a tope). La ruta los traduce a 4xx/5xx.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Loyalty_Transfers {

	/** Longitud máxima de la nota libre que se guarda en el libro. */
	private const NOTE_MAX = 200;

	/**
	 * Transfiere puntos del usuario `$fromUserId` al dueño del código `$recipientCode`.
	 *
	 * @return array{ok:true,sent:int,received:int,fee:int,recipient_name:string}
	 *       | array{ok:false,error:string}
	 */
	public static function transfer(
		int $fromUserId,
		string $recipientCode,
		int $points,
		string $idempotencyKey,
		string $note = ''
	): array {
		if ( ! HWE_Loyalty_Settings::transfersActive() ) {
			return self::error( 'disabled' );
		}
		if ( $fromUserId <= 0 || ! HWE_Loyalty_Settings::canParticipate( $fromUserId ) ) {
			self::log( 'transferencia rechazada: el emisor ' . $fromUserId . ' no participa.' );
			return self::error( 'disabled' );
		}

		$key = trim( $idempotencyKey );
		if ( $points <= 0 || '' === $key ) {
			return self::error( 'invalid_request' );
		}

		$recipientId = HWE_Loyalty_Referrals::userByCode( $recipientCode );
		if ( null === $recipientId ) {
			self::log( 'transferencia rechazada del usuario ' . $fromUserId . ': código sin dueño.' );
			return self::error( 'invalid_recipient' );
		}
		if ( $recipientId === $fromUserId ) {
			return self::error( 'self' );
		}
		if ( ! HWE_Loyalty_Settings::canParticipate( $recipientId ) ) {
			self::log( 'transferencia rechazada del usuario ' . $fromUserId . ': el receptor ' . $recipientId . ' no participa.' );
			return self::error( 'invalid_recipient' );
		}

		$settings = HWE_Loyalty_Settings::get();
		if ( $points < (int) $settings['transfer_min'] ) {
			return self::error( 'below_minimum' );
		}

		$fee = self::feeFor( $points, (int) $settings['transfer_fee_percent'] );
		if ( $fee >= $points ) {
			self::log( 'transferencia rechazada del usuario ' . $fromUserId . ': la comisión del 100 % no deja nada al receptor.' );
			return self::error( 'disabled' );
		}

		$dailyCap = (int) $settings['transfer_daily_cap'];
		if ( $dailyCap > 0 && HWE_Loyalty_Ledger::transferredToday( $fromUserId ) + $points > $dailyCap ) {
			return self::error( 'daily_cap' );
		}

		$result = HWE_Loyalty_Ledger::transfer(
			$fromUserId,
			$recipientId,
			$points,
			$fee,
			$key,
			array( 'note' => self::sanitizeNote( $note ) )
		);

		if ( null === $result ) {
			// El libro no distingue el motivo; se reconstruye aquí para dar un código estable.
			if ( $dailyCap > 0 && HWE_Loyalty_Ledger::transferredToday( $fromUserId ) + $points > $dailyCap ) {
				return self::error( 'daily_cap' );
			}
			if ( HWE_Loyalty_Ledger::balance( $fromUserId )['balance'] < $points ) {
				return self::error( 'insufficient' );
			}
			self::log( 'la transferencia del usuario ' . $fromUserId . ' no se pudo completar.' );
			return self::error( 'failed' );
		}

		$sent = (int) $result['out_points'] + (int) $result['fee_points'];
		if (
			(int) $result['to'] !== $recipientId
			|| $sent !== $points
			|| (int) $result['fee_points'] !== $fee
		) {
			self::log( 'clave de idempotencia reutilizada con otros parámetros por el usuario ' . $fromUserId . '.' );
			return self::error( 'conflict' );
		}

		return array(
			'ok'             => true,
			'sent'           => $sent,
			'received'       => (int) $result['received'],
			'fee'            => (int) $result['fee_points'],
			'recipient_name' => self::displayName( $recipientId ),
		);
	}

	/**
	 * Comisión en puntos, redondeada **hacia arriba** con aritmética entera.
	 * `fee_percent` ya viene acotado a [0, 100] por los ajustes.
	 */
	public static function feeFor( int $points, int $feePercent ): int {
		if ( $points <= 0 || $feePercent <= 0 ) {
			return 0;
		}
		return intdiv( $points * $feePercent + 99, 100 );
	}

	/** Nombre visible del receptor; cadena vacía si la cuenta ya no existe. Nunca login ni email. */
	private static function displayName( int $userId ): string {
		$user = function_exists( 'get_userdata' ) ? get_userdata( $userId ) : false;
		if ( ! is_object( $user ) || ! isset( $user->display_name ) || ! is_scalar( $user->display_name ) ) {
			return '';
		}

		$name = trim( (string) $user->display_name );
		return function_exists( 'sanitize_text_field' ) ? (string) sanitize_text_field( $name ) : $name;
	}

	/** Nota libre: texto plano, una sola línea y acotado; vacía se guarda como `null`. */
	private static function sanitizeNote( string $note ): string {
		$note = trim( $note );
		if ( '' === $note ) {
			return '';
		}

		$note = function_exists( 'sanitize_text_field' ) ? (string) sanitize_text_field( $note ) : $note;
		$note = (string) preg_replace( '/\s+/u', ' ', $note );

		return function_exists( 'mb_substr' )
			? mb_substr( $note, 0, self::NOTE_MAX )
			: substr( $note, 0, self::NOTE_MAX );
	}

	/** @return array{ok:false,error:string} */
	private static function error( string $code ): array {
		return array( 'ok' => false, 'error' => $code );
	}

	private static function log( string $message ): void {
		error_log( '[hwe] loyalty: ' . $message );
	}
}
