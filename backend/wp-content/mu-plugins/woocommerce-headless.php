<?php
/**
 * Plugin Name: WooCommerce Headless Bridge
 * Description: Ajustes de WooCommerce para operación headless detrás del BFF de
 *              Next.js: desactiva el nonce de la Store API (el BFF ya impone
 *              verificación de Origin + CSRF) y mantiene CORS coherente.
 * Author:      Headless Web Ecosystem
 * Version:     2.0.0
 *
 * Parte de la Fase 3 (proxy inverso a WooCommerce) del plan de producción.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * 1) DESACTIVAR EL NONCE DE LA STORE API
 *    La Store API exige un nonce para mutaciones de carrito/checkout como
 *    protección CSRF pensada para llamadas desde el navegador. En este montaje
 *    headless, SOLO el BFF (servidor Next, red interna) llama a la Store API, y
 *    el BFF ya verifica el Origin (+ CSRF en Fase 4). Por eso es seguro y
 *    necesario desactivar el nonce: la autenticación del carrito se hace con el
 *    `Cart-Token`.
 *
 *    Requiere WooCommerce Blocks (incluido en WooCommerce moderno).
 * ---------------------------------------------------------------------- */
add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );

/* -------------------------------------------------------------------------
 * 1b) PASARELAS HEADLESS (siempre disponibles en Store API)
 *    El cobro real lo hace el BFF (pasarela configurada o noop). Woo solo
 *    necesita un método habilitado para CREAR el pedido. COD nativo a menudo
 *    falla con "not available for this order" (envío/virtual/ajustes), así
 *    que registramos dos gateways propios, siempre `is_available()`:
 *      - `hwe_offline`: pago en línea; lo cobra la pasarela del BFF.
 *      - `hwe_whatsapp_link`: link de pago por WhatsApp; sin cobro en línea.
 *    Cada uno deja en el pedido su propio medio de pago, así que wp-admin, los
 *    correos y la pantalla de gracias saben cómo se pidió pagar. Qué medios
 *    se OFRECEN lo decide HWE Control Center → Pasarelas de pago, y lo aplica
 *    el BFF.
 *
 *    NO se fuerza ningún gateway nativo: habilitar o deshabilitar `cod` y
 *    `bacs` es decisión del operador. En producción deben quedar
 *    deshabilitados: aceptarían pedidos sin cobrar.
 * ---------------------------------------------------------------------- */

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		if ( ! class_exists( 'WC_Gateway_HWE_Offline' ) ) {
			/**
			 * Gateway mínimo para Store API / headless. No cobra; el BFF sí.
			 */
			class WC_Gateway_HWE_Offline extends WC_Payment_Gateway {
				public function __construct() {
					$this->id                 = 'hwe_offline';
					$this->method_title       = 'HWE Headless (offline)';
					$this->method_description = 'Crea el pedido; el cobro lo confirma el BFF.';
					// Es el título que WooCommerce guarda en el pedido y pinta en
					// los correos y en wp-admin: nombra el medio de pago del
					// cliente, no el mecanismo interno.
					$this->title              = 'Pago en línea';
					$this->description        = 'Tarjeta y otros medios en la pasarela de pago.';
					$this->enabled            = 'yes';
					$this->has_fields         = false;
					$this->supports           = array( 'products' );
				}

				public function is_available() {
					return true;
				}

				public function process_payment( $order_id ) {
					$order = wc_get_order( $order_id );
					if ( $order ) {
						$order->update_status( 'pending', 'Headless: pedido creado; cobro vía BFF.' );
						WC()->cart->empty_cart();
					}
					return array(
						'result'   => 'success',
						'redirect' => $this->get_return_url( $order ),
					);
				}
			}
		}

		if ( ! class_exists( 'WC_Gateway_HWE_Whatsapp_Link' ) ) {
			/**
			 * Link de pago por WhatsApp. Crea el pedido en `pending`, como el
			 * anterior; el BFF lo pasa a «En espera» DESPUÉS de aplicar los
			 * extras de envío, porque ese cambio de estado es el que dispara los
			 * correos y estos deben llevar ya el total final.
			 */
			class WC_Gateway_HWE_Whatsapp_Link extends WC_Gateway_HWE_Offline {
				public function __construct() {
					parent::__construct();
					$this->id                 = 'hwe_whatsapp_link';
					$this->method_title       = 'HWE Link de pago por WhatsApp';
					$this->method_description = 'Crea el pedido sin cobro en línea; el cliente pide el link de pago por WhatsApp.';
					$this->title              = 'Link de pago por WhatsApp';
					$this->description        = 'Sin cobro en línea: se envía un link de pago por WhatsApp.';
				}
			}
		}

		add_filter(
			'woocommerce_payment_gateways',
			static function ( $gateways ) {
				$gateways[] = 'WC_Gateway_HWE_Offline';
				$gateways[] = 'WC_Gateway_HWE_Whatsapp_Link';
				return $gateways;
			}
		);
	},
	20
);

