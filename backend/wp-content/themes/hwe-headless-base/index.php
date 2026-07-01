<?php
/**
 * Plantilla única del tema. Este WordPress es headless: no sirve HTML público
 * real, solo un aviso informativo por si alguien navega directamente al
 * dominio de WordPress en vez de al frontend. El contenido real (WPGraphQL +
 * WooCommerce) se consume desde el frontend Next.js — ver README.md.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$frontend_url = getenv( 'HEADLESS_FRONTEND_URL' ) ?: home_url( '/' );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php bloginfo( 'name' ); ?> — Headless CMS</title>
</head>
<body style="font-family: system-ui, sans-serif; max-width: 32rem; margin: 4rem auto; padding: 0 1.5rem; color: #111;">
	<h1 style="font-size: 1.25rem;"><?php bloginfo( 'name' ); ?> funciona en modo headless</h1>
	<p>Este WordPress es solo el backend (CMS + WooCommerce vía WPGraphQL). No hay una web pública aquí.</p>
	<p>
		El sitio se sirve desde el frontend:
		<a href="<?php echo esc_url( $frontend_url ); ?>"><?php echo esc_html( $frontend_url ); ?></a>
	</p>
</body>
</html>
