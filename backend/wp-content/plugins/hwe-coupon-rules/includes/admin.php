<?php
/**
 * Pantalla de edición del cupón (Marketing → Cupones), reorganizada por tema.
 *
 * WooCommerce reparte sus campos en tres pestañas que mezclan preguntas
 * distintas («Restricciones de uso» junta el gasto mínimo, los productos y los
 * correos; la caducidad vive en «General», lejos de la fecha de inicio), y los
 * campos propios de este plugin caían donde había un hook. Aquí la pantalla se
 * ordena por la pregunta que responde cada campo:
 *
 *   Descuento               — tipo, importe, envío gratis (la «General» nativa)
 *   Productos               — «Aplica a» + su lista, y las exclusiones
 *   Clientes                — quién puede usarlo, correos, usos por cliente
 *   Vigencia y visibilidad  — desde / hasta, y si se anuncia en la web
 *   Límites                 — condiciones del carrito y número de usos
 *
 * Los campos PROPIOS se pintan en PHP dentro de sus pestañas. Los NATIVOS los
 * pinta el core en las suyas y `admin.js` los traslada a su hueco
 * (`data-hwe-slot`): se mueve la fila entera, con su `name` intacto, así que el
 * guardado del core no cambia. Sin JS la pantalla se degrada a la nativa más
 * las pestañas propias: todo sigue guardándose. Una pestaña nativa solo se
 * oculta si quedó vacía, para no esconder lo que otro plugin añada en ella.
 *
 * Una columna lateral, «Resumen del cupón», traduce la configuración a frases
 * y avisa de las combinaciones que no hacen lo que parecen.
 *
 * Lo carga `hwe-coupon-rules.php` solo en el admin (`includes/admin.php`).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Valores del selector «Quién puede usarlo», derivados de las dos metas. */
const HWE_COUPON_AUDIENCES = [ 'anyone', 'login', 'first' ];

/** `true` en la pantalla de edición/alta de un cupón. */
function hwe_coupon_is_edit_screen(): bool {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	return $screen instanceof WP_Screen && $screen->base === 'post' && $screen->post_type === 'shop_coupon';
}

/** Audiencia actual: la primera compra implica sesión, así que gana. */
function hwe_coupon_audience( int $coupon_id ): string {
	if ( get_post_meta( $coupon_id, HWE_COUPON_META_FIRST_ONLY, true ) === 'yes' ) {
		return 'first';
	}
	return hwe_coupon_requires_login( $coupon_id ) ? 'login' : 'anyone';
}

/* -------------------------------------------------------------------------- */
/* Pestañas                                                                    */
/* -------------------------------------------------------------------------- */

add_filter( 'woocommerce_coupon_data_tabs', function ( array $tabs ): array {
	$ordered = [];

	if ( isset( $tabs['general'] ) ) {
		$tabs['general']['label'] = __( 'Descuento', 'hwe' );
		$ordered['general']       = $tabs['general'];
	}

	$ordered['hwe_scope']     = [ 'label' => __( 'Productos', 'hwe' ), 'target' => 'hwe_scope_coupon_data', 'class' => '' ];
	$ordered['hwe_customers'] = [ 'label' => __( 'Clientes', 'hwe' ), 'target' => 'hwe_customers_coupon_data', 'class' => '' ];
	$ordered['hwe_validity']  = [ 'label' => __( 'Vigencia y visibilidad', 'hwe' ), 'target' => 'hwe_validity_coupon_data', 'class' => '' ];
	$ordered['hwe_limits']    = [ 'label' => __( 'Límites', 'hwe' ), 'target' => 'hwe_limits_coupon_data', 'class' => '' ];

	// Las nativas que quedan (y las de otros plugins) van detrás. `admin.js`
	// oculta las nativas que se quedan sin campos tras el traslado.
	foreach ( $tabs as $key => $tab ) {
		if ( ! isset( $ordered[ $key ] ) ) {
			$ordered[ $key ] = $tab;
		}
	}
	return $ordered;
} );

/** Encabezado de sección dentro de una pestaña, con una línea de contexto opcional. */
function hwe_coupon_section( string $title, string $intro = '' ): void {
	echo '<div class="hwe-coupon-section"><h4>' . esc_html( $title ) . '</h4>';
	if ( $intro !== '' ) {
		echo '<p>' . esc_html( $intro ) . '</p>';
	}
	echo '</div>';
}

