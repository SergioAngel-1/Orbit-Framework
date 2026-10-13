<?php
/**
 * Plugin Name: HWE Coupon Rules
 * Description: Reglas de cupón que WooCommerce no trae. (1) VENTANA DE VALIDEZ:
 *              añade la fecha de inicio —el core solo tiene caducidad— y hace
 *              que la caducidad nativa sea INCLUSIVA (el core mata el cupón a
 *              las 00:00 del día indicado, así que «caduca el 27» significaba
 *              «no funciona el 27»). (2) PRIMERA COMPRA: restricción por cliente
 *              sin pedidos, con fecha de corte para ignorar el historial
 *              migrado. (3) ALCANCE: «Aplica a» todo el catálogo, a unos
 *              productos o a unas categorías, nunca a productos y categorías
 *              a la vez. (4) SESIÓN: «Requiere sesión» limita el cupón a
 *              clientes con cuenta; sin marcarlo, también vale para invitados.
 *              (5) VISIBILIDAD: un cupón «público» se anuncia en el frontend
 *              (categorías y fichas de su alcance; login y registro si exige
 *              sesión) vía el endpoint interno `hwe/v1/coupons/public`; uno
 *              privado solo funciona escribiendo el código. Todo se edita por
 *              cupón en Marketing → Cupones, cuya pantalla se reorganiza en
 *              pestañas por tema con un resumen lateral
 *              (`hwe-coupon-rules/admin.php`).
 *
 * Author:      Headless Web Ecosystem
 * Version:     1.3.0
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Text Domain: hwe
 *
 * Es un MÓDULO del framework (`coupon-rules`): ver README.md y MANUAL.md.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const HWE_COUPON_META_STARTS      = '_hwe_coupon_starts';
const HWE_COUPON_META_FIRST_ONLY  = '_hwe_first_order_only';
const HWE_COUPON_META_FIRST_SINCE = '_hwe_first_order_since';
const HWE_COUPON_META_LOGIN       = '_hwe_coupon_requires_login';
const HWE_COUPON_META_PUBLIC      = '_hwe_coupon_public';

/**
 * Motivo del último rechazo, para que `woocommerce_coupon_error` pueda cambiar
 * el genérico «Cupón no válido» por el mensaje real. WooCommerce dispara los dos
 * filtros en la misma llamada, así que basta con guardarlo entre uno y otro.
 */
final class HWE_Coupon_Reason {
	/** @var string|null */
	public static $value = null;
	/** @var string */
	public static $detail = '';
}

/** `true` si la cadena tiene forma AAAA-MM-DD. */
function hwe_coupon_is_date( string $value ): bool {
	return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) === 1;
}

/**
 * Medianoche de esa fecha en la zona horaria del SITIO (Ajustes → Generales),
 * como timestamp UTC. Si alguien cambia esa zona, cambia con ella la hora real de
 * inicio y de caducidad de todos los cupones.
 */
function hwe_coupon_day_start( string $date ): int {
	return ( new DateTimeImmutable( $date . ' 00:00:00', wp_timezone() ) )->getTimestamp();
}

/* -------------------------------------------------------------------------- */
/* Alcance: todo el catálogo, unos productos o unas categorías                 */
/* -------------------------------------------------------------------------- */

/**
 * Alcance efectivo del cupón, DERIVADO de las listas nativas (no se guarda
 * aparte: un meta propio podría contradecir lo que WooCommerce aplica).
 *
 * El core deja rellenar «Productos» y «Categorías» a la vez, y entonces exige
 * que el carrito tenga un artículo de CADA lista (`validate_coupon_product_ids`
 * y `validate_coupon_product_categories` se validan por separado), que no es
 * lo que nadie entiende al configurarlo. El selector «Aplica a» impide esa
 * mezcla al guardar; un cupón anterior al selector con las dos listas se
 * trata como de productos, la más estrecha.
 *
 * Una categoría cubre también su descendencia: WooCommerce compara contra
 * `wc_get_product_cat_ids()`, que suma los ancestros de cada categoría del
 * producto.
 *
 * @return string 'all' | 'products' | 'categories'
 */
