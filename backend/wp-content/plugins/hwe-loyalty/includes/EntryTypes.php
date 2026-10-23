<?php
/**
 * Tipos de movimiento del libro mayor, declarados en un solo sitio (spec §5.2).
 *
 * Cada tipo pertenece a un lado del libro:
 *  · `credit`: suma puntos (abono) y nace con remanente y, si toca, caducidad.
 *  · `debit`: resta puntos (cargo) y consume remanentes de abonos (FIFO).
 *
 * El mapa es ampliable con el filtro `hwe_loyalty_entry_types`, que recibe y devuelve el mismo
 * array `tipo => 'credit'|'debit'`. Un tipo extra declarado por una instancia entra en el libro
 * sin tocar el núcleo; una entrada mal formada se descarta y se registra (nada se traga).
 *
 * Aquí no hay textos para el cliente: la descripción de un movimiento se compone al leer a
 * partir del tipo y de `data`, con el nombre de la unidad de la configuración vigente.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Loyalty_EntryTypes {

	public const CREDIT = 'credit';
	public const DEBIT  = 'debit';

	/**
	 * Tipos de spec §5.2. El orden no importa; el valor, sí.
	 *
	 * @var array<string,string>
	 */
	private const TYPES = array(
		// Abonos.
		'cashback'                => self::CREDIT,
		'referral_commission_l1'  => self::CREDIT,
		'referral_commission_l2'  => self::CREDIT,
		'referral_product_bonus'  => self::CREDIT,
		'referral_signup_l1'      => self::CREDIT,
		'referral_signup_l2'      => self::CREDIT,
		'registration'            => self::CREDIT,
		'review'                  => self::CREDIT,
		'birthday'                => self::CREDIT,
		'transfer_in'             => self::CREDIT,
		'redeem_refund'           => self::CREDIT,
		'admin_credit'            => self::CREDIT,
		// Cargos.
		'redeem'                  => self::DEBIT,
		'transfer_out'            => self::DEBIT,
		'transfer_fee'            => self::DEBIT,
		'expire'                  => self::DEBIT,
		'admin_debit'             => self::DEBIT,
		'earn_reversal'           => self::DEBIT,
	);

	/**
	 * Tipo con el que se reabona un cargo cuando se revierte. La devolución de un canje usa
	 * `redeem_refund` (spec §5.3); los demás son la vuelta natural de su cargo.
	 *
	 * @var array<string,string>
	 */
	private const REFUNDS = array(
		'redeem'      => 'redeem_refund',
		'transfer_out' => 'transfer_in',
		'admin_debit' => 'admin_credit',
	);

	/**
	 * Tipos vigentes: los de arriba, ampliables por filtro.
	 *
	 * @return array<string,string>
	 */
	public static function all(): array {
		$filtered = apply_filters( 'hwe_loyalty_entry_types', self::TYPES );

		if ( ! is_array( $filtered ) ) {
			self::log( 'el filtro hwe_loyalty_entry_types devolvió ' . get_debug_type( $filtered ) . '; se usan los tipos del núcleo.' );
			return self::TYPES;
		}

		$types = array();
		foreach ( $filtered as $type => $side ) {
			if ( ! is_string( $type ) || '' === trim( $type ) || ! is_string( $side ) ) {
				self::log( 'se descarta un tipo de movimiento mal formado en hwe_loyalty_entry_types.' );
				continue;
			}
			if ( self::CREDIT !== $side && self::DEBIT !== $side ) {
				self::log( 'se descarta el tipo de movimiento «' . preg_replace( '/[^a-z0-9_\-]/i', '', $type ) . '»: su lado no es credit ni debit.' );
				continue;
			}
			$types[ trim( $type ) ] = $side;
		}

		return $types;
	}

	/** @return list<string> */
	public static function credits(): array {
		return array_keys( array_filter( self::all(), static fn ( string $side ): bool => self::CREDIT === $side ) );
	}

	/** @return list<string> */
	public static function debits(): array {
		return array_keys( array_filter( self::all(), static fn ( string $side ): bool => self::DEBIT === $side ) );
	}

	public static function exists( string $type ): bool {
		return isset( self::all()[ $type ] );
	}

	public static function isCredit( string $type ): bool {
		return self::CREDIT === ( self::all()[ $type ] ?? null );
	}

	public static function isDebit( string $type ): bool {
		return self::DEBIT === ( self::all()[ $type ] ?? null );
	}

	/**
	 * Tipo del abono que revierte un cargo. Un cargo desconocido (por ejemplo, uno añadido por
	 * una instancia) se devuelve como `admin_credit`, que deja rastro manual en lugar de
	 * inventarse una ganancia.
	 */
	public static function refundFor( string $debitType ): string {
		if ( isset( self::REFUNDS[ $debitType ] ) ) {
			return self::REFUNDS[ $debitType ];
		}

		self::log( 'no hay tipo de devolución para el cargo «' . $debitType . '»; se usa admin_credit.' );
		return 'admin_credit';
	}

	private static function log( string $message ): void {
		error_log( '[hwe] loyalty: ' . $message );
	}
}
