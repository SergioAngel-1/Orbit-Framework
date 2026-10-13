/**
 * Contrato de datos núcleo ↔ instancia (frontend).
 *
 * `InstanceRegistry` describe TODO lo que el núcleo puede leer de la instancia:
 * solo datos, nunca componentes de React. La instancia lo rellena en
 * `src/instance/registry.ts`, que es el ÚNICO módulo de instancia que el núcleo
 * importa (ver la regla ESLint «Frontera núcleo/instancia»).
 *
 * Campos que existirán (cada uno lo añade su tarea):
 * - `revalidationTags` (esta tarea): tags ISR extra para `/api/revalidate`.
 * - `decorPresets` (módulo `banners`): visuales decorativos de plantilla.
 * - `landings` (módulo `landings`): landings con diseño propio y sus secciones únicas.
 * - `productFields` (mu-plugin `hwe-product-fields`): declaración de los campos de producto.
 */

/** Política de inversión de un visual decorativo en tema oscuro (ver `lib/banners/decor-presets`). */
export type DecorInvert = "theme" | "light" | "never";

/** Un visual decorativo de plantilla de un slide de banner. */
export interface DecorPreset {
  /** Ruta pública del archivo (SVG) en `frontend/public`. */
  src: string;
  /** Por defecto, `theme`. */
  invert?: DecorInvert;
}

/**
 * Una sección ÚNICA de una landing con diseño propio: una pieza de código de la instancia que se
 * inserta tras una sección base. Solo datos: el mapa `clave → componente` vive en las vistas de la
 * instancia (el núcleo nunca importa componentes).
 */
export interface UniqueSectionDeclaration {
  /** Clave de la sección; la vista de la instancia la mapea a un componente. */
  key: string;
  /**
   * Slot base tras el que se inserta (`hero`, `lead`, `s1`…`s5`, `quote`, `faq`, `products`,
   * `closing`). Un ancla CONSUMIDA (ver `consumesBase`) no existe en el flujo: la sección se
   * empuja al final. Entre varias con la misma ancla manda el orden de declaración.
   */
  after: string;
}

/**
 * Una landing con diseño propio (secciones de código). Debe declararse también en el backend con
 * el filtro `hwe_landings_static_entries` (misma clave, `base` y tipo): así su contenido se edita
 * en el mismo panel. Un identificador del panel que choque con una estática se ignora.
 */
export interface InstanceLandingEntry {
  /** Nombre legible (respaldo del título del hero). */
  label: string;
  /** Último tramo de la URL por defecto. Sin él, el propio identificador. */
  path?: string;
  /** Tramo por defecto en un idioma concreto (`{ en: "usage-guide" }`). */
  paths?: Record<string, string>;
  /** Dónde cuelga por defecto: `area` (`/learn/<tramo>`) o `root` (`/<tramo>`). */
  base?: "area" | "root";
  /** Tipo de datos estructurados. Por defecto `article`. */
  jsonLd?: "article" | "person";
  /** Secciones únicas por código, en orden de declaración. */
  uniqueSections?: UniqueSectionDeclaration[];
  /**
   * Secciones base que una sección única absorbe: salen del RENDER, no del DATO. Se filtran
   * ANTES de resolver las anclas `after`.
   */
  consumesBase?: string[];
  /** Si la FAQ se pinta junto a esta sección (`s1`…`s5`) en vez de como banda propia. */
  faqAside?: string;
  /** Claves i18n (de la instancia) del SEO por defecto cuando el CMS no lo trae. */
  seoFallback?: { titleKey: string; descriptionKey: string } | null;
  /** Pistas de presentación opacas para las vistas de la instancia (el núcleo no las lee). */
  hints?: Record<string, string>;
}

export interface InstanceLandings {
  /** Landings con diseño propio, por identificador. */
  staticEntries: Record<string, InstanceLandingEntry>;
  /** Tramos de la raíz que la instancia ocupa con rutas propias (además de los del núcleo). */
  reservedRootSegments?: string[];
  /** Orden editorial de los grupos del índice de landings de producto (slugs de categoría). */
  productGroupOrder?: string[];
}

/** Tipos de campo de producto declarativo (`hwe_product_fields`). */
export type ProductFieldType =
  | "text"
  | "textarea"
  | "richtext"
  | "url"
  | "number"
  | "boolean"
  | "select"
  | "color"
  | "image";

/** Cómo el frontend conoce un campo de producto declarado en el backend. */
export interface ProductFieldDeclaration {
  /** Debe coincidir con el `type` del filtro `hwe_product_fields`. */
  type: ProductFieldType;
  /** Etiqueta para pintarlo (la ficha del starter lista los campos con valor). */
  label?: string;
}

/**
 * Valores de los campos de producto, por clave. Vacía en el núcleo (que no declara campos): la
 * instancia la amplía por *module augmentation* para tipar `product.fields`:
 *
 *     declare module "@/lib/instance/types" {
 *       interface ProductFieldValues { dosage?: string; weight?: string }
 *     }
 *
 * Lo que no llega es `undefined`: un campo sin valor no viaja.
 */
// eslint-disable-next-line @typescript-eslint/no-empty-object-type
export interface ProductFieldValues {}

export interface InstanceRegistry {
  /** Tags ISR extra que `/api/revalidate` acepta además de los del núcleo. */
  revalidationTags: string[];
  /**
   * Manifiesto de las tarjetas sociales estáticas de la instancia
   * (`"<vista>.<idioma>" → hash`), que escribe `npm run og:generate`. Vacío =
   * todas las vistas usan la tarjeta dinámica de marca.
   */
  ogCards?: Record<string, string>;
  /**
   * Visuales decorativos de plantilla de los slides de banner: clave → archivo. El orden
   * importa (el primero es el respaldo) y las claves deben coincidir con las del filtro
   * PHP `hwe_banners_decor_presets`.
   */
  decorPresets?: Record<string, DecorPreset>;
  /** Landings con diseño propio y datos del motor de landings (módulo `landings`). */
  landings?: InstanceLandings;
  /**
   * Campos de producto declarativos (`hwe_product_fields` en el backend): clave → tipo y
   * etiqueta. Con ella el frontend sabe qué campos existen y cómo tratarlos; los valores salen
   * de `Product.hweFields(locale)` y se tipan con `ProductFieldValues`.
   */
  productFields?: Record<string, ProductFieldDeclaration>;
}
