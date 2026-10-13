<?php
/**
 * Admin de `hwe-content-i18n`: la caja «Traducciones» de la ficha (una pestaña por idioma
 * secundario) y los campos por idioma del editor de términos.
 *
 * Con un solo idioma no se añade nada. Los campos de cada pestaña salen del registro
 * (`hwe_translatable_fields`): lo que una instancia declare para su tipo aparece solo.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Nombre legible de un idioma para la pestaña (código en mayúsculas si no se conoce). */
function hwe_i18n_locale_label( string $suffix ): string {
	return strtoupper( str_replace( '_', '-', $suffix ) );
}

/** Añade la caja «Traducciones» a cada tipo de post con campos traducibles. */
function hwe_i18n_register_boxes(): void {
	if ( array() === hwe_i18n_locales() ) {
		return;
	}
	foreach ( hwe_i18n_registry() as $type => $def ) {
		if ( 'post' !== $def['kind'] || array() === $def['fields'] ) {
			continue;
		}
		add_meta_box( 'hwe_i18n', __( 'Traducciones', 'hwe' ), 'hwe_i18n_render_post_box', $type, 'normal', 'default' );
	}
}
add_action( 'add_meta_boxes', 'hwe_i18n_register_boxes' );

/**
 * HTML de un campo de una pestaña.
 *
 * @param array{label:string,input:string,max:int,search:bool} $spec
 */
function hwe_i18n_render_field( string $kind, string $suffix, string $name, array $spec, string $value, string $placeholder ): string {
	$input_name = 'hwe_i18n[' . $suffix . '][' . $name . ']';
	$id         = 'hwe_i18n_' . $suffix . '_' . $name;
	$label      = '<label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $spec['label'] ) . '</strong></label>';
	$max        = $spec['max'] > 0 ? ' maxlength="' . (int) $spec['max'] . '"' : '';

	switch ( $spec['input'] ) {
		case 'textarea':
			return '<p>' . $label . '<br><textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $input_name ) . '" rows="3" style="width:100%"' . $max . '>' . esc_textarea( $value ) . '</textarea></p>';
		case 'html':
			ob_start();
			echo '<p>' . $label . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escapado arriba.
			wp_editor(
				$value,
				$id,
				array(
					'textarea_name' => $input_name,
					'textarea_rows' => 12,
					'media_buttons' => false,
				)
			);
			return (string) ob_get_clean();
		case 'slug':
			return '<p>' . $label . '<br><input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $input_name ) . '" style="width:100%" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '"></p>'
				. '<p class="description">' . esc_html__( 'Vacío = la URL usa el mismo slug que el idioma por defecto. Cambiarlo cambia la URL pública: la anterior redirige a la nueva.', 'hwe' ) . '</p>';
		default:
			return '<p>' . $label . '<br><input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $input_name ) . '" style="width:100%" value="' . esc_attr( $value ) . '"' . $max . '></p>';
	}
}

