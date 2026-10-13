<?php
/**
 * Registro de campos de producto declarativos (`hwe_product_fields`).
 *
 * Forma de una entrada (clave → datos):
 *
 *   label         etiqueta del campo en la ficha (obligatoria)
 *   type          text | textarea | richtext | url | number | boolean | select | color | image
 *   translatable  bool: solo text, textarea y richtext; se edita en «Traducciones»
 *   box           título de la caja de la ficha donde va (por defecto «Datos del producto»)
 *   description   ayuda bajo el control (opcional)
 *   options       valor → etiqueta, obligatoria con `select`
 *   max           longitud máxima (text y textarea; opcional)
 *
 * Una entrada que no cumple se descarta y se registra (una vez por petición): un dato mal
 * declarado no rompe la ficha.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const HWE_PRODUCT_FIELD_TYPES        = array( 'text', 'textarea', 'richtext', 'url', 'number', 'boolean', 'select', 'color', 'image' );
const HWE_PRODUCT_FIELD_TRANSLATABLE = array( 'text', 'textarea', 'richtext' );
const HWE_PRODUCT_FIELD_DEFAULT_BOX  = 'Datos del producto';

/**
 * Nombres que el núcleo ya usa para el producto: traducibles propios y el espacio de
 * `hwe-product-landing` (`_hwe_landing_*`). Declararlos pisaría su meta.
 *
 * @return list<string>
 */
function hwe_product_fields_reserved_names(): array {
	return array( 'name', 'short_description', 'description', 'seo_title', 'seo_description', 'slug' );
}

/** Aviso de configuración, una sola vez por petición y clave. */
function hwe_product_fields_log( string $key, string $message ): void {
	if ( isset( $GLOBALS['hwe_product_fields_logged'][ $key ] ) ) {
		return;
	}
	$GLOBALS['hwe_product_fields_logged'][ $key ] = true;
	error_log( '[hwe] product-fields: ' . $message );
}

/** Meta del valor de un campo en el idioma por defecto. */
function hwe_product_field_meta_key( string $key ): string {
	return '_hwe_' . $key;
}

/**
 * Campos declarados y válidos, por clave. El valor del filtro se valida una vez por petición.
 *
 * @return array<string,array{label:string,type:string,translatable:bool,box:string,description:string,options:array<string,string>,max:int}>
 */
function hwe_product_fields(): array {
	if ( isset( $GLOBALS['hwe_product_fields_cache'] ) ) {
		return $GLOBALS['hwe_product_fields_cache'];
	}

	$declared = apply_filters( 'hwe_product_fields', array() );
	if ( ! is_array( $declared ) ) {
		hwe_product_fields_log( 'not-array', 'el filtro hwe_product_fields debe devolver un mapa clave → datos (devolvió ' . get_debug_type( $declared ) . ').' );
		return $GLOBALS['hwe_product_fields_cache'] = array();
	}

	$fields = array();
	foreach ( $declared as $key => $def ) {
		$name = is_string( $key ) ? $key : '';
		$why  = '';
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{0,39}$/', $name ) ) {
			$why = 'la clave debe estar en minúsculas, con guion bajo (hasta 40 caracteres)';
		} elseif ( in_array( $name, hwe_product_fields_reserved_names(), true ) || str_starts_with( $name, 'landing_' ) ) {
			$why = 'la clave está reservada por el núcleo';
		} elseif ( ! is_array( $def ) ) {
			$why = 'los datos deben ser una lista';
		}
		$label = is_array( $def ) && isset( $def['label'] ) && is_string( $def['label'] ) ? trim( $def['label'] ) : '';
		$type  = is_array( $def ) && isset( $def['type'] ) && is_string( $def['type'] ) ? $def['type'] : 'text';
		if ( '' === $why && '' === $label ) {
			$why = 'falta la etiqueta';
		}
		if ( '' === $why && ! in_array( $type, HWE_PRODUCT_FIELD_TYPES, true ) ) {
			$why = 'el tipo «' . $type . '» no existe (' . implode( ', ', HWE_PRODUCT_FIELD_TYPES ) . ')';
		}
		$options = array();
		if ( '' === $why && 'select' === $type ) {
			foreach ( (array) ( $def['options'] ?? array() ) as $value => $text ) {
				if ( ( is_string( $value ) || is_int( $value ) ) && '' !== (string) $value && is_string( $text ) ) {
					$options[ (string) $value ] = $text;
				}
			}
			if ( array() === $options ) {
				$why = 'el tipo «select» necesita `options`';
			}
		}
		if ( '' !== $why ) {
			hwe_product_fields_log( 'field:' . $name, 'campo «' . $name . '» descartado de hwe_product_fields: ' . $why . '.' );
			continue;
		}

		$translatable = ! empty( $def['translatable'] );
		if ( $translatable && ! in_array( $type, HWE_PRODUCT_FIELD_TRANSLATABLE, true ) ) {
			hwe_product_fields_log( 'translatable:' . $name, '«translatable» ignorado en «' . $name . '»: solo admiten traducción los tipos ' . implode( ', ', HWE_PRODUCT_FIELD_TRANSLATABLE ) . '.' );
			$translatable = false;
		}
		$box = isset( $def['box'] ) && is_string( $def['box'] ) && '' !== trim( $def['box'] ) ? trim( $def['box'] ) : HWE_PRODUCT_FIELD_DEFAULT_BOX;

		$fields[ $name ] = array(
			'label'        => $label,
			'type'         => $type,
			'translatable' => $translatable,
			'box'          => $box,
			'description'  => isset( $def['description'] ) && is_string( $def['description'] ) ? $def['description'] : '',
			'options'      => $options,
			'max'          => isset( $def['max'] ) ? max( 0, (int) $def['max'] ) : 0,
		);
	}

	// Una clave que coincidiera con la traducción de otra (`nota` y `nota_en`) pisaría su meta.
	if ( function_exists( 'hwe_i18n_locales' ) ) {
		foreach ( array_keys( $fields ) as $name ) {
			if ( ! isset( $fields[ $name ] ) || ! $fields[ $name ]['translatable'] ) {
				continue;
			}
			foreach ( hwe_i18n_locales() as $suffix ) {
				$clash = $name . '_' . $suffix;
				if ( isset( $fields[ $clash ] ) ) {
					hwe_product_fields_log( 'clash:' . $clash, 'campo «' . $clash . '» descartado: coincide con la traducción a «' . $suffix . '» de «' . $name . '».' );
					unset( $fields[ $clash ] );
				}
			}
		}
	}

	return $GLOBALS['hwe_product_fields_cache'] = $fields;
}