/** Hueco al que `admin.js` traslada campos nativos. */
function hwe_coupon_slot( string $slot ): void {
	echo '<div class="hwe-coupon-slot" data-hwe-slot="' . esc_attr( $slot ) . '"></div>';
}

/** Aviso en línea que `admin.js` muestra u oculta (`data-hwe-warning`). */
function hwe_coupon_inline_warning( string $key, string $text ): void {
	echo '<p class="hwe-coupon-warning" data-hwe-warning="' . esc_attr( $key ) . '" hidden>' . esc_html( $text ) . '</p>';
}

/** Campo de fecha con el mismo selector que la caducidad nativa. */
function hwe_coupon_date_input( string $id, string $label, string $description, string $value ): void {
	woocommerce_wp_text_input( [
		'id'                => $id,
		'label'             => $label,
		'description'       => $description,
		'desc_tip'          => false,
		'class'             => 'date-picker',
		'placeholder'       => 'AAAA-MM-DD',
		'value'             => $value,
		'custom_attributes' => [
			'pattern'      => '[0-9]{4}-(0[1-9]|1[012])-(0[1-9]|1[0-9]|2[0-9]|3[01])',
			'autocomplete' => 'off',
		],
	] );
}

add_action( 'woocommerce_coupon_data_panels', function ( int $coupon_id, $coupon = null ): void {
	$coupon = $coupon instanceof WC_Coupon ? $coupon : new WC_Coupon( $coupon_id );

	/* ---------------------------------------------------------------- Productos */
	echo '<div id="hwe_scope_coupon_data" class="panel woocommerce_options_panel hwe-coupon-panel">';

	echo '<div class="options_group">';
	hwe_coupon_section(
		__( '¿A qué se aplica?', 'hwe' ),
		__( 'Una categoría incluye sus subcategorías. Nunca productos y categorías a la vez: al guardar se vacía la lista que no corresponde.', 'hwe' )
	);
	woocommerce_wp_radio( [
		'class'   => 'hwe-coupon-radio',
		'id'      => 'hwe_coupon_scope',
		'label'   => __( 'Aplica a', 'hwe' ),
		'value'   => hwe_coupon_scope( $coupon ),
		'options' => [
			'all'        => __( 'Todo el catálogo', 'hwe' ),
			'categories' => __( 'Solo unas categorías', 'hwe' ),
			'products'   => __( 'Solo unos productos', 'hwe' ),
		],
	] );
	hwe_coupon_slot( 'scope-lists' );
	hwe_coupon_inline_warning( 'scope-empty', __( 'La lista está vacía: así el cupón aplica a todo el catálogo.', 'hwe' ) );
	echo '</div>';

	echo '<div class="options_group">';
	hwe_coupon_section(
		__( 'Excepciones', 'hwe' ),
		__( 'Opcional. Lo que el cupón nunca descuenta, aunque esté dentro de su alcance.', 'hwe' )
	);
	hwe_coupon_slot( 'exclusions' );
	echo '</div>';

	// Marcas: los campos los añade WooCommerce (Brands, desde 9.6) en «Restricciones
	// de uso». Son un filtro MÁS, que se suma al alcance. `admin.js` oculta la
	// sección si no existen.
	echo '<div class="options_group" data-hwe-section="brands">';
	hwe_coupon_section(
		__( 'Marcas', 'hwe' ),
		__( 'Opcional. Se suman al alcance: el producto tiene que ser, además, de una de estas marcas.', 'hwe' )
	);
	hwe_coupon_slot( 'brands' );
	echo '</div>';

	echo '</div>';

	/* ----------------------------------------------------------------- Clientes */
	echo '<div id="hwe_customers_coupon_data" class="panel woocommerce_options_panel hwe-coupon-panel">';

	echo '<div class="options_group">';
	hwe_coupon_section( __( '¿Quién puede usarlo?', 'hwe' ) );
	woocommerce_wp_radio( [
		'class'   => 'hwe-coupon-radio',
		'id'      => 'hwe_coupon_audience',
		'label'   => __( 'Clientes', 'hwe' ),
		'value'   => hwe_coupon_audience( $coupon_id ),
		'options' => [
			'anyone' => __( 'Cualquiera, también sin cuenta (invitados)', 'hwe' ),
			'login'  => __( 'Solo clientes con sesión iniciada', 'hwe' ),
			'first'  => __( 'Solo en la primera compra (exige sesión)', 'hwe' ),
		],
	] );
	echo '<div data-hwe-when="audience-first">';
	hwe_coupon_date_input(
		HWE_COUPON_META_FIRST_SINCE,
		__( 'Contar pedidos desde', 'hwe' ),
		__( 'Solo cuentan los pedidos pagados desde esta fecha. Pon la del lanzamiento de la web nueva para ignorar el historial migrado.', 'hwe' ),
		(string) get_post_meta( $coupon_id, HWE_COUPON_META_FIRST_SINCE, true )
	);
	hwe_coupon_inline_warning( 'first-since-empty', __( 'Sin fecha se cuenta todo el historial, incluidos los pedidos de la web antigua: casi ningún cliente registrado tendría «primera compra».', 'hwe' ) );
	echo '</div>';
	echo '</div>';

	echo '<div class="options_group">';
	hwe_coupon_section( __( 'Más restricciones', 'hwe' ), __( 'Opcional.', 'hwe' ) );
	hwe_coupon_slot( 'customers' );
	echo '</div>';

	echo '</div>';

	/* --------------------------------------------------- Vigencia y visibilidad */
	echo '<div id="hwe_validity_coupon_data" class="panel woocommerce_options_panel hwe-coupon-panel">';

	echo '<div class="options_group">';
	hwe_coupon_section(
		__( '¿Cuándo funciona?', 'hwe' ),
		__( 'Las dos fechas son inclusivas y van en la zona horaria del sitio (Ajustes → Generales). Vacías = sin límite.', 'hwe' )
	);
	hwe_coupon_date_input(
		HWE_COUPON_META_STARTS,
		__( 'Válido desde', 'hwe' ),
		__( 'Funciona desde las 00:00 de este día.', 'hwe' ),
		(string) get_post_meta( $coupon_id, HWE_COUPON_META_STARTS, true )
	);
	hwe_coupon_slot( 'validity' );
	hwe_coupon_inline_warning( 'dates-reversed', __( 'La fecha de inicio es posterior a la de fin: el cupón nunca funcionaría.', 'hwe' ) );
	echo '</div>';

	echo '<div class="options_group">';
	hwe_coupon_section( __( '¿Se anuncia en la web?', 'hwe' ) );
	woocommerce_wp_radio( [
		'class'   => 'hwe-coupon-radio',
		'id'      => HWE_COUPON_META_PUBLIC,
		'label'   => __( 'Visibilidad', 'hwe' ),
		'value'   => get_post_meta( $coupon_id, HWE_COUPON_META_PUBLIC, true ) === 'yes' ? 'yes' : 'no',
		'options' => [
			'no'  => __( 'Privado: solo funciona si el cliente conoce el código', 'hwe' ),
			'yes' => __( 'Público: se anuncia con su código en la web', 'hwe' ),
		],
	] );
	echo '<p class="hwe-coupon-note" data-hwe-when="public">'
		. esc_html__( 'El aviso sale en las fichas y categorías a las que aplica y, si exige sesión, en el inicio de sesión y el registro. Solo dentro de sus fechas y mientras le queden usos. No cambia ningún precio. Un cupón con correos permitidos o con marcas NO se anuncia (el aviso no puede saber a quién ni a qué marca se le ofrece): sigue funcionando escribiendo el código.', 'hwe' )
		. '</p>';
	hwe_coupon_inline_warning( 'public-products-hidden', __( 'Un cupón de productos sueltos no se anuncia en ninguna categoría: solo en la ficha de esos productos.', 'hwe' ) );
	echo '</div>';

	echo '</div>';

	/* ------------------------------------------------------------------ Límites */
	echo '<div id="hwe_limits_coupon_data" class="panel woocommerce_options_panel hwe-coupon-panel">';

	echo '<div class="options_group">';
	hwe_coupon_section( __( 'Condiciones del carrito', 'hwe' ), __( 'Opcional. Se comprueban sobre el subtotal del carrito.', 'hwe' ) );
	hwe_coupon_slot( 'cart' );
	echo '</div>';

	echo '<div class="options_group">';
	hwe_coupon_section( __( 'Número de usos', 'hwe' ), __( 'Opcional. Vacío = sin límite.', 'hwe' ) );
	hwe_coupon_slot( 'usage' );
	echo '</div>';

	echo '</div>';
}, 10, 2 );

