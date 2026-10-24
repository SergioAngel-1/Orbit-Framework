<?php
/**
 * Capacidades propias del módulo (spec §11.4).
 *
 * `hwe_memberships_view` lee (menú, niveles, beneficios y comparativa) y
 * `hwe_memberships_manage` escribe (guardar los beneficios de un nivel y, en las tareas de
 * socios, las acciones sobre ellos). Se conceden solo a `administrator` y `shop_manager`: un rol
 * creado por una instancia nunca las recibe por su cuenta, y si la instancia quiere dárselas lo
 * hace explícitamente con `add_cap`.
 *
 * La activación del plugin concede las dos. Además, `admin_menu` (prioridad 1, antes de que
 * WordPress compruebe el acceso a las páginas del módulo) y `admin_init` las autorreparan si
 * faltan: es la misma idea que el evento diario del módulo, barata e idempotente. Solo toca los
 * dos roles del núcleo; un rol de la instancia ni se consulta.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Admin_Capabilities {

	/** Capacidad de lectura. Coincide con la que declara el CPT de niveles. */
	public const CAP_VIEW = 'hwe_memberships_view';

	/** Capacidad de gestión. Coincide con la que declara el CPT de niveles. */
	public const CAP_MANAGE = 'hwe_memberships_manage';

	/** Archivo del plugin que dispara la concesión en `activated_plugin`. */
	public const PLUGIN_FILE = 'hwe-memberships/hwe-memberships.php';

	/** Roles del núcleo que reciben las capacidades. Nunca se amplía desde la instancia. */
	private const CORE_ROLES = array( 'administrator', 'shop_manager' );

	/** Engancha la autorreparación. La llama `HWE_Memberships_Admin::register()`. */
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
		if ( self::PLUGIN_FILE === $plugin ) {
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
	 * Autorreparación: si `administrator` o `shop_manager` perdieron alguna capacidad, se
	 * vuelven a conceder a los dos. Sin escrituras cuando ya las tienen. Si el rol no existe
	 * todavía (arranque de WordPress), no hace nada: la activación o la siguiente pasada lo
	 * resolverán.
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

		error_log( '[hwe] memberships: faltaban las capacidades de administración y se volvieron a conceder a administrator y shop_manager.' );
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
