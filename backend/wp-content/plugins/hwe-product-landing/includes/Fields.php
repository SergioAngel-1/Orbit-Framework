<?php
namespace HWE\ProductLanding;

final class Fields {

    /**
     * Meta keys planas (no repeater). key => ['type', 'label', 'translatable' (bool), 'default'?, 'hint'?].
     *
     * `translatable`: el campo tiene versión por idioma secundario del sitio. El idioma por defecto
     * vive en `_hwe_landing_<key>`; los demás en `_hwe_landing_<key>_<idioma>`, que gestiona el
     * mu-plugin `hwe-content-i18n` (caja «Traducciones» de la ficha: ver `Translations`).
     *
     * `hint` es la ayuda que se pinta bajo el control (`Render::hint()`). En
     * los campos de imagen lleva la MEDIDA RECOMENDADA, deducida del recorte
     * real que hace el frontend: si una sección cambia de proporción, el hint
     * cambia con ella. Cuando el molde recorta distinto en móvil se dice
     * aquí, junto con si hace falta —o no— un archivo aparte para móvil.
     *
     * `default` solo aplica hoy a los booleanos: es el valor que se pinta en el
     * metabox y el que reporta GraphQL mientras el meta NUNCA se haya guardado
     * (valor ''), p. ej. en una landing publicada desde el multiselect de HWE
     * Control Center. Por spec §2.1, las secciones Educativa / Cualidades /
     * Consejos vienen activadas y Video desactivada. En cuanto el metabox se
     * guarda una vez, el `<input type="hidden" value="0">` garantiza un '1'/'0'
     * explícito y el default deja de intervenir.
     */
    public const SCALAR_FIELDS = [
        'published'            => ['type' => 'boolean',  'label' => 'Publicar landing', 'translatable' => false, 'default' => false],

        'seo_title'            => ['type' => 'text',     'label' => 'Título SEO', 'translatable' => true],
        'seo_description'      => ['type' => 'textarea', 'label' => 'Descripción SEO', 'translatable' => true],
        'seo_og_image'         => ['type' => 'image',    'label' => 'Imagen para compartir (OG)', 'translatable' => false, 'hint' => 'Medida recomendada: 1200×630 px (1,91:1) — la que piden redes y buscadores. No necesita versión móvil.'],

        // Paleta propia de la landing. Dos hex `#rrggbb` opcionales que el
        // frontend usa como EXCEPCIÓN de color de esa vista (tokens
        // --color-landing-accent-1/2): acento 1 = rellenos decorativos
        // (medallones, marcas, barras de cualidades), acento 2 = disco del CTA
        // de compra. Vacío = la landing usa el color de la plantilla base. No
        // bilingües (un color no se traduce).
        'accent_1'            => ['type' => 'color',    'label' => 'Color de acento 1 (vacío = color de la plantilla)', 'translatable' => false],
        'accent_2'            => ['type' => 'color',    'label' => 'Color de acento 2 (vacío = color de la plantilla)', 'translatable' => false],

        'hero_subtitle'        => ['type' => 'text',     'label' => 'Subtítulo del hero', 'translatable' => true],
        'hero_image'           => ['type' => 'image',    'label' => 'Imagen del hero (fallback: imagen del producto)', 'translatable' => false, 'hint' => 'Medida recomendada: 1200×1500 px (4:5). El marco del hero es vertical en TODOS los tamaños, así que el mismo archivo sirve en móvil: no hay versión aparte. Vacío = se usa la imagen destacada del producto.'],

        'section_edu_enabled'  => ['type' => 'boolean',  'label' => 'Mostrar sección educativa', 'translatable' => false, 'default' => true],
        'edu_kicker'           => ['type' => 'text',     'label' => 'Antetítulo educativa', 'translatable' => true],
        'edu_title'            => ['type' => 'text',     'label' => 'Título educativa', 'translatable' => true],
        'edu_body'             => ['type' => 'richtext', 'label' => 'Cuerpo educativa', 'translatable' => true],
        'edu_image'            => ['type' => 'image',    'label' => 'Imagen educativa (opcional)', 'translatable' => false, 'hint' => 'Medida recomendada: 1200×1500 px (4:5) — va en columna junto al cuerpo. Si dejas el cuerpo vacío, la imagen pasa a banda ancha: entonces conviene 1920×820 px (21:9), que en móvil se recorta a 4:3, así que centra el sujeto. No hace falta una versión aparte para móvil.'],

        'about_title'          => ['type' => 'text',     'label' => 'Título "sobre el producto"', 'translatable' => true],
        'about_body'           => ['type' => 'richtext', 'label' => 'Cuerpo "sobre el producto"', 'translatable' => true],

        'section_qualities_enabled' => ['type' => 'boolean', 'label' => 'Mostrar cualidades', 'translatable' => false, 'default' => true],
        'qualities_title'     => ['type' => 'text',     'label' => 'Título de cualidades (opcional)', 'translatable' => true],

        'section_advice_enabled' => ['type' => 'boolean', 'label' => 'Mostrar consejos', 'translatable' => false, 'default' => true],
        'advice_title'        => ['type' => 'text',     'label' => 'Título de consejos', 'translatable' => true],
        'advice_intro'        => ['type' => 'richtext', 'label' => 'Intro de consejos (opcional)', 'translatable' => true],

        'cta_title'           => ['type' => 'text',     'label' => 'Título del CTA de compra (opcional)', 'translatable' => true],
        'cta_text'            => ['type' => 'text',     'label' => 'Texto del CTA de compra (opcional)', 'translatable' => true],

        'section_video_enabled' => ['type' => 'boolean', 'label' => 'Mostrar video', 'translatable' => false, 'default' => false],
        'video_url'           => ['type' => 'url',      'label' => 'URL de YouTube', 'translatable' => false],
        'video_caption'       => ['type' => 'text',     'label' => 'Pie del video (opcional)', 'translatable' => true],
        // Portada propia del video. Sin ella se usa la que genera YouTube
        // (`hqdefault`), que es 4:3 y suele traer el fotograma que el sistema
        // escoge: en una caja 16:9 se recorta y casi nunca es el encuadre que
        // la marca quiere ensenar. No se traduce: es una fotografia.
        'video_poster'        => ['type' => 'image',    'label' => 'Miniatura del video (opcional)', 'translatable' => false, 'hint' => 'Medida recomendada: 1280×720 px (16:9). Es la imagen que se ve antes de darle a reproducir; el video no se carga hasta entonces. Vacío = se usa la miniatura que genera YouTube, que es 4:3 y se recorta.'],
        // Alimenta `uploadDate` del `VideoObject` que emite la landing. Google
        // la exige para el resultado enriquecido de video; sin ella el nodo se
        // emite igual —sigue siendo senal util— pero no opta a esa ficha.
        // No se traduce: una fecha es la misma en los dos idiomas.
        'video_date'          => ['type' => 'text',     'label' => 'Fecha del video (AAAA-MM o AAAA-MM-DD)', 'translatable' => false],

        'closing_title'       => ['type' => 'text',     'label' => 'Título de cierre', 'translatable' => true],
        'closing_text'        => ['type' => 'richtext', 'label' => 'Texto de cierre', 'translatable' => true],
        'closing_cta_label'   => ['type' => 'text',     'label' => 'Texto botón de cierre (opcional)', 'translatable' => true],
        'closing_cta_href'    => ['type' => 'text',     'label' => 'Enlace botón de cierre (opcional)', 'translatable' => true],
    ];

