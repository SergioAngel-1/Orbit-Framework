<?php
/**
 * Exportación CSV de socios (spec §11.1).
 *
 * Columnas: id de usuario, número de socio, nombre visible, nivel, estado y fin de la cobertura
 * en UTC. **No** incluye email ni login: el equipo los tiene en el listado de usuarios, pero un
 * CSV circula fuera del panel y no necesita llevar la identidad completa.
 *
 * El volcado va **por lotes** (`fputcsv` sobre la salida, sin acumular el histórico en memoria) y
 * cada celda pasa por `csvCell`, que neutraliza lo que Excel o LibreOffice ejecutarían como
 * fórmula (`=`, `+`, `-`, `@`, tabulador y retorno de carro). Un número negativo sigue siendo un
 * dato: escaparlo convertiría cada id en texto.
 *
 * Exige `hwe_memberships_manage` (no basta `hwe_memberships_view`): es una exportación masiva y
 * la pantalla oculta la acción a quien solo puede consultar.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Admin_Csv {

	/** Acción de `admin-post.php`. */
	public const ACTION = 'hwe_memberships_export';

	/** Acción del nonce de la descarga. */
	public const NONCE = 'hwe_memberships_export';

	/** Filas por lote del volcado. */
	public const BATCH = 500;

	/** Engancha la descarga. La llama `HWE_Memberships_Admin::register()`. */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'export' ) );
	}

	/**
	 * Exportar es una descarga masiva (aunque no lleve email ni login): exige la capacidad de
	 * gestión, no la de lectura. La pantalla usa esta misma decisión para ocultar la acción.
	 */
	public static function canExport(): bool {
		return class_exists( 'HWE_Memberships_Admin_Capabilities' )
			&& HWE_Memberships_Admin_Capabilities::canManage();
	}

	/**
	 * Celda inerte: prefijo `'` si el primer carácter es peligroso para una hoja de cálculo. Un
	 * número (también negativo) es un dato, no una fórmula.
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
			'usuario_id',
			'numero_socio',
			'usuario',
			'nivel',
			'estado',
			'fin_utc',
		);
	}

	/**
	 * Escribe las filas en un recurso ya abierto. Devuelve cuántas escribió. Se usa también en
	 * los tests con `php://memory` para probar el volcado sin enviar cabeceras ni salir.
	 *
	 * @param resource $handle
	 * @param array{level:int,status:string,origin:string,orderby:string,order:string,paged:int} $filters
	 */
	public static function writeRows( mixed $handle, array $filters ): int {
		if ( ! is_resource( $handle ) ) {
			return 0;
		}

		$written = 0;
		$offset  = 0;
		$nowSql  = gmdate( 'Y-m-d H:i:s', time() );

		do {
			$page = HWE_Memberships_Admin_Members_Screen::items( $filters, self::BATCH, $offset );
			foreach ( $page as $row ) {
				$levelId = (int) ( $row->level_id ?? 0 );
				$level   = $levelId > 0 ? HWE_Memberships_Levels::get( $levelId ) : null;
				$line    = array(
					(int) ( $row->user_id ?? 0 ),
					(string) ( $row->member_number ?? '' ),
					(string) ( $row->display_name ?? '' ),
					null === $level ? ( $levelId > 0 ? '#' . $levelId : '' ) : (string) $level['name'],
					HWE_Memberships_Admin_Members_Screen::statusLabel(
						HWE_Memberships_Admin_Members_Screen::statusOf( $row, $nowSql )
					),
					(string) ( $row->covers_until ?? '' ),
				);

				fputcsv( $handle, array_map( array( self::class, 'csvCell' ), $line ), ',', '"', '\\' );
				$written++;
			}

			$offset += self::BATCH;
		} while ( count( $page ) === self::BATCH );

		return $written;
	}

	/** POST/GET de `admin-post.php`: capacidad + nonce, cabeceras y streaming. */
	public static function export(): void {
		if ( ! self::canExport() ) {
			wp_die( esc_html__( 'No autorizado.', 'hwe' ) );
			return;
		}

		$nonce = isset( $_GET['_wpnonce'] ) && is_scalar( $_GET['_wpnonce'] )
			? sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			wp_die( esc_html__( 'Nonce no válido.', 'hwe' ) );
			return;
		}

		$filters = HWE_Memberships_Admin_Members_Screen::filters( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="membresias-socios-' . gmdate( 'Ymd-His' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			error_log( '[hwe] memberships: no se pudo abrir la salida del CSV de socios.' );
			return;
		}

		fwrite( $out, "\xEF\xBB\xBF" ); // BOM: Excel abre el UTF-8 sin romper las tildes.
		fputcsv( $out, self::columns(), ',', '"', '\\' );
		$written = self::writeRows( $out, $filters );
		fclose( $out );

		error_log( '[hwe] memberships: CSV de socios exportado con ' . $written . ' filas.' );
	}
}