function hwe_coupon_scope( WC_Coupon $coupon ): string {
	if ( count( $coupon->get_product_ids( 'edit' ) ) > 0 ) {
		return 'products';
	}
	if ( count( $coupon->get_product_categories( 'edit' ) ) > 0 ) {
		return 'categories';
	}
	return 'all';
}

/**
 * ¿Exige sesión? Sí si lo marca el cupón o si es de primera compra: el
 * historial se cuenta por CUENTA, así que con un invitado esa regla no
 * significaría nada (el core, además, se salta el límite por usuario cuando
 * `get_current_user_id()` es 0).
 */
function hwe_coupon_requires_login( int $coupon_id ): bool {
	return get_post_meta( $coupon_id, HWE_COUPON_META_LOGIN, true ) === 'yes'
		|| get_post_meta( $coupon_id, HWE_COUPON_META_FIRST_ONLY, true ) === 'yes';
}

/* -------------------------------------------------------------------------- */
/* Admin: pantalla del cupón                                                   */
/* -------------------------------------------------------------------------- */

// Pestañas por tema, campos propios, resumen lateral y guardado de las metas.
// Su JS y su CSS viven en `assets/`.
if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin.php';
}

/* -------------------------------------------------------------------------- */
/* Ventana de validez                                                          */
/* -------------------------------------------------------------------------- */

/**
 * Hace INCLUSIVA la caducidad nativa. El core compara contra la medianoche del
 * día guardado (`time() > get_date_expires()->getTimestamp()`), de modo que un
 * cupón «hasta el 27» dejaba de funcionar al empezar el 27. Aquí caduca cuando
 * ese día TERMINA, que es lo que entiende cualquiera que escriba la fecha.
 *
 * Aplica a TODOS los cupones del sitio, a propósito: es una corrección de
 * semántica, no una regla por cupón.
 */
add_filter( 'woocommerce_coupon_validate_expiry_date', function ( $expired, $coupon ) {
	$expires = $coupon->get_date_expires();
	if ( ! $expires ) {
		return $expired;
	}

	return time() >= $expires->getTimestamp() + DAY_IN_SECONDS;
}, 10, 2 );

/* -------------------------------------------------------------------------- */
/* Validación propia (inicio + sesión + primera compra)                        */
/* -------------------------------------------------------------------------- */

/**
 * ¿Tiene el cliente un pedido PAGADO (processing/completed) creado en la fecha
 * de corte o después? Solo necesita saber si hay alguno, así que pide uno.
 * `wc_get_orders` es compatible con HPOS; no se usa `wc_get_customer_order_count`
 * porque cuenta cualquier estado (fallido, cancelado) y no admite fecha.
 *
 * Se mira por CUENTA y también por CORREO de facturación: quien compró como
 * invitado con el mismo correo antes de registrarse (o desde otro dispositivo,
 * donde `claimGuestOrder` no pudo vincularle el pedido) ya compró.
 *
 * Se memoiza por petición: WooCommerce revalida los cupones del carrito en
 * CADA lectura o cambio (Store API), y sin esto cada una repetía estas consultas.
 */
function hwe_coupon_has_previous_order( int $user_id, string $since ): bool {
	static $memo = [];
	$key = $user_id . '|' . $since;
	if ( isset( $memo[ $key ] ) ) {
		return $memo[ $key ];
	}

	$base = [
		'status' => wc_get_is_paid_statuses(),
		'limit'  => 1,
		'return' => 'ids',
	];
	if ( $since !== '' ) {
		$base['date_created'] = '>=' . $since;
	}

	$found = count( wc_get_orders( $base + [ 'customer_id' => $user_id ] ) ) > 0;
	if ( ! $found ) {
		$user  = get_userdata( $user_id );
		$email = $user ? (string) $user->user_email : '';
		if ( $email !== '' ) {
			$found = count( wc_get_orders( $base + [ 'billing_email' => $email ] ) ) > 0;
		}
	}

	return $memo[ $key ] = $found;
}

/**
 * Cuántas veces ha usado este cliente ESTE cupón, contado como WooCommerce en
 * su «Límite de uso por usuario»: por id y por correo (un pedido de invitado
 * con el mismo correo también cuenta), sobre los usos registrados (pedidos en
 * espera, procesando y completados) más los retenidos mientras se paga. Memoizado
 * por petición (ver `hwe_coupon_has_previous_order`).
 */
