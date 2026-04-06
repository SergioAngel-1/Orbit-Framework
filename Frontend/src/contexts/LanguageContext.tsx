/**
 * LanguageContext - Fuente única de verdad para el estado del idioma
 * 
 * Responsabilidades:
 * 1. Detectar idioma desde la URL al montar (/en/... → EN, todo lo demás → ES)
 * 2. Sincronizar i18next cuando cambia el idioma
 * 3. Sincronizar la URL cuando se cambia de idioma (agrega/quita /en/)
 * 4. Proveer helpers: currentLang, switchLanguage(), localizedPath()
 * 
 * Estrategia de URLs:
 * - ES (default): sin prefijo → /catalogo, /contacto
 * - EN: con prefijo → /en/catalogo, /en/contacto
 * 
 * Uso en componentes:
 *   const { currentLang, switchLanguage, localizedPath } = useLanguage();
 *   // Para traducciones de texto, seguir usando:
 *   const { t } = useTranslation('namespace');
 */
import { createContext, useContext, useCallback, useEffect, useMemo, type ReactNode } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { cacheManager } from '../services/query/cacheManager';
import { getCachedMembershipLevels } from '../hooks/useMembershipLevels';
import { translateMembershipSlug, isKnownMembershipSlug } from '../utils/membershipRouteUtils';

// --- Constantes ---
export const SUPPORTED_LANGS = ['es', 'en'] as const;
export const DEFAULT_LANG = 'es';
export const NON_DEFAULT_LANGS = SUPPORTED_LANGS.filter(l => l !== DEFAULT_LANG);
const LANG_STORAGE_KEY = 'flores-preferred-lang';

export type SupportedLang = typeof SUPPORTED_LANGS[number];

// --- Mapa de traducción de rutas (ES ↔ EN) ---
// Las claves son los slugs en ES (idioma default), los valores son los slugs en EN.
// Solo se traducen las páginas estáticas. Las rutas dinámicas (/catalogo/:slug/:slug) mantienen sus slugs.
const ROUTE_TRANSLATIONS: Record<string, string> = {
  '/catalogo/producto': '/catalog/product',
  '/catalogo': '/catalog',
  '/membresias': '/memberships',
  '/contacto': '/contact',
  '/toures': '/tours',
  '/reserva': '/cart',
  '/finalizar-retiro': '/checkout',
  '/invitados': '/referrals',
  '/fondo-de-aportes': '/wallet',
  '/privacidad': '/privacy',
  '/terminos': '/terms',
  '/guia-requisa': '/search-guide',
  '/marco-legal': '/legal-framework',
  '/politica-invitados': '/referral-policy',
  '/registrarse': '/register',
  '/iniciar-sesion': '/login',
  '/verificar-socio': '/verify-member',
};

// Mapa inverso: EN → ES (generado automáticamente)
const ROUTE_TRANSLATIONS_REVERSE: Record<string, string> = Object.fromEntries(
  Object.entries(ROUTE_TRANSLATIONS).map(([es, en]) => [en, es])
);

/**
 * Traduce un path de ES → EN.
 * Busca el segmento inicial más largo que coincida con una ruta traducible.
 * '/catalogo/flores/producto-x' → '/catalog/flores/producto-x'
 * '/catalogo/membresia-bronce' → '/catalog/bronze-membership'
 * '/membresias' → '/memberships'
 * '/faq' → '/faq' (sin traducción, se mantiene igual)
 */
function translatePathToEN(esPath: string): string {
  // Buscar coincidencia exacta primero
  if (ROUTE_TRANSLATIONS[esPath]) return ROUTE_TRANSLATIONS[esPath];
  // Buscar coincidencia por prefijo (para rutas con subrutas dinámicas)
  for (const [esSlug, enSlug] of Object.entries(ROUTE_TRANSLATIONS)) {
    if (esPath.startsWith(esSlug + '/')) {
      const rest = esPath.slice(esSlug.length);
      // Traducir slugs de membresía en rutas de catálogo
      const translatedRest = (esSlug === '/catalogo') ? translateMembershipSlugsInPath(rest, 'en') : rest;
      return enSlug + translatedRest;
    }
  }
  return esPath;
}

/**
 * Traduce un path de EN → ES (inverso).
 * '/catalog/flores/producto-x' → '/catalogo/flores/producto-x'
 * '/catalog/bronze-membership' → '/catalogo/membresia-bronce'
 */