/* -------------------------------------------------------------------------- */
/* Rótulos nativos que cambian de sentido con este plugin                      */
/* -------------------------------------------------------------------------- */

/**
 * La caducidad nativa pasa a ser inclusiva (ver `woocommerce_coupon_validate_expiry_date`
 * en el plugin principal), así que su ayuda original —«caduca a las 00:00 de
 * esta fecha»— mentiría. Se cambia en la cadena ORIGINAL (inglés), así vale
 * con cualquier idioma del admin.
 */
add_filter( 'gettext_woocommerce', function ( $translation, $text ) {
	if ( ! is_admin() || ( $GLOBALS['typenow'] ?? '' ) !== 'shop_coupon' ) {
		return $translation;
	}
	switch ( $text ) {
		case 'Coupon expiry date':
			return __( 'Válido hasta', 'hwe' );
		case 'The coupon will expire at 00:00:00 of this date.':
			return __( 'Último día en que funciona, incluido: caduca al terminar ese día.', 'hwe' );
		case 'Coupon amount':
			return __( 'Importe', 'hwe' );
		case 'Allow free shipping':
			return __( 'Envío gratis', 'hwe' );
		// La ayuda original pide un método «Envío gratuito» en la zona que exija
		// cupón. Aquí no aplica: la zona solo tiene la tarifa por ciudad, y es el
		// plugin HWE Shipping quien la deja a 0 con este cupón.
		case 'Check this box if the coupon grants free shipping. A <a href="%s" target="_blank">free shipping method</a> must be enabled in your shipping zone and be set to require "a valid free shipping coupon" (see the "Free Shipping Requires" setting).':
			return __( 'El envío (la tarifa de la ciudad) sale gratis con este cupón. Los extras de envío que elija el cliente se siguen cobrando. No hace falta configurar nada más en la zona de envío.', 'hwe' );
	}
	return $translation;
}, 10, 2 );