function hwe_coupon_customer_usage( WC_Coupon $coupon, int $user_id ): int {
	static $memo = [];
	$key = $coupon->get_id() . '|' . $user_id;
	if ( isset( $memo[ $key ] ) ) {
		return $memo[ $key ];
	}

	// `get_data_store()` devuelve el envoltorio `WC_Data_Store`, que reenvía los
	// métodos con `__call`: `method_exists()` da `false` aunque existan, y el
	// conteo quedaba siempre en 0 (el cupón agotado se seguía ofreciendo). La
	// pregunta correcta es `has_callable()`.
	$used  = 0;
	$store = $coupon->get_data_store();
	if ( $store && $store->has_callable( 'get_usage_by_user_id' ) ) {
		$used = (int) $store->get_usage_by_user_id( $coupon, $user_id );
		$user = get_userdata( $user_id );
		if ( $user && $user->user_email && $store->has_callable( 'get_usage_by_email' ) ) {
			$used = max( $used, (int) $store->get_usage_by_email( $coupon, $user->user_email ) );
		}
	}

	return $memo[ $key ] = $used;
}

/**
 * ¿Deja de ser «primera compra» para este cliente? Sí si ya tiene un pedido
 * pagado desde la fecha de corte, o si ya usó ESTE cupón en un pedido aún sin
 * pagar (en espera: link de pago por WhatsApp, pago en línea pendiente). El historial de pedidos
 * solo cuenta pagados, así que sin lo segundo un pedido abierto con el cupón
 * no impedía usarlo otra vez en otro.
 */
function hwe_coupon_first_order_lost( WC_Coupon $coupon, int $user_id ): bool {
	$since = (string) get_post_meta( $coupon->get_id(), HWE_COUPON_META_FIRST_SINCE, true );
	return hwe_coupon_has_previous_order( $user_id, $since )
		|| hwe_coupon_customer_usage( $coupon, $user_id ) > 0;
}

/**
 * Pedidos EN ESPERA DE PAGO (`pending`) de este cliente que ya llevan ESTE
 * cupón. WooCommerce solo registra el uso cuando el pedido pasa a en espera,
 * procesando o completado, así que un pedido pendiente no figura y el mismo
 * cupón se podía usar en otro. Aquí bloquea: mientras el pedido esté pendiente
 * el cupón cuenta como reservado; si se paga, su uso queda registrado; si se
 * cancela o falla, deja de ser `pending` y el cupón se libera solo.
 *
 * Se buscan los pedidos pendientes del cliente (por cuenta y por correo de
 * facturación; son pocos) y se mira cuáles llevan el código. Se excluye el
 * pedido BORRADOR/en pago de la propia sesión: es el que este mismo carrito
 * está cobrando y no debe quitarse el cupón a sí mismo.
 */
function hwe_coupon_pending_orders( WC_Coupon $coupon, int $user_id ): int {
	static $memo = [];
	$key = $coupon->get_id() . '|' . $user_id;
	if ( isset( $memo[ $key ] ) ) {
		return $memo[ $key ];
	}

	$own = [];
	if ( function_exists( 'WC' ) && WC()->session ) {
		foreach ( [ 'store_api_draft_order', 'order_awaiting_payment' ] as $session_key ) {
			$own[] = absint( WC()->session->get( $session_key, 0 ) );
		}
	}

	$base = [ 'status' => [ 'pending' ], 'limit' => 20, 'return' => 'objects' ];
	$orders = wc_get_orders( $base + [ 'customer_id' => $user_id ] );
	$user   = get_userdata( $user_id );
	$email  = $user ? (string) $user->user_email : '';
	if ( $email !== '' ) {
		$orders = array_merge( $orders, wc_get_orders( $base + [ 'billing_email' => $email ] ) );
	}

	$code  = strtolower( $coupon->get_code() );
	$count = 0;
	$seen  = [];
	foreach ( $orders as $order ) {
		$id = $order->get_id();
		if ( isset( $seen[ $id ] ) || in_array( $id, $own, true ) ) {
			continue;
		}
		$seen[ $id ] = true;
		if ( in_array( $code, array_map( 'strtolower', $order->get_coupon_codes() ), true ) ) {
			++$count;
		}
	}

	return $memo[ $key ] = $count;
}