function translatePathToES(enPath: string): string {
  if (ROUTE_TRANSLATIONS_REVERSE[enPath]) return ROUTE_TRANSLATIONS_REVERSE[enPath];
  for (const [enSlug, esSlug] of Object.entries(ROUTE_TRANSLATIONS_REVERSE)) {
    if (enPath.startsWith(enSlug + '/')) {
      const rest = enPath.slice(enSlug.length);
      // Traducir slugs de membresía en rutas de catálogo
      const translatedRest = (esSlug === '/catalogo') ? translateMembershipSlugsInPath(rest, 'es') : rest;
      return esSlug + translatedRest;
    }
  }
  return enPath;
}

/**
 * Traduce slugs de membresía dentro de una sub-ruta de catálogo.
 * Solo traduce el primer segmento si es un slug de membresía conocido.
 * Ej: '/membresia-bronce/categoria/producto' → '/bronze-membership/categoria/producto'
 */
function translateMembershipSlugsInPath(subPath: string, targetLang: string): string {
  const levels = getCachedMembershipLevels();
  if (levels.length === 0) return subPath;
  
  // subPath empieza con '/', ej: '/membresia-bronce' o '/membresia-bronce/categoria'
  const segments = subPath.split('/');
  // segments[0] es '' (antes del primer /), segments[1] es el primer segmento real
  if (segments.length < 2 || !segments[1]) return subPath;
  
  const firstSegment = segments[1];
  if (isKnownMembershipSlug(firstSegment, levels)) {
    segments[1] = translateMembershipSlug(firstSegment, targetLang, levels);
    return segments.join('/');
  }
  
  return subPath;
}

// --- Helpers puros (sin hooks, exportables para uso fuera de React) ---

/**
 * Extrae el idioma y el path limpio (en ES canónico) desde un pathname.
 * '/en/catalog' → { lang: 'en', pathWithoutLang: '/catalogo' }
 * '/en/catalog/flores' → { lang: 'en', pathWithoutLang: '/catalogo/flores' }
 * '/catalogo'  → { lang: 'es', pathWithoutLang: '/catalogo' }
 */
export function getLangFromPath(pathname: string): { lang: SupportedLang; pathWithoutLang: string } {
  for (const lang of NON_DEFAULT_LANGS) {
    const prefix = `/${lang}`;
    if (pathname === prefix || pathname.startsWith(`${prefix}/`)) {
      const rawPath = pathname.slice(prefix.length) || '/';
      // Traducir de EN → ES para obtener el path canónico
      const pathWithoutLang = translatePathToES(rawPath);
      return { lang: lang as SupportedLang, pathWithoutLang };
    }
  }
  return { lang: DEFAULT_LANG, pathWithoutLang: pathname };
}

/**
 * Construye un path con el prefijo de idioma correcto y slugs traducidos.
 * buildLocalizedPath('/catalogo', 'en') → '/en/catalog'
 * buildLocalizedPath('/catalogo', 'es') → '/catalogo'
 * buildLocalizedPath('/catalogo/flores/producto-x', 'en') → '/en/catalog/flores/producto-x'
 * 
 * IMPORTANTE: El path de entrada siempre debe estar en ES (forma canónica).
 * Todos los localizedPath() en componentes ya pasan paths en ES.
 */
export function buildLocalizedPath(path: string, lang: SupportedLang): string {
  if (!path.startsWith('/')) return path;
  // No duplicar prefijo si ya lo tiene
  for (const l of NON_DEFAULT_LANGS) {
    if (path === `/${l}` || path.startsWith(`/${l}/`)) {
      return path;
    }
  }
  if (lang === DEFAULT_LANG) {
    return path;
  }
  // Traducir slugs de ES → EN y agregar prefijo
  const translatedPath = translatePathToEN(path);
  return `/${lang}${translatedPath}`;
}

// --- Context ---

interface LanguageContextType {
  /** Idioma actual ('es' | 'en') */
  currentLang: SupportedLang;
  /** true si el idioma actual es el default (ES) */
  isDefaultLang: boolean;
  /** Prefijo de URL: '' para ES, '/en' para EN */
  langPrefix: string;
  /** Cambia el idioma y navega a la URL equivalente */
  switchLanguage: (lang: SupportedLang) => void;
  /** Agrega el prefijo de idioma actual a un path */
  localizedPath: (path: string) => string;
  /** Genera la URL actual pero en otro idioma */
  switchLanguagePath: (targetLang: SupportedLang) => string;
  /** Lista de idiomas soportados */
  supportedLanguages: typeof SUPPORTED_LANGS;
}

