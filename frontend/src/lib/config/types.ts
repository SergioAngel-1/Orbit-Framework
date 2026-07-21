/** Configuración pública de la plantilla, tal como la expone /wp-json/hwe/v1/config. */
export interface SiteConfig {
  brand: {
    name: string;
    tagline: string;
    description: string;
    url: string;
    /** URL del logo de cabecera. Vacío = mostrar el nombre del sitio como texto. */
    logo: string;
    locale: string;
    og_image: string;
  };
  social: {
    twitter: string;
    instagram: string;
    facebook: string;
    linkedin: string;
    youtube: string;
    wikipedia: string;
    wikidata: string;
  };
  legal: {
    company: string;
    nif: string;
    email: string;
    address: string;
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
  };
  banners: {
    /** Activa el renderizado de banners en el frontend. La autoría vive en el plugin HWE Banners. */
    enabled: boolean;
  };
  integrations: {
    analytics_provider: string;
    analytics_id: string;
  };
  seo: {
    title_template: string;
    robots: string;
    google_site_verification: string;
    /** 'auto' = OG/iconos generados desde la marca · 'custom' = usar brand.og_image. */
    default_og: string;
    /** Datos estructurados de producto (todos opcionales; vacío = no emitir). */
    product_brand: string;
    shipping_amount: string;
    return_days: string;
    /** 'finite' | 'unlimited' | 'none' */
    return_category: string;
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
  geo: {
    /** 'allow' | 'search_only' | 'block' */
    ai_crawlers: string;
    llms_txt_enabled: boolean;
    /** Líneas "pregunta | respuesta". */
    faq: string;
    /** Emitir directiva Content-Signal en robots.txt. */
    content_signal: boolean;
  };
}
