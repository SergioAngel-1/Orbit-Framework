<?php

namespace HWE\Landings;

use HWE\ControlCenter\Schema;
use HWE\ControlCenter\Storage;

/**
 * El esquema del Control Center del módulo: el registro de landings y, por cada una, su molde.
 *
 * Se inyecta en el grupo reservado `landings` del núcleo (no en `instance`: una clave raíz ajena
 * al núcleo se descarta). Cada landing cuelga de `landings.editorial.<id>`, con slots FIJOS:
 *
 *   hero (banner `landing-<id>`, tipo `landing`) · entradilla · secciones `s1`…`s5` ·
 *   cita · preguntas `q1`…`q6` · productos · cierre
 *
 * La IDENTIDAD de una sección es su slot (`s1`…`s5`), no su posición: `order` solo cambia dónde
 * se pinta, así que reordenar no huérfana nada. Todos los campos son `public` (alimentan el
 * frontend por `/wp-json/hwe/v1/config`) y los de texto llevan `translatable`: el esquema del
 * núcleo les añade un hermano `<campo>_<idioma>` por cada idioma secundario del sitio.
 */
final class ContentSchema {

	public const SECTION_COUNT = 5;
	public const FAQ_COUNT     = 6;

	/** Disposiciones de una sección de contenido. */
	public const LAYOUTS = array(
		'image-left'   => 'Imagen a la izquierda',
		'image-right'  => 'Imagen a la derecha',
		'full'         => 'Ancho completo',
		'text-only'    => 'Solo texto',
		'prose-blocks' => 'Bloques con subtítulos',
		'mirror-pair'  => 'Pareja en espejo',
		'divider'      => 'Separador pegado al banner',
	);

	/** Dónde puede colocarse el bloque de productos (después de qué slot). */
	public const PRODUCTS_AFTER = array(
		'lead'  => 'La entradilla',
		's1'    => 'La sección 1',
		's2'    => 'La sección 2',
		's3'    => 'La sección 3',
		's4'    => 'La sección 4',
		's5'    => 'La sección 5',
		'quote' => 'La cita',
		'faq'   => 'Las preguntas frecuentes',
	);

	private const SECTION_IMAGE_SIZES = 'Medida recomendada según la disposición: «Imagen a la izquierda» 1500×1200 px (5:4) · «Imagen a la derecha» 1200×1600 px (3:4) · «Ancho completo» 2400×1030 px (21:9, que en móvil se recorta a 3:2: deja el sujeto centrado). «Solo texto» y «Bloques con subtítulos» no pintan imagen. No hace falta una versión aparte para móvil.';

	/**
	 * Filtro `hwe_control_center_schema`: añade el registro y el contenido al grupo `landings`.
	 *
	 * @param mixed $schema
	 * @return mixed
	 */
	public static function inject( $schema ) {
		if ( ! is_array( $schema ) || ! isset( $schema['landings']['children'] ) || ! is_array( $schema['landings']['children'] ) ) {
			return $schema;
		}

		$landings = Registry::all();

		$schema['landings']['children']['registry'] = array(
			'type'        => 'landing_registry',
			'label'       => 'Registro de landings',
			'default'     => '',
			'public'      => true,
			'description' => 'Cada fila crea una landing con el molde genérico (hero, entradilla, hasta 5 secciones, cita, preguntas, productos y cierre). El identificador y el nombre quedan fijos tras crearla; el tipo y la URL pública se editan en el bloque de esa landing. Una landing con diseño propio no se crea desde aquí: la declara el desarrollo de la instancia.',
		);

		if ( array() === $landings ) {
			return $schema;
		}

		// Las listas largas se calculan UNA vez para todas las landings.
		$categories = Options::categories();
		$products   = array( '' => '— Ninguno —' ) + Options::products();

		$children = array();
		foreach ( $landings as $id => $meta ) {
			$children[ $id ] = array(
				'type'     => 'group',
				'label'    => $meta['label'] . ( $meta['dynamic'] ? '' : ' (diseño propio)' ),
				'children' => self::landing( (string) $id, $meta, $landings, $categories, $products ),
			);
		}

		$schema['landings']['children']['editorial'] = array(
			'type'     => 'group',
			'label'    => 'Contenido de las landings',
			'children' => $children,
		);

		return $schema;
	}

