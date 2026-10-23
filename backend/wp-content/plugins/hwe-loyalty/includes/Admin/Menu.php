<?php
/**
 * Menú propio de wp-admin, de título neutro («Fidelización»), con sus cinco pantallas.
 *
 * La capacidad de lectura (`hwe_loyalty_view`) abre el menú; las acciones de escritura exigen
 * además `hwe_loyalty_manage`, que comprueban sus manejadores. El menú solo se registra con el
 * módulo activo (ver `HWE_Loyalty_Admin::register()`).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Loyalty_Admin_Menu {

	public const SUMMARY   = 'summary';
	public const MOVEMENTS = 'movements';
	public const NETWORK   = 'network';
	public const ADJUST    = 'adjust';
	public const CODES     = 'codes';

	/** Slug de la página principal (el que aparece en `?page=`). */
	public const MAIN = 'hwe-loyalty';

	/** Engancha el menú. La llama `HWE_Loyalty_Admin::register()`. */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'addMenu' ) );
	}

	public static function addMenu(): void {
		$cap = HWE_Loyalty_Admin_Capabilities::CAP_VIEW;

		add_menu_page(
			__( 'Fidelización', 'hwe' ),
			__( 'Fidelización', 'hwe' ),
			$cap,
			self::MAIN,
			array( HWE_Loyalty_Admin_Pages::class, 'renderSummary' ),
			'dashicons-awards',
			56
		);

		add_submenu_page(
			self::MAIN,
			__( 'Resumen', 'hwe' ),
			__( 'Resumen', 'hwe' ),
			$cap,
			self::MAIN,
			array( HWE_Loyalty_Admin_Pages::class, 'renderSummary' )
		);

		add_submenu_page(
			self::MAIN,
			__( 'Movimientos', 'hwe' ),
			__( 'Movimientos', 'hwe' ),
			$cap,
			self::slug( self::MOVEMENTS ),
			array( HWE_Loyalty_Admin_Pages::class, 'renderMovements' )
		);

		add_submenu_page(
			self::MAIN,
			__( 'Red', 'hwe' ),
			__( 'Red', 'hwe' ),
			$cap,
			self::slug( self::NETWORK ),
			array( HWE_Loyalty_Admin_Pages::class, 'renderNetwork' )
		);

		add_submenu_page(
			self::MAIN,
			__( 'Ajuste manual', 'hwe' ),
			__( 'Ajuste manual', 'hwe' ),
			$cap,
			self::slug( self::ADJUST ),
			array( HWE_Loyalty_Admin_Pages::class, 'renderAdjust' )
		);

		add_submenu_page(
			self::MAIN,
			__( 'Códigos', 'hwe' ),
			__( 'Códigos', 'hwe' ),
			$cap,
			self::slug( self::CODES ),
			array( HWE_Loyalty_Admin_Pages::class, 'renderCodes' )
		);
	}

	/**
	 * URL de una pantalla del módulo.
	 *
	 * @param array<string,mixed> $args
	 */
	public static function url( string $screen = self::SUMMARY, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::slug( $screen ) ), $args ), admin_url( 'admin.php' ) );
	}

	/** Slug de WordPress para una pantalla estable. */
	public static function slug( string $screen ): string {
		return self::SUMMARY === $screen ? self::MAIN : 'hwe-loyalty-' . $screen;
	}
}