/* -------------------------------------------------------------------------- */
/* Resumen lateral                                                             */
/* -------------------------------------------------------------------------- */

add_action( 'add_meta_boxes_shop_coupon', function (): void {
	add_meta_box(
		'hwe-coupon-summary',
		__( 'Resumen del cupón', 'hwe' ),
		function (): void {
			// Lo rellena `admin.js` en vivo a partir de los campos del formulario.
			echo '<div id="hwe-coupon-summary-body" aria-live="polite"><p class="description">'
				. esc_html__( 'El resumen necesita JavaScript.', 'hwe' )
				. '</p></div>';
		},
		'shop_coupon',
		'side',
		'high'
	);
} );

/* -------------------------------------------------------------------------- */
/* Recursos                                                                    */
/* -------------------------------------------------------------------------- */

add_action( 'admin_enqueue_scripts', function (): void {
	if ( ! hwe_coupon_is_edit_screen() ) {
		return;
	}

	$dir = dirname( __DIR__ ) . '/assets';
	$url = plugins_url( 'assets', dirname( __DIR__ ) . '/hwe-coupon-rules.php' );

	wp_enqueue_style( 'hwe-coupon-admin', $url . '/admin.css', [], (string) filemtime( $dir . '/admin.css' ) );
	wp_enqueue_script( 'hwe-coupon-admin', $url . '/admin.js', [ 'jquery' ], (string) filemtime( $dir . '/admin.js' ), true );

	wp_localize_script( 'hwe-coupon-admin', 'hweCouponAdmin', [
		'currency' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
		'decimals' => wc_get_price_decimals(),
		// «Hoy» en la zona del sitio, no la del navegador: es la que decide la validez.
		'today'    => wp_date( 'Y-m-d' ),
	] );
} );

/* -------------------------------------------------------------------------- */
/* Guardado                                                                    */
/* -------------------------------------------------------------------------- */

