<?php
/**
 * Plugin Name: HWE Login Slug
 * Description: Mueve el login del panel de /wp-login.php a /<HWE_LOGIN_SLUG>.
 *              /wp-login.php y /wp-admin sin sesión responden 404.
 * Author:      Sergio Jauregui
 * Version:     1.0.0
 *
 * Práctica de seguridad, no una barrera: quita del camino a los bots que
 * prueban credenciales contra /wp-login.php a ciegas —la inmensa mayoría del
 * tráfico hostil de un WordPress— y deja el rate-limit de login
 * (`rate-limit.php`) y las contraseñas como defensa real. Mismo esquema que el
 * plugin conocido WPS Hide Login:
 *
 *   - `/<slug>` sirve wp-login.php. Se detecta en `plugins_loaded` (para que
 *     `$pagenow` valga ya 'wp-login.php' durante el arranque, como si se
 *     hubiera pedido el archivo) y se carga en `wp_loaded`, con WordPress ya
 *     arrancado del todo: cargarlo antes lo ejecutaría con el `init` a medias.
 *   - `/wp-login.php` directo → 404 (cualquier camino: Caddy, red interna, local).
 *   - `/wp-admin` sin sesión → 404 en vez de la redirección al login, que
 *     delataría el slug. `admin-ajax.php` y `admin-post.php` quedan fuera:
 *     tienen sus propias comprobaciones y algún plugin los usa sin sesión.
 *   - Todo enlace que WordPress construye a wp-login.php (formulario de
 *     acceso, cerrar sesión, recuperar contraseña, `interim-login` del panel,
 *     correos de confirmación de privacidad…) sale por `site_url` /
 *     `network_site_url` / `wp_redirect` y se reescribe al slug.
 *
 * Solo se activa con la constante `HWE_LOGIN_SLUG` (variable de entorno del
 * mismo nombre; docker-compose la define en el `wp-config.php` que comparten
 * `wordpress`, `wpcli` y el cron, para que un correo generado por un seed o por
 * el cron también lleve el enlace bueno). Formato: 6–63 caracteres `[a-z0-9-]`,
 * empezando por letra o cifra; otro valor (o vacío) deja todo como siempre.
 * Caddy enruta `/<slug>` a WordPress con la misma variable. Cada instancia
 * elige el suyo. No lo publiques (ni en robots.txt, ni en el frontend).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * La clase se declara siempre (PHP la registra al compilar el archivo aunque se
 * saliera antes con `return`) y `rate-limit.php` la consulta: por eso valida el
 * slug ella misma y, sin uno válido, no reconoce ninguna petición ni registra nada.
 */
final class HWE_Login_Slug {
	private static bool $serveLogin = false;

	/** El slug configurado, o null si falta o no tiene un formato válido. */
	public static function slug(): ?string {
		if ( ! defined( 'HWE_LOGIN_SLUG' ) || ! is_string( HWE_LOGIN_SLUG )
			|| ! preg_match( '/^[a-z0-9][a-z0-9-]{5,62}$/', HWE_LOGIN_SLUG ) ) {
			return null;
		}
		return HWE_LOGIN_SLUG;
	}

	public static function register(): void {
		add_action( 'plugins_loaded', array( self::class, 'route' ), 1 );
		add_action( 'wp_loaded', array( self::class, 'serve' ), 1 );

		add_filter( 'site_url', array( self::class, 'rewriteUrl' ), 10, 1 );
		add_filter( 'network_site_url', array( self::class, 'rewriteUrl' ), 10, 1 );
		add_filter( 'wp_redirect', array( self::class, 'rewriteUrl' ), 10, 1 );
	}

	/** Ruta de la petición, sin query y sin barra final. */
	private static function requestPath(): string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		return '/' === $path ? $path : rtrim( $path, '/' );
	}

	/** ¿La petición es la del login nuevo? Lo usa también `rate-limit.php`. */
	public static function isLoginRequest(): bool {
		$slug = self::slug();
		return null !== $slug && '/' . $slug === self::requestPath();
	}

	public static function route(): void {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}

		if ( self::isLoginRequest() ) {
			global $pagenow;
			$pagenow           = 'wp-login.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			self::$serveLogin  = true;
			return;
		}

		$path = self::requestPath();
		if ( str_ends_with( $path, '/wp-login.php' ) ) {
			self::notFound();
		}

		if ( is_admin()
			&& ! ( defined( 'DOING_AJAX' ) && DOING_AJAX )
			&& ! str_ends_with( $path, '/admin-post.php' )
			&& ! is_user_logged_in() ) {
			self::notFound();
		}
	}

	public static function serve(): void {
		if ( ! self::$serveLogin ) {
			return;
		}
		// wp-login.php declara variables en su ámbito que luego lee como
		// globales (`$user_login`, `$error`, `$interim_login`…).
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		global $error, $interim_login, $action, $user_login, $user;
		require_once ABSPATH . 'wp-login.php';
		exit;
	}

	/** Reescribe `…/wp-login.php…` al slug, conservando la query. */
	public static function rewriteUrl( $url ) {
		if ( ! is_string( $url ) || ! str_contains( $url, 'wp-login.php' ) ) {
			return $url;
		}
		$slug = self::slug();
		if ( null === $slug ) {
			return $url;
		}
		return (string) preg_replace( '#/wp-login\.php(?=$|[?\#])#', '/' . $slug, $url );
	}

	private static function notFound(): void {
		status_header( 404 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'Not Found';
		exit;
	}
}

if ( null !== HWE_Login_Slug::slug() ) {
	HWE_Login_Slug::register();
}
