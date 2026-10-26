<?php
/**
 * Plugin Name: HWE — Secreto interno del BFF (compartido)
 * Description: La comprobación del secreto con el que el BFF (Next.js) se identifica
 *              ante WordPress (`X-HWE-Internal-Secret` = `HWE_REVALIDATION_SECRET`).
 *              Vive aquí, en un único sitio, porque la usan la puerta de
 *              `hwe-bff-only.php` (404 a quien no lo presenta) y `rate-limit.php`
 *              (las llamadas del BFF no consumen el presupuesto por IP).
 * Author:      Sergio Jauregui
 * Version:     1.0.0
 *
 * POR QUÉ ES UN ARCHIVO APARTE
 * ----------------------------
 * `hwe-bff-only.php` y `rate-limit.php` tienen que aplicar exactamente la misma
 * regla: si divergieran, una petición podría pasar la puerta y, a la vez, gastar el
 * presupuesto de todos los compradores (o al revés, quedar exenta sin ser el BFF).
 * Duplicar la comparación invita a que la próxima rotación de secreto arregle solo
 * una de las dos copias. Los dos mu-plugins lo incluyen con `require_once`.
 *
 * La comparación es en tiempo constante también respecto a la LONGITUD (se comparan
 * los SHA-256 con `hash_equals`, que sobre las cadenas crudas delataría el tamaño).
 * Sin `HWE_REVALIDATION_SECRET` definido, nadie puede identificarse: falla cerrado.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cabecera con la que el BFF presenta el secreto, tal como la ve PHP en $_SERVER.
 * Es la misma que ya comprueba `hwe-auth.php` en `2fa-status`.
 */
const HWE_BFF_ONLY_SERVER_KEY = 'HTTP_X_HWE_INTERNAL_SECRET';

/** El secreto esperado, o '' si no está definido (entonces nadie puede identificarse). */
function hwe_bff_only_expected_secret(): string {
	return defined( 'HWE_REVALIDATION_SECRET' ) ? trim( (string) HWE_REVALIDATION_SECRET ) : '';
}

/**
 * Registra un problema como máximo una vez por ventana, para que un fallo de
 * configuración no pase desapercibido (todo daría 404 sin explicación) ni inunde el log.
 */
function hwe_bff_only_log_throttled( string $key, string $message, int $window_seconds = 60 ): void {
	static $last = array();
	$now = time();
	if ( isset( $last[ $key ] ) && ( $now - $last[ $key ] ) < $window_seconds ) {
		return;
	}
	$last[ $key ] = $now;
	error_log( '[hwe] bff-only: ' . $message );
}

/**
 * ¿La petición trae el secreto interno correcto?
 *
 * Se comparan los SHA-256 de ambos valores con `hash_equals`: tiempo constante también
 * respecto a la LONGITUD, que `hash_equals` sobre las cadenas crudas sí delataría.
 */
function hwe_bff_only_has_valid_secret(): bool {
	$expected = hwe_bff_only_expected_secret();
	if ( '' === $expected ) {
		hwe_bff_only_log_throttled(
			'no-secret',
			'HWE_REVALIDATION_SECRET no está definido: GraphQL, la Store API y hwe/v1/auth|consent|loyalty|memberships '
			. 'solo atienden a WP-CLI, y las llamadas del BFF consumen el presupuesto de rate-limit por IP. '
			. 'Defínelo en wp-config.php con el mismo valor que el frontend.',
			3600
		);
		return false;
	}

	$received = isset( $_SERVER[ HWE_BFF_ONLY_SERVER_KEY ] )
		? trim( (string) wp_unslash( $_SERVER[ HWE_BFF_ONLY_SERVER_KEY ] ) )
		: '';
	if ( '' === $received ) {
		return false;
	}

	if ( hash_equals( hash( 'sha256', $expected ), hash( 'sha256', $received ) ) ) {
		return true;
	}

	// Presente pero incorrecto: casi siempre es el BFF con otro secreto (despliegue a
	// medias, rotación). Sin este aviso la causa sería invisible: todo respondería 404.
	hwe_bff_only_log_throttled(
		'bad-secret',
		'secreto interno inválido recibido; si es el BFF, comprueba que HWE_REVALIDATION_SECRET '
		. 'coincide en el frontend y en WordPress.'
	);
	return false;
}