	/**
	 * Los campos de UNA landing.
	 *
	 * @param array{label:string,jsonld:string,base:string,dynamic:bool,registry_index?:int} $meta
	 * @param array<string,array<string,mixed>> $landings   Todas las landings (para «Página de esa persona»).
	 * @param array<string,string>              $categories
	 * @param array<string,string>              $products
	 * @return array<string,array<string,mixed>>
	 */
	private static function landing( string $id, array $meta, array $landings, array $categories, array $products ): array {
		$fields = array(
			'published' => array(
				'type'    => 'boolean',
				'label'   => 'Publicar',
				'public'  => true,
				'description' => 'Sin marcar, la landing no se muestra en la web aunque tenga contenido.',
			),
		);

		// El tipo de una landing del panel es la 3.ª columna de su fila del registro: este campo
		// escribe ahí, no en un valor propio (ver `RegistryTypeType`).
		if ( $meta['dynamic'] ) {
			$fields['jsonld_type'] = array(
				'type'           => 'registry_type',
				'label'          => 'Tipo de landing',
				'registry_index' => $meta['registry_index'] ?? 0,
				'current_value'  => $meta['jsonld'],
				'description'    => 'Elige «Artículo» para páginas de contenido y «Persona» para páginas de perfil (datos estructurados).',
			);
		}

		$fields['url']       = self::url( $id, $meta['base'] );
		$fields['hero_note'] = array(
			'type'         => 'link',
			'label'        => 'Hero',
			'url'          => admin_url( 'edit.php?post_type=hwe_banner' ),
			'button_label' => 'Editar el hero en Banners',
			'description'  => sprintf(
				'El hero de esta landing es un banner de tipo «Landing» en la locación «landing-%s». Créalo y edítalo en Banners.',
				$id
			),
		);
		$fields['accent']    = array(
			'type'        => 'color',
			'label'       => 'Color de apoyo',
			'default'     => '',
			'public'      => true,
			'description' => 'Sustituye al color de acento de la marca SOLO en esta landing. Vacío = el de la marca.',
		);

		$storedProduct          = (string) Storage::get( array( 'landings', 'editorial', $id, 'product' ), '' );
		$fields['product']      = array(
			'type'        => 'select',
			'label'       => 'Producto asociado a esta landing',
			'default'     => '',
			'public'      => true,
			'options'     => self::withStored( $products, array( $storedProduct ) ),
			'description' => 'Vacío = la landing no se asocia a un producto. Con un producto, la ficha enlaza a esta landing. Si ese producto ya tiene su propia landing de producto publicada, esa gana y ésta quedaría inalcanzable.',
		);
		$fields['product_cta_label'] = array(
			'type'         => 'text',
			'label'        => 'Texto del botón desde la ficha del producto',
			'default'      => '',
			'public'       => true,
			'translatable' => true,
			'description'  => 'Vacío = un texto genérico.',
		);

		$fields['seo']          = self::seo( $landings );
		$fields['lead_enabled'] = array( 'type' => 'boolean', 'label' => 'Mostrar entradilla', 'public' => true );
		$fields['lead_body']    = array(
			'type'         => 'richtext',
			'label'        => 'Entradilla',
			'default'      => '',
			'public'       => true,
			'translatable' => true,
		);
		$fields['sections']     = array(
			'type'     => 'group',
			'label'    => 'Secciones de contenido (hasta ' . self::SECTION_COUNT . ')',
			'children' => self::sections(),
		);
		$fields['quote']        = self::quote();
		$fields['faq']          = self::faq();
		$fields['products']     = self::productsBlock( $id, $categories );
		$fields['closing']      = self::closing();

		return $fields;
	}