    /** Repeaters. key => ['label', 'meta_key', 'row_fields' => [name => ['type','label','translatable']]]. */
    public const REPEATERS = [
        'components' => [
            'label'    => 'Componentes principales',
            'meta_key' => '_hwe_landing_components',
            // El frontend (`MAX_ITEMS` de la sección de componentes) solo pinta los
            // primeros 11 en el arco de §3: sin este tope, un editor puede
            // cargar más filas de las que la sección va a mostrar y no se
            // entera de que las de más allá del 11.º nunca aparecen en la
            // landing publicada.
            // 11 y no 8: el arco de la plantilla tiene un tercer escalón de geometría
            // (≥9) para repartir tantos componentes sin que los retratos se pisen.
            'max_rows' => 11,
            'row_fields' => [
                'image' => ['type' => 'image',  'label' => 'Imagen', 'translatable' => false, 'hint' => 'Medida recomendada: 400×400 px (1:1). Se recorta a un retrato circular de 112 px sobre el arco: sujeto centrado y con aire por los cuatro lados. Igual en móvil.'],
                'icon'  => ['type' => 'select', 'label' => 'Ícono (fallback si no hay imagen)', 'translatable' => false, 'options' => 'icons'],
                'name'  => ['type' => 'text',   'label' => 'Nombre', 'translatable' => true],
                'note'  => ['type' => 'text',   'label' => 'Nota (opcional)', 'translatable' => true],
            ],
        ],
        'qualities' => [
            'label'    => 'Cualidades',
            'meta_key' => '_hwe_landing_qualities',
            // El frontend (`MAX_ITEMS` de la tabla de cualidades) solo pinta las
            // primeras 11; con número impar la última ocupa las dos columnas de
            // la tabla. Sin este tope el metabox dejaría cargar filas que la
            // landing publicada nunca muestra.
            // El tope va emparejado con el de `components`: cada cualidad puede
            // describir a su componente (la n.ª N a la N.ª), así que ambas listas
            // tienen el mismo largo.
            'max_rows' => 11,
            'row_fields' => [
                'icon'  => ['type' => 'select', 'label' => 'Ícono', 'translatable' => false, 'options' => 'icons'],
                'image' => ['type' => 'image',  'label' => 'Imagen (reemplaza al ícono)', 'translatable' => false, 'hint' => 'Medida recomendada: 200×200 px (1:1). Se pinta como disco de 24 px dentro de la tabla: solo se lee un símbolo simple, no una fotografía.'],
                'title' => ['type' => 'text',   'label' => 'Título', 'translatable' => true],
                'text'  => ['type' => 'text',   'label' => 'Texto', 'translatable' => true],
            ],
        ],
        'advice' => [
            'label'    => 'Consejos / preguntas',
            'meta_key' => '_hwe_landing_advice',
            // Repeater mixto: filas sin encabezado son consejos, filas con
            // encabezado son preguntas. El frontend (`splitAdvice`,
            // ADVICE_MAX_PER_TYPE) capa cada tipo a 10 por separado — de ahí
            // que el tope total sean 20 filas.
            'max_rows' => 20,
            'row_fields' => [
                'heading' => ['type' => 'text',     'label' => 'Encabezado (vacío = ítem de lista)', 'translatable' => true],
                'body'    => ['type' => 'textarea',  'label' => 'Cuerpo', 'translatable' => true],
            ],
        ],
    ];

