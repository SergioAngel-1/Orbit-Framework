/**
 * Variantes por idioma de los campos traducibles: `<campo>_<sufijo>` por cada
 * idioma de `i18n.locales` (p. ej. `tagline_en`, `tagline_pt_br`). Se leen con
 * `pickLocalized(entity, campo, locale)`, nunca a mano.
 */
export type Translations<K extends string> = {
  [P in `${K}_${string}`]?: string;
};

/** Configuración pública de la plantilla, tal como la expone /wp-json/hwe/v1/config. */
export interface SiteConfig {
  /** Idiomas secundarios del Control Center (códigos separados por comas). */
  i18n: {
    locales: string;
  };
  /** Acceso: login y registro por teléfono (apagado por defecto). */
  auth: {
    phone_login_enabled: boolean;
    /** Prefijo para números escritos sin él (p. ej. "+34"). Vacío = siempre con prefijo. */
    phone_default_dial_code: string;
  };
  brand: Translations<"tagline" | "description"> & {
    name: string;
    tagline: string;
    description: string;
    url: string;
    /** URL del logo de cabecera. Vacío = mostrar el nombre del sitio como texto. */
    logo: string;
    /** Variante del logo para correos. Vacío = usa el logo de cabecera. */
    email_logo: string;
    /**
     * Icono del sitio de WordPress (Ajustes → Generales), calculado por el
     * backend. Null = el frontend genera uno desde la marca.
     */
    icon_url: string | null;
    locale: string;
  };
  social: {
    twitter: string;
    instagram: string;
    facebook: string;
    linkedin: string;
    youtube: string;
    /** Número(s) internacional(es). El botón flotante usa el primero. */
    whatsapp: string;
    wikipedia: string;
    wikidata: string;
  };
  legal: {
    company: string;
    nif: string;
    email: string;
    phone: string;
    mobile: string;
    address: string;
    /** Partes de la dirección para datos estructurados (`addressLocality`…). */
    city?: string;
    region?: string;
    postal_code?: string;
    locations: {
      location_1: SiteConfigLocation;
      location_2: SiteConfigLocation;
      location_3: SiteConfigLocation;
      location_4: SiteConfigLocation;
      location_5: SiteConfigLocation;
    };
  };
  design: {
    /** `system` sigue al dispositivo; `light` y `dark` lo fijan (`lib/config/color-scheme.ts`). */
    color_scheme?: ColorScheme;
    colors: {
      brand: string;
      brand_dark: string;
      brand_light: string;
      secondary: string;
      secondary_dark: string;
      accent: string;
      surface: string;
      background: string;
      foreground: string;
    };
    typography: {
      font_sans: string;
      font_url: string;
      font_heading: string;
      font_heading_url: string;
    };
  };
  ecommerce: {
    currency: string;
    /**
     * Decimales de precio de WooCommerce (Ajustes → General), calculados por
     * WordPress al servir la config. `null` = sin WooCommerce: se usan los de Intl.
     */
    price_decimals: number | null;
    country: string;
    products_per_page: string;
    reviews_enabled: boolean;
    wishlist_enabled: boolean;
    coupons_enabled: boolean;
    search_enabled: boolean;
    /** `true` = solo clientes con cuenta pueden comprar. */
    checkout_login_required: boolean;
    free_shipping: {
      enabled: boolean;
      /** En la moneda de la tienda, sin decimales. */
      min_amount: string;
      /** Slugs `slug-1 | slug-2`. Vacío = aplica a todo el catálogo. */
      categories: string;
      products: string;
    };
  };
  banners: {
    /** Activa el renderizado de banners en el frontend. La autoría vive en el plugin HWE Banners. */
    enabled: boolean;
  };
  /**
   * Envío del módulo `shipping`. `distance_enabled`: hay al menos una ciudad por distancia, así
   * que el mapa del pin es obligatorio para esas ciudades. Lo calcula WordPress (no es un ajuste).
   */
  shipping: {
    distance_enabled: boolean;
  };
  /**
   * Programa de fidelización (módulo `loyalty`): monedero de puntos y referidos. Los valores
   * numéricos viajan como texto, igual que el resto de campos `number` del Control Center; los
   * convierte quien opera con ellos. Con el módulo apagado llegan los defaults y el BFF responde
   * 404. `allowed_roles` no es público y no forma parte de este contrato.
   */
  loyalty: LoyaltyConfig;
  /**
   * Membresías (módulo `memberships`): solo los campos públicos del carné y su verificación. Los
   * niveles y beneficios son contenido editable y llegan por su propia ruta, no por `config`. El
   * TTL del token, los días de aviso y la exigencia de correo verificado son del backend y no
   * forman parte de este contrato. Con el módulo apagado llegan los defaults y el BFF responde 404.
   */
  memberships: MembershipsConfig;
  /**
   * Landings editoriales (módulo `landings`): el registro y el contenido de cada una. Vacío con
   * el módulo apagado o sin landings.
   */
  landings: {
    /** Texto `identificador | Nombre | article|person`, una landing por línea. */
    registry: string;
    /** Contenido por identificador. */
    editorial: Record<string, EditorialLandingConfig | undefined>;
  };
  /** Medios de pago que ofrece el checkout (lo secreto va por el canal interno). */
  payments: {
    gateway_enabled: boolean;
    whatsapp_enabled: boolean;
    cash_enabled: boolean;
  };
  integrations: {
    analytics_provider: string;
    analytics_id: string;
    gtm_id: string;
    meta_pixel_id: string;
    tiktok_pixel_id: string;
    hubspot_portal_id: string;
  };
  seo: {
    title_template: string;
    robots: string;
    google_site_verification: string;
    /** Código de la meta `msvalidate.01` de Bing Webmaster Tools. */
    bing_site_verification: string;
    /** 'auto' = OG/iconos generados desde la marca · 'custom' = usar seo.og_image. */
    default_og: string;
    /** URL de OG cuando default_og es 'custom'. */
    og_image: string;
    /** Datos estructurados de producto (todos opcionales; vacío = no emitir). */
    product_brand: string;
    shipping_amount: string;
    return_days: string;
    /** 'finite' | 'unlimited' | 'none' */
    return_category: string;
    /** '' = no declarar · 'mail' | 'in_store' | 'mail_and_store' */
    return_method: string;
    /** '' = no declarar · 'free' | 'customer_pays' */
    return_fees: string;
    /** «mín-máx» o número. Vacío = no declarar. */
    handling_days: string;
    transit_days: string;
    /** Otros nombres de la marca, uno por línea o con comas. */
    alternate_names: string;
    /** Logo para el schema Organization. Vacío = icono dinámico de marca. */
    organization_logo: string;
    /** Fecha de fundación ISO 8601 (Organization.foundingDate). */
    founding_date: string;
    /** Temas de experiencia (knowsAbout), uno por línea o separados por comas. */
    knows_about: string;
    /** Responsable/fundador (Person en /about). Vacío = no se publica. */
    founder_name: string;
    founder_role: string;
    founder_url: string;
    /** Tipo schema.org de cada sede (`LocalBusiness` por defecto). */
    business_type: string;
  };
  geo: Translations<"faq" | "faq_payments"> & {
    /** 'allow' | 'search_only' | 'block' */
    ai_crawlers: string;
    llms_txt_enabled: boolean;
    /** Líneas "pregunta | respuesta". */
    faq: string;
    /** Preguntas sobre pagos. Vacío = una sola columna. */
    faq_payments: string;
    /** Emitir directiva Content-Signal en robots.txt. */
    content_signal: boolean;
  };
  /**
   * Módulos opcionales (id → activo), calculados en WordPress según si cada
   * plugin del catálogo está activo. Es la fuente de verdad para gatear la UI
   * y las rutas BFF de cada módulo. Ver `lib/config/modules.ts`.
   */
  modules: Record<ModuleId, boolean>;
  /** Ajustes propios de la instancia (grupo `instance` del Control Center). Vacío en el núcleo. */
  instance: InstanceConfig;
}

