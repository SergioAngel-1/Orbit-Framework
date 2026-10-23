<?php

namespace HWE\Shipping\Distance;

/**
 * Prepara una dirección escrita para el geocodificador. Las reglas no van en el código sino en el
 * paquete de país (`geocoding` de `hwe-geo`): abreviaturas de vía que se expanden y tokens que se
 * descartan. Con el paquete genérico solo se limpian los espacios.
 *
 * Cómo trabaja:
 * - El texto se parte por comas. El primer segmento es la dirección; los siguientes son
 *   complementos (apartamento, torre…) si empiezan por un token descartable: se quitan enteros.
 *   Los demás (p. ej. la ciudad) se conservan, porque sin ciudad el geocodificador acierta peor.
 * - Dentro de cada segmento, los tokens descartables se quitan y las abreviaturas se expanden
 *   (sin distinguir mayúsculas y con o sin punto final).
 * - Regla de la letra suelta: un token descartable de UNA sola letra (la letra de vía de «Calle
 *   10 A») solo se descarta si el token anterior del mismo segmento contiene un dígito. Así
 *   «Diagonal A 12» y «Juan Y Pedro» conservan su letra. El resto de tokens descartables se quita
 *   en cualquier posición.
 */
final class AddressNormalizer {

	/**
	 * @param array<string,mixed> $pack Paquete de país con la clave `geocoding` (o sin ella).
	 */
	public static function forGeocoding( string $raw, array $pack ): string {
		$rules         = is_array( $pack['geocoding'] ?? null ) ? $pack['geocoding'] : array();
		$abbreviations = self::abbreviations( $rules['abbreviations'] ?? array() );
		$drop          = self::dropSet( $rules['drop_tokens'] ?? array() );

		$parts = array();
		foreach ( explode( ',', $raw ) as $index => $segment ) {
			$tokens = self::tokens( $segment );
			if ( array() === $tokens ) {
				continue;
			}
			if ( $index > 0 && self::isDropped( $tokens[0], null, $drop ) ) {
				continue; // Complemento tras la coma: se quita entero.
			}

			$kept     = array();
			$previous = null;
			foreach ( $tokens as $token ) {
				if ( ! self::isDropped( $token, $previous, $drop ) ) {
					$kept[] = $abbreviations[ mb_strtolower( rtrim( $token, '.' ) ) ] ?? $token;
				}
				$previous = $token;
			}
			if ( array() !== $kept ) {
				$parts[] = implode( ' ', $kept );
			}
		}

		return implode( ', ', $parts );
	}

	/**
	 * Si el token se descarta. Una letra suelta solo cuenta como descartable tras un token con
	 * dígito (la letra de vía tras el número); en cualquier otro caso se conserva.
	 *
	 * @param array<string,true> $drop
	 */
	private static function isDropped( string $token, ?string $previous, array $drop ): bool {
		if ( ! isset( $drop[ mb_strtolower( $token ) ] ) ) {
			return false;
		}
		if ( 1 === preg_match( '/^\p{L}$/u', $token ) ) {
			return null !== $previous && 1 === preg_match( '/\d/', $previous );
		}
		return true;
	}

	/** @return string[] Tokens separados por espacios, sin vacíos. */
	private static function tokens( string $segment ): array {
		return preg_split( '/\s+/', trim( $segment ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
	}

	/**
	 * @param mixed $raw
	 * @return array<string,string> Abreviatura en minúsculas → forma completa.
	 */
	private static function abbreviations( $raw ): array {
		$map = array();
		foreach ( is_array( $raw ) ? $raw : array() as $abbr => $full ) {
			if ( is_string( $abbr ) && '' !== trim( $abbr ) && is_string( $full ) ) {
				$map[ mb_strtolower( trim( $abbr ) ) ] = $full;
			}
		}
		return $map;
	}

	/**
	 * @param mixed $raw
	 * @return array<string,true> Tokens descartables en minúsculas, como conjunto.
	 */
	private static function dropSet( $raw ): array {
		$set = array();
		foreach ( is_array( $raw ) ? $raw : array() as $token ) {
			if ( is_string( $token ) && '' !== trim( $token ) ) {
				$set[ mb_strtolower( trim( $token ) ) ] = true;
			}
		}
		return $set;
	}
}
