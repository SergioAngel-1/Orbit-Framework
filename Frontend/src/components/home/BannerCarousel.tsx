import React from "react";
import { Swiper, SwiperSlide } from "swiper/react";
import { Navigation, Pagination, Autoplay } from "swiper/modules";
import type { Swiper as SwiperType } from "swiper";
import "swiper/css";
import "swiper/css/navigation";
import "swiper/css/pagination";
import logger from "../../utils/logger";
import Loader from "../ui/Loader";
import MembershipBadge from "../common/MembershipBadge";
import { fluidSizing } from "../../utils/fluidSizing";
import BannerInfoOverlay from "./BannerInfoOverlay";
import { bannerApiService } from "../../services/banners";
import type { BannerType } from "../../services/banners/bannerTypes";
import { useTranslation } from "react-i18next";
import { useLanguage } from "../../contexts/LanguageContext";
import { isBannerAction, executeBannerAction } from "../../utils/bannerActions";
import "./css/BannerCarousel.css";

// Interfaces - Estandarizadas con MembershipContext
// La estructura de MembershipInfo es consistente con la usada en MembershipContext
interface MembershipInfo {
  level: number;
  name?: string;
  icon?: string;
  color?: string;
  mode?: 'cascade' | 'exact';
}

interface CarouselImage {
  url: string;
  mobile_url?: string;
  title?: string;
  link?: string;
  description?: string;
  subtitle?: string;
  cta?: string;
  hideInfoBox?: boolean;
  banner_url?: string;
  /** Nivel mínimo de membresía (estandarizado desde backend) */
  min_membership_level?: number;
  /** Info detallada del nivel (estandarizado desde backend) */
  min_membership_info?: MembershipInfo | null;
}

export interface Banner {
  id: number;
  title: string;
  subtitle?: string;
  description?: string;
  cta?: string;
  link?: string;
  image?: string;
  imageMobile?: string;
  hideInfoBox?: boolean;
  /** Nivel mínimo de membresía (estandarizado desde backend) */
  min_membership_level?: number;
  /** Info detallada del nivel (estandarizado desde backend) */
  min_membership_info?: MembershipInfo | null;
  carouselImages?: CarouselImage[];
}

interface BannerCarouselProps {
  /** Banners proporcionados externamente (modo controlado) */
  banners?: Banner[];
  /** Estado de carga externo (modo controlado) */
  loading?: boolean;
  /** Error externo (modo controlado) */
  error?: string | null;
  /** Tipo de banner para auto-fetch (modo autónomo). Si se proporciona, el componente obtiene sus propios datos. */
  bannerType?: BannerType;
  /** Clase CSS adicional para el contenedor */
  className?: string;
  /** Modo full-width: sin bordes redondeados, sin sombra, altura responsiva fija con object-cover. Ideal para hero de landings. */
  fullWidth?: boolean;
  /** Modo tall: llena la altura del padre con object-cover. Ideal para imágenes verticales/portrait en columnas laterales. */
  tall?: boolean;
  /** Icono personalizado para el CTA del overlay */
  ctaIcon?: React.ReactNode;
}

/**
 * Extrae el nivel de membresía de un objeto
 * Usa propiedades estandarizadas del backend (min_membership_level, min_membership_info)
 * Consistente con la estructura de MembershipContext
 */
const getMembershipLevel = (data: CarouselImage | Banner): number | undefined => {
  // Propiedad estandarizada directa (enviada por backend)
  if (typeof data.min_membership_level === 'number') {
    return data.min_membership_level;
  }
  // Fallback: extraer de min_membership_info si existe
  if (data.min_membership_info?.level !== undefined) {
    return Number(data.min_membership_info.level);
  }
  return undefined;
};

/** Tipo para slide aplanado del carrusel */
type FlattenedSlide = {
  bannerId: number;
  data: (Banner & CarouselImage & { _index?: number });
};

const flattenSlides = (banners: Banner[]): FlattenedSlide[] => {
  const slides: FlattenedSlide[] = [];
  banners.forEach((banner) => {
    if (banner.carouselImages && banner.carouselImages.length > 0) {
      banner.carouselImages.forEach((img, idx) => {
        slides.push({ bannerId: banner.id, data: { ...banner, ...img, _index: idx } });
      });
    } else {
      slides.push({ bannerId: banner.id, data: { ...banner, url: banner.image || '', _index: 0 } });
    }
  });
  return slides;
};