const LanguageContext = createContext<LanguageContextType | undefined>(undefined);

// --- Hook público ---

export function useLanguage(): LanguageContextType {
  const context = useContext(LanguageContext);
  if (!context) {
    throw new Error('useLanguage debe ser usado dentro de un LanguageProvider');
  }
  return context;
}

// --- Provider ---

interface LanguageProviderProps {
  children: ReactNode;
}

export const LanguageProvider: React.FC<LanguageProviderProps> = ({ children }) => {
  const { i18n } = useTranslation();
  const location = useLocation();
  const navigate = useNavigate();

  // Derivar idioma actual desde i18n (que ya fue sincronizado)
  const currentLang = (SUPPORTED_LANGS.includes(i18n.language as SupportedLang)
    ? i18n.language
    : DEFAULT_LANG) as SupportedLang;

  const langPrefix = currentLang === DEFAULT_LANG ? '' : `/${currentLang}`;
  const isDefaultLang = currentLang === DEFAULT_LANG;

  // Al montar: si la URL no tiene prefijo de idioma y hay preferencia guardada, redirigir
  useEffect(() => {
    const { lang: urlLang } = getLangFromPath(location.pathname);
    const savedLang = localStorage.getItem(LANG_STORAGE_KEY) as SupportedLang | null;
    if (
      savedLang &&
      SUPPORTED_LANGS.includes(savedLang) &&
      savedLang !== DEFAULT_LANG &&
      urlLang === DEFAULT_LANG
    ) {
      // pathWithoutLang ya está en ES canónico gracias a getLangFromPath
      const { pathWithoutLang: canonicalPath } = getLangFromPath(location.pathname);
      const targetPath = `${buildLocalizedPath(canonicalPath, savedLang)}${location.search}`;
      i18n.changeLanguage(savedLang);
      cacheManager.setLanguage(savedLang);
      navigate(targetPath, { replace: true });
      return;
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []); // Solo al montar

  // Sincronizar i18n desde la URL al navegar
  useEffect(() => {
    const { lang } = getLangFromPath(location.pathname);
    if (i18n.language !== lang) {
      i18n.changeLanguage(lang);
    }
  }, [location.pathname]); // i18n is a stable singleton, not needed as dependency

  // Cambiar idioma: actualiza i18n + navega a la URL con slugs traducidos
  const switchLanguage = useCallback((targetLang: SupportedLang) => {
    if (targetLang === currentLang) return;
    const { pathWithoutLang } = getLangFromPath(location.pathname);
    const search = location.search;
    const targetPath = `${buildLocalizedPath(pathWithoutLang, targetLang)}${search}`;
    i18n.changeLanguage(targetLang);
    localStorage.setItem(LANG_STORAGE_KEY, targetLang);
    cacheManager.setLanguage(targetLang);
    navigate(targetPath, { replace: true });
  }, [currentLang, location.pathname, location.search, i18n, navigate]);

  // Construir path localizado con el idioma actual
  const localizedPath = useCallback((path: string): string => {
    return buildLocalizedPath(path, currentLang);
  }, [currentLang]);

  // Generar la URL actual pero en otro idioma (sin navegar)
  const switchLanguagePath = useCallback((targetLang: SupportedLang): string => {
    const { pathWithoutLang } = getLangFromPath(location.pathname);
    const search = location.search;
    return `${buildLocalizedPath(pathWithoutLang, targetLang)}${search}`;
  }, [location.pathname, location.search]);

  const value = useMemo<LanguageContextType>(() => ({
    currentLang,
    isDefaultLang,
    langPrefix,
    switchLanguage,
    localizedPath,
    switchLanguagePath,
    supportedLanguages: SUPPORTED_LANGS,
  }), [currentLang, isDefaultLang, langPrefix, switchLanguage, localizedPath, switchLanguagePath]);

  return (
    <LanguageContext.Provider value={value}>
      {children}
    </LanguageContext.Provider>
  );
};

export default LanguageProvider;