	/**
	 * Grupo «URL pública». La clave del registro es un IDENTIFICADOR interno inmutable: si la URL
	 * se editara renombrándolo, cada cambio huérfanaría el contenido ya escrito. Por eso la URL
	 * vive en campos aparte, editables en cualquier momento.
	 *
	 * @return array<string,mixed>
	 */
	private static function url( string $id, string $defaultBase ): array {
		$path = (string) Storage::get( array( 'landings', 'editorial', $id, 'url', 'path' ), '' );
		if ( '' === $path ) {
			$path = $id;
		}

		$children = array(
			'base' => array(
				'type'        => 'select',
				'label'       => 'Ubicación de la URL',
				'default'     => $defaultBase,
				'public'      => true,
				'options'     => array(
					'area' => 'En el área educativa  →  /learn/' . $path,
					'root' => 'En la raíz del sitio  →  /' . $path,
				),
				'description' => 'Dónde cuelga la página. «Raíz» la deja sin página padre. Se sirve directamente en esa URL: la otra pasa a devolver 404.',
			),
			'path' => array(
				'type'        => 'slug',
				'label'       => 'Tramo de la URL',
				'default'     => '',
				'public'      => true,
				'description' => sprintf(
					'Solo el último tramo, sin barras (p. ej. «%s»). Vacío: se usa «%s». En la raíz no puede ser una sección del sitio (tienda, blog, carrito, cuenta…): esos tramos están ocupados y la landing volvería sola al área. Cambiarlo cambia la URL pública; la anterior deja de responder.',
					$id,
					$id
				),
			),
		);

		// Un tramo por idioma secundario: vacío = el mismo que en el idioma por defecto. El tramo
		// de cualquier idioma sigue respondiendo y redirige (308) al del idioma de la página.
		foreach ( Schema::secondaryLocales() as $suffix ) {
			$children[ 'path_' . $suffix ] = array(
				'type'        => 'slug',
				'label'       => sprintf( 'Tramo de la URL (%s)', strtoupper( str_replace( '_', '-', $suffix ) ) ),
				'default'     => '',
				'public'      => true,
				'description' => 'Vacío = el mismo tramo que en el idioma por defecto. La ubicación es la de arriba.',
			);
		}

		return array(
			'type'     => 'group',
			'label'    => 'URL pública',
			'children' => $children,
		);
	}

	/**
	 * @param array<string,array<string,mixed>> $landings
	 * @return array<string,mixed>
	 */
	private static function seo( array $landings ): array {
		$people = array( '' => '— Ninguna —' );
		foreach ( $landings as $id => $meta ) {
			if ( 'person' === ( $meta['jsonld'] ?? '' ) ) {
				$people[ (string) $id ] = (string) $meta['label'];
			}
		}

		return array(
			'type'     => 'group',
			'label'    => 'SEO',
			'children' => array(
				'title'          => array( 'type' => 'text', 'label' => 'Título SEO', 'public' => true, 'translatable' => true, 'description' => 'Vacío: se usa el título del hero.' ),
				'description'    => array( 'type' => 'textarea', 'label' => 'Descripción SEO', 'public' => true, 'translatable' => true ),
				'og_image'       => array( 'type' => 'image', 'label' => 'Imagen para compartir (OG)', 'public' => true, 'description' => 'Medida recomendada: 1200×630 px (1,91:1). Vacío: se usa la imagen del hero.' ),
				'date_published' => array( 'type' => 'text', 'label' => 'Fecha de publicación', 'public' => true, 'description' => 'Formato AAAA-MM-DD. Vacío: no se declara ninguna fecha (mejor que una inventada).' ),
				'date_modified'  => array( 'type' => 'text', 'label' => 'Fecha de última revisión', 'public' => true, 'description' => 'Formato AAAA-MM-DD. Actualízala cuando revises el contenido: es la señal de frescura para el buscador.' ),
				'author'         => array( 'type' => 'text', 'label' => 'Firmado por', 'public' => true, 'description' => 'Nombre de quien firma el contenido. Solo cuenta para los datos estructurados: no se pinta. Vacío: lo firma la marca.' ),
				'author_landing' => array(
					'type'        => 'select',
					'label'       => 'Página de esa persona',
					'default'     => '',
					'public'      => true,
					'options'     => $people,
					'description' => 'Si esa persona tiene su propia landing, elígela: los datos estructurados enlazarán la firma con esa página.',
				),
			),
		);
	}