/** Una sección de contenido (`s1`…`s5`) de una landing editorial en la config pública. */
export interface EditorialSectionConfig {
  enabled?: boolean;
  order?: string;
  layout?: string;
  kicker?: string | null;
  title?: string | null;
  body?: string | null;
  image?: string | null;
  image_alt?: string | null;
  image_2?: string | null;
  image_2_alt?: string | null;
  /** `<campo>_<idioma>`: la variante traducida de cada texto. */
  [translation: string]: unknown;
}

/**
 * El contenido de una landing editorial tal como llega de `GET /wp-json/hwe/v1/config`
 * (`landings.editorial.<id>`). Todo es opcional: una landing recién creada no tiene nada.
 * Los textos traducibles llevan además `<campo>_<idioma>`.
 */
export interface EditorialLandingConfig {
  published?: boolean;
  url?: {
    /** `area` | `root`. */
    base?: string | null;
    path?: string | null;
    /** `path_<idioma>`: el tramo en cada idioma secundario. */
    [pathByLocale: string]: string | null | undefined;
  } | null;
  accent?: string | null;
  product?: string | null;
  product_cta_label?: string | null;
  seo?: {
    title?: string | null;
    description?: string | null;
    og_image?: string | null;
    date_published?: string | null;
    date_modified?: string | null;
    author?: string | null;
    author_landing?: string | null;
    [translation: string]: unknown;
  } | null;
  lead_enabled?: boolean;
  lead_body?: string | null;
  sections?: Partial<
    Record<"s1" | "s2" | "s3" | "s4" | "s5", EditorialSectionConfig>
  > | null;
  quote?: {
    enabled?: boolean;
    text?: string | null;
    author?: string | null;
    author_photo?: string | null;
    background_image?: string | null;
    logo?: string | null;
    logo_alt?: string | null;
    [translation: string]: unknown;
  } | null;
  faq?: {
    enabled?: boolean;
    title?: string | null;
    [slotOrTranslation: string]: unknown;
  } | null;
  products?: {
    enabled?: boolean;
    title?: string | null;
    after?: string | null;
    mode?: string | null;
    category?: string | null;
    manual?: string | null;
    [translation: string]: unknown;
  } | null;
  closing?: {
    enabled?: boolean;
    title?: string | null;
    text?: string | null;
    cta_label?: string | null;
    cta_href?: string | null;
    cta2_label?: string | null;
    cta2_href?: string | null;
    background_image?: string | null;
    [translation: string]: unknown;
  } | null;
  /** `<campo>_<idioma>` de los campos de primer nivel. */
  [translation: string]: unknown;
}