/**
 * ¿Bloquea un pedido pendiente el uso de este cupón? Solo los cupones que
 * dependen del cliente (primera compra o «Límite de uso por usuario»): uno sin
 * límite se puede usar en tantos pedidos como se quiera. Con primera compra el
 * límite efectivo es 1; con límite N, bloquea cuando los usos registrados más
 * los pedidos pendientes lo alcanzan.
 */
function hwe_coupon_blocked_by_pending( WC_Coupon $coupon, int $user_id ): bool {
	if ( $user_id <= 0 || ! hwe_coupon_is_per_user_limited( $coupon ) ) {
		return false;
	}
	$pending = hwe_coupon_pending_orders( $coupon, $user_id );
	if ( $pending === 0 ) {
		return false;
	}
	if ( get_post_meta( $coupon->get_id(), HWE_COUPON_META_FIRST_ONLY, true ) === 'yes' ) {
		return true;
	}
	return hwe_coupon_customer_usage( $coupon, $user_id ) + $pending >= $coupon->get_usage_limit_per_user();
}

add_filter( 'woocommerce_coupon_is_valid', function ( bool $valid, WC_Coupon $coupon ): bool {
	if ( ! $valid ) {
		return $valid;
	}

	$coupon_id = $coupon->get_id();

	// 1) Aún no ha empezado.
	$starts = (string) get_post_meta( $coupon_id, HWE_COUPON_META_STARTS, true );
	if ( hwe_coupon_is_date( $starts ) && time() < hwe_coupon_day_start( $starts ) ) {
		HWE_Coupon_Reason::$value  = 'not_started';
		// Formato numérico a propósito: el mensaje es español y el sitio está en inglés,
		// así que `date_format` devolvería «September 23, 2026» dentro de una frase castellana.
		HWE_Coupon_Reason::$detail = date_i18n( 'd/m/Y', hwe_coupon_day_start( $starts ) );
		return false;
	}

	$first_only = get_post_meta( $coupon_id, HWE_COUPON_META_FIRST_ONLY, true ) === 'yes';
	$user_id    = get_current_user_id();

	// 2) Sesión. Un cupón de primera compra la exige siempre.
	if ( $user_id <= 0 && hwe_coupon_requires_login( $coupon_id ) ) {
		HWE_Coupon_Reason::$value = $first_only ? 'auth_first' : 'auth';
		return false;
	}

	// 3) Primera compra.
	if ( $first_only && hwe_coupon_first_order_lost( $coupon, $user_id ) ) {
		HWE_Coupon_Reason::$value = 'not_first';
		return false;
	}

	// 4) Un pedido pendiente de pago ya lo tiene reservado (primera compra o
	// límite por usuario). Se libera al cancelarse y se consume al pagarse.
	if ( hwe_coupon_blocked_by_pending( $coupon, $user_id ) ) {
		HWE_Coupon_Reason::$value = 'pending';
		return false;
	}

	HWE_Coupon_Reason::$value = null;
	return true;
}, 10, 2 );