    /** Íconos del set neutro (claves y etiquetas). Reflejado en el frontend: `components/products/quality-icons.tsx`. */
    public const DEFAULT_ICONS = [
        'sparkles' => 'Destellos', 'heart' => 'Corazón', 'leaf' => 'Hoja',
        'shield'   => 'Escudo', 'sun' => 'Sol', 'moon' => 'Luna',
        'droplets' => 'Gotas', 'wind' => 'Viento', 'flame' => 'Llama',
        'anchor'   => 'Ancla', 'compass' => 'Brújula', 'sprout' => 'Brote',
    ];

    /**
     * Íconos del selector de cualidades y componentes: el set neutro más los que la instancia
     * añada con `hwe_product_landing_icons` (clave en minúsculas → etiqueta). La instancia
     * declara los mismos en su frontend (el starter solo conoce el set neutro).
     *
     * @return array<string,string>
     */
    public static function icons(): array {
        $filtered = apply_filters('hwe_product_landing_icons', self::DEFAULT_ICONS);
        if (!is_array($filtered)) {
            return self::DEFAULT_ICONS;
        }
        $icons = [];
        foreach ($filtered as $key => $label) {
            $key = is_string($key) ? $key : '';
            if (preg_match('/^[a-z][a-z0-9-]{0,31}$/', $key) === 1 && is_string($label) && $label !== '') {
                $icons[$key] = $label;
            }
        }
        return $icons !== [] ? $icons : self::DEFAULT_ICONS;
    }

    /** Campos escalares con versión por idioma, en el orden de pintado. @return list<string> */
    public static function translatableScalars(): array {
        return array_keys(array_filter(self::SCALAR_FIELDS, static fn (array $s): bool => !empty($s['translatable'])));
    }

    /** Campos traducibles de cada repeater: repeater => [campo…]. @return array<string,list<string>> */
    public static function translatableRowFields(): array {
        $out = [];
        foreach (self::REPEATERS as $repeater => $spec) {
            $out[$repeater] = array_keys(array_filter($spec['row_fields'], static fn (array $f): bool => !empty($f['translatable'])));
        }
        return $out;
    }

    /** Prefijo de meta para los campos escalares: `_hwe_landing_<key>` (+ `_<idioma>` en los traducibles). */
    public static function metaKey(string $field): string {
        return '_hwe_landing_' . $field;
    }
}
