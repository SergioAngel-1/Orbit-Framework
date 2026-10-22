<?php
/**
 * Plugin Name: HWE Loyalty
 * Description: Programa de fidelización: monedero de puntos (ganar, caducar, canjear y
 *              transferir) y referidos de dos niveles que pagan sus comisiones en esos puntos.
 *              Es un módulo opcional del framework y no trae vistas: entrega el contrato de
 *              configuración, las rutas internas de WordPress y la lógica del backend.
 * Author:      Sergio Jauregui
 * Version:     1.0.0
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Text Domain: hwe
 *
 * WordPress es la fuente de verdad del libro. Este archivo solo arranca el plugin:
 *  · comprueba que WooCommerce esté presente (sin él no hay pedidos ni carrito que fidelizar);
 *  · carga `includes/`;
 *  · engancha los ajustes del módulo al grupo reservado `loyalty` del HWE Control Center.
 *
 * Ajustes (Control Center → Fidelización): los campos de `includes/Settings.php`. El grupo lo
 * declara vacío el núcleo (`SchemaGroups/Loyalty.php`) y este plugin lo puebla con
 * `hwe_control_center_schema`; así el panel, la API pública `/wp-json/hwe/v1/config`,
 * `wp hwe setup` y la revalidación de `site-config` comparten una sola configuración.
 *
 * Con el módulo apagado (plugin inactivo) no hay ajustes activos, los lectores del frontend
 * devuelven vacío y las rutas del BFF responden 404. La puerta la calcula el núcleo con
 * `config.modules.loyalty`, que refleja si este plugin está activo.
 *
 * Estado: incluye el arranque, los ajustes, el esquema de base de datos, el libro mayor
 * transaccional (abonos, cargos FIFO, devoluciones y caducidad), la tarea diaria que caduca
 * los abonos vencidos y paga los cumpleaños, los referidos (códigos, atribución y confirmación
 * con sus primas de alta) con sus rutas internas, las ganancias por pedido (cashback,
 * comisiones N1/N2, bono por producto y su reversión) y las ganancias por eventos (una reseña
 * por pedido, de producto o del sitio, y el cumpleaños anual). El canje, la administración y
 * las rutas del BFF llegan en las siguientes tareas del módulo (ver README.md y MANUAL.md de
 * esta carpeta).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HWE_LOYALTY_VERSION', '1.0.0' );
define( 'HWE_LOYALTY_DIR', __DIR__ );

require_once __DIR__ . '/includes/Settings.php';
require_once __DIR__ . '/includes/EntryTypes.php';
require_once __DIR__ . '/includes/Schema.php';
require_once __DIR__ . '/includes/Ledger.php';
require_once __DIR__ . '/includes/Referrals.php';
require_once __DIR__ . '/includes/ProductBonus.php';
require_once __DIR__ . '/includes/Earnings.php';
require_once __DIR__ . '/includes/Scheduler.php';
require_once __DIR__ . '/includes/Events.php';
require_once __DIR__ . '/includes/Rest/ReferralRoutes.php';

final class HWE_Loyalty {

	/**
	 * Arranque del módulo. Se engancha a `plugins_loaded` con prioridad tardía: para entonces
	 * WooCommerce ya se ha cargado (o no está, y el módulo no debe hacer nada).
	 */
	public static function boot(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			error_log( '[hwe] loyalty: WooCommerce no está activo; el módulo no se arranca.' );
			add_action( 'admin_notices', array( self::class, 'woocommerceMissingNotice' ) );
			return;
		}

		HWE_Loyalty_Settings::register();
		HWE_Loyalty_Schema::register();
		HWE_Loyalty_Scheduler::register();
		HWE_Loyalty_Referrals::register();
		HWE_Loyalty_ProductBonus::register();
		HWE_Loyalty_Earnings::register();
		HWE_Loyalty_Events::register();
		HWE_Loyalty_ReferralRoutes::register();
	}

	/** Aviso en wp-admin si el plugin se activó sin WooCommerce (la cabecera ya lo impide). */
	public static function woocommerceMissingNotice(): void {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'HWE Loyalty necesita WooCommerce activo: sin él no hay pedidos que fidelizar.', 'hwe' )
			. '</p></div>';
	}
}

add_action( 'plugins_loaded', array( 'HWE_Loyalty', 'boot' ), 20 );

// Ciclo de vida del evento diario: al activar se programa (idempotente) y al desactivar se
// retira. En WordPress las dos funciones existen siempre; el `function_exists` mantiene el
// plugin cargable fuera del entorno de WordPress (tests unitarios) sin fatal.
if ( function_exists( 'register_activation_hook' ) ) {
	register_activation_hook( __FILE__, array( 'HWE_Loyalty_Scheduler', 'schedule' ) );
}
if ( function_exists( 'register_deactivation_hook' ) ) {
	register_deactivation_hook( __FILE__, array( 'HWE_Loyalty_Scheduler', 'unschedule' ) );
}