/** Sustituye el genérico «Cupón no válido» por el motivo real. */
add_filter( 'woocommerce_coupon_error', function ( $message, $error_code, $coupon ) {
	// Los rechazos por usos de WooCommerce salen en inglés y con el código entre
	// `&quot;`: en español, y distinguiendo al cliente que lo agotó del cupón
	// que se agotó para todos.
	switch ( (int) $error_code ) {
		case WC_Coupon::E_WC_COUPON_USAGE_LIMIT_REACHED:
			$user_id = get_current_user_id();
			$limit   = $coupon instanceof WC_Coupon ? (int) $coupon->get_usage_limit_per_user() : 0;
			if ( $user_id > 0 && $limit > 0 && hwe_coupon_customer_usage( $coupon, $user_id ) >= $limit ) {
				return __( 'Ya usaste este cupón el máximo de veces permitido.', 'hwe' );
			}
			return __( 'Este cupón ya alcanzó su límite de usos.', 'hwe' );
		case WC_Coupon::E_WC_COUPON_USAGE_LIMIT_COUPON_STUCK:
			return __( 'Ya tienes un pedido en proceso con este cupón: págalo o cancélalo para poder usarlo de nuevo.', 'hwe' );
		case WC_Coupon::E_WC_COUPON_USAGE_LIMIT_COUPON_STUCK_GUEST:
			return __( 'Este cupón está en uso en otro pedido sin terminar. Inténtalo de nuevo en unos minutos.', 'hwe' );
	}

	if ( (int) $error_code !== WC_Coupon::E_WC_COUPON_INVALID_FILTERED ) {
		return $message;
	}

	$reason = HWE_Coupon_Reason::$value;
	$detail = HWE_Coupon_Reason::$detail;
	// Se consume: un motivo viejo no debe contaminar la siguiente validación.
	HWE_Coupon_Reason::$value  = null;
	HWE_Coupon_Reason::$detail = '';

	if ( $reason === 'not_started' ) {
		/* translators: %s: fecha de inicio del cupón */
		return sprintf( __( 'Este cupón todavía no está activo: empieza el %s.', 'hwe' ), $detail );
	}
	if ( $reason === 'auth_first' ) {
		return __( 'Este cupón es para tu primera compra: inicia sesión o crea tu cuenta para usarlo.', 'hwe' );
	}
	if ( $reason === 'auth' ) {
		return __( 'Este cupón es para clientes con cuenta: inicia sesión o crea tu cuenta para usarlo.', 'hwe' );
	}
	if ( $reason === 'pending' ) {
		return __( 'Ya tienes un pedido pendiente de pago con este cupón: págalo o cancélalo para poder usarlo de nuevo.', 'hwe' );
	}
	if ( $reason === 'not_first' ) {
		return __( 'Este cupón solo es válido en la primera compra, y en tu cuenta ya figura un pedido.', 'hwe' );
	}

	return $message;
}, 10, 3 );

/* -------------------------------------------------------------------------- */
/* Cupones públicos: canal interno para el BFF                                 */
/* -------------------------------------------------------------------------- */

/**
 * Cuánto antes de su «Válido desde» se publica un cupón. Las fichas y categorías
 * son ISR (hasta 1 h) y leen esta lista con su propia caché de 1 h, así que una
 * página viva a la hora de empezar el cupón pudo generarse hasta 2 h antes con
 * una lista de hasta 1 h de antigüedad: necesita recibirlo con ese margen para
 * que el aviso aparezca a la hora exacta (el navegador lo oculta hasta
 * entonces). Son 3 h con holgura.
 *
 * A cambio, el código de un cupón que aún no ha empezado viaja en el HTML de
 * esas páginas (oculto) como mucho 3 h antes: no es secreto hasta su hora, solo
 * deja de estar a la vista. Carrito, checkout, login y registro son dinámicos
 * y NO lo reciben antes de tiempo (`getCartCoupons`/`getLoginCoupons` solo
 * pasan los vigentes).
 */
const HWE_COUPON_PUBLIC_LOOKAHEAD = 3 * HOUR_IN_SECONDS;

/** Slugs de una lista de IDs de producto (se omiten los que ya no existen). */
function hwe_coupon_product_slugs( array $ids ): array {
	$slugs = [];
	foreach ( $ids as $id ) {
		$slug = get_post_field( 'post_name', (int) $id );
		if ( is_string( $slug ) && $slug !== '' ) {
			$slugs[] = $slug;
		}
	}
	return $slugs;
}

/** Slugs de una lista de IDs de `product_cat` (se omiten los que ya no existen). */
function hwe_coupon_category_slugs( array $ids ): array {
	$slugs = [];
	foreach ( $ids as $id ) {
		$term = get_term( (int) $id, 'product_cat' );
		if ( $term instanceof WP_Term ) {
			$slugs[] = $term->slug;
		}
	}
	return $slugs;
}

