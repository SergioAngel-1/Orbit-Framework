import React, { createContext, useContext, useEffect, useState, useCallback, useRef } from 'react';
import { SiteConfig, DEFAULT_SITE_CONFIG } from '../types/siteConfig';
import { baseApiUrl } from '../services/apiConfig';
import logger from '../utils/logger';
import i18n from '../config/i18n';

interface SiteConfigContextType {
  config: SiteConfig;
  loading: boolean;
  error: string | null;
  refetch: () => Promise<void>;
}

const SiteConfigContext = createContext<SiteConfigContextType>({
  config: DEFAULT_SITE_CONFIG,
  loading: true,
  error: null,
  refetch: async () => {},
});

/**
 * Oscurece un color hex por un porcentaje dado (para hover states)
 */
function darkenColor(hex: string, percent: number): string {
  const num = parseInt(hex.replace('#', ''), 16);
  const r = Math.max(0, (num >> 16) - Math.round(255 * (percent / 100)));
  const g = Math.max(0, ((num >> 8) & 0x00FF) - Math.round(255 * (percent / 100)));
  const b = Math.max(0, (num & 0x0000FF) - Math.round(255 * (percent / 100)));
  return `#${(r << 16 | g << 8 | b).toString(16).padStart(6, '0')}`;
}

/**
 * Aclara un color hex mezclándolo con blanco por un factor dado (0-1)
 */
function lightenColor(hex: string, factor: number): string {
  const num = parseInt(hex.replace('#', ''), 16);
  const r = Math.min(255, Math.round((num >> 16) + (255 - (num >> 16)) * (1 - factor)));
  const g = Math.min(255, Math.round(((num >> 8) & 0x00FF) + (255 - ((num >> 8) & 0x00FF)) * (1 - factor)));
  const b = Math.min(255, Math.round((num & 0x0000FF) + (255 - (num & 0x0000FF)) * (1 - factor)));
  return `#${(r << 16 | g << 8 | b).toString(16).padStart(6, '0')}`;
}

/**
 * Inyecta CSS custom properties en :root basándose en la configuración del sitio.
 * Esto permite que Tailwind y los estilos CSS consuman colores/fuentes dinámicos.
 */
function injectCSSVariables(branding: SiteConfig['branding']): void {
  const root = document.documentElement;
  const primary = branding.branding_primary_color || DEFAULT_SITE_CONFIG.branding.branding_primary_color;
  const secondary = branding.branding_secondary_color || DEFAULT_SITE_CONFIG.branding.branding_secondary_color;
  const font = branding.branding_font || DEFAULT_SITE_CONFIG.branding.branding_font;

  // Colores principales
  root.style.setProperty('--color-primary', primary);
  root.style.setProperty('--color-secondary', secondary);
  root.style.setProperty('--color-primary-dark', darkenColor(primary, 15));
  root.style.setProperty('--color-primary-light', lightenColor(primary, 0.15));
  root.style.setProperty('--color-hover', darkenColor(primary, 10));

  // Colores semánticos derivados (mapeo a las variables existentes del CSS)
  root.style.setProperty('--primario', primary);
  root.style.setProperty('--secundario', secondary);
  root.style.setProperty('--oscuro', darkenColor(primary, 25));
  root.style.setProperty('--claro', lightenColor(primary, 0.08));
  root.style.setProperty('--texto', darkenColor(primary, 35));
  root.style.setProperty('--hover', darkenColor(primary, 10));
  root.style.setProperty('--border', lightenColor(secondary, 0.3));

  // Fuente
  root.style.setProperty('--font-family', `'${font}', system-ui, sans-serif`);
  root.style.setProperty('font-family', `'${font}', system-ui, sans-serif`);
}

/**
 * Inyecta dinámicamente el <link> de Google Fonts si la fuente cambió
 */
function injectGoogleFont(font: string): void {
  const fontId = 'site-config-google-font';
  const existing = document.getElementById(fontId);
  const fontUrl = `https://fonts.googleapis.com/css2?family=${encodeURIComponent(font)}:wght@300;400;500;600;700&display=swap`;

  if (existing) {
    // Actualizar si cambió
    if (existing.getAttribute('href') !== fontUrl) {
      existing.setAttribute('href', fontUrl);
    }
  } else {
    const link = document.createElement('link');
    link.id = fontId;
    link.rel = 'stylesheet';
    link.href = fontUrl;
    document.head.appendChild(link);
  }
}

