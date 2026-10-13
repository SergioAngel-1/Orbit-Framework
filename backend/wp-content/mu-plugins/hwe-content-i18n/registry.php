<?php
/**
 * Idiomas y registro de campos traducibles de `hwe-content-i18n`.
 *
 * Un campo traducible se declara por tipo de contenido (tipo de post o taxonomía) con el
 * filtro `hwe_translatable_fields`. Su traducción a un idioma se guarda en la meta
 * `_hwe_<campo>_<sufijo>`, donde el sufijo es el idioma normalizado (`pt-BR` → `pt_br`).
 * Los campos `title`, `excerpt`, `content`, `seo_title`, `seo_description`, `name`,
 * `short_description`, `description` y `slug` producen las mismas claves que ya usaba la
 * versión anterior para el inglés (`_hwe_title_en`…), así que no hay migración de datos.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Formatos de entrada admitidos por un campo: texto de una línea, texto largo, HTML y slug. */
const HWE_I18N_INPUTS = array( 'text', 'textarea', 'html', 'slug' );

/** Largos de los campos SEO (los mismos que `hwe-seo/fields.php`). */
const HWE_I18N_SEO_TITLE_MAX = 60;
const HWE_I18N_SEO_DESC_MAX  = 160;

/**
 * Idiomas secundarios del sitio como sufijos de clave, sin el idioma por defecto.
 * Vacío = sitio de un solo idioma: el plugin no registra nada.
 *
 * @return list<string>
 */
function hwe_i18n_locales(): array {
	$raw = apply_filters( 'hwe_content_i18n_locales', array() );
	if ( ! is_array( $raw ) ) {
		return array();
	}
	$out = array();
	foreach ( $raw as $locale ) {
		$suffix = is_string( $locale ) ? hwe_i18n_locale_suffix( $locale ) : '';
		if ( '' !== $suffix && ! in_array( $suffix, $out, true ) ) {
			$out[] = $suffix;
		}
	}
	return $out;
}

/**
 * Idioma → sufijo de clave: minúsculas y `-` → `_`. Devuelve '' si no es un código de
 * idioma (así un valor de la petición nunca llega a una clave de meta).
 */
function hwe_i18n_locale_suffix( string $locale ): string {
	$suffix = str_replace( '-', '_', strtolower( trim( $locale ) ) );
	return 1 === preg_match( '/^[a-z]{2,3}(?:_[a-z0-9]{2,8})?$/', $suffix ) ? $suffix : '';
}

/**
 * Proveedor por defecto de los idiomas: `i18n.locales` del Control Center. Va como filtro
 * (y no dentro de `hwe_i18n_locales()`) para que una instancia pueda sustituirlo.
 *
 * @param mixed $locales Lista que traiga el filtro.
 * @return mixed
 */
function hwe_i18n_default_locales( $locales ) {
	if ( is_array( $locales ) && array() !== $locales ) {
		return $locales;
	}
	if ( class_exists( 'HWE\ControlCenter\Schema' ) ) {
		return \HWE\ControlCenter\Schema::secondaryLocales();
	}
	return array();
}
add_filter( 'hwe_content_i18n_locales', 'hwe_i18n_default_locales', 5 );

/**
 * Registro de campos que declara el núcleo.
 *
 * @return array<string,array<string,mixed>>
 */