/**
 * ¿Tiene una restricción que el frontend no puede evaluar para decidir DÓNDE
 * anunciar el cupón? Los correos permitidos dependen de quién compra y las
 * marcas (WooCommerce Brands: metas `product_brands` / `exclude_product_brands`)
 * de un dato del producto que el BFF no maneja. Anunciarlo a todos prometería
 * algo que WordPress rechazaría al aplicarlo, así que uno así NO se publica:
 * sigue funcionando escribiendo el código.
 */
function hwe_coupon_has_unadvertisable_restriction( WC_Coupon $coupon ): bool {
	if ( count( array_filter( (array) $coupon->get_email_restrictions() ) ) > 0 ) {
		return true;
	}
	foreach ( [ 'product_brands', 'exclude_product_brands' ] as $meta ) {
		$brands = get_post_meta( $coupon->get_id(), $meta, true );
		if ( is_array( $brands ) && count( array_filter( $brands ) ) > 0 ) {
			return true;
		}
	}
	return false;
}

/** Importe mínimo/máximo de gasto como número, o `null` si no hay límite. */
function hwe_coupon_spend_limit( $value ): ?float {
	$amount = (float) $value;
	return $amount > 0 ? $amount : null;
}

/** IDs de los cupones públicos publicados (tope de 50: es una lista de campañas). */
function hwe_coupon_public_ids(): array {
	return get_posts( [
		'post_type'      => 'shop_coupon',
		'post_status'    => 'publish',
		'posts_per_page' => 50,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => [ [ 'key' => HWE_COUPON_META_PUBLIC, 'value' => 'yes' ] ],
	] );
}

/**
 * ¿Ya NO puede usar este cliente el cupón? Dos motivos que dependen de la
 * persona y que la lista pública (compartida, en caché) no puede saber:
 *
 *   - agotó su «Límite de uso por usuario» (un cupón de un solo uso ya
 *     usado). Se cuenta como WooCommerce: por id de usuario y por correo
 *     (un pedido de invitado con el mismo correo también cuenta), incluidos
 *     los usos «en espera» de un pedido aún sin pagar;
 *   - es de primera compra y ya tiene un pedido pagado desde la fecha de corte
 *     (o ya usó este cupón en un pedido en espera: `hwe_coupon_first_order_lost`);
 *   - tiene un pedido PENDIENTE DE PAGO con este cupón (`hwe_coupon_blocked_by_pending`):
 *     lo reserva hasta que se pague (queda usado) o se cancele (se libera).
 *
 * WordPress lo volvería a rechazar al aplicarlo; esto solo evita OFRECERLO.
 */
function hwe_coupon_used_up_by( WC_Coupon $coupon, int $user_id ): bool {
	$limit = $coupon->get_usage_limit_per_user();
	if ( $limit > 0 && hwe_coupon_customer_usage( $coupon, $user_id ) >= $limit ) {
		return true;
	}

	if ( get_post_meta( $coupon->get_id(), HWE_COUPON_META_FIRST_ONLY, true ) === 'yes'
		&& hwe_coupon_first_order_lost( $coupon, $user_id ) ) {
		return true;
	}

	return hwe_coupon_blocked_by_pending( $coupon, $user_id );
}

/**
 * ¿Depende del cliente que el cupón siga disponible? Con «Límite de uso por
 * usuario» o «Solo primera compra» sí; sin ninguno de los dos, lo que queda
 * (fechas, usos totales) es igual para todos y ya viaja en la lista compartida.
 */
function hwe_coupon_is_per_user_limited( WC_Coupon $coupon ): bool {
	return $coupon->get_usage_limit_per_user() > 0
		|| get_post_meta( $coupon->get_id(), HWE_COUPON_META_FIRST_ONLY, true ) === 'yes';
}

/** Códigos de los cupones públicos que este cliente ya no puede usar. */
function hwe_coupon_public_used_up( int $user_id ): array {
	$codes = [];
	foreach ( hwe_coupon_public_ids() as $id ) {
		$coupon = new WC_Coupon( (int) $id );
		if ( $coupon->get_id() && hwe_coupon_is_per_user_limited( $coupon ) && hwe_coupon_used_up_by( $coupon, $user_id ) ) {
			$codes[] = $coupon->get_code();
		}
	}
	return $codes;
}

