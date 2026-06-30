<?php
/**
 * Plugin Name: HWE Transactional SMTP
 * Description: Enruta TODO el correo de WordPress y WooCommerce (pedidos, reset
 *              de contraseña, verificación de email, notificaciones) por un
 *              servidor SMTP configurado desde el HWE Control Center
 *              (Ajustes → HWE Config → Integraciones). La contraseña se guarda
 *              cifrada (AES-256-GCM) vía SecretsStorage. Incluye un botón de
 *              "enviar email de prueba" en el propio panel.
 * Author:      Headless Web Ecosystem
 * Version:     1.0.0
 *
 * Por qué: sin SMTP, WordPress usa el sendmail/PHP por defecto, que en
 * producción suele acabar en spam o no entregarse. Este mu-plugin es el
 * TRANSPORTE; el branding de las plantillas vive en
 * woocommerce-email-branding.php (complementarios, sin solaparse).
 *
 * Prioridad de configuración: constantes HWE_SMTP_* (wp-config) > Control
 * Center > valores por defecto. Así un despliegue puede gestionar el secreto
 * fuera de la BD si lo prefiere.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Devuelve la configuración SMTP efectiva, resolviendo constantes y los valores
 * del Control Center (públicos vía Storage, contraseña cifrada vía SecretsStorage).
 *
 * @return array{enabled:bool,host:string,port:int,encryption:string,auth:bool,user:string,password:string,from:string,from_name:string}
 */
function hwe_smtp_config(): array {
	$pub = static function ( array $path, $default = '' ) {
		if ( class_exists( '\\HWE\\ControlCenter\\Storage' ) ) {
			$v = \HWE\ControlCenter\Storage::get( $path, $default );
			return ( null === $v ) ? $default : $v;
		}
		return $default;
	};
	$secret = static function ( array $path, string $default = '' ): string {
		if ( class_exists( '\\HWE\\ControlCenter\\SecretsStorage' ) ) {
			return \HWE\ControlCenter\SecretsStorage::get( $path, $default );
		}
		return $default;
	};

	$enabledRaw = $pub( array( 'integrations', 'smtp_enabled' ), false );
	$enabled    = ( true === $enabledRaw || '1' === $enabledRaw || 1 === $enabledRaw );

	$port = (int) ( $pub( array( 'integrations', 'smtp_port' ), '587' ) ?: 587 );

	return array(
		'enabled'    => defined( 'HWE_SMTP_ENABLED' ) ? (bool) HWE_SMTP_ENABLED : $enabled,
		'host'       => defined( 'HWE_SMTP_HOST' ) ? (string) HWE_SMTP_HOST : (string) $pub( array( 'integrations', 'smtp_host' ) ),
		'port'       => defined( 'HWE_SMTP_PORT' ) ? (int) HWE_SMTP_PORT : $port,
		'encryption' => defined( 'HWE_SMTP_ENCRYPTION' ) ? (string) HWE_SMTP_ENCRYPTION : (string) ( $pub( array( 'integrations', 'smtp_encryption' ), 'tls' ) ?: 'tls' ),
		'auth'       => defined( 'HWE_SMTP_AUTH' ) ? (bool) HWE_SMTP_AUTH : (bool) $pub( array( 'integrations', 'smtp_auth' ), true ),
		'user'       => defined( 'HWE_SMTP_USER' ) ? (string) HWE_SMTP_USER : (string) $pub( array( 'integrations', 'smtp_user' ) ),
		'password'   => defined( 'HWE_SMTP_PASSWORD' ) ? (string) HWE_SMTP_PASSWORD : $secret( array( 'integrations', 'smtp_password' ) ),
		'from'       => defined( 'HWE_SMTP_FROM' ) ? (string) HWE_SMTP_FROM : (string) $pub( array( 'integrations', 'smtp_from' ) ),
		'from_name'  => defined( 'HWE_SMTP_FROM_NAME' ) ? (string) HWE_SMTP_FROM_NAME : (string) $pub( array( 'integrations', 'smtp_from_name' ) ),
	);
}

/** ¿SMTP activo y mínimamente configurado (enabled + host)? */
function hwe_smtp_is_active(): bool {
	$c = hwe_smtp_config();
	return $c['enabled'] && '' !== $c['host'];
}

/* ---------------------------------------------------------------------------
 * 1) TRANSPORTE: configura PHPMailer en cada envío de wp_mail().
 * ------------------------------------------------------------------------ */
