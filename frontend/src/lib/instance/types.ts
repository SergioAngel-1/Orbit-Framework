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

import type { ColorScheme } from "@/lib/config/types";

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

/**
 * Valor de una ruta pública: el mismo tramo para todos los idiomas (`"/productos"`) o uno por
 * idioma (`{ es: "/productos", en: "/products" }`). Un idioma sin entrada conserva el tramo
 * interno en ese idioma.
 */
export type PublicPathValue = string | Readonly<Record<string, string>>;

/** Mapa ruta interna → ruta pública (`"/products": "/productos"`). */
export type PublicPathMap = Readonly<Record<string, PublicPathValue>>;

/** Frecuencia de cambio que admite el protocolo de sitemaps. */
export type SitemapChangeFrequency =
  "always" | "hourly" | "daily" | "weekly" | "monthly" | "yearly" | "never";

/** Una página propia de la instancia que se anuncia en el sitemap. */
export interface InstanceSitemapPage {
  /** Ruta INTERNA (formato de `pathnames`, p. ej. `"/example-page"`). */
  path: string;
  /** Por defecto, `"monthly"`. */
  changeFrequency?: SitemapChangeFrequency;
  /** Entre 0 y 1. Por defecto, `0.5`. */
  priority?: number;
}

/**
 * Ajustes del sitemap y de `llms.txt` declarados por la instancia: páginas propias y rutas
 * que no se anuncian. Solo datos; ver `lib/instance/sitemap.ts`.
 */
export interface InstanceSitemap {
  /** Páginas propias, en el segmento `paginas`, expandidas a todos los idiomas. */
  pages?: readonly InstanceSitemapPage[];
  /**
   * Rutas INTERNAS que no se anuncian: exactas (`"/legal/privacy"`) o un prefijo con `/*`
   * (`"/products/*"`, que incluye las fichas y sus landings).
   */
  exclude?: readonly string[];
}

/**
 * Redirección de la instancia (ver `lib/instance/redirects.ts`). `from` es la ruta PÚBLICA
 * tal como llega, exacta o un prefijo con `/*`; `to`, una ruta del sitio o una URL http(s)
 * (con `*` para copiar el resto de un prefijo). 301 salvo `permanent: false` (302).
 */
export interface InstanceRedirect {
  from: string;
  to: string;
  permanent?: boolean;
}

export interface InstanceRegistry {
  /** Tags ISR extra que `/api/revalidate` acepta además de los del núcleo. */
  revalidationTags: string[];
  /**
   * Rutas públicas de la instancia: ruta **interna** (el formato de `pathnames` de next-intl,
   * p. ej. `"/products"` o `"/products/[slug]"`) → tramo **público** con el que se sirve, igual
   * para todos los idiomas (`"/products": "/productos"`) o por idioma
   * (`"/products": { es: "/productos", en: "/products" }`).
   *
   * Un hijo sin entrada propia hereda el prefijo de su padre: declarar `"/account": "/cuenta"`
   * mueve `/account/orders` a `/cuenta/orders`; una entrada propia manda. Las carpetas de
   * `app/[locale]/` NO cambian: el núcleo traduce, redirige la ruta interna (308) y reescribe la
   * pública a la interna. Sin este campo, todas las URL son las de siempre.
   */
  publicPaths?: PublicPathMap;
  /**
   * Sitemap de la instancia: páginas propias que se añaden al segmento `paginas` y rutas que
   * no se anuncian (ni en el sitemap ni en `llms.txt`). Sin el campo, nada cambia.
   */
  sitemap?: InstanceSitemap;
  /**
   * Redirecciones 301 de la migración (URLs de la web anterior → las nuevas). Las aplica el
   * proxy antes que el idioma; ver `lib/instance/redirects.ts`. Sin el campo, ninguna.
   */
  redirects?: readonly InstanceRedirect[];
  /**
   * Días que dura la cookie de referido `hwe_ref`, entero de 1 a 365. Sin el campo, 30. Va aquí y
   * no en el Control Center porque la escribe el proxy, que no consulta la config.
   */
  referralCookieDays?: number;
  /**
   * Esquema de color cuando WordPress no responde y no se puede leer `design.color_scheme`. Sin el
   * campo, `system`. Una instancia sin tema oscuro declara `light`: un fallo de la API no debe
   * cambiar el aspecto de la tienda.
   */
  colorSchemeFallback?: ColorScheme;
  /**
   * Zona horaria de la tienda (IANA, p. ej. `"America/Bogota"`), igual para todos los idiomas
   * o por idioma (`{ es: "America/Bogota", en: "America/New_York" }`). La usa
   * `i18n/request.ts` para formatear fechas y horas. Sin ella, se mantienen los valores de
   * siempre (`es` → `Europe/Madrid`, `en` → `America/New_York`).
   */
  timeZone?: string | Record<string, string>;
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
