<?php
namespace HWE\ProductLanding;

/**
 * Expone la landing del producto en WPGraphQL como `Product.landing`.
 *
 * Los campos escalares se derivan de `Fields::SCALAR_FIELDS` (clave snake_case → campo camelCase),
 * siempre en el idioma por defecto. Cada tipo con campos traducibles lleva además
 * `translation(locale: String!)`, con el mismo patrón que `hwe-content-i18n`: el frontend lo pide
 * con el marcador `# @translation <Tipo>: campos` y lo recibe aplanado (`hero_subtitle_en`).
 * Las filas de los tres repeaters (`components`, `qualities`, `adviceItems`) se leen de la meta
 * con las traducciones anidadas en `i18n.<idioma>`.
 *
 * `Product.landing` es null cuando `_hwe_landing_published !== '1'`.
 */
final class Graphql {

    /** Tipo GraphQL de cada repeater. */
    private const ROW_TYPES = [
        'components' => 'ProductLandingComponent',
        'qualities'  => 'ProductLandingQuality',
        'advice'     => 'ProductLandingAdvice',
    ];

    public static function register(): void {
        add_action( 'graphql_register_types', [ self::class, 'registerTypes' ] );
    }

    public static function registerTypes(): void {
        if ( ! function_exists( 'register_graphql_object_type' ) ) {
            return;
        }

        foreach ( self::ROW_TYPES as $repeater => $type ) {
            self::registerRowType( $repeater, $type );
        }
        self::registerLandingType();
        self::registerProductField();
        self::registerPublishedSlugsField();
    }

    public static function camel( string $snake ): string {
        return lcfirst( str_replace( ' ', '', ucwords( str_replace( '_', ' ', $snake ) ) ) );
    }

    /** Campos de un tipo de fila (todos String) y, si hay traducibles, el tipo de su traducción. */
    private static function registerRowType( string $repeater, string $type ): void {
        $fields      = [];
        $translated  = [];
        foreach ( Fields::REPEATERS[ $repeater ]['row_fields'] as $name => $spec ) {
            $fields[ self::camel( $name ) ] = [ 'type' => 'String' ];
            if ( ! empty( $spec['translatable'] ) ) {
                $translated[ self::camel( $name ) ] = [ 'type' => 'String' ];
            }
        }
        if ( $translated !== [] ) {
            register_graphql_object_type( $type . 'Translation', [
                'description' => 'Traducción de una fila de la landing. Null = se usa el idioma por defecto.',
                'fields'      => $translated,
            ] );
            $fields['translation'] = [
                'type'        => $type . 'Translation',
                'description' => 'La fila en un idioma secundario del sitio (null si el idioma no es uno de ellos).',
                'args'        => [ 'locale' => [ 'type' => [ 'non_null' => 'String' ] ] ],
                'resolve'     => static function ( $row, array $args ) use ( $repeater ): ?array {
                    return self::rowTranslation( $repeater, is_array( $row ) ? $row : [], (string) ( $args['locale'] ?? '' ) );
                },
            ];
        }
        register_graphql_object_type( $type, [
            'description' => 'Fila de una lista de la landing de producto.',
            'fields'      => $fields,
        ] );
    }

    /**
     * Traducción de una fila a un idioma, o null si no es un idioma secundario del sitio. Los
     * campos sin traducción valen null.
     *
     * @param array<string,mixed> $row
     * @return array<string,?string>|null
     */
    public static function rowTranslation( string $repeater, array $row, string $locale ): ?array {
        $suffix = self::suffix( $locale );
        if ( $suffix === null ) {
            return null;
        }
        $out = [];
        foreach ( Fields::translatableRowFields()[ $repeater ] ?? [] as $field ) {
            $value                          = (string) ( $row['_i18n'][ $suffix ][ $field ] ?? '' );
            $out[ self::camel( $field ) ]   = $value !== '' ? $value : null;
        }
        return $out;
    }

    /** Sufijo normalizado del idioma si es uno de los secundarios del sitio. */
    private static function suffix( string $locale ): ?string {
        $suffix = str_replace( '-', '_', strtolower( trim( $locale ) ) );
        return in_array( $suffix, Translations::locales(), true ) ? $suffix : null;
    }

    private static function registerLandingType(): void {
        $fields     = [];
        $translated = [];
        foreach ( Fields::SCALAR_FIELDS as $key => $spec ) {
            $fields[ self::camel( $key ) ] = [ 'type' => $spec['type'] === 'boolean' ? 'Boolean' : 'String' ];
            if ( ! empty( $spec['translatable'] ) ) {
                $translated[ self::camel( $key ) ] = [ 'type' => 'String' ];
            }
        }
        $fields['components']  = [ 'type' => [ 'list_of' => [ 'non_null' => 'ProductLandingComponent' ] ] ];
        $fields['qualities']   = [ 'type' => [ 'list_of' => [ 'non_null' => 'ProductLandingQuality' ] ] ];
        $fields['adviceItems'] = [ 'type' => [ 'list_of' => [ 'non_null' => 'ProductLandingAdvice' ] ] ];

        register_graphql_object_type( 'ProductLandingTranslation', [
            'description' => 'Traducción de los textos de la landing a un idioma secundario del sitio. Null = se usa el idioma por defecto.',
            'fields'      => $translated,
        ] );
        $fields['translation'] = [
            'type'        => 'ProductLandingTranslation',
            'description' => 'Los textos de la landing en un idioma secundario del sitio (null si el idioma no es uno de ellos).',
            'args'        => [ 'locale' => [ 'type' => [ 'non_null' => 'String' ] ] ],
            'resolve'     => static function ( $landing, array $args ): ?array {
                $id = is_array( $landing ) ? (int) ( $landing['_id'] ?? 0 ) : 0;
                return $id > 0 ? self::scalarTranslation( $id, (string) ( $args['locale'] ?? '' ) ) : null;
            },
        ];

        register_graphql_object_type( 'ProductLanding', [
            'description' => 'Landing de un producto. Null si no está publicada.',
            'fields'      => $fields,
        ] );
    }