/** Caja «Traducciones» de una ficha: una pestaña por idioma secundario. */
function hwe_i18n_render_post_box( \WP_Post $post ): void {
	$fields  = hwe_i18n_fields_for( $post->post_type );
	$locales = hwe_i18n_locales();
	if ( array() === $fields || array() === $locales ) {
		return;
	}

	echo wp_nonce_field( 'hwe_i18n_save_post', 'hwe_i18n_nonce', true, false ); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML de WordPress.

	$errors = get_transient( 'hwe_i18n_error_' . $post->ID . '_' . get_current_user_id() );
	if ( is_array( $errors ) && array() !== $errors ) {
		delete_transient( 'hwe_i18n_error_' . $post->ID . '_' . get_current_user_id() );
		echo '<div style="color:#b32d2e"><strong>' . esc_html__( 'No se guardó:', 'hwe' ) . '</strong><ul>';
		foreach ( $errors as $error ) {
			echo '<li>' . esc_html( (string) $error ) . '</li>';
		}
		echo '</ul></div>';
	}

	echo '<div class="hwe-i18n" data-hwe-i18n><div role="tablist" aria-label="' . esc_attr__( 'Idiomas', 'hwe' ) . '" style="margin-bottom:12px">';
	foreach ( $locales as $i => $suffix ) {
		echo '<button type="button" role="tab" class="button' . ( 0 === $i ? ' button-primary' : '' ) . '" id="hwe_i18n_tab_' . esc_attr( $suffix ) . '" aria-controls="hwe_i18n_panel_' . esc_attr( $suffix ) . '" aria-selected="' . ( 0 === $i ? 'true' : 'false' ) . '" style="margin-right:4px">' . esc_html( hwe_i18n_locale_label( $suffix ) ) . '</button>';
	}
	echo '</div>';

	foreach ( $locales as $suffix ) {
		echo '<div role="tabpanel" id="hwe_i18n_panel_' . esc_attr( $suffix ) . '" aria-labelledby="hwe_i18n_tab_' . esc_attr( $suffix ) . '">';
		foreach ( $fields as $name => $spec ) {
			echo hwe_i18n_render_field( 'post', $suffix, $name, $spec, hwe_i18n_get( 'post', $post->ID, $name, $suffix ), $post->post_name ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado en la función.
		}
		echo '</div>';
	}
	echo '<p class="description">' . esc_html__( 'Todos opcionales. Vacío = esa versión del sitio muestra el contenido del idioma por defecto.', 'hwe' ) . '</p></div>';
	echo hwe_i18n_tabs_script(); // phpcs:ignore WordPress.Security.EscapeOutput -- script fijo.
}

/**
 * Pestañas: sin JavaScript todos los paneles quedan visibles; con él se muestra uno.
 */
function hwe_i18n_tabs_script(): string {
	static $printed = false;
	if ( $printed ) {
		return '';
	}
	$printed = true;
	return '<script>(function(){function wire(root){var tabs=[].slice.call(root.querySelectorAll("[role=tab]"));function show(t){tabs.forEach(function(x){var on=x===t;x.setAttribute("aria-selected",on?"true":"false");x.classList.toggle("button-primary",on);var p=document.getElementById(x.getAttribute("aria-controls"));if(p){p.hidden=!on;}});}tabs.forEach(function(t){t.addEventListener("click",function(){show(t);});});if(tabs.length){show(tabs[0]);}}function init(){document.querySelectorAll("[data-hwe-i18n]").forEach(wire);}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",init);}else{init();}})();</script>';
}

/** Guarda las traducciones de la ficha. */
add_action(
	'save_post',
	static function ( int $post_id, $post ): void {
		if ( $post instanceof \WP_Post ) {
			hwe_i18n_save_post( $post_id, $post );
		}
	},
	10,
	2
);

/**
 * Campos por idioma en los formularios de «añadir» y «editar» de cada taxonomía con campos
 * traducibles. Se engancha en `init` (tarde): para entonces las taxonomías existen y las
 * instancias ya declararon las suyas.
 */
function hwe_i18n_register_term_hooks(): void {
	if ( array() === hwe_i18n_locales() ) {
		return;
	}
	foreach ( hwe_i18n_registry() as $taxonomy => $def ) {
		if ( 'term' !== $def['kind'] || array() === $def['fields'] ) {
			continue;
		}
		add_action(
			"{$taxonomy}_add_form_fields",
			static function () use ( $taxonomy ): void {
				echo hwe_i18n_render_term_fields( $taxonomy, 0, 'add' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado en la función.
			}
		);
		add_action(
			"{$taxonomy}_edit_form_fields",
			static function ( $term ) use ( $taxonomy ): void {
				if ( $term instanceof \WP_Term ) {
					echo hwe_i18n_render_term_fields( $taxonomy, $term->term_id, 'edit', $term->slug ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado en la función.
				}
			}
		);
		$save = static function ( int $term_id ) use ( $taxonomy ): void {
			hwe_i18n_save_term( $term_id, $taxonomy );
		};
		add_action( "created_{$taxonomy}", $save );
		add_action( "edited_{$taxonomy}", $save );
	}
}
add_action( 'init', 'hwe_i18n_register_term_hooks', 99 );

/**
 * HTML de los campos por idioma de un término.
 *
 * @param string $mode 'add' (div.form-field) o 'edit' (tr.form-field).
 */
function hwe_i18n_render_term_fields( string $taxonomy, int $term_id, string $mode, string $native_slug = '' ): string {
	$fields = hwe_i18n_fields_for( $taxonomy );
	$out    = wp_nonce_field( 'hwe_i18n_save_term', 'hwe_i18n_term_nonce', true, false );

	if ( $term_id > 0 ) {
		$errors = get_transient( 'hwe_i18n_term_error_' . $term_id . '_' . get_current_user_id() );
		if ( is_array( $errors ) && array() !== $errors ) {
			delete_transient( 'hwe_i18n_term_error_' . $term_id . '_' . get_current_user_id() );
			$items = '';
			foreach ( $errors as $error ) {
				$items .= '<li>' . esc_html( (string) $error ) . '</li>';
			}
			$notice = '<div style="color:#b32d2e"><strong>' . esc_html__( 'No se guardó:', 'hwe' ) . '</strong><ul>' . $items . '</ul></div>';
			$out   .= 'edit' === $mode ? '<tr class="form-field"><td colspan="2">' . $notice . '</td></tr>' : $notice;
		}
	}

	foreach ( hwe_i18n_locales() as $suffix ) {
		$heading = sprintf(
			/* translators: %s: código del idioma */
			__( 'Traducción (%s)', 'hwe' ),
			hwe_i18n_locale_label( $suffix )
		);
		$out .= 'edit' === $mode
			? '<tr class="form-field"><th colspan="2"><strong>' . esc_html( $heading ) . '</strong></th></tr>'
			: '<div class="form-field"><strong>' . esc_html( $heading ) . '</strong></div>';

		foreach ( $fields as $name => $spec ) {
			$value = $term_id > 0 ? hwe_i18n_get( 'term', $term_id, $name, $suffix ) : '';
			$id    = 'hwe_i18n_' . $suffix . '_' . $name;
			$label = '<label for="' . esc_attr( $id ) . '">' . esc_html( $spec['label'] ) . '</label>';
			$input_name = 'hwe_i18n[' . $suffix . '][' . $name . ']';
			$max   = $spec['max'] > 0 ? ' maxlength="' . (int) $spec['max'] . '"' : '';
			if ( 'html' === $spec['input'] || 'textarea' === $spec['input'] ) {
				$control = '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $input_name ) . '" rows="4" style="width:95%"' . $max . '>' . esc_textarea( $value ) . '</textarea>';
			} else {
				$placeholder = 'slug' === $spec['input'] ? ' placeholder="' . esc_attr( $native_slug ) . '"' : '';
				$control     = '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $input_name ) . '" style="width:95%" value="' . esc_attr( $value ) . '"' . $placeholder . $max . '>';
			}
			$out .= 'edit' === $mode
				? '<tr class="form-field"><th>' . $label . '</th><td>' . $control . '</td></tr>'
				: '<div class="form-field">' . $label . $control . '</div>';
		}
	}
	return $out;
}
