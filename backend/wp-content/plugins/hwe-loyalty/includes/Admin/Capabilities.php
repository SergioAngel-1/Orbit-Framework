<?php
/**
 * Capacidades propias del módulo (spec §12).
 *
 * `hwe_loyalty_view` lee (resumen, movimientos, red, perfil y pedido) y `hwe_loyalty_manage`
 * escribe (ajuste manual, código, referidor y lote de códigos). Se conceden solo a
 * `administrator` y `shop_manager`: un rol creado por una instancia nunca las recibe por su
 * cuenta, y si la instancia quiere dárselas lo hace explícitamente con `add_cap`.
 *
 * La activación del plugin concede las dos. Además, `admin_init` las autorrepara si faltan en
 * `administrator` (por ejemplo, tras actualizar el plugin con una versión anterior o si alguien
 * editó el rol): es la misma idea que el evento diario del módulo, barata e idempotente. Solo
 * toca los dos roles del núcleo; un rol de la instancia ni se consulta.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Loyalty_Admin_Capabilities {

	public const CAP_VIEW   = 'hwe_loyalty_view';
	public const CAP_MANAGE = 'hwe_loyalty_manage';

	/** Roles del núcleo que reciben las capacidades. Nunca se amplía desde la instancia. */
	private const CORE_ROLES = array( 'administrator', 'shop_manager' );

	/** Engancha la autorreparación. La llama `HWE_Loyalty_Admin::register()`. */
	public static function register(): void {
		// `wp-admin/menu.php` comprueba el acceso a `admin.php?page=…` ANTES de `admin_init`, así
		// que en las páginas del módulo la reparación tiene que llegar en `admin_menu`, antes de
		// registrar el menú (prioridad 10). `admin_init` cubre `admin-post.php` y AJAX.
		add_action( 'admin_menu', array( self::class, 'maybeRepair' ), 1 );
		add_action( 'admin_init', array( self::class, 'maybeRepair' ) );
	}

	/**
	 * `activated_plugin`: concede las capacidades en la misma petición de activación. El hook
	 * llega también cuando se activa otro plugin, así que se filtra por el archivo de este
	 * módulo; conceder de más sería inocuo, pero ensuciaría el ciclo de vida ajeno.
	 */
	public static function onActivated( string $plugin ): void {
		if ( 'hwe-loyalty/hwe-loyalty.php' === $plugin ) {
			self::grant();
		}
	}

	/**
	 * Concede las dos capacidades a los dos roles del núcleo. Idempotente: `add_cap` con el
	 * mismo valor no cambia nada. Un rol ausente (WooCommerce sin `shop_manager`) se salta sin
	 * ruido; no es un error de esta instalación.
	 */
	public static function grant(): void {
		if ( ! function_exists( 'get_role' ) ) {
			return;
		}

		foreach ( self::CORE_ROLES as $slug ) {
			$role = get_role( $slug );
			if ( ! is_object( $role ) || ! method_exists( $role, 'add_cap' ) ) {
				continue;
			}

			$role->add_cap( self::CAP_VIEW );
			$role->add_cap( self::CAP_MANAGE );
		}
	}

	/**
	 * Autorreparación en `admin_init`: si `administrator` perdió las capacidades, se vuelven a
	 * conceder a los dos roles. Si el rol no existe todavía (arranque de WordPress), no hace
	 * nada: la activación o la siguiente pasada lo resolverán.
	 */
	public static function maybeRepair(): void {
		if ( ! function_exists( 'get_role' ) ) {
			return;
		}

		$missing = false;
		foreach ( self::CORE_ROLES as $slug ) {
			$role = get_role( $slug );
			if ( ! is_object( $role ) || ! method_exists( $role, 'has_cap' ) ) {
				continue;
			}
			if ( ! $role->has_cap( self::CAP_VIEW ) || ! $role->has_cap( self::CAP_MANAGE ) ) {
				$missing = true;
				break;
			}
		}

		if ( ! $missing ) {
			return;
		}

		self::grant();
		// El usuario de esta petición ya cargó sus capacidades: sin refrescarlas, seguiría
		// viendo un 403 hasta la siguiente.
		$current = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		if ( is_object( $current ) && method_exists( $current, 'get_role_caps' ) ) {
			$current->get_role_caps();
		}
		error_log( '[hwe] loyalty: faltaban las capacidades de administración y se volvieron a conceder a administrator y shop_manager.' );
	}

	/** ¿Puede leer? `manage` implica `view` aunque alguien haya concedido solo la de escritura. */
	public static function canView(): bool {
		return current_user_can( self::CAP_VIEW ) || current_user_can( self::CAP_MANAGE );
	}

	/** ¿Puede escribir? Toda acción de escritura de esta administración la exige. */
	public static function canManage(): bool {
		return current_user_can( self::CAP_MANAGE );
	}
}
