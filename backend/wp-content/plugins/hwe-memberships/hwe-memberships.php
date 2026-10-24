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
 * Estado: arranque, ajustes, esquema de datos, niveles editables, el registro de tipos de
 * beneficio (con la ventaja informativa), la tarea diaria que materializa las transiciones, el
 * servicio transaccional de concesiones (alta, renovación, mejora, revocación, retirada,
 * congelación y descongelación) con su historial y sus hooks, los productos de membresía y su
 * compra con activación al cobrar, los requisitos de acceso al catálogo con acceso anticipado y
 * la ficha de escaparate en GraphQL, la Store API y WC REST, la extensión de la Store API con
 * el bloqueo de compra por requisito y el descuento de miembro en el carrito y el pedido, con
 * `priceFor()` como precio de referencia. Las rutas internas y la UI del frontend llegan en
 * entregas posteriores; README.md y MANUAL.md se completan con ellas.
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
require_once __DIR__ . '/includes/Scheduler.php';
require_once __DIR__ . '/includes/LoyaltyBridge.php';
require_once __DIR__ . '/includes/Benefits/Benefit.php';
require_once __DIR__ . '/includes/Benefits/InfoPerk.php';
require_once __DIR__ . '/includes/Benefits/EarlyAccess.php';
require_once __DIR__ . '/includes/Benefits/ActivationPoints.php';
require_once __DIR__ . '/includes/Benefits/ReferralBonus.php';
require_once __DIR__ . '/includes/Benefits/ReferralGift.php';
require_once __DIR__ . '/includes/Benefits/MemberDiscount.php';
require_once __DIR__ . '/includes/Benefits/Registry.php';
require_once __DIR__ . '/includes/Access.php';
require_once __DIR__ . '/includes/Teaser.php';
require_once __DIR__ . '/includes/Graphql.php';
require_once __DIR__ . '/includes/Admin/TermFields.php';
require_once __DIR__ . '/includes/Products.php';
require_once __DIR__ . '/includes/Purchase.php';
require_once __DIR__ . '/includes/StoreApi.php';
require_once __DIR__ . '/includes/Pricing.php';
require_once __DIR__ . '/includes/Privacy.php';
require_once __DIR__ . '/includes/Card.php';
require_once __DIR__ . '/includes/Rest/CardRoutes.php';
require_once __DIR__ . '/includes/Admin/Admin.php';

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
		HWE_Memberships_Scheduler::register();
		HWE_Memberships_LoyaltyBridge::register();
		HWE_Memberships_Benefits::register();
		HWE_Memberships_Access::register();
		HWE_Memberships_Teaser::register();
		HWE_Memberships_Graphql::register();
		HWE_Memberships_Admin_TermFields::register();
		HWE_Memberships_Products::register();
		HWE_Memberships_Purchase::register();
		HWE_Memberships_StoreApi::register();
		HWE_Memberships_Pricing::register();
		HWE_Memberships_Privacy::register();
		HWE_Memberships_Card::register();
		HWE_Memberships_CardRoutes::register();
		HWE_Memberships_Admin::register();
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

// La activación crea o repara las tablas y programa la tarea diaria. La instalación perezosa de
// `init` cubre el resto de los casos (actualización del plugin, tabla borrada a mano) sin
// depender de la activación; el planificador también se autorrepara en `init`. Al desactivar se
// retiran el evento diario y su posible continuación.
if ( function_exists( 'register_activation_hook' ) ) {
	register_activation_hook( __FILE__, array( 'HWE_Memberships_Schema', 'install' ) );
	register_activation_hook( __FILE__, array( 'HWE_Memberships_Scheduler', 'schedule' ) );
}
if ( function_exists( 'register_deactivation_hook' ) ) {
	register_deactivation_hook( __FILE__, array( 'HWE_Memberships_Scheduler', 'unschedule' ) );
}

// La concesión de capacidades va en `activated_plugin`, que se dispara en la misma petición de
// activación (el hook llega también al activar otros plugins; `onActivated` filtra por archivo).
// El menú y la autorreparación los registra `HWE_Memberships_Admin` en `boot()`.
if ( class_exists( 'HWE_Memberships_Admin_Capabilities' ) ) {
	add_action( 'activated_plugin', array( 'HWE_Memberships_Admin_Capabilities', 'onActivated' ) );
}
