import React, { useEffect, useState, useCallback, Suspense } from 'react';
import { useTranslation } from 'react-i18next';
import i18n from '../config/i18n';
import { categoryService } from '../services/api';
import { bannerService } from '../services/api';
import { cacheManager } from '../services/query/cacheManager';
import { clearMembershipLevelsCache } from '../hooks/useMembershipLevels';
import PullToRefresh from '../components/ui/PullToRefresh';
import {
  HeroSection,
  MiddleBanner,
  ProductSections,
} from '../components/home';
import { useMembership } from '../contexts/MembershipContext';
import { useAuth } from '../contexts/AuthContext';
import { useLanguage } from '../contexts/LanguageContext';
import { HomeSectionsProvider, useHomeSectionsContext } from '../contexts/HomeSectionsContext';
import { useSEO } from '../hooks/useSEO';
import { OG_IMAGES, getBaseUrl } from '../utils/seo';
import logger from '../utils/logger';

// Below-the-fold: lazy load para reducir bundle inicial
const Benefits = React.lazy(() => import('../components/home/Benefits'));
const MembershipLevelsSection = React.lazy(() => import('../components/home/MembershipLevelsSection'));
const SocialNetworks = React.lazy(() => import('../components/home/SocialNetworks'));

// Inyectar CSS de animación una sola vez (singleton)
let spinStyleInjected = false;
const injectSpinStyle = () => {
  if (spinStyleInjected) return;
  spinStyleInjected = true;
  
  const style = document.createElement('style');
  style.id = 'home-spin-animation';
  style.textContent = `
    @keyframes spin {
      from { transform: rotate(0deg); }
      to { transform: rotate(360deg); }
    }
    .spin-slow { animation: spin 10s linear infinite; }
  `;
  document.head.appendChild(style);
};

/**
 * Contenido interno del HomePage que usa el contexto de secciones
 */