function hwe_i18n_core_registry(): array {
	$title   = array( 'label' => __( 'Título', 'hwe' ), 'input' => 'text', 'search' => true );
	$seo     = array(
		'seo_title'       => array( 'label' => __( 'Título SEO', 'hwe' ), 'input' => 'text', 'max' => HWE_I18N_SEO_TITLE_MAX ),
		'seo_description' => array( 'label' => __( 'Descripción SEO', 'hwe' ), 'input' => 'textarea', 'max' => HWE_I18N_SEO_DESC_MAX ),
	);
	$slug    = array( 'label' => __( 'Slug de la URL', 'hwe' ), 'input' => 'slug' );
	$name    = array( 'label' => __( 'Nombre', 'hwe' ), 'input' => 'text', 'search' => true );
	$content = array( 'label' => __( 'Contenido', 'hwe' ), 'input' => 'html', 'search' => true );

	return array(
		'post'       => array(
			'kind'       => 'post',
			'graphql'    => 'Post',
			'revalidate' => 'posts',
			'fields'     => array(
				'title'   => $title,
				'excerpt' => array( 'label' => __( 'Resumen', 'hwe' ), 'input' => 'textarea', 'search' => true ),
				'content' => $content,
			) + $seo + array( 'slug' => $slug ),
		),
		'page'       => array(
			'kind'    => 'post',
			'graphql' => 'Page',
			'fields'  => array(
				'title'   => $title,
				'content' => $content,
			) + $seo,
		),
		'product'    => array(
			'kind'       => 'post',
			'graphql'    => 'Product',
			'revalidate' => 'products',
			'fields'     => array(
				'name'              => $name,
				'short_description' => array( 'label' => __( 'Descripción corta', 'hwe' ), 'input' => 'textarea', 'search' => true ),
				'description'       => array( 'label' => __( 'Descripción larga', 'hwe' ), 'input' => 'html', 'search' => true ),
			) + $seo + array( 'slug' => $slug ),
		),
		'product_cat' => array(
			'kind'       => 'term',
			'graphql'    => 'ProductCategory',
			'revalidate' => 'products',
			'fields'     => array(
				'name'        => $name,
				'description' => array( 'label' => __( 'Descripción', 'hwe' ), 'input' => 'html' ),
			) + $seo + array( 'slug' => $slug ),
		),
		// El slug de las categorías y etiquetas del blog no se traduce: `?tag=` y
		// `/blog/category/<slug>` son filtros, y el rótulo es lo que cambia.
		'category'   => array(
			'kind'       => 'term',
			'graphql'    => 'Category',
			'revalidate' => 'posts',
			'fields'     => array(
				'name'        => $name,
				'description' => array( 'label' => __( 'Descripción', 'hwe' ), 'input' => 'textarea' ),
			),
		),
		'post_tag'   => array(
			'kind'       => 'term',
			'graphql'    => 'Tag',
			'revalidate' => 'posts',
			'fields'     => array(
				'name' => $name,
			),
		),
	);
}

/**
 * Registro de campos traducibles por tipo de contenido (tipo de post o taxonomía), ya
 * validado: lo declarado con una forma incorrecta se descarta y se deja en el log.
 *
 * @return array<string,array{kind:string,graphql:?string,revalidate:?string,fields:array<string,array{label:string,input:string,max:int,search:bool}>}>
 */
