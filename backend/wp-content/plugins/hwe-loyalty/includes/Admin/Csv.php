<?php
/**
 * Exportación CSV de movimientos (spec §12).
 *
 * Columnas: id, fecha UTC, id de usuario, nombre visible, tipo, cantidad, remanente, referencia,
 * actor, nota y caducidad. **No** incluye email ni login: el equipo ya los ve en el listado de
 * usuarios, pero un CSV circula fuera del panel y no necesita llevar la identidad completa. La
 * nota es la que escribió un administrador (o la trazabilidad automática del libro).
 *
 * Volcado por lotes con `fputcsv` directamente a la salida (streaming): no se acumula el
 * histórico en memoria. Cada celda pasa por `csvCell`, que neutraliza los valores que Excel o
 * LibreOffice ejecutarían como fórmula (`=`, `+`, `-`, `@`, tabulador y retorno de carro).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Loyalty_Admin_Csv {

	public const ACTION = 'hwe_loyalty_export';
	public const NONCE  = 'hwe_loyalty_export';

	/** Engancha la descarga. La llama `HWE_Loyalty_Admin::register()`. */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'export' ) );
	}

	/**
	 * Celda inerte: prefijo `'` si el primer carácter es peligroso para una hoja de cálculo. Un
	 * número (también negativo) es un dato, no una fórmula: escaparlo lo convertiría en texto.
	 */
	public static function csvCell( mixed $value ): string {
		$value = (string) $value;
		if ( 1 === preg_match( '/^-?\d+(\.\d+)?$/', $value ) ) {
			return $value;
		}
		return '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ? "'" . $value : $value;
	}

	/** @return list<string> */
	public static function columns(): array {
		return array(
			'id',
			'fecha_utc',
			'usuario_id',
			'usuario',
			'tipo',
			'cantidad',
			'remanente',
			'referencia',
			'actor',
			'nota',
			'caduca_utc',
		);
	}

	/**
	 * Escribe las filas en un recurso ya abierto. Devuelve cuántas escribió. Se usa también en
	 * los tests con `php://memory` para probar el volcado sin enviar cabeceras ni salir.
	 *
	 * @param resource                                                             $handle
	 * @param array{user_id:int,type:string,from:string,to:string,order_id:int} $filters
	 */
	public static function writeRows( mixed $handle, array $filters ): int {
		if ( ! is_resource( $handle ) ) {
			return 0;
		}

		$written = 0;
		$offset  = 0;

		do {
			$page = HWE_Loyalty_Admin_Movements::items( $filters, HWE_Loyalty_Admin_Movements::CSV_BATCH, $offset );
			foreach ( $page as $row ) {
				$line = array(
					(int) ( $row->id ?? 0 ),
					(string) ( $row->created_at ?? '' ),
					(int) ( $row->user_id ?? 0 ),
					(string) ( $row->display_name ?? '' ),
					(string) ( $row->type ?? '' ),
					(int) ( $row->points ?? 0 ),
					null === ( $row->remaining_points ?? null ) ? '' : (int) $row->remaining_points,
					self::reference( $row ),
					(string) ( $row->actor ?? '' ),
					(string) ( $row->note ?? '' ),
					(string) ( $row->expires_at ?? '' ),
				);

				fputcsv( $handle, array_map( array( self::class, 'csvCell' ), $line ), ',', '"', '\\' );
				$written++;
			}

			$offset += HWE_Loyalty_Admin_Movements::CSV_BATCH;
		} while ( count( $page ) === HWE_Loyalty_Admin_Movements::CSV_BATCH );

		return $written;
	}

	/** POST/GET de `admin-post.php`: capacidad + nonce, cabeceras y streaming. */
	public static function export(): void {
		if ( ! HWE_Loyalty_Admin_Capabilities::canView() ) {
			wp_die( 'No autorizado.', 403 );
			return;
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			wp_die( 'Nonce no válido.', 403 );
			return;
		}

		$filters = HWE_Loyalty_Admin_Movements::filters( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="fidelizacion-movimientos-' . gmdate( 'Ymd-His' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			error_log( '[hwe] loyalty: no se pudo abrir la salida del CSV de movimientos.' );
			return;
		}

		fwrite( $out, "\xEF\xBB\xBF" ); // BOM: Excel abre el UTF-8 sin romper las tildes.
		fputcsv( $out, self::columns(), ',', '"', '\\' );
		$written = self::writeRows( $out, $filters );
		fclose( $out );

		error_log( '[hwe] loyalty: CSV de movimientos exportado con ' . $written . ' filas.' );
	}

	private static function reference( object $row ): string {
		$type = (string) ( $row->ref_type ?? '' );
		$id   = (string) ( $row->ref_id ?? '' );
		return '' === $type || '' === $id ? '' : $type . ':' . $id;
	}
}