const HomePageContent = () => {
  const { t } = useTranslation('homePage');
  // SEO: Meta tags optimizados para indexación en Google (i18n-aware)
  useSEO({
    title: t('seo.title', { defaultValue: i18n.t('seo:pages.home.title') }),
    description: t('seo.description', { defaultValue: i18n.t('seo:pages.home.description') }),
    keywords: t('seo.keywords', { defaultValue: i18n.t('seo:pages.home.keywords') }),
    url: getBaseUrl(),
    type: 'website',
    image: OG_IMAGES.home,
    schema: {
      '@context': 'https://schema.org',
      '@type': 'WebSite',
      'name': t('seo.schemaName', { defaultValue: 'My Store' }),
      'alternateName': t('seo.schemaAlternateName', { defaultValue: '' }),
      'url': getBaseUrl(),
      'description': t('seo.schemaDescription', { defaultValue: i18n.t('seo:pages.home.description') }),
      'potentialAction': {
        '@type': 'SearchAction',
        'target': `${getBaseUrl()}/catalogo/buscar?q={search_term_string}`,
        'query-input': 'required name=search_term_string'
      }
    }
  });
  
  // Obtener nivel de membresía y versión para recargar datos cuando cambie
  // membershipVersion se incrementa cada vez que cambia el nivel, forzando recarga
  const { currentLevel, membershipVersion } = useMembership();
  
  // Obtener estado de autenticación para forzar recarga en logout
  const { isAuthenticated } = useAuth();
  const { currentLang } = useLanguage();
  
  // Obtener información sobre qué zonas tienen secciones desde el contexto compartido
  const { hasTopSections, hasMiddleSections, loading: sectionsLoading } = useHomeSectionsContext();
  
  const [banners, setBanners] = useState<any[]>([]);
  const [middleBanners, setMiddleBanners] = useState<any[]>([]);
  const [bannersLoading, setBannersLoading] = useState(true);
  const [bannersError, setBannersError] = useState<string | null>(null);
  const [featuredCategories, setFeaturedCategories] = useState<any[]>([]);
  const [featuredCategoriesLoading, setFeaturedCategoriesLoading] = useState(true);
  const [featuredCategoriesError, setFeaturedCategoriesError] = useState<string | null>(null);
  
  // Estado para forzar recarga desde Pull-to-Refresh
  const [refreshKey, setRefreshKey] = useState(0);

  // Cargar banners principales desde la API de WordPress
  // Se recarga cuando cambia el nivel de membresía O el estado de autenticación
  // Esto asegura que al hacer logout se recarguen los banners públicos
  // NOTA: NO bloqueamos por membershipLoading. Cargamos inmediatamente con el nivel
  // actual (0 si aún no se resuelve) y recargamos cuando membershipVersion cambie.
  // El backend filtra por JWT del usuario, así que siempre retorna datos correctos.
  useEffect(() => {
    const fetchBanners = async () => {
      try {
        setBannersLoading(true);
        logger.info('HomePage', `Obteniendo banners para nivel ${currentLevel} (auth: ${isAuthenticated})...`);

        // Usar el servicio de banners con reintentos automáticos
        // bannerService.getAll() ya retorna el array de banners directamente
        const bannerData = await bannerService.getAll();

        logger.info('HomePage', 'Banners obtenidos:', bannerData);
        
        // Filtrar banners por tipo
        if (bannerData && Array.isArray(bannerData)) {
          // Banners principales (carrusel hero)
          const mainBanners = bannerData.filter(banner => banner.type === 'main');
          setBanners(mainBanners);
          
          // Banners del medio
          const midBanners = bannerData.filter(banner => 
            banner.type === 'middle'
          );
          setMiddleBanners(midBanners);
        }
        
        setBannersError(null);
      } catch (error: any) {
        logger.error('HomePage', 'Error al cargar banners:', error);
        const errorMessage = error.message || i18n.t('errors:generic.loadBannersError');
        setBannersError(errorMessage);
      } finally {
        setBannersLoading(false);
      }
    };

    fetchBanners();
  }, [currentLevel, membershipVersion, isAuthenticated, refreshKey, currentLang]);

  // Cargar categorías destacadas - se recarga cuando cambia el nivel de membresía o autenticación
  // NOTA: NO bloqueamos por membershipLoading (misma razón que banners)
  useEffect(() => {
    const fetchFeaturedCategories = async () => {
      try {
        setFeaturedCategoriesLoading(true);
        logger.info('HomePage', `Obteniendo categorías destacadas para nivel ${currentLevel} (auth: ${isAuthenticated})...`);
        
        // Usar el servicio centralizado para categorías con reintentos
        const response = await categoryService.getFeatured();
        
        logger.info('HomePage', 'Categorías destacadas obtenidas:', response.data);
        
        if (response.data && Array.isArray(response.data)) {
          setFeaturedCategories(response.data);
        }
        
        setFeaturedCategoriesError(null);
      } catch (error: any) {
        logger.error('HomePage', 'Error al cargar categorías destacadas:', error);
        const errorMessage = error.message || i18n.t('errors:generic.loadCategoriesError');
        setFeaturedCategoriesError(errorMessage);
      } finally {
        setFeaturedCategoriesLoading(false);
      }
    };

    fetchFeaturedCategories();
  }, [currentLevel, membershipVersion, isAuthenticated, refreshKey, currentLang]);

  // Inyectar estilos CSS para la animación de rotación (una sola vez)
  useEffect(() => {
    injectSpinStyle();
  }, []);

  /**
   * Pull-to-Refresh: Invalida caché y recarga todos los datos
   * Diseñado para PWA donde no hay refresh nativo del navegador
   */
  const handlePullToRefresh = useCallback(async () => {
    logger.info('HomePage', '🔄 Pull-to-Refresh activado - invalidando caché completo');
    
    // Invalidar todo el caché (sin broadcast a otras pestañas, es acción local)
    cacheManager.clearAll(false);
    clearMembershipLevelsCache();
    
    // Forzar recarga de todos los datos incrementando refreshKey
    setRefreshKey(prev => prev + 1);
    
    // Pequeña espera para que los efectos se disparen
    await new Promise(resolve => setTimeout(resolve, 100));
    
    logger.info('HomePage', '✅ Pull-to-Refresh completado');
  }, []);

  return (
    <PullToRefresh
      onRefresh={handlePullToRefresh}
      pullText={t('pullToRefresh.pull')}
      releaseText={t('pullToRefresh.release')}
      refreshingText={t('pullToRefresh.refreshing')}
    >
      <div className="font-['Poppins'] bg-white">
        {/* Sección Hero: Carrusel de banners principales y categorías destacadas */}
        <HeroSection 
          banners={banners}
          bannersLoading={bannersLoading}
          bannersError={bannersError}
          featuredCategories={featuredCategories}
          featuredCategoriesLoading={featuredCategoriesLoading}
          featuredCategoriesError={featuredCategoriesError}
        />

        {/* Secciones superiores de productos configurables desde el admin */}
        <ProductSections.Top />

        {/* Banner intermedio: después de Top si hay secciones Top */}
        {!sectionsLoading && hasTopSections && (
          <MiddleBanner banners={middleBanners} />
        )}

        {/* Secciones intermedias de productos configurables desde el admin */}
        <ProductSections.Middle />

        {/* Banner intermedio: después de Middle si no hay Top pero hay Middle */}
        {!sectionsLoading && !hasTopSections && hasMiddleSections && (
          <MiddleBanner banners={middleBanners} />
        )}

        {/* Secciones finales de productos configurables desde el admin */}
        <ProductSections.Bottom />

        {/* Banner intermedio: después de Bottom si no hay Top ni Middle */}
        {!sectionsLoading && !hasTopSections && !hasMiddleSections && (
          <MiddleBanner banners={middleBanners} />
        )}

        {/* Below-the-fold: lazy loaded */}
        <Suspense fallback={null}>
          <Benefits />
          <MembershipLevelsSection />
          <SocialNetworks />
        </Suspense>
      </div>
    </PullToRefresh>
  );
};

/**
 * HomePage con Provider de secciones
 * El provider carga las secciones una sola vez y las comparte con todos los componentes hijos
 */
const HomePage = () => {
  return (
    <HomeSectionsProvider>
      <HomePageContent />
    </HomeSectionsProvider>
  );
};

export default HomePage;