/**
 * Configuración pública del módulo `loyalty` (spec §9.1). Todos los campos numéricos viajan
 * como texto, como el resto de campos `number` del Control Center.
 */
export interface LoyaltyConfig {
  /** Enciende el monedero de puntos (ganar, canjear y transferir). */
  points_enabled: boolean;
  /** Comisiones y puntos por alta de referidos; exige puntos activos. */
  referrals_enabled: boolean;
  /** Nombre de la unidad en singular (por defecto «punto»). */
  currency_name: string;
  /** Nombre de la unidad en plural (por defecto «puntos»). */
  currency_name_plural: string;
  /** URL del icono de la unidad. Vacío = sin icono. */
  currency_icon: string;
  /** Valor de 1 unidad en la moneda de la tienda (decimal como texto). */
  point_value: string;
  /** Mínimo de unidades para canjear (0 = sin mínimo). */
  min_redeem: string;
  /** Tope de unidades por pedido (0 = sin tope). */
  max_redeem_per_order: string;
  /**
   * Tope del descuento por canje como porcentaje de la base sin envío (productos + fees
   * ajenos), entero 0-100. `0` = sin tope. El descuento se convierte a unidades hacia abajo.
   */
  max_redeem_percent: string;
  /** Permite que el canje cubra el pedido completo (envío incluido). */
  full_payment_enabled: boolean;
  /** Importe que debe quedar por pagar en dinero con el pago completo apagado. */
  min_cash_due: string;
  /** Días de caducidad de los abonos (0 = no caducan). */
  expiry_days: string;
  /** Devuelve un porcentaje de la compra al comprador. */
  cashback_enabled: boolean;
  cashback_percent: string;
  /** Unidades fijas por evento (0 = apagado). */
  events: {
    registration: string;
    review: string;
    birthday: string;
  };
  referral_signup_points_l1: string;
  referral_signup_points_l2: string;
  commission_first_percent: string;
  commission_next_percent: string;
  level2_enabled: boolean;
  commission_level2_percent: string;
  /** Días desde la atribución en los que se paga comisión (0 = sin límite). */
  commission_duration_days: string;
  transfers_enabled: boolean;
  transfer_min: string;
  transfer_fee_percent: string;
  /** Tope diario de unidades transferidas (0 = sin tope). */
  transfer_daily_cap: string;
}

/**
 * Configuración pública del módulo `memberships` (spec §10.1). Solo los campos que necesita el
 * frontend para pintar y verificar el carné; el resto de ajustes son operativos del backend.
 */
export interface MembershipsConfig {
  /** Enciende el carné digital con su verificación por QR. */
  card_enabled: boolean;
  /** Ruta del frontend a la que apunta el QR del carné (por defecto `/verify`). */
  card_verify_path: string;
  /** Texto corto que precede al número de socio. Vacío = sin prefijo. */
  member_number_prefix: string;
}

/** Ids del catálogo de módulos (spec §4.1). Debe coincidir con `Modules::CORE_IDS` en PHP. */
export type ModuleId =
  | "banners"
  | "shipping"
  | "product-landing"
  | "landings"
  | "consent-log"
  | "site-reviews"
  | "coupon-rules"
  | "customer-identity"
  | "external-integration"
  | "loyalty"
  | "memberships";

/**
 * Ajustes propios de la instancia. Vacía en el framework: cada instancia la
 * amplía por *module augmentation* en `src/instance/config.d.ts`.
 */
// eslint-disable-next-line @typescript-eslint/no-empty-object-type
export interface InstanceConfig {}

/** Esquema de color del sitio (`design.color_scheme`). */
export type ColorScheme = "system" | "light" | "dark";

/** Una sede del grupo `legal.locations`, tal como llega de la config pública. */
export interface SiteConfigLocation {
  label: string;
  address: string;
  city: string;
  region: string;
  postal_code: string;
  maps: string;
  phone: string;
  hours: string;
  latitude: string;
  longitude: string;
}
