<?php
/**
 * Punto de entrada estable de `loyalty` para otros módulos (spec §8).
 *
 * El módulo de fidelización es dueño de su libro mayor y de sus clases internas. Cualquier otro
 * módulo (por ejemplo, membresías) que necesite abonar o revertir puntos habla SOLO con las tres
 * funciones globales de este archivo:
 *
 *  · `hwe_loyalty_available( $feature )`: ¿está encendida la función `points` o `referrals`?
 *  · `hwe_loyalty_credit( … )`: abona puntos con un tipo declarado, una referencia y una clave
 *    de idempotencia; pasa por el libro, con su transacción y su bloqueo de fila.
 *  · `hwe_loyalty_reverse_credit( … )`: revierte un abono por su clave, limitado al saldo e
 *    idempotente por la clave de reversión (mismo criterio que `earn_reversal`).
 *
 * Reglas del contrato:
 *  · Las funciones se cargan con el plugin (`hwe-loyalty.php` las incluye al arrancar). Si
 *    `loyalty` no está activo no existen: el consumidor comprueba `function_exists()`.
 *  · Ninguna de las tres conoce clases de otro módulo ni devuelve textos para el cliente. No
 *    hay frases: el consumidor compone el texto con su propia clave y sus datos estructurados.
 *  · `credit` responde `null` sin escribir cuando los puntos están apagados, el usuario no
 *    participa, el tipo no está declarado como abono o la clave ya existía.
 *  · `reverse_credit` responde `null` sin escribir cuando la clave original no existe o no es
 *    un abono, cuando no queda saldo o cuando la clave de reversión ya se aplicó. Revertir es
 *    una corrección contable: funciona aunque el programa se haya apagado después del abono.
 *  · Un tipo de movimiento propio se declara con el filtro `hwe_loyalty_entry_types`; sin
 *    declararlo, `credit` lo rechaza (falla cerrado, como el libro).
 *
 * Los errores se registran con `error_log('[hwe] loyalty: …')` y solo con ids y claves técnicas:
 * nunca emails, logins ni nombres.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Loyalty_Api {

	/** Longitud máxima de una clave de idempotencia (la columna es varchar(191)). */
	private const KEY_MAX = 191;

	/** Prefijo de la clave del cargo de reversión, para no colisionar con claves de abono. */
	private const REVERSAL_PREFIX = 'reversal:';

	/**
	 * ¿Está disponible una función del programa?
	 *
	 * `points` exige el plugin activo y el interruptor «Programa de puntos»; `referrals` exige
	 * además el de referidos (sin puntos no hay unidad con la que pagar). Cualquier otro valor
	 * responde `false` y se registra: el contrato no adivina funciones futuras.
	 */
	public static function available( string $feature ): bool {
		$feature = strtolower( trim( $feature ) );

		if ( 'points' === $feature ) {
			return HWE_Loyalty_Settings::pointsActive();
		}
		if ( 'referrals' === $feature ) {
			return HWE_Loyalty_Settings::referralsActive();
		}

		self::log( 'hwe_loyalty_available() recibió la función desconocida «' . self::label( $feature ) . '»; se responde que no.' );

		return false;
	}

	/**
	 * Abona puntos en nombre de otro módulo.
	 *
	 * Delega en el libro (`HWE_Loyalty_Ledger::credit()`) con la caducidad configurada y sin
	 * tocar su comportamiento: la clave es única, la fila del saldo se bloquea dentro de la
	 * transacción y la acción `hwe_loyalty_credited` se dispara después del COMMIT.
	 *
	 * @param int          $userId Usuario que recibe el abono.
	 * @param int          $points Puntos enteros positivos.
	 * @param string       $type   Tipo declarado como `credit` en `hwe_loyalty_entry_types`.
	 * @param string       $key    Clave de idempotencia (única en todo el libro).
	 * @param array<mixed> $ref    Referencia tipada: `['type' => …, 'id' => …]` o `['order' => 55]`.
	 * @param array<mixed> $data   Nota estructurada para el historial (sin textos de cliente).
	 * @return int|null Id del movimiento, o `null` sin escribir si no procede.
	 */
	public static function credit(
		int $userId,
		int $points,
		string $type,
		string $key,
		array $ref = array(),
		array $data = array()
	): ?int {
		if ( ! self::available( 'points' ) ) {
			return null;
		}

		if ( ! HWE_Loyalty_Settings::canParticipate( $userId ) ) {
			self::log(
				'abono «' . self::label( $type ) . '» rechazado: el usuario ' . $userId
				. ' no existe, no tiene un rol participante o el programa está apagado.'
			);
			return null;
		}

		return HWE_Loyalty_Ledger::credit( $userId, $points, $type, $key, $ref, null, $data );
	}

	/**
	 * Revierte un abono identificado por su clave de idempotencia.
	 *
	 * Sigue el criterio de `earn_reversal`: recupera como máximo los puntos del abono original
	 * y nunca deja el saldo en negativo (si el cliente ya gastó parte, solo vuelve lo que
	 * queda). El cargo es idempotente por `$reversalKey`: una segunda pasada no vuelve a mover
	 * puntos aunque el usuario haya ganado saldo nuevo entretanto.
	 *
	 * No exige que «Puntos» siga encendido: revertir es una corrección contable y debe funcionar
	 * aunque el programa se apagara después del abono.
	 *
	 * @param string $key         Clave del abono original.
	 * @param string $reversalKey Clave única de esta reversión (idempotencia).
	 * @return int|null Id del cargo `earn_reversal`, o `null` sin escribir si no procede.
	 */
	public static function reverseCredit( string $key, string $reversalKey ): ?int {
		$key         = self::normalizeKey( $key );
		$reversalKey = self::normalizeKey( $reversalKey );

		if ( '' === $key || '' === $reversalKey ) {
			self::log( 'reversión rechazada: falta la clave del abono o la de la reversión.' );
			return null;
		}

		$credit = self::entryByKey( $key );
		if ( null === $credit || (int) $credit->points <= 0 ) {
			self::log( 'no existe un abono con la clave «' . self::label( $key ) . '» que revertir.' );
			return null;
		}

		$debitKey = self::REVERSAL_PREFIX . $reversalKey;
		if ( null !== self::entryByKey( $debitKey ) ) {
			return null; // Ya revertido: idempotente, no es un error.
		}

		$userId    = (int) $credit->user_id;
		$points    = (int) $credit->points;
		$balance   = HWE_Loyalty_Ledger::balance( $userId );
		$available = max( 0, (int) ( $balance['balance'] ?? 0 ) );
		$take      = min( $points, $available );

		if ( $take <= 0 ) {
			self::log(
				'la reversión del abono «' . self::label( $key ) . '» no recupera nada: el usuario '
				. $userId . ' no tiene saldo.'
			);
			return null;
		}

		$type = 'earn_reversal';
		$ref  = array(
			'type' => null === $credit->ref_type ? null : (string) $credit->ref_type,
			'id'   => null === $credit->ref_id ? null : (string) $credit->ref_id,
		);
		$data = array(
			'credit'        => (int) $credit->id,
			'original_type' => (string) $credit->type,
		);
		if ( 'order' === (string) $credit->ref_type && is_numeric( $credit->ref_id ) ) {
			$data['order'] = (int) $credit->ref_id;
		}

		return HWE_Loyalty_Ledger::debit( $userId, $take, $type, $debitKey, $ref, $data );
	}

	/**
	 * Fila del libro con esa clave, sea abono o cargo. La escritura y sus importes son
	 * inmutables, así que leerla fuera de la transacción es seguro; el cargo de reversión
	 * vuelve a comprobar la clave y el saldo ya bloqueados dentro de su propia transacción.
	 */
	private static function entryByKey( string $key ): ?object {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . HWE_Loyalty_Schema::ledgerTable()
				. ' WHERE idempotency_key = %s LIMIT 1',
				$key
			)
		);

		return is_object( $row ) ? $row : null;
	}

	private static function normalizeKey( string $key ): string {
		return substr( trim( $key ), 0, self::KEY_MAX );
	}

	/** Texto técnico saneado para un log: solo letras, números y separadores habituales. */
	private static function label( string $value ): string {
		$clean = (string) preg_replace( '/[^a-z0-9_:.\-]/i', '', $value );
		return substr( $clean, 0, 80 );
	}

	private static function log( string $message ): void {
		error_log( '[hwe] loyalty: ' . $message );
	}
}

/**
 * ¿Está disponible una función del programa? `points` | `referrals`; cualquier otro valor,
 * `false`.
 */
function hwe_loyalty_available( string $feature ): bool {
	return HWE_Loyalty_Api::available( $feature );
}

/**
 * Abona puntos desde otro módulo (tipo declarado, referencia y clave de idempotencia).
 *
 * @param array<mixed> $ref
 * @param array<mixed> $data
 */
function hwe_loyalty_credit(
	int $userId,
	int $points,
	string $type,
	string $key,
	array $ref = array(),
	array $data = array()
): ?int {
	return HWE_Loyalty_Api::credit( $userId, $points, $type, $key, $ref, $data );
}

/**
 * Revierte por clave un abono hecho con `hwe_loyalty_credit()` (limitado al saldo, idempotente
 * por `$reversalKey`).
 */
function hwe_loyalty_reverse_credit( string $key, string $reversalKey ): ?int {
	return HWE_Loyalty_Api::reverseCredit( $key, $reversalKey );
}
