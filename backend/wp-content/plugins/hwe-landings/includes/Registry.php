<?php

namespace HWE\Landings;

use HWE\ControlCenter\Storage;

/**
 * Las landings registradas: las estáticas que declara la instancia (con diseño propio, ligadas
 * a código) y las creadas desde el panel (campo `landings.registry`).
 *
 * Valor guardado del registro: texto multilínea `identificador | Nombre | tipo` (una landing por
 * línea; tipo `article` o `person`). Es un escalar a propósito: `Storage::merge` lo reemplaza
 * entero al quitar una fila (una lista se fusionaría por índice y dejaría entradas huérfanas).
 *
 * El IDENTIFICADOR es interno e inmutable: es la clave bajo la que cuelga el contenido
 * (`landings.editorial.<id>`) y la locación del banner del hero (`landing-<id>`). La URL pública
 * vive en campos aparte (`url.base`, `url.path`) y se puede cambiar sin huérfanar nada.
 */
final class Registry {

	/** Dónde cuelga la URL pública: el área educativa (`/learn/<ruta>`) o la raíz del sitio. */
	public const BASES = array( 'area', 'root' );

	/** Tipo de datos estructurados de la landing. */
	public const KINDS = array( 'article', 'person' );

	/**
	 * Landings con diseño propio que declara la instancia (`id => ['label', 'jsonld', 'base']`).
	 * Una entrada mal formada se descarta y se registra; un id repetido gana el primero.
	 *
	 * @return array<string,array{label:string,jsonld:string,base:string}>
	 */
	public static function staticEntries(): array {
		$raw = apply_filters( 'hwe_landings_static_entries', array() );
		if ( ! is_array( $raw ) ) {
			error_log( '[hwe] landings: el filtro hwe_landings_static_entries debe devolver un mapa id → datos; se ignora.' );
			return array();
		}
		$out = array();
		foreach ( $raw as $id => $entry ) {
			$id = is_string( $id ) ? sanitize_title( $id ) : '';
			if ( '' === $id || ! is_array( $entry ) ) {
				error_log( '[hwe] landings: entrada descartada de hwe_landings_static_entries (id no válido o datos que no son una lista).' );
				continue;
			}
			if ( isset( $out[ $id ] ) ) {
				continue;
			}
			$label = isset( $entry['label'] ) && is_string( $entry['label'] ) ? sanitize_text_field( $entry['label'] ) : '';
			$out[ $id ] = array(
				'label'  => '' !== $label ? $label : $id,
				'jsonld' => self::kind( $entry['jsonld'] ?? '' ),
				'base'   => self::base( $entry['base'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Filas de un valor de registro (`identificador | Nombre | tipo`). Líneas sin identificador se
	 * descartan; un identificador repetido conserva la primera fila.
	 *
	 * @return list<array{slug:string,label:string,jsonld:string}>
	 */
	public static function parse( string $value ): array {
		$out  = array();
		$seen = array();
		foreach ( preg_split( '/\r\n|\r|\n/', trim( $value ) ) ?: array() as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line ) );
			$slug  = sanitize_title( $parts[0] );
			if ( '' === $slug || isset( $seen[ $slug ] ) ) {
				continue;
			}
			$seen[ $slug ] = true;
			$label         = $parts[1] ?? '';
			$out[]         = array(
				'slug'   => $slug,
				'label'  => '' !== $label ? $label : $slug,
				'jsonld' => self::kind( $parts[2] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * @param list<array{slug:string,label:string,jsonld:string}> $rows
	 */
	public static function serialize( array $rows ): string {
		$lines = array();
		foreach ( $rows as $row ) {
			// Una barra vertical o un salto de línea en el nombre rompería el formato.
			$label   = trim( (string) preg_replace( '/[\s|]+/', ' ', $row['label'] ) );
			$lines[] = $row['slug'] . ' | ' . ( '' !== $label ? $label : $row['slug'] ) . ' | ' . $row['jsonld'];
		}
		return implode( "\n", $lines );
	}

	/** Valor guardado del registro. */
	public static function stored(): string {
		$value = Storage::get( array( 'landings', 'registry' ), '' );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Todas las landings: las estáticas y las del registro. Una del panel cuyo identificador
	 * choque con una estática se ignora (la estática manda: lleva secciones de código).
	 *
	 * @return array<string,array{label:string,jsonld:string,base:string,dynamic:bool,registry_index?:int}>
	 */
	public static function all(): array {
		$out = array();
		foreach ( self::staticEntries() as $id => $meta ) {
			$out[ $id ] = $meta + array( 'dynamic' => false );
		}
		foreach ( self::parse( self::stored() ) as $index => $row ) {
			if ( isset( $out[ $row['slug'] ] ) ) {
				continue;
			}
			$out[ $row['slug'] ] = array(
				'label'          => $row['label'],
				'jsonld'         => $row['jsonld'],
				// Una landing nacida del panel cuelga del área; se mueve a la raíz desde su bloque.
				'base'           => 'area',
				'dynamic'        => true,
				'registry_index' => $index,
			);
		}
		return $out;
	}

	public static function kind( mixed $value ): string {
		return 'person' === $value ? 'person' : 'article';
	}

	public static function base( mixed $value ): string {
		return in_array( $value, self::BASES, true ) ? (string) $value : 'area';
	}
}