add_action(
	'phpmailer_init',
	function ( $phpmailer ) {
		if ( ! hwe_smtp_is_active() ) {
			return; // Sin configurar: WordPress usa su transporte por defecto.
		}
		$c = hwe_smtp_config();

		$phpmailer->isSMTP();
		$phpmailer->Host     = $c['host'];
		$phpmailer->Port     = $c['port'] ?: 587;
		$phpmailer->SMTPAuth = $c['auth'];

		if ( $c['auth'] ) {
			$phpmailer->Username = $c['user'];
			$phpmailer->Password = $c['password'];
		}

		// 'tls' → STARTTLS (587) · 'ssl' → TLS implícito (465) · 'none' → sin cifrado.
		if ( 'tls' === $c['encryption'] ) {
			$phpmailer->SMTPSecure  = 'tls';
			$phpmailer->SMTPAutoTLS = true;
		} elseif ( 'ssl' === $c['encryption'] ) {
			$phpmailer->SMTPSecure = 'ssl';
		} else {
			$phpmailer->SMTPSecure  = '';
			$phpmailer->SMTPAutoTLS = false;
		}
	},
	20
);

/* ---------------------------------------------------------------------------
 * 2) REMITENTE: From por defecto de WordPress (reset, verificación, etc.).
 * ------------------------------------------------------------------------ */
add_filter(
	'wp_mail_from',
	function ( $email ) {
		$c = hwe_smtp_config();
		return ( hwe_smtp_is_active() && '' !== $c['from'] ) ? $c['from'] : $email;
	},
	20
);

add_filter(
	'wp_mail_from_name',
	function ( $name ) {
		$c = hwe_smtp_config();
		return ( hwe_smtp_is_active() && '' !== $c['from_name'] ) ? $c['from_name'] : $name;
	},
	20
);

/* ---------------------------------------------------------------------------
 * 3) COHERENCIA con WooCommerce (usa su propio From para los emails de pedido).
 * ------------------------------------------------------------------------ */
add_filter(
	'woocommerce_email_from_address',
	function ( $email ) {
		$c = hwe_smtp_config();
		return ( hwe_smtp_is_active() && '' !== $c['from'] ) ? $c['from'] : $email;
	},
	20
);

add_filter(
	'woocommerce_email_from_name',
	function ( $name ) {
		$c = hwe_smtp_config();
		return ( hwe_smtp_is_active() && '' !== $c['from_name'] ) ? $c['from_name'] : $name;
	},
	20
);

/* ---------------------------------------------------------------------------
 * 4) DIAGNÓSTICO: registra el último fallo de envío (sin PII sensible).
 * ------------------------------------------------------------------------ */
add_action(
	'wp_mail_failed',
	function ( $error ) {
		if ( $error instanceof \WP_Error ) {
			set_transient( 'hwe_smtp_last_error', $error->get_error_message(), 300 );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions
			error_log( '[HWE SMTP] Fallo de envío: ' . $error->get_error_message() );
		}
	}
);

/* ---------------------------------------------------------------------------
 * 5) PRUEBA: envía un email de test desde el panel (admin-post + nonce).
 *    El botón se renderiza en AdminPage del Control Center.
 * ------------------------------------------------------------------------ */
add_action(
	'admin_post_hwe_smtp_test',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No autorizado.', 'hwe' ) );
		}
		check_admin_referer( 'hwe_smtp_test' );

		$to = isset( $_POST['hwe_smtp_test_email'] )
			? sanitize_email( wp_unslash( $_POST['hwe_smtp_test_email'] ) )
			: '';
		if ( ! $to ) {
			$to = wp_get_current_user()->user_email;
		}

		delete_transient( 'hwe_smtp_last_error' );

		$transport = hwe_smtp_is_active() ? 'SMTP' : 'PHP/sendmail (SMTP inactivo)';
		$subject   = '[' . get_bloginfo( 'name' ) . '] Prueba de email transaccional';
		$body      = '<p>Este es un email de prueba enviado desde el HWE Control Center.</p>'
			. '<p>Transporte usado: <strong>' . esc_html( $transport ) . '</strong></p>'
			. '<p>Si lo recibes, la entrega funciona. Revisa también la carpeta de spam y la '
			. 'alineación <strong>SPF/DKIM/DMARC</strong> del dominio remitente para máxima entregabilidad.</p>';

		$ok = wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'hwe-config',
					'hwe_smtp_test' => $ok ? 'sent' : 'failed',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}
);