/**
 * Actualiza el theme-color meta tag
 */
function updateThemeColor(color: string): void {
  let meta = document.querySelector('meta[name="theme-color"]') as HTMLMetaElement | null;
  if (meta) {
    meta.content = color;
  }
}

const CACHE_KEY = 'site_config_cache';
const CACHE_TTL = 5 * 60 * 1000; // 5 minutos

interface CachedConfig {
  data: SiteConfig;
  timestamp: number;
}

function getCachedConfig(): SiteConfig | null {
  try {
    const cached = localStorage.getItem(CACHE_KEY);
    if (!cached) return null;
    const parsed: CachedConfig = JSON.parse(cached);
    if (Date.now() - parsed.timestamp > CACHE_TTL) {
      localStorage.removeItem(CACHE_KEY);
      return null;
    }
    return parsed.data;
  } catch {
    return null;
  }
}

function setCachedConfig(data: SiteConfig): void {
  try {
    const cached: CachedConfig = { data, timestamp: Date.now() };
    localStorage.setItem(CACHE_KEY, JSON.stringify(cached));
  } catch {
    // Silently fail if localStorage is full
  }
}

export const SiteConfigProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [config, setConfig] = useState<SiteConfig>(() => getCachedConfig() || DEFAULT_SITE_CONFIG);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const initializedRef = useRef(false);

  const fetchConfig = useCallback(async () => {
    try {
      setError(null);
      const response = await fetch(`${baseApiUrl}/wp-json/site-settings/v1/config`);
      
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }

      const data = await response.json();
      
      // Validar que la respuesta tenga la estructura esperada
      if (data && typeof data === 'object' && data.identity) {
        setConfig(data as SiteConfig);
        setCachedConfig(data as SiteConfig);
        logger.info('SiteConfig', 'Configuración cargada exitosamente');
      } else {
        logger.warn('SiteConfig', 'Respuesta con estructura inesperada, usando defaults');
      }
    } catch (err: any) {
      const message = err?.message || 'Error desconocido al cargar configuración';
      logger.error('SiteConfig', 'Error al cargar configuración:', message);
      setError(message);
      // Mantener cached o defaults — la app sigue funcionando
    } finally {
      setLoading(false);
    }
  }, []);

  // Fetch inicial
  useEffect(() => {
    if (!initializedRef.current) {
      initializedRef.current = true;
      fetchConfig();
    }
  }, [fetchConfig]);

  // Sincronizar siteName en i18next para interpolación {{siteName}} global
  useEffect(() => {
    const name = config.identity.site_name || DEFAULT_SITE_CONFIG.identity.site_name;
    if (i18n.options.interpolation?.defaultVariables) {
      i18n.options.interpolation.defaultVariables.siteName = name;
    }
  }, [config.identity.site_name]);

  // Inyectar CSS variables cada vez que cambie el branding
  useEffect(() => {
    const font = config.branding.branding_font || DEFAULT_SITE_CONFIG.branding.branding_font;
    injectCSSVariables(config.branding);
    injectGoogleFont(font);
    updateThemeColor(config.branding.branding_primary_color || DEFAULT_SITE_CONFIG.branding.branding_primary_color);
  }, [config.branding]);

  return (
    <SiteConfigContext.Provider value={{ config, loading, error, refetch: fetchConfig }}>
      {children}
    </SiteConfigContext.Provider>
  );
};

/**
 * Hook para acceder a la configuración del sitio
 */
export function useSiteConfig(): SiteConfigContextType {
  return useContext(SiteConfigContext);
}

/**
 * Acceso directo a secciones comunes
 */
export function useBranding() {
  const { config } = useSiteConfig();
  return config.branding;
}

export function useIdentity() {
  const { config } = useSiteConfig();
  return config.identity;
}

export function useSiteCurrency() {
  const { config } = useSiteConfig();
  return config.currency;
}

export function useSiteFeatures() {
  const { config } = useSiteConfig();
  return config.features;
}

export function useVirtualCurrency() {
  const { config } = useSiteConfig();
  return config.virtual_currency;
}

export default SiteConfigContext;
