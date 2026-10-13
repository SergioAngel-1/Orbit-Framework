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
  brand: Translations<"tagline" | "description"> & {
    name: string;
    tagline: string;
    description: string;
    url: string;
    /** URL del logo de cabecera. Vacío = mostrar el nombre del sitio como texto. */
    logo: string;
    /** Variante del logo para correos. Vacío = usa el logo de cabecera. */
    email_logo: string;
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
    locations: {
      location_1: SiteConfigLocation;
      location_2: SiteConfigLocation;
      location_3: SiteConfigLocation;
      location_4: SiteConfigLocation;
    };
  };
  design: {
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
  | "external-integration";

/**
 * Ajustes propios de la instancia. Vacía en el framework: cada instancia la
 * amplía por *module augmentation* en `src/instance/config.d.ts`.
 */
// eslint-disable-next-line @typescript-eslint/no-empty-object-type
export interface InstanceConfig {}

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