const BannerCarousel: React.FC<BannerCarouselProps> = ({ banners: externalBanners, loading: externalLoading, error: externalError, bannerType, className, fullWidth = false, tall = false, ctaIcon }) => {
  const { t } = useTranslation('homeBannerCarousel');
  const { currentLang } = useLanguage();
  // Estado interno para modo autónomo (cuando se proporciona bannerType)
  const [internalBanners, setInternalBanners] = React.useState<Banner[]>([]);
  const [internalLoading, setInternalLoading] = React.useState(!!bannerType);
  const [internalError, setInternalError] = React.useState<string | null>(null);

  // Auto-fetch cuando se proporciona bannerType o cambia el idioma
  React.useEffect(() => {
    if (!bannerType) return;

    let cancelled = false;

    const fetchBanners = async () => {
      try {
        setInternalLoading(true);
        setInternalError(null);
        const data = await bannerApiService.getByType(bannerType);
        if (!cancelled) {
          setInternalBanners(data);
          logger.info('BannerCarousel', `Banners tipo '${bannerType}' cargados: ${data.length}`);
        }
      } catch (err: any) {
        if (!cancelled) {
          const msg = err?.message || 'Error al cargar banners';
          setInternalError(msg);
          logger.error('BannerCarousel', `Error al cargar banners tipo '${bannerType}':`, err);
        }
      } finally {
        if (!cancelled) {
          setInternalLoading(false);
        }
      }
    };

    fetchBanners();

    return () => { cancelled = true; };
  }, [bannerType, currentLang]);

  // Resolver fuente de datos: externo (modo controlado) o interno (modo autónomo)
  const banners = bannerType ? internalBanners : externalBanners;
  const loading = bannerType ? internalLoading : (externalLoading ?? false);
  const error = bannerType ? internalError : (externalError ?? null);

  // Asegurar que el array de banners sea siempre un array válido y sin valores nulos
  const safeBanners: Banner[] = React.useMemo(() => {
    if (!Array.isArray(banners)) return [];
    return banners.filter(Boolean);
  }, [banners]);

  // Estados para tracking de slides cargadas
  const resizeTimeoutRef = React.useRef<NodeJS.Timeout | null>(null);

  // Estado para controlar qué slides ya cargaron su imagen principal
  const [loadedSlides, setLoadedSlides] = React.useState<Record<string, boolean>>({});
  // Estado para la altura del contenedor
  const [containerHeight, setContainerHeight] = React.useState<number | null>(null);
  const containerRef = React.useRef<HTMLDivElement>(null);

  // Función para calcular altura en mobile
  const calculateMobileHeight = React.useCallback((imgElement: HTMLImageElement) => {
    if (window.innerWidth >= 640 || !imgElement.naturalHeight) return;
    
    const aspectRatio = imgElement.naturalWidth / imgElement.naturalHeight;
    const containerWidth = containerRef.current?.offsetWidth || window.innerWidth;
    const calculatedHeight = containerWidth / aspectRatio;
    
    const minHeight = 200;
    const maxHeight = 400;
    const finalHeight = Math.max(minHeight, Math.min(maxHeight, calculatedHeight));
    
    setContainerHeight(finalHeight);
  }, []);

  const handleImageLoad = React.useCallback((key: string, imgElement?: HTMLImageElement) => {
    setLoadedSlides(prev => ({ ...prev, [key]: true }));
    
    // En mobile, ajustar la altura del contenedor a la imagen activa
    if (imgElement) {
      calculateMobileHeight(imgElement);
    }
  }, [calculateMobileHeight]);

  // Swiper maneja el autoplay automáticamente - no necesitamos lógica manual

  // Efecto para manejar el resize de la ventana con throttle
  React.useEffect(() => {
    const handleResize = () => {
      // Throttle: solo ejecutar cada 150ms
      if (resizeTimeoutRef.current) return;
      
      resizeTimeoutRef.current = setTimeout(() => {
        resizeTimeoutRef.current = null;
        
        if (window.innerWidth >= 640) {
          setContainerHeight(null);
        } else if (containerRef.current) {
          const activeImage = containerRef.current.querySelector('.swiper-slide-active img');
          if (activeImage && activeImage instanceof HTMLImageElement && activeImage.complete) {
            calculateMobileHeight(activeImage);
          }
        }
      }, 150);
    };

    window.addEventListener('resize', handleResize);
    return () => {
      window.removeEventListener('resize', handleResize);
      if (resizeTimeoutRef.current) {
        clearTimeout(resizeTimeoutRef.current);
      }
    };
  }, [calculateMobileHeight]);

  // Renderizado condicional
  if (loading) {
    return (
      <div className="hero-loading-state bg-white rounded-lg">
        <Loader size="large" text={t('loading')} />
      </div>
    );
  }

  if (error) {
    return (
      <div className="hero-loading-state bg-white rounded-lg">
        <div className="text-center text-red-500 p-4">
          <p>{t('errorTitle')}</p>
          <p className="text-sm mt-2">{error}</p>
        </div>
      </div>
    );
  }

  const slides = flattenSlides(safeBanners);

  if (slides.length === 0) {
    return (
      <div className="hero-loading-state bg-white rounded-lg">
        <p className="text-gray-500">{t('emptyMessage')}</p>
      </div>
    );
  }

  return (
    <div 
      ref={containerRef}
      className={`relative overflow-hidden w-full h-full ${fullWidth || tall ? '' : 'rounded-lg shadow-lg'} ${className || ''}`}
      style={{ 
        height: (fullWidth || tall) 
          ? undefined 
          : (containerHeight && window.innerWidth < 640 ? `${containerHeight}px` : undefined),
        transition: (fullWidth || tall) ? undefined : 'height 0.3s ease-in-out'
      }}
    >
      <Swiper
        modules={[Navigation, Pagination, Autoplay]}
        navigation={slides.length > 1}
        pagination={slides.length > 1 ? { clickable: true } : false}
        autoplay={slides.length > 1 ? { delay: 30000, disableOnInteraction: false } : false}
        loop={slides.length > 1}
        className="w-full h-full"
        style={{ height: '100%' }}
        onSlideChange={(swiper: SwiperType) => {
          // Log del cambio de slide para debugging
          if (typeof swiper.activeIndex !== 'number' || Number.isNaN(swiper.activeIndex)) {
            logger.warn('BannerCarousel', 'activeIndex indefinido en onSlideChange');
            return;
          }
          logger.debug('BannerCarousel', `Slide cambiado a índice: ${swiper.activeIndex}`);
          
          // Actualizar altura del contenedor en mobile cuando cambie la slide
          if (window.innerWidth < 640) {
            const activeSlide = swiper.slides[swiper.activeIndex];
            const activeImage = activeSlide?.querySelector('img');
            
            if (activeImage && activeImage.complete && activeImage.naturalHeight > 0) {
              const aspectRatio = activeImage.naturalWidth / activeImage.naturalHeight;
              const containerWidth = containerRef.current?.offsetWidth || window.innerWidth;
              const calculatedHeight = containerWidth / aspectRatio;
              
              const minHeight = 200;
              const maxHeight = 400;
              const finalHeight = Math.max(minHeight, Math.min(maxHeight, calculatedHeight));
              
              setContainerHeight(finalHeight);
            }
          }
        }}
      >
        {slides.map((slide, slideIndex) => {
          const { data } = slide;
          if (!data) {
            logger.warn('BannerCarousel', 'Slide data indefinido, se omite slide');
            return null;
          }
          const slideKey = `${slide.bannerId}-${data._index ?? 0}`;
          const isFirstSlide = slideIndex === 0;
          const hasInfo = !data.hideInfoBox && (data.subtitle || data.description);
          // Usar función helper para extraer nivel de membresía de forma consistente
          const parsedMembershipLevel = getMembershipLevel(data);
          const hasValidLevel = parsedMembershipLevel !== undefined && Number.isFinite(parsedMembershipLevel);
          const shouldShowBadge = hasValidLevel && parsedMembershipLevel > 0;
            return (
            <SwiperSlide key={slideKey} className="relative w-full h-full">
              {/* Badge de membresía mínima - esquina superior izquierda */}
              {shouldShowBadge && (
                <div 
                  className="absolute z-40 rounded-br-lg pointer-events-none"
                  style={{ 
                    top: 0, 
                    left: 0, 
                    padding: `${fluidSizing.space.xs} ${fluidSizing.space.sm}`
                  }}
                >
                  <MembershipBadge level={parsedMembershipLevel as number} size="sm" />
                </div>
              )}
              
              {/* Imagen base - usar banner_url para toda la imagen, link para el botón CTA */}
              <div className="relative w-full h-full">
                {data.banner_url && !hasInfo ? (
                  // Si hay URL del banner pero NO hay info box, envolver toda la imagen
                  isBannerAction(data.banner_url) ? (
                  <div
                    className="block w-full h-full cursor-pointer"
                    onClick={() => executeBannerAction(data.banner_url!)}
                    role="button"
                    tabIndex={0}
                    onKeyDown={(e) => e.key === 'Enter' && executeBannerAction(data.banner_url!)}
                  >
                    <picture className="block w-full h-full">
                      {data.mobile_url || data.imageMobile ? (
                        <>
                          <source media="(max-width: 639px)" srcSet={data.mobile_url || data.imageMobile} />
                        </>
                      ) : null}
                      <img
                        src={data.url || data.image}
                        alt={data.title}
                        className="w-full h-full object-cover"
                        style={{ 
                          objectPosition: fullWidth ? 'center center' : undefined,
                          filter: loadedSlides[slideKey] ? 'none' : 'blur(5px) grayscale(20%)',
                          transition: 'filter 0.3s ease-in-out'
                        }}
                        loading={isFirstSlide ? 'eager' : 'lazy'}
                        {...(isFirstSlide ? { fetchPriority: 'high' as const } : {})}
                        onLoad={(e) => handleImageLoad(slideKey, e.currentTarget)}
                      />
                    </picture>
                  </div>
                  ) : (
                  <a
                    href={data.banner_url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="block w-full h-full cursor-pointer"
                    title={t('externalLinkTitle', { url: data.banner_url })}
                  >
                    <picture className="block w-full h-full">
                      {data.mobile_url || data.imageMobile ? (
                        <>
                          <source media="(max-width: 639px)" srcSet={data.mobile_url || data.imageMobile} />
                        </>
                      ) : null}
                      <img
                        src={data.url || data.image}
                        alt={data.title}
                        className="w-full h-full object-cover"
                        style={{ 
                          objectPosition: fullWidth ? 'center center' : undefined,
                          filter: loadedSlides[slideKey] ? 'none' : 'blur(5px) grayscale(20%)',
                          transition: 'filter 0.3s ease-in-out'
                        }}
                        loading={isFirstSlide ? 'eager' : 'lazy'}
                        {...(isFirstSlide ? { fetchPriority: 'high' as const } : {})}
                        onLoad={(e) => handleImageLoad(slideKey, e.currentTarget)}
                      />
                    </picture>
                  </a>
                  )
                ) : (
                  // Si NO hay URL del banner o SÍ hay info box, imagen normal (puede tener click en área libre)
                  <picture className="block w-full h-full">
                    {data.mobile_url || data.imageMobile ? (
                      <>
                        <source media="(max-width: 639px)" srcSet={data.mobile_url || data.imageMobile} />
                      </>
                    ) : null}
                    <img
                      src={data.url || data.image}
                      alt={data.title}
                      className={`w-full h-full object-cover ${data.banner_url && hasInfo ? 'cursor-pointer' : ''}`}
                      style={{ 
                        objectPosition: fullWidth ? 'center center' : undefined,
                        filter: loadedSlides[slideKey] ? 'none' : 'blur(5px) grayscale(20%)',
                        transition: 'filter 0.3s ease-in-out'
                      }}
                      loading={isFirstSlide ? 'eager' : 'lazy'}
                      {...(isFirstSlide ? { fetchPriority: 'high' as const } : {})}
                      onLoad={(e) => handleImageLoad(slideKey, e.currentTarget)}
                      onClick={data.banner_url && hasInfo ? () => {
                        if (isBannerAction(data.banner_url)) {
                          executeBannerAction(data.banner_url!);
                        } else {
                          window.open(data.banner_url, '_blank', 'noopener,noreferrer');
                        }
                      } : undefined}
                    />
                  </picture>
                )}
              </div>

              {!loadedSlides[slideKey] && (
                <div className="absolute inset-0 flex items-center justify-center bg-white/40 z-10">
                  <div className="w-12 h-12">
                    <Loader size="large" text="" />
                  </div>
                </div>
              )}

              {/* Gradient overlay for text readability */}
              {hasInfo && loadedSlides[slideKey] && (
                <div className="absolute inset-0 bg-gradient-to-t sm:bg-none from-black/70 via-black/30 to-transparent pointer-events-none"></div>
              )}

              {/* Info overlay: mobile (pill + CTA) + desktop (prominent left overlay) */}
              {hasInfo && loadedSlides[slideKey] && (
                <BannerInfoOverlay
                  subtitle={data.subtitle}
                  description={data.description}
                  link={data.link}
                  cta={data.cta}
                  visible={true}
                  ctaIcon={ctaIcon}
                  compact={!fullWidth}
                />
              )}
            </SwiperSlide>
          );
        })}
      </Swiper>
    </div>
  );
};

export default BannerCarousel;