/**
 * Cupones públicos vigentes o a punto de empezar, con lo que el frontend
 * necesita para anunciarlos: código, descuento, alcance (por SLUG, que es la
 * identidad del catálogo en el frontend) y condiciones. `startsAt`/`endsAt`
 * son timestamps. WooCommerce da la caducidad como el INICIO de su día y el
 * cupón vale durante todo ese día (caducidad inclusiva), así que `endsAt` es el
 * instante en que deja de valer: ese inicio + 24 h, EXCLUSIVO (`now < endsAt`).
 * Un cupón agotado (`usage_limit`) no se anuncia.
 */
function hwe_coupon_public_list(): array {
	$ids = hwe_coupon_public_ids();

	$now = time();
	$out = [];
	foreach ( $ids as $id ) {
		$coupon = new WC_Coupon( (int) $id );
		if ( ! $coupon->get_id() ) {
			continue;
		}

		$expires = $coupon->get_date_expires();
		$ends_at = $expires ? $expires->getTimestamp() + DAY_IN_SECONDS : null;
		if ( $ends_at !== null && $now >= $ends_at ) {
			continue;
		}

		$starts    = (string) get_post_meta( $coupon->get_id(), HWE_COUPON_META_STARTS, true );
		$starts_at = hwe_coupon_is_date( $starts ) ? hwe_coupon_day_start( $starts ) : null;
		if ( $starts_at !== null && $starts_at > $now + HWE_COUPON_PUBLIC_LOOKAHEAD ) {
			continue;
		}

		$limit = $coupon->get_usage_limit();
		if ( $limit > 0 && $coupon->get_usage_count() >= $limit ) {
			continue;
		}

		if ( hwe_coupon_has_unadvertisable_restriction( $coupon ) ) {
			continue;
		}

		$scope = hwe_coupon_scope( $coupon );
		$out[] = [
			'code'                  => $coupon->get_code(),
			'discountType'          => $coupon->get_discount_type(),
			'amount'                => (float) $coupon->get_amount(),
			// Lo aplica el módulo `shipping` (regla de envío gratis): la tarifa queda a 0.
			'freeShipping'          => $coupon->get_free_shipping(),
			'scope'                 => $scope,
			'productSlugs'          => $scope === 'products' ? hwe_coupon_product_slugs( $coupon->get_product_ids() ) : [],
			'categorySlugs'         => $scope === 'categories' ? hwe_coupon_category_slugs( $coupon->get_product_categories() ) : [],
			'excludedProductSlugs'  => hwe_coupon_product_slugs( $coupon->get_excluded_product_ids() ),
			'excludedCategorySlugs' => hwe_coupon_category_slugs( $coupon->get_excluded_product_categories() ),
			'excludeSaleItems'      => $coupon->get_exclude_sale_items(),
			'minimumAmount'         => hwe_coupon_spend_limit( $coupon->get_minimum_amount() ),
			'maximumAmount'         => hwe_coupon_spend_limit( $coupon->get_maximum_amount() ),
			'requiresLogin'         => hwe_coupon_requires_login( $coupon->get_id() ),
			'firstOrderOnly'        => get_post_meta( $coupon->get_id(), HWE_COUPON_META_FIRST_ONLY, true ) === 'yes',
			// ¿Puede un cliente concreto haberlo agotado? Solo entonces el BFF consulta
			// `coupons/public/used-up` (una llamada a WordPress por cliente y página).
			'perUserLimited'        => hwe_coupon_is_per_user_limited( $coupon ),
			'startsAt'              => $starts_at,
			'endsAt'                => $ends_at,
		];
	}
	return $out;
}