add_action( 'woocommerce_coupon_options_save', function ( int $coupon_id, $coupon = null ): void {
	// WooCommerce ya verificó el nonce y la capacidad antes de disparar este hook,
	// y ya guardó los campos nativos: aquí se vacía la lista que el alcance excluye.
	$coupon = $coupon instanceof WC_Coupon ? $coupon : new WC_Coupon( $coupon_id );
	$scope  = isset( $_POST['hwe_coupon_scope'] ) ? sanitize_key( wp_unslash( $_POST['hwe_coupon_scope'] ) ) : '';
	if ( in_array( $scope, [ 'all', 'products', 'categories' ], true ) ) {
		if ( $scope !== 'products' ) {
			$coupon->set_product_ids( [] );
		}
		if ( $scope !== 'categories' ) {
			$coupon->set_product_categories( [] );
		}
		$coupon->save();
	}

	// Radio (`yes`/`no`) y, por compatibilidad, la casilla anterior (`yes` o ausente).
	$public = isset( $_POST[ HWE_COUPON_META_PUBLIC ] ) && wp_unslash( $_POST[ HWE_COUPON_META_PUBLIC ] ) === 'yes';
	update_post_meta( $coupon_id, HWE_COUPON_META_PUBLIC, $public ? 'yes' : 'no' );

	// «Quién puede usarlo» se guarda en las dos metas que lee la validación.
	// Si no llega (formulario de otra versión), no se tocan.
	$audience = isset( $_POST['hwe_coupon_audience'] ) ? sanitize_key( wp_unslash( $_POST['hwe_coupon_audience'] ) ) : '';
	if ( in_array( $audience, HWE_COUPON_AUDIENCES, true ) ) {
		update_post_meta( $coupon_id, HWE_COUPON_META_FIRST_ONLY, $audience === 'first' ? 'yes' : 'no' );
		update_post_meta( $coupon_id, HWE_COUPON_META_LOGIN, $audience === 'anyone' ? 'no' : 'yes' );
	}

	foreach ( [ HWE_COUPON_META_STARTS, HWE_COUPON_META_FIRST_SINCE ] as $meta ) {
		$value = isset( $_POST[ $meta ] ) ? sanitize_text_field( wp_unslash( $_POST[ $meta ] ) ) : '';
		// Solo se guarda una fecha con forma AAAA-MM-DD; cualquier otra cosa se descarta.
		if ( hwe_coupon_is_date( $value ) ) {
			update_post_meta( $coupon_id, $meta, $value );
		} else {
			delete_post_meta( $coupon_id, $meta );
		}
	}

	// Avisos tras guardar: combinaciones válidas para WooCommerce que no hacen
	// lo que parece. No bloquean el guardado.
	if ( ! class_exists( 'WC_Admin_Meta_Boxes' ) ) {
		return;
	}
	$coupon  = new WC_Coupon( $coupon_id );
	$starts  = (string) get_post_meta( $coupon_id, HWE_COUPON_META_STARTS, true );
	$expires = $coupon->get_date_expires();
	if ( hwe_coupon_is_date( $starts ) && $expires && $starts > $expires->date( 'Y-m-d' ) ) {
		WC_Admin_Meta_Boxes::add_error( __( 'Cupón: «Válido desde» es posterior a «Válido hasta», así que nunca funcionará. Revisa la pestaña «Vigencia y visibilidad».', 'hwe' ) );
	}
	if ( $audience === 'first' && ! hwe_coupon_is_date( (string) get_post_meta( $coupon_id, HWE_COUPON_META_FIRST_SINCE, true ) ) ) {
		WC_Admin_Meta_Boxes::add_error( __( 'Cupón de primera compra sin «Contar pedidos desde»: cuenta también los pedidos migrados de la web antigua. Revisa la pestaña «Clientes».', 'hwe' ) );
	}
	if ( in_array( $scope, [ 'products', 'categories' ], true ) && hwe_coupon_scope( $coupon ) === 'all' ) {
		WC_Admin_Meta_Boxes::add_error( __( 'Cupón: elegiste aplicarlo a unos productos o categorías pero la lista quedó vacía, así que aplica a todo el catálogo.', 'hwe' ) );
	}
}, 10, 2 );