	/**
	 * Cinco slots fijos de sección. `order` es la posición por defecto (el índice).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function sections(): array {
		$slots = array();
		for ( $i = 1; $i <= self::SECTION_COUNT; $i++ ) {
			$slots[ "s$i" ] = array(
				'type'     => 'group',
				'label'    => "Sección $i",
				'children' => array(
					'enabled'      => array( 'type' => 'boolean', 'label' => 'Mostrar', 'public' => true ),
					'order'        => array(
						'type'    => 'select',
						'label'   => 'Orden',
						'public'  => true,
						'options' => array_combine(
							array_map( 'strval', range( 1, self::SECTION_COUNT ) ),
							array_map( 'strval', range( 1, self::SECTION_COUNT ) )
						),
						'default' => (string) $i,
					),
					'layout'       => array(
						'type'    => 'select',
						'label'   => 'Disposición',
						'public'  => true,
						'options' => self::LAYOUTS,
						'default' => 'image-right',
					),
					'kicker'       => array( 'type' => 'text', 'label' => 'Antetítulo', 'public' => true, 'translatable' => true ),
					'title'        => array( 'type' => 'text', 'label' => 'Título', 'public' => true, 'translatable' => true ),
					'body'         => array( 'type' => 'richtext', 'label' => 'Cuerpo', 'default' => '', 'public' => true, 'translatable' => true ),
					'image'        => array( 'type' => 'image', 'label' => 'Imagen', 'public' => true, 'description' => self::SECTION_IMAGE_SIZES ),
					'image_alt'    => array( 'type' => 'text', 'label' => 'Texto alternativo de la imagen', 'public' => true, 'translatable' => true, 'description' => 'Qué se ve, en una frase, para quien no puede verla. No repitas el título. Vacío = imagen decorativa.' ),
					'image_2'      => array( 'type' => 'image', 'label' => 'Segunda imagen', 'public' => true, 'description' => 'Solo la usa la disposición «Pareja en espejo».' ),
					'image_2_alt'  => array( 'type' => 'text', 'label' => 'Texto alternativo de la segunda imagen', 'public' => true, 'translatable' => true ),
				),
			);
		}
		return $slots;
	}

	/** @return array<string,mixed> */
	private static function quote(): array {
		return array(
			'type'     => 'group',
			'label'    => 'Cita destacada',
			'children' => array(
				'enabled'          => array( 'type' => 'boolean', 'label' => 'Mostrar', 'public' => true ),
				'text'             => array( 'type' => 'textarea', 'label' => 'Cita', 'public' => true, 'translatable' => true ),
				'author'           => array( 'type' => 'text', 'label' => 'Autor', 'public' => true, 'translatable' => true ),
				'author_photo'     => array( 'type' => 'image', 'label' => 'Foto del autor', 'public' => true, 'description' => 'Medida recomendada: 400×400 px (1:1). Se pinta como retrato circular: encuadre de cara y centrado.' ),
				'background_image' => array( 'type' => 'image', 'label' => 'Imagen de fondo', 'public' => true, 'description' => 'Medida recomendada: 1920×1080 px (16:9). Va bajo un velo de contraste y la cita se pinta encima: sin texto en la propia foto. El mismo archivo sirve en móvil.' ),
				'logo'             => array( 'type' => 'image', 'label' => 'Marca que firma la banda', 'public' => true, 'description' => 'Se pinta centrada bajo el texto. Sube un PNG con fondo transparente y sin márgenes de más. Vacío: no se pinta nada.' ),
				'logo_alt'         => array( 'type' => 'text', 'label' => 'Texto alternativo de la marca', 'public' => true, 'translatable' => true, 'description' => 'Déjalo vacío si el nombre ya está escrito en el texto de la banda: entonces la imagen es decorativa.' ),
			),
		);
	}

	/** @return array<string,mixed> */
	private static function faq(): array {
		$children = array(
			'enabled' => array( 'type' => 'boolean', 'label' => 'Mostrar', 'public' => true ),
			'title'   => array( 'type' => 'text', 'label' => 'Título', 'public' => true, 'translatable' => true ),
		);
		for ( $i = 1; $i <= self::FAQ_COUNT; $i++ ) {
			$children[ "q$i" ] = array(
				'type'     => 'group',
				'label'    => "Pregunta $i",
				'children' => array(
					'question' => array( 'type' => 'text', 'label' => 'Pregunta', 'public' => true, 'translatable' => true ),
					'answer'   => array( 'type' => 'richtext', 'label' => 'Respuesta', 'default' => '', 'public' => true, 'translatable' => true ),
				),
			);
		}
		return array(
			'type'     => 'group',
			'label'    => 'Preguntas frecuentes (hasta ' . self::FAQ_COUNT . ')',
			'children' => $children,
		);
	}

