// Configuración global de la aplicación

// URL base de la API de WordPress
export const API_URL = import.meta.env.VITE_WP_API_URL || 'http://admin.starter.local';

// Claves de WooCommerce
export const WC_CONSUMER_KEY = import.meta.env.VITE_WC_CONSUMER_KEY || '';
export const WC_CONSUMER_SECRET = import.meta.env.VITE_WC_CONSUMER_SECRET || '';

// Configuración de paginación
export const ITEMS_PER_PAGE = 12;

// Configuración de timeouts para peticiones
export const API_TIMEOUT = 10000; // 10 segundos

// URLs de redes sociales — se sobrescriben dinámicamente desde SiteConfig
// Estos valores son fallback si el backend no está disponible
export const SOCIAL_MEDIA = {
  facebook: '',
  instagram: '',
  whatsapp: ''
};

// Configuración de mapas
export const MAP_API_KEY = import.meta.env.VITE_MAP_API_KEY || '';

// Límites de la aplicación
export const MAX_ADDRESSES = 3;
export const MIN_AGE = 18;

// Configuración de imágenes — URL placeholder para productos/banners sin imagen
export const DEFAULT_IMAGE = 'https://placehold.co/400x400/e2e8f0/64748b?text=No+Image';
export const BANNER_SIZES = {
  desktop: { width: 1920, height: 500 },
  mobile: { width: 768, height: 400 }
};

// Configuración de moneda — se lee dinámicamente desde SiteConfig (formatters.ts)
// Estos valores son fallback si el backend no está disponible
export const CURRENCY = {
  code: 'USD',
  symbol: '$',
  decimals: 2
};