/** Olvida el registro validado (los tests declaran campos distintos en cada caso). */
function hwe_product_fields_reset(): void {
	unset( $GLOBALS['hwe_product_fields_cache'], $GLOBALS['hwe_product_fields_logged'] );
}

/**
 * Los campos traducibles, en la forma del registro de `hwe-content-i18n`: así la caja
 * «Traducciones» de la ficha y `Product.translation(locale)` los incluyen sin más.
 *
 * @param mixed $registry
 * @return mixed
 */
function hwe_product_fields_register_translatable( $registry ) {
	if ( ! is_array( $registry ) || ! isset( $registry['product']['fields'] ) || ! is_array( $registry['product']['fields'] ) ) {
		return $registry;
	}
	foreach ( hwe_product_fields() as $key => $field ) {
		if ( ! $field['translatable'] || isset( $registry['product']['fields'][ $key ] ) ) {
			continue;
		}
		$registry['product']['fields'][ $key ] = array(
			'label' => $field['label'],
			'input' => 'richtext' === $field['type'] ? 'html' : ( 'textarea' === $field['type'] ? 'textarea' : 'text' ),
			'max'   => $field['max'],
		);
	}
	return $registry;
}
add_filter( 'hwe_translatable_fields', 'hwe_product_fields_register_translatable' );

/**
 * Valor ya saneado de un campo según su tipo (cadena vacía = sin valor).
 *
 * @param array{type:string,options:array<string,string>,max:int} $field
 * @param mixed                                                   $raw
 */
function hwe_product_field_sanitize( array $field, $raw ): string {
	$value = is_scalar( $raw ) ? (string) $raw : '';
	switch ( $field['type'] ) {
		case 'boolean':
			$value = '1' === $value ? '1' : '';
			break;
		case 'number':
			$value = is_numeric( trim( $value ) ) ? trim( $value ) : '';
			break;
		case 'select':
			$value = array_key_exists( $value, $field['options'] ) ? $value : '';
			break;
		case 'color':
			$value = 1 === preg_match( '/^#[0-9a-fA-F]{6}$/', trim( $value ) ) ? strtolower( trim( $value ) ) : '';
			break;
		case 'url':
		case 'image':
			$value = esc_url_raw( trim( $value ) );
			break;
		case 'richtext':
			$value = wp_kses_post( $value );
			break;
		case 'textarea':
			$value = sanitize_textarea_field( $value );
			break;
		default:
			$value = sanitize_text_field( $value );
	}
	if ( $field['max'] > 0 && in_array( $field['type'], array( 'text', 'textarea' ), true ) ) {
		$value = mb_substr( $value, 0, $field['max'] );
	}
	return $value;
}
