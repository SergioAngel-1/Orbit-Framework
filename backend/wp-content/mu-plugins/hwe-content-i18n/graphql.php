<?php
/**
 * WPGraphQL de `hwe-content-i18n`.
 *
 * - `translation(locale: String!)` en cada tipo afectado, con un objeto `Hwe<Tipo>Translation`
 *   cuyos campos son los traducibles del registro (nulos si no hay traducción). Devuelve
 *   null para un idioma que no es uno de los secundarios del sitio.
 * - `hweSlugFrom(postType, locale, slug)`: slug nativo de la ficha PUBLICADA que tiene ese
 *   slug traducido. Es la búsqueda inversa de las URLs `/<idioma>/…/<slug>`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Id de un nodo de WPGraphQL (post o término), 0 si no se reconoce. */
function hwe_i18n_node_id( $node, string $kind ): int {
	if ( ! is_object( $node ) ) {
		return 0;
	}
	$keys = 'term' === $kind ? array( 'term_id', 'databaseId' ) : array( 'databaseId', 'ID' );
	foreach ( $keys as $key ) {
		if ( isset( $node->$key ) && (int) $node->$key > 0 ) {
			return (int) $node->$key;
		}
	}
	return 0;
}

/** Registra `translation` en cada tipo y la búsqueda inversa del slug. */
function hwe_i18n_register_graphql(): void {
	if ( ! function_exists( 'register_graphql_field' ) || ! function_exists( 'register_graphql_object_type' ) ) {
		return;
	}
	if ( array() === hwe_i18n_locales() ) {
		return;
	}

	foreach ( hwe_i18n_registry() as $type => $def ) {
		if ( null === $def['graphql'] || array() === $def['fields'] ) {
			continue;
		}
		$object = 'Hwe' . $def['graphql'] . 'Translation';
		$shape  = array();
		foreach ( $def['fields'] as $name => $spec ) {
			$shape[ hwe_i18n_graphql_field( $name ) ] = array(
				'type'        => 'String',
				'description' => sprintf(
					/* translators: %s: nombre del campo */
					__( '%s en este idioma. Null = el sitio usa el contenido del idioma por defecto.', 'hwe' ),
					$spec['label']
				),
			);
		}
		register_graphql_object_type(
			$object,
			array(
				'description' => __( 'Traducción del contenido a un idioma secundario del sitio.', 'hwe' ),
				'fields'      => $shape,
			)
		);

		$kind = $def['kind'];
		register_graphql_field(
			$def['graphql'],
			'translation',
			array(
				'type'        => $object,
				'description' => __( 'Traducción a un idioma secundario del sitio (`i18n.locales`). Null si el idioma no es uno de ellos.', 'hwe' ),
				'args'        => array(
					'locale' => array(
						'type'        => array( 'non_null' => 'String' ),
						'description' => __( 'Código del idioma, p. ej. «en» o «pt-BR».', 'hwe' ),
					),
				),
				'resolve'     => static function ( $node, array $args ) use ( $type, $kind ): ?array {
					$suffix = hwe_i18n_locale_suffix( (string) ( $args['locale'] ?? '' ) );
					if ( '' === $suffix || ! in_array( $suffix, hwe_i18n_locales(), true ) ) {
						return null;
					}
					$id = hwe_i18n_node_id( $node, $kind );
					if ( $id <= 0 ) {
						return null;
					}
					$out = array();
					foreach ( hwe_i18n_fields_for( $type ) as $name => $spec ) {
						$value = hwe_i18n_get( $kind, $id, $name, $suffix );
						$out[ hwe_i18n_graphql_field( $name ) ] = '' !== $value ? $value : null;
					}
					return $out;
				},
			)
		);
	}

	register_graphql_field(
		'RootQuery',
		'hweSlugFrom',
		array(
			'type'        => 'String',
			'description' => __( 'Slug nativo de la ficha PUBLICADA cuyo slug en `locale` es `slug`. Null si ninguna.', 'hwe' ),
			'args'        => array(
				'postType' => array( 'type' => array( 'non_null' => 'String' ) ),
				'locale'   => array( 'type' => array( 'non_null' => 'String' ) ),
				'slug'     => array( 'type' => array( 'non_null' => 'String' ) ),
			),
			'resolve'     => static function ( $root, array $args ): ?string {
				$post_type = (string) ( $args['postType'] ?? '' );
				$registry  = hwe_i18n_registry();
				$def       = $registry[ $post_type ] ?? null;
				if ( null === $def || 'post' !== $def['kind'] || ! isset( $def['fields']['slug'] ) ) {
					return null;
				}
				$suffix = hwe_i18n_locale_suffix( (string) ( $args['locale'] ?? '' ) );
				if ( '' === $suffix || ! in_array( $suffix, hwe_i18n_locales(), true ) ) {
					return null;
				}
				$slug = sanitize_title( (string) ( $args['slug'] ?? '' ) );
				if ( '' === $slug ) {
					return null;
				}
				global $wpdb;
				$native = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT p.post_name FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value = %s AND p.post_type = %s AND p.post_status = 'publish' LIMIT 1",
						hwe_i18n_meta_key( 'slug', $suffix ),
						$slug,
						$post_type
					)
				);
				return is_string( $native ) && '' !== $native ? $native : null;
			},
		)
	);
}
add_action( 'graphql_register_types', 'hwe_i18n_register_graphql' );