// Solo el BFF: aunque el aviso sea público, la lista incluye cupones que aún
// no han empezado (los próximos HWE_COUPON_PUBLIC_LOOKAHEAD), y su código no
// debe poder leerse directamente antes de tiempo.
add_action( 'rest_api_init', function () {
	register_rest_route( 'hwe/v1', '/coupons/public', [
		'methods'             => 'GET',
		'permission_callback' => function ( WP_REST_Request $request ): bool {
			return function_exists( 'hwe_has_internal_secret' ) && hwe_has_internal_secret( $request );
		},
		'callback'            => function () {
			return new WP_REST_Response( [ 'items' => hwe_coupon_public_list() ], 200 );
		},
	] );

	// Por cliente, sin caché: qué cupones públicos ya agotó. Lo pide el BFF al
	// pintar el carrito y el checkout para no ofrecerle uno que ya usó. El
	// `user_id` sale de la sesión del BFF (JWT verificado), nunca del navegador.
	register_rest_route( 'hwe/v1', '/coupons/public/used-up', [
		'methods'             => 'GET',
		'permission_callback' => function ( WP_REST_Request $request ): bool {
			return function_exists( 'hwe_has_internal_secret' ) && hwe_has_internal_secret( $request );
		},
		'args'                => [
			'user_id' => [
				'required'          => true,
				'validate_callback' => function ( $value ): bool {
					return is_numeric( $value ) && (int) $value > 0;
				},
			],
		],
		'callback'            => function ( WP_REST_Request $request ) {
			$codes = hwe_coupon_public_used_up( (int) $request->get_param( 'user_id' ) );
			return new WP_REST_Response( [ 'codes' => $codes ], 200 );
		},
	] );
} );

/** Declara el tag ISR del módulo: sin esto el núcleo no envía su revalidación. */
add_filter( 'hwe_revalidation_tags', function ( $tags ): array {
	$tags   = is_array( $tags ) ? $tags : [];
	$tags[] = 'coupons';
	return array_values( array_unique( $tags ) );
} );

/**
 * Guardar, publicar, mandar a la papelera o restaurar un cupón invalida los
 * avisos del frontend (tag `coupons`) sin esperar a su caché. Las FECHAS no
 * disparan nada —no hay evento a medianoche—: esas las resuelve el navegador.
 * El AGOTAMIENTO (límite total de usos) sí tiene evento, el contador de uso:
 * ver `hwe_coupon_usage_changed` más abajo.
 */
function hwe_coupon_revalidate(): void {
	if ( class_exists( 'HWE\ControlCenter\Revalidation' ) ) {
		\HWE\ControlCenter\Revalidation::trigger( 'coupons' );
	}
}
add_action( 'woocommerce_coupon_options_save', 'hwe_coupon_revalidate', 20 );
add_action( 'transition_post_status', function ( $new_status, $old_status, $post ): void {
	if ( $post instanceof WP_Post && $post->post_type === 'shop_coupon' && $new_status !== $old_status ) {
		hwe_coupon_revalidate();
	}
}, 10, 3 );

/**
 * Un cupón público con límite total de usos deja de anunciarse cuando el último
 * uso se consume, y vuelve a anunciarse si un pedido se cancela y lo libera.
 * WooCommerce cambia el contador al pasar un pedido a pagado/cancelado, sin
 * tocar el cupón, así que ni el guardado ni el cambio de estado lo avisaban y
 * el anuncio seguía hasta una hora (caché de la lista) después de agotarse.
 * Solo se revalida al CRUZAR el límite, no en cada uso: un cupón sin límite o
 * con usos de sobra no cuesta una llamada al frontend por pedido.
 *
 * @param int $delta +1 si el uso se consumió, -1 si se liberó.
 */
function hwe_coupon_usage_changed( $coupon, $new_count, int $delta ): void {
	if ( ! $coupon instanceof WC_Coupon ) {
		return;
	}
	$limit = (int) $coupon->get_usage_limit();
	if ( $limit <= 0 || get_post_meta( $coupon->get_id(), HWE_COUPON_META_PUBLIC, true ) !== 'yes' ) {
		return;
	}
	$new_count = (int) $new_count;
	$was_full  = ( $new_count - $delta ) >= $limit;
	$is_full   = $new_count >= $limit;
	if ( $was_full !== $is_full ) {
		hwe_coupon_revalidate();
	}
}
add_action( 'woocommerce_increase_coupon_usage_count', function ( $coupon, $new_count ): void {
	hwe_coupon_usage_changed( $coupon, $new_count, 1 );
}, 10, 2 );
add_action( 'woocommerce_decrease_coupon_usage_count', function ( $coupon, $new_count ): void {
	hwe_coupon_usage_changed( $coupon, $new_count, -1 );
}, 10, 2 );