function hwe_i18n_registry(): array {
	$declared = apply_filters( 'hwe_translatable_fields', hwe_i18n_core_registry() );
	if ( ! is_array( $declared ) ) {
		hwe_i18n_log( 'registry:not-array', 'el filtro hwe_translatable_fields debe devolver un array (devolvió ' . get_debug_type( $declared ) . ').' );
		return array();
	}

	$registry = array();
	foreach ( $declared as $type => $def ) {
		if ( ! is_string( $type ) || 1 !== preg_match( '/^[a-z0-9_-]{1,32}$/', $type ) || ! is_array( $def ) ) {
			hwe_i18n_log( 'registry:type:' . ( is_scalar( $type ) ? (string) $type : '?' ), 'tipo de contenido descartado de hwe_translatable_fields: ' . ( is_scalar( $type ) ? '«' . $type . '»' : get_debug_type( $type ) ) . '.' );
			continue;
		}
		$kind = $def['kind'] ?? '';
		if ( ! in_array( $kind, array( 'post', 'term' ), true ) ) {
			hwe_i18n_log( 'registry:kind:' . $type, 'el tipo «' . $type . '» de hwe_translatable_fields necesita kind «post» o «term».' );
			continue;
		}

		$fields = array();
		foreach ( (array) ( $def['fields'] ?? array() ) as $name => $field ) {
			$input = is_array( $field ) ? ( $field['input'] ?? '' ) : '';
			if ( ! is_string( $name ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{0,39}$/', $name ) || ! in_array( $input, HWE_I18N_INPUTS, true ) ) {
				hwe_i18n_log(
					'registry:field:' . $type . ':' . ( is_scalar( $name ) ? (string) $name : '?' ),
					'campo descartado del tipo «' . $type . '»: «' . ( is_scalar( $name ) ? $name : get_debug_type( $name ) ) . '» (nombre en minúsculas con guion bajo y entrada text, textarea, html o slug).'
				);
				continue;
			}
			// El slug solo tiene sentido con la entrada `slug`, y esa solo para el campo `slug`.
			if ( ( 'slug' === $input ) !== ( 'slug' === $name ) ) {
				hwe_i18n_log( 'registry:slug:' . $type . ':' . $name, 'campo descartado del tipo «' . $type . '»: la entrada «slug» y el campo «slug» van juntos.' );
				continue;
			}
			$fields[ $name ] = array(
				'label'  => is_string( $field['label'] ?? null ) && '' !== $field['label'] ? $field['label'] : $name,
				'input'  => $input,
				'max'    => isset( $field['max'] ) ? max( 0, (int) $field['max'] ) : 0,
				'search' => ! empty( $field['search'] ),
			);
		}

		$graphql    = $def['graphql'] ?? null;
		$revalidate = $def['revalidate'] ?? null;
		$registry[ $type ] = array(
			'kind'       => $kind,
			'graphql'    => is_string( $graphql ) && 1 === preg_match( '/^[A-Za-z][A-Za-z0-9]*$/', $graphql ) ? $graphql : null,
			'revalidate' => is_string( $revalidate ) && '' !== $revalidate ? $revalidate : null,
			'fields'     => $fields,
		);
	}
	return $registry;
}

/**
 * Campos traducibles de un tipo de contenido, o `array()` si no está registrado.
 *
 * @return array<string,array{label:string,input:string,max:int,search:bool}>
 */
function hwe_i18n_fields_for( string $type ): array {
	$registry = hwe_i18n_registry();
	return $registry[ $type ]['fields'] ?? array();
}

/** Meta de la traducción de un campo a un idioma (`_hwe_title_en`). */
function hwe_i18n_meta_key( string $field, string $suffix ): string {
	return '_hwe_' . $field . '_' . $suffix;
}

/** `seo_title` → `seoTitle`: nombre del campo en GraphQL. */
function hwe_i18n_graphql_field( string $field ): string {
	return lcfirst( str_replace( ' ', '', ucwords( str_replace( '_', ' ', $field ) ) ) );
}

/**
 * Registra un aviso de configuración una sola vez por petición (registro de WordPress con
 * el prefijo `[hwe]`).
 */
function hwe_i18n_log( string $key, string $message ): void {
	if ( isset( $GLOBALS['hwe_i18n_logged'][ $key ] ) ) {
		return;
	}
	$GLOBALS['hwe_i18n_logged'][ $key ] = true;
	error_log( '[hwe] content-i18n: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}

/**
 * Declara el tag `products`: guardar la traducción de una categoría de producto (nombre,
 * descripción, slug) no dispara el webhook de WooCommerce, así que se revalida desde aquí.
 *
 * @param mixed $tags Tags que ya acepta el núcleo.
 * @return list<string>
 */
function hwe_i18n_revalidation_tags( $tags ): array {
	$tags = is_array( $tags ) ? array_values( $tags ) : array();
	if ( ! in_array( 'products', $tags, true ) ) {
		$tags[] = 'products';
	}
	return $tags;
}
add_filter( 'hwe_revalidation_tags', 'hwe_i18n_revalidation_tags' );
