<?php
/**
 * Menú propio de wp-admin, de título neutro («Membresías»), con las pantallas del módulo en el
 * orden del spec §11.1: resumen, niveles (con sus beneficios y la comparativa), socios e
 * historial. La pantalla en masa (§11.3) llegará con su tarea.
 *
 * La capacidad de lectura (`hwe_memberships_view`) abre el menú; las pantallas de escritura
 * exigen además `hwe_memberships_manage`. El CPT de niveles se cuelga de este menú
 * (`show_in_menu` → `MAIN`, ver `HWE_Memberships_Levels::registerPostType()`): el listado nativo
 * de WordPress queda como submenú «Todos los niveles» y la portada del módulo sigue siendo la
 * tabla de niveles con sus accesos.
 *
 * El menú solo se registra con el módulo activo: la puerta está en
 * `HWE_Memberships_Admin::register()`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Admin_Menu {

	/** Slug de la página principal (el que aparece en `?page=`). */
	public const MAIN = 'hwe-memberships';

	/** Resumen del módulo (§11.1). */
	public const SUMMARY = 'summary';

	/** Pantalla de beneficios por nivel. */
	public const BENEFITS = 'benefits';

	/** Pantalla comparativa de niveles × beneficios. */
	public const COMPARISON = 'comparison';

	/** Listado de socios (§11.1). */
	public const MEMBERS = 'members';

	/** Historial de membresías (§11.1). */
	public const HISTORY = 'history';

	/** Engancha el menú. La llama `HWE_Memberships_Admin::register()`. */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'addMenu' ) );
	}

	public static function addMenu(): void {
		$cap = HWE_Memberships_Admin_Capabilities::CAP_VIEW;

		add_menu_page(
			__( 'Membresías', 'hwe' ),
			__( 'Membresías', 'hwe' ),
			$cap,
			self::MAIN,
			array( HWE_Memberships_Admin_Levels_Screen::class, 'render' ),
			'dashicons-id-alt',
			57
		);

		add_submenu_page(
			self::MAIN,
			__( 'Resumen', 'hwe' ),
			__( 'Resumen', 'hwe' ),
			$cap,
			self::slug( self::SUMMARY ),
			array( HWE_Memberships_Admin_Stats::class, 'render' )
		);

		add_submenu_page(
			self::MAIN,
			__( 'Niveles', 'hwe' ),
			__( 'Niveles', 'hwe' ),
			$cap,
			self::MAIN,
			array( HWE_Memberships_Admin_Levels_Screen::class, 'render' )
		);

		add_submenu_page(
			self::MAIN,
			__( 'Beneficios', 'hwe' ),
			__( 'Beneficios', 'hwe' ),
			$cap,
			self::slug( self::BENEFITS ),
			array( HWE_Memberships_Admin_Benefits_Screen::class, 'render' )
		);

		add_submenu_page(
			self::MAIN,
			__( 'Comparativa', 'hwe' ),
			__( 'Comparativa', 'hwe' ),
			$cap,
			self::slug( self::COMPARISON ),
			array( HWE_Memberships_Admin_Benefits_Screen::class, 'renderComparison' )
		);

		add_submenu_page(
			self::MAIN,
			__( 'Socios', 'hwe' ),
			__( 'Socios', 'hwe' ),
			$cap,
			self::slug( self::MEMBERS ),
			array( HWE_Memberships_Admin_Members_Screen::class, 'render' )
		);

		add_submenu_page(
			self::MAIN,
			__( 'Historial', 'hwe' ),
			__( 'Historial', 'hwe' ),
			$cap,
			self::slug( self::HISTORY ),
			array( HWE_Memberships_Admin_History_Screen::class, 'render' )
		);
	}

	/**
	 * URL de una pantalla del módulo.
	 *
	 * @param array<string,mixed> $args
	 */
	public static function url( string $screen = self::MAIN, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::slug( $screen ) ), $args ), admin_url( 'admin.php' ) );
	}

	/** Slug de WordPress para una pantalla estable. */
	public static function slug( string $screen ): string {
		return self::MAIN === $screen ? self::MAIN : 'hwe-memberships-' . $screen;
	}
}
