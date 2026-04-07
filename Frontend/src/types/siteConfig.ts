/**
 * Tipos TypeScript para la configuración del sitio
 * Mapea la respuesta de GET /site-settings/v1/config
 */

export interface SiteIdentity {
  site_name: string;
  site_short_name: string;
  site_tagline: string;
  site_description: string;
}

export interface SiteUrls {
  frontend_url: string;
}

export interface SiteContact {
  contact_email: string;
  contact_phone: string;
  contact_whatsapp: string;
}

export interface SiteSocial {
  social_facebook: string;
  social_instagram: string;
  social_tiktok: string;
  social_twitter: string;
}

export type ColorSource = 'primary' | 'secondary' | 'accent';
export type ColorMode = 'darken' | 'lighten';

export interface SiteBranding {
  branding_primary_color: string;
  branding_secondary_color: string;
  branding_accent_color: string;

  // Variaciones de color derivadas
  branding_dark_source: ColorSource;
  branding_dark_mode: ColorMode;
  branding_dark_amount: number;
  branding_light_source: ColorSource;
  branding_light_mode: ColorMode;
  branding_light_amount: number;
  branding_text_source: ColorSource;
  branding_text_mode: ColorMode;
  branding_text_amount: number;
  branding_hover_source: ColorSource;
  branding_hover_mode: ColorMode;
  branding_hover_amount: number;
  branding_border_source: ColorSource;
  branding_border_mode: ColorMode;
  branding_border_amount: number;

  branding_font: string;
  branding_logo: string;
  branding_favicon: string;
  branding_og_image: string;
  branding_loader: string;
}

export interface SiteCurrency {
  currency_code: string;
  currency_symbol: string;
  currency_decimals: number;
  currency_locale: string;
  currency_rounding_multiple: number;
}

export interface SiteGeo {
  geo_country: string;
  geo_region: string;
  geo_timezone: string;
}

export interface SiteVirtualCurrency {
  virtual_currency_name: string;
  virtual_currency_short: string;
  virtual_currency_icon: string;
  virtual_currency_image_front: string;
  virtual_currency_image_back: string;
  virtual_currency_conversion_rate: number;
}

export interface SiteLimits {
  limits_max_addresses: number;
  limits_min_age: number;
  limits_items_per_page: number;
}

export interface SiteSeo {
  seo_title_suffix: string;
  seo_default_keywords: string;
  seo_author: string;
}

export interface SitePayments {
  payment_gateway: string;
}

export interface SiteFeatures {
  memberships: boolean;
  referrals_points: boolean;
  home_sections: boolean;
  woocommerce: boolean;
}

export interface SiteConfig {
  identity: SiteIdentity;
  urls: SiteUrls;
  contact: SiteContact;
  social: SiteSocial;
  branding: SiteBranding;
  currency: SiteCurrency;
  geo: SiteGeo;
  virtual_currency: SiteVirtualCurrency;
  limits: SiteLimits;
  seo: SiteSeo;
  payments: SitePayments;
  features: SiteFeatures;
}

/**
 * Valores por defecto para desarrollo local (sin backend)
 */
export const DEFAULT_SITE_CONFIG: SiteConfig = {
  identity: {
    site_name: 'Mi Tienda',
    site_short_name: 'Mi Tienda',
    site_tagline: 'Tu tienda online',
    site_description: 'La mejor tienda online con membresías y beneficios exclusivos.',
  },
  urls: {
    frontend_url: 'https://example.com',
  },
  contact: {
    contact_email: 'info@example.com',
    contact_phone: '+1 000 000 0000',
    contact_whatsapp: 'https://wa.me/10000000000',
  },
  social: {
    social_facebook: '',
    social_instagram: '',
    social_tiktok: '',
    social_twitter: '',
  },
  branding: {
    branding_primary_color: '#16a34a',
    branding_secondary_color: '#FF6B35',
    branding_accent_color: '#8FD8B9',

    branding_dark_source: 'primary',
    branding_dark_mode: 'darken',
    branding_dark_amount: 25,
    branding_light_source: 'primary',
    branding_light_mode: 'lighten',
    branding_light_amount: 8,
    branding_text_source: 'primary',
    branding_text_mode: 'darken',
    branding_text_amount: 35,
    branding_hover_source: 'primary',
    branding_hover_mode: 'darken',
    branding_hover_amount: 10,
    branding_border_source: 'secondary',
    branding_border_mode: 'lighten',
    branding_border_amount: 30,

    branding_font: 'Poppins',
    branding_logo: '',
    branding_favicon: '',
    branding_og_image: '',
    branding_loader: '',
  },
  currency: {
    currency_code: 'USD',
    currency_symbol: '$',
    currency_decimals: 2,
    currency_locale: 'en-US',
    currency_rounding_multiple: 1,
  },
  geo: {
    geo_country: 'US',
    geo_region: 'United States',
    geo_timezone: 'America/New_York',
  },
  virtual_currency: {
    virtual_currency_name: 'Points',
    virtual_currency_short: 'PTS',
    virtual_currency_icon: '⭐',
    virtual_currency_image_front: '',
    virtual_currency_image_back: '',
    virtual_currency_conversion_rate: 0.01,
  },
  limits: {
    limits_max_addresses: 3,
    limits_min_age: 18,
    limits_items_per_page: 12,
  },
  seo: {
    seo_title_suffix: '| Mi Tienda',
    seo_default_keywords: '',
    seo_author: 'Mi Tienda',
  },
  payments: {
    payment_gateway: 'wompi',
  },
  features: {
    memberships: false,
    referrals_points: false,
    home_sections: false,
    woocommerce: true,
  },
};