	/**
	 * @param array<string,string> $categories
	 * @return array<string,mixed>
	 */
	private static function productsBlock( string $id, array $categories ): array {
		// La lista manual se acota a la categoría YA guardada de ESTA landing y conserva lo
		// elegido: un catálogo entero en una lista de casillas no es usable, y sin conservar la
		// selección el primer guardado la borraría.
		$category = (string) Storage::get( array( 'landings', 'editorial', $id, 'products', 'category' ), '' );
		$manual   = (string) Storage::get( array( 'landings', 'editorial', $id, 'products', 'manual' ), '' );
		$selected = array_values( array_filter( array_map( 'trim', explode( '|', $manual ) ) ) );

		return array(
			'type'     => 'group',
			'label'    => 'Productos',
			'children' => array(
				'enabled'  => array( 'type' => 'boolean', 'label' => 'Mostrar', 'public' => true ),
				'title'    => array( 'type' => 'text', 'label' => 'Título', 'public' => true, 'translatable' => true ),
				'after'    => array(
					'type'    => 'select',
					'label'   => 'Colocar después de',
					'public'  => true,
					'options' => self::PRODUCTS_AFTER,
					'default' => 'faq',
				),
				'mode'     => array(
					'type'    => 'select',
					'label'   => 'Fuente',
					'public'  => true,
					'options' => array( 'category' => 'Por categoría', 'manual' => 'Selección manual' ),
					'default' => 'category',
				),
				'category' => array(
					'type'        => 'select',
					'label'       => 'Categoría',
					'default'     => '',
					'public'      => true,
					'options'     => array( '' => '— Sin categoría —' ) + self::withStored( $categories, array( $category ) ),
					'description' => 'Al cambiarla, GUARDA para refrescar la lista de la selección manual.',
				),
				'manual'   => array(
					'type'        => 'product_multiselect',
					'label'       => 'Productos',
					'default'     => '',
					'public'      => true,
					'options'     => Options::products( $category, $selected ),
					'description' => 'Con «Selección manual», los productos de la categoría de arriba (o todos si no hay ninguna).',
				),
			),
		);
	}

	/** @return array<string,mixed> */
	private static function closing(): array {
		return array(
			'type'     => 'group',
			'label'    => 'Cierre',
			'children' => array(
				'enabled'          => array( 'type' => 'boolean', 'label' => 'Mostrar', 'public' => true ),
				'title'            => array( 'type' => 'text', 'label' => 'Título', 'public' => true, 'translatable' => true ),
				'text'             => array( 'type' => 'richtext', 'label' => 'Texto', 'default' => '', 'public' => true, 'translatable' => true ),
				'cta_label'        => array( 'type' => 'text', 'label' => 'Botón 1 · texto', 'public' => true, 'translatable' => true ),
				'cta_href'         => array( 'type' => 'text', 'label' => 'Botón 1 · enlace', 'public' => true, 'translatable' => true, 'description' => 'Ruta interna (/products) o dirección completa (https://…).' ),
				'cta2_label'       => array( 'type' => 'text', 'label' => 'Botón 2 · texto', 'public' => true, 'translatable' => true ),
				'cta2_href'        => array( 'type' => 'text', 'label' => 'Botón 2 · enlace', 'public' => true, 'translatable' => true ),
				'background_image' => array( 'type' => 'image', 'label' => 'Imagen de fondo', 'public' => true, 'description' => 'Ancho panorámico, mínimo 1920×720 px. El texto se pinta encima: conviene una foto de tono medio y sin sujetos en la zona central. Vacío: relleno de color.' ),
			),
		);
	}

	/**
	 * Une a una lista de opciones las claves ya guardadas que falten (lo guardado va primero).
	 *
	 * @param array<string,string> $options
	 * @param list<string>         $stored
	 * @return array<string,string>
	 */
	private static function withStored( array $options, array $stored ): array {
		$extra = array();
		foreach ( $stored as $slug ) {
			$slug = trim( $slug );
			if ( '' !== $slug && ! array_key_exists( $slug, $options ) ) {
				$extra[ $slug ] = $slug . ' (no encontrado)';
			}
		}
		return $extra + $options;
	}
}
