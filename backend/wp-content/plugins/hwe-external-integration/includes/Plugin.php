<?php

namespace HWE\ExternalIntegration;

/** Cableado del módulo. */
final class Plugin {

	public static function register(): void {
		// Enciende la puerta de red y las Application Passwords: sin el módulo, ni la IP ni el
		// rol existen a efectos del resto del framework.
		add_filter( 'hwe_integration_gate_enabled', '__return_true' );
		add_filter( 'hwe_application_passwords_enabled', '__return_true' );

		Role::register();
		Access::register();
		GraphqlGuard::register();
	}
}
