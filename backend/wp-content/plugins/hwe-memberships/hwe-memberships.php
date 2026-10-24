<?php
/**
 * Plugin Name: HWE Memberships
 * Description: Membresías: niveles y beneficios editables, carné de socio con verificación por
 *              QR, renovaciones y regalos por referido. Es un módulo opcional del framework y no
 *              trae vistas: entrega el contrato de configuración y la lógica del backend.
 * Author:      Sergio Jauregui
 * Version:     1.0.0
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Text Domain: hwe
 *
 * Este archivo solo arranca el plugin:
 *  · comprueba que WooCommerce esté presente (sin él no hay pedidos ni clientes a los que aplicar membresías);
 *  · carga `includes/`;
 *  · engancha los ajustes del módulo al grupo reservado `memberships` del HWE Control Center.
 *
 * Ajustes (Control Center → Membresías): los campos de `includes/Settings.php`. El grupo lo
 * declara vacío el núcleo (`SchemaGroups/Memberships.php`) y este plugin lo puebla con
 * `hwe_control_center_schema`; así el panel, la API pública `/wp-json/hwe/v1/config`,
 * `wp hwe setup` y la revalidación de `site-config` comparten una sola configuración.
 *
 * Con el módulo apagado (plugin inactivo) no hay ajustes activos, los lectores del frontend
 * devuelven vacío y las rutas del BFF responden 404. La puerta la calcula el núcleo con
 * `config.modules.memberships`, que refleja si este plugin está activo.
 *
 * Estado: arranque, ajustes, esquema de datos, niveles editables y el servicio transaccional de
 * concesiones (alta, renovación, mejora, revocación, retirada, congelación y descongelación) con
 * su historial y sus hooks. Las rutas internas, la extensión de la Store API y la UI del
 * frontend llegan en entregas posteriores; README.md y MANUAL.md se completan con ellas.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HWE_MEMBERSHIPS_VERSION', '1.0.0' );
define( 'HWE_MEMBERSHIPS_DIR', __DIR__ );

require_once __DIR__ . '/includes/Settings.php';
require_once __DIR__ . '/includes/Schema.php';
require_once __DIR__ . '/includes/Levels.php';
require_once __DIR__ . '/includes/Timeline.php';
require_once __DIR__ . '/includes/Events.php';
require_once __DIR__ . '/includes/Members.php';
require_once __DIR__ . '/includes/Grants.php';

final class HWE_Memberships {

	/**
	 * Arranque del módulo. Se engancha a `plugins_loaded` con prioridad tardía: para entonces
	 * WooCommerce ya se ha cargado (o no está, y el módulo no debe hacer nada).
	 */
	public static function boot(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			error_log( '[hwe] memberships: WooCommerce no está activo; el módulo no se arranca.' );
			add_action( 'admin_notices', array( self::class, 'woocommerceMissingNotice' ) );
			return;
		}

		HWE_Memberships_Settings::register();
		HWE_Memberships_Schema::register();
		HWE_Memberships_Levels::register();
	}

	/** Aviso en wp-admin si el plugin se activó sin WooCommerce (la cabecera ya lo impide). */
	public static function woocommerceMissingNotice(): void {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'HWE Memberships necesita WooCommerce activo: sin él no hay clientes ni pedidos a los que aplicar membresías.', 'hwe' )
			. '</p></div>';
	}
}

add_action( 'plugins_loaded', array( 'HWE_Memberships', 'boot' ), 20 );

// La activación crea o repara las tablas. La instalación perezosa de `init` cubre el resto de
// los casos (actualización del plugin, tabla borrada a mano) sin depender de la activación.
if ( function_exists( 'register_activation_hook' ) ) {
	register_activation_hook( __FILE__, array( 'HWE_Memberships_Schema', 'install' ) );
}
