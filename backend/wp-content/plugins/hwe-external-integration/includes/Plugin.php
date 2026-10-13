<?php

namespace HWE\ExternalIntegration;

/** Cableado del módulo. */
final class Plugin {

	/**
	 * Enciende la puerta de red y las Application Passwords: sin el módulo, ni la IP ni el rol
	 * existen a efectos del resto del framework.
	 *
	 * Se llama AL CARGAR el archivo del plugin, no en `plugins_loaded`: WPGraphQL-WooCommerce
	 * pregunta `is_graphql_http_request()` en `plugins_loaded` (antes de que este plugin se
	 * enganche) y WPGraphQL cachea la respuesta. Con el filtro registrado tarde, la puerta daba
	 * «no» y la IP autorizada recibía 404 aunque el módulo estuviera activo.
	 */
	public static function boot(): void {
		add_filter( 'hwe_integration_gate_enabled', '__return_true' );
		add_filter( 'hwe_application_passwords_enabled', '__return_true' );
	}

	public static function register(): void {
		Role::register();
		Access::register();
		GraphqlGuard::register();
	}
}