// Los gateways headless deben estar siempre disponibles: son con los que el BFF
// crea el pedido (antes de mandar al usuario a la pasarela, o sin cobro).
add_filter(
	'woocommerce_available_payment_gateways',
	static function ( $available ) {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return $available;
		}
		$all = WC()->payment_gateways()->payment_gateways();
		foreach ( array( 'hwe_offline', 'hwe_whatsapp_link' ) as $id ) {
			if ( isset( $all[ $id ] ) ) {
				$available[ $id ] = $all[ $id ];
			}
		}
		return $available;
	},
	100
);

/* -------------------------------------------------------------------------
 * 1c) AVISO SI COD/BACS SIGUEN ACTIVOS EN PRODUCCIÓN
 *    Aceptarían pedidos sin cobrar. Antes esto dependía por completo de que
 *    el operador se acordara de desactivarlos manualmente; ahora se avisa
 *    en el propio wp-admin si sigue faltando. No se desactivan solos: es
 *    una decisión del operador (puede haber un motivo legítimo, p. ej. un
 *    piloto con pago contra entrega), solo debe dejar de pasar inadvertido.
 * ---------------------------------------------------------------------- */
/**
 * Pasarelas nativas que aceptarían pedidos sin cobrar y siguen activas.
 *
 * COD activado desde HWE Control Center («Activar pago en efectivo») es
 * intencional: no es un olvido del operador y no se incluye.
 *
 * @return list<string> Ids (`cod`, `bacs`).
 */
function hwe_headless_risky_gateways(): array {
	$risky = array();
	foreach ( array( 'cod', 'bacs' ) as $id ) {
		if ( 'cod' === $id && class_exists( 'HWE\ControlCenter\WooCashGatewaySync' ) && HWE\ControlCenter\WooCashGatewaySync::configuredEnabled() ) {
			continue;
		}
		$settings = get_option( "woocommerce_{$id}_settings" );
		if ( is_array( $settings ) && ( $settings['enabled'] ?? 'no' ) === 'yes' ) {
			$risky[] = $id;
		}
	}
	return $risky;
}

add_action( 'admin_notices', function () {
	if ( 'production' !== wp_get_environment_type() || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$labels = array( 'cod' => 'Contra reembolso (COD)', 'bacs' => 'Transferencia bancaria directa (BACS)' );
	$risky  = array();
	foreach ( hwe_headless_risky_gateways() as $id ) {
		$risky[] = $labels[ $id ];
	}
	if ( empty( $risky ) ) {
		return;
	}
	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		esc_html( sprintf(
			'HWE: la(s) pasarela(s) %s siguen activas en producción. Aceptan pedidos sin cobrar: si no es intencional, desactívalas en WooCommerce → Ajustes → Pagos.',
			implode( ' y ', $risky )
		) )
	);
} );

/* -------------------------------------------------------------------------
 * 2) CORS PARA LA STORE API (defensa en profundidad)
 *    Aunque el BFF llama servidor-a-servidor (sin CORS de navegador), si en
 *    algún flujo se llamara desde el cliente, restringimos el origen a la
 *    allowlist definida en headless-config.php.
 * ---------------------------------------------------------------------- */
add_filter(
	'rest_pre_serve_request',
	function ( $served ) {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( false === strpos( (string) $uri, '/wc/store/' ) ) {
			return $served;
		}

		$request = isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		// Reutiliza el comparador de orígenes definido en headless-config.php.
		if ( function_exists( 'hwe_match_origin' ) ) {
			$allowed = hwe_match_origin( $request );
			if ( null !== $allowed ) {
				header( 'Access-Control-Allow-Origin: ' . $allowed );
				header( 'Access-Control-Allow-Credentials: true' );
				header( 'Access-Control-Expose-Headers: Cart-Token, Nonce' );
				header( 'Vary: Origin' );
			}
		}

		return $served;
	}
);
