/** Configuración pública de la plantilla, tal como la expone /wp-json/hwe/v1/config. */
export interface SiteConfig {
  brand: {
    name: string;
    tagline: string;
    description: string;
    url: string;
    locale: string;
    og_image: string;
  };
  social: {
    twitter: string;
    instagram: string;
    facebook: string;
    linkedin: string;
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
      background: string;
      foreground: string;
    };
    typography: {
      font_sans: string;
      font_url: string;
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
  integrations: {
    analytics_provider: string;
    analytics_id: string;
  };
  seo: {
    title_template: string;
    robots: string;
    google_site_verification: string;
  };
}