    /**
     * Traducción de los textos de la landing de un producto a un idioma.
     *
     * @return array<string,?string>|null
     */
    public static function scalarTranslation( int $productId, string $locale ): ?array {
        $suffix = self::suffix( $locale );
        if ( $suffix === null ) {
            return null;
        }
        $out = [];
        foreach ( Fields::translatableScalars() as $key ) {
            $value                  = (string) get_post_meta( $productId, Fields::metaKey( $key ) . '_' . $suffix, true );
            $out[ self::camel( $key ) ] = $value !== '' ? $value : null;
        }
        return $out;
    }

    private static function registerProductField(): void {
        register_graphql_field( 'Product', 'landing', [
            'type'        => 'ProductLanding',
            'description' => 'Landing del producto (null si no está publicada).',
            'resolve'     => static function ( $product ): ?array {
                $id = is_object( $product ) ? (int) ( $product->databaseId ?? $product->ID ?? 0 ) : 0;
                return $id > 0 ? self::landing( $id ) : null;
            },
        ] );
    }

    /**
     * La landing de un producto en el idioma por defecto, o null si no está publicada.
     *
     * @return array<string,mixed>|null
     */
    public static function landing( int $id ): ?array {
        if ( get_post_meta( $id, '_hwe_landing_published', true ) !== '1' ) {
            return null;
        }
        $out = [ '_id' => $id ];
        foreach ( Fields::SCALAR_FIELDS as $key => $spec ) {
            $val = (string) get_post_meta( $id, Fields::metaKey( $key ), true );
            if ( $spec['type'] === 'boolean' ) {
                // Meta nunca guardado ('') → el default del spec, no `false`: una landing
                // publicada desde el multiselect del Control Center no ha pasado por el
                // metabox, y sus secciones deben reportar los defaults del molde
                // (Educativa/Cualidades/Consejos on, Video off).
                $out[ self::camel( $key ) ] = $val === '' ? ! empty( $spec['default'] ) : ( $val === '1' );
            } else {
                $out[ self::camel( $key ) ] = $val !== '' ? $val : null;
            }
        }
        foreach ( self::ROW_TYPES as $repeater => $_type ) {
            $name         = $repeater === 'advice' ? 'adviceItems' : $repeater;
            $out[ $name ] = self::rows( $id, $repeater );
        }
        return $out;
    }

    /**
     * `RootQuery.hweProductLandingsPublished`: los slugs de producto con landing publicada. Para
     * prerender / sitemap del frontend sin pedir `Product.landing` entera producto a producto
     * (N+1) solo para leer el booleano `published`.
     */
    private static function registerPublishedSlugsField(): void {
        register_graphql_field( 'RootQuery', 'hweProductLandingsPublished', [
            'type'        => [ 'list_of' => 'String' ],
            'description' => 'Slugs de los productos con landing publicada (_hwe_landing_published = "1").',
            'resolve'     => static function (): array {
                // Sin `fields => 'ids'`: WP_Query con solo IDs NO ceba el caché de posts, así que
                // un `get_post_field('post_name', $id)` por fila reintroduce el N+1 que este campo
                // existe para evitar. Pidiendo los WP_Post hidratados, el slug sale del objeto
                // sin consulta extra (y no leemos meta → cache de meta desactivada).
                $posts = get_posts( [
                    'post_type'              => 'product',
                    'post_status'            => 'publish',
                    'posts_per_page'         => -1,
                    'meta_key'               => '_hwe_landing_published',
                    'meta_value'             => '1',
                    'orderby'                => 'ID',
                    'order'                  => 'ASC',
                    'no_found_rows'          => true,
                    'suppress_filters'       => true,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                ] );
                $slugs = array_map(
                    static fn ( \WP_Post $p ) => (string) $p->post_name,
                    $posts
                );
                return array_values( array_filter( $slugs, static fn ( $s ) => $s !== '' ) );
            },
        ] );
    }

    /**
     * Filas de un repeater como filas planas camelCase en el idioma por defecto. La traducción
     * anidada (`i18n.<idioma>`) viaja en `_i18n` para el resolvedor `translation`.
     *
     * @return list<array<string,mixed>>
     */
    private static function rows( int $id, string $repeater ): array {
        $spec = Fields::REPEATERS[ $repeater ];
        $raw  = get_post_meta( $id, $spec['meta_key'], true );
        if ( ! is_array( $raw ) ) {
            return [];
        }
        $out = [];
        foreach ( $raw as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $flat = [ '_i18n' => is_array( $row['i18n'] ?? null ) ? $row['i18n'] : [] ];
            foreach ( array_keys( $spec['row_fields'] ) as $f ) {
                $flat[ self::camel( $f ) ] = isset( $row[ $f ] ) && $row[ $f ] !== '' ? (string) $row[ $f ] : null;
            }
            $out[] = $flat;
        }
        return $out;
    }
}
