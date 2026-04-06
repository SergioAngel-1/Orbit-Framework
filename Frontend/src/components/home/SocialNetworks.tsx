import React, { useState, useEffect, useRef, useMemo, useCallback } from 'react';
import gsap from 'gsap';
import { FiChevronLeft, FiChevronRight } from 'react-icons/fi';
import { useTranslation } from 'react-i18next';
import SocialNetworkCard from './SocialNetworkCard';
import { useSocialNetworks } from '../../hooks/useSocialNetworks';
import { fluidSizing } from '../../utils/fluidSizing';
import logger from '../../utils/logger';

interface SocialNetwork {
  id: string;
  title: string;
  subtitle?: string;
  cta?: string;
  link: string;
  icon: string;
  color: string;
  minMembershipLevel?: number;
}

const SocialNetworks: React.FC = () => {
  const { socialNetworks, loading, error } = useSocialNetworks();
  const { t } = useTranslation('homeSocialNetworks');
  
  // IMPORTANTE: Todos los hooks deben estar ANTES de cualquier return condicional
  const [currentSlide, setCurrentSlide] = useState(0);
  const [isAnimating, setIsAnimating] = useState(false);
  const carouselRef = useRef<HTMLDivElement>(null);
  const slideRefs = useRef<Array<HTMLDivElement | null>>([]);

  // Memoizar transformación de datos para evitar recrear el array en cada render
  const allSocialNetworks: SocialNetwork[] = useMemo(() => 
    socialNetworks.map((network, index) => ({
      id: `social-${index}`,
      title: network.name,
      subtitle: network.username || '',
      cta: t('card.cta'),
      link: network.url,
      icon: network.icon || 'instagram',
      color: network.color || '#E1306C',
      minMembershipLevel: network.membershipInfo?.level
    })),
    [socialNetworks, t]
  );

  // Calcular el número total de slides
  const totalSlides = Math.ceil(allSocialNetworks.length / 4) || 1;

  // Inicializar referencias para los slides - DEBE estar antes de los returns
  useEffect(() => {
    slideRefs.current = slideRefs.current.slice(0, totalSlides);
  }, [totalSlides]);

  // Función memoizada para navegar a un slide específico - DEBE estar antes de returns
  const goToSlide = useCallback((index: number) => {
    if (isAnimating || index === currentSlide || !carouselRef.current) return;
    
    setIsAnimating(true);
    
    gsap.to(carouselRef.current, {
      x: `${-index * 100}%`,
      duration: 0.5,
      ease: 'power2.inOut',
      onComplete: () => {
        setCurrentSlide(index);
        setIsAnimating(false);
      }
    });
  }, [isAnimating, currentSlide]);
  
  // Navegar al siguiente slide - DEBE estar antes de returns
  const nextSlide = useCallback(() => {
    const next = (currentSlide + 1) % totalSlides;
    goToSlide(next);
  }, [currentSlide, totalSlides, goToSlide]);
  
  // Navegar al slide anterior - DEBE estar antes de returns
  const prevSlide = useCallback(() => {
    const prev = (currentSlide - 1 + totalSlides) % totalSlides;
    goToSlide(prev);
  }, [currentSlide, totalSlides, goToSlide]);

  // Mostrar loading state
  if (loading) {
    return (
      <section className="py-12 md:py-20 bg-gradient-to-b from-white to-[var(--claro)]">
        <div className="container mx-auto px-2 sm:px-3 md:px-4">
          <div className="max-w-3xl mx-auto text-center">
            <p className="text-gray-600">{t('loading')}</p>
          </div>
        </div>
      </section>
    );
  }

  // Mostrar error si existe
  if (error) {
    logger.error('SocialNetworks', 'Error al cargar redes sociales:', error);
    return null;
  }

  // No renderizar si no hay redes sociales
  if (allSocialNetworks.length === 0) {
    logger.warn('SocialNetworks', 'No se encontraron redes sociales');
    return null;
  }

  return (
    <section 
      className="bg-gradient-to-b from-white to-secundario/30"
      style={{ paddingTop: fluidSizing.space['2xl'], paddingBottom: fluidSizing.space['2xl'] }}
    >
      <div className="container mx-auto" style={{ paddingLeft: fluidSizing.space.md, paddingRight: fluidSizing.space.md }}>
        {/* Header */}
        <div className="max-w-3xl mx-auto text-center" style={{ marginBottom: fluidSizing.space.xl }}>
          <h2 
            className="font-bold text-primario"
            style={{ fontSize: fluidSizing.text['3xl'], marginBottom: fluidSizing.space.sm }}
          >
            {t('title')}
          </h2>
          <p className="text-texto" style={{ fontSize: fluidSizing.text.base }}>
            {t('subtitle')}
          </p>
          <div className="w-24 h-1 bg-gradient-to-r from-primario to-primario-light mx-auto mt-6 rounded-full" />
        </div>
        
        {/* Contenedor principal del carrusel con flechas separadas */}
        <div className="max-w-7xl mx-auto">
          <div className="flex items-center" style={{ gap: fluidSizing.space.md }}>
            {/* Flecha izquierda - Desktop */}
            {allSocialNetworks.length > 4 && (
              <button 
                onClick={prevSlide} 
                disabled={isAnimating || currentSlide === 0}
                className={`hidden md:flex items-center justify-center flex-shrink-0 rounded-full transition-all duration-300 ${
                  currentSlide === 0 
                    ? 'bg-secundario/30 text-texto/30 cursor-not-allowed' 
                    : 'bg-primario/10 text-primario hover:bg-primario hover:text-white'
                }`}
                style={{ 
                  width: fluidSizing.size.floatingButton, 
                  height: fluidSizing.size.floatingButton 
                }}
                aria-label={t('carousel.previous')}
              >
                <FiChevronLeft style={{ width: fluidSizing.size.iconMd, height: fluidSizing.size.iconMd }} />
              </button>
            )}
            
            {/* Carrusel con overflow-x hidden para slide, overflow-y visible para animaciones */}
            <div className="flex-1 overflow-x-hidden overflow-y-visible">
              <div 
                ref={carouselRef}
                className="flex"
                style={{ willChange: 'transform' }}
              >
                {/* Dividimos las redes sociales en grupos de 4 */}
                {Array.from({ length: totalSlides }).map((_, slideIndex) => {
                  const slideItems = allSocialNetworks.slice(slideIndex * 4, (slideIndex + 1) * 4);
                  
                  // Determinar si este slide está incompleto (menos de 4 items en desktop)
                  const isIncompleteSlide = slideItems.length < 4;
                  
                  return (
                    <div 
                      key={`slide-${slideIndex}`}
                      ref={(el: HTMLDivElement | null) => { slideRefs.current[slideIndex] = el; }}
                      className={`w-full flex-shrink-0 flex flex-wrap ${isIncompleteSlide ? 'justify-center' : 'justify-start'}`}
                      style={{ gap: fluidSizing.space.sm, paddingTop: fluidSizing.space.md, paddingBottom: fluidSizing.space.sm }}
                    >
                      {slideItems.map((network) => (
                        <div 
                          key={network.id} 
                          className="transform transition-all duration-300 hover:-translate-y-1 w-[calc(50%-0.5rem)] md:w-[calc(25%-0.75rem)]"
                        >
                          <SocialNetworkCard
                            id={network.id}
                            title={network.title}
                            subtitle={network.subtitle}
                            cta={network.cta || t('card.cta')}
                            link={network.link}
                            icon={network.icon}
                            color={network.color}
                            minMembershipLevel={network.minMembershipLevel}
                          />
                        </div>
                      ))}
                    </div>
                  );
                })}
              </div>
            </div>
            
            {/* Flecha derecha - Desktop */}
            {allSocialNetworks.length > 4 && (
              <button 
                onClick={nextSlide} 
                disabled={isAnimating || currentSlide === totalSlides - 1}
                className={`hidden md:flex items-center justify-center flex-shrink-0 rounded-full transition-all duration-300 ${
                  currentSlide === totalSlides - 1 
                    ? 'bg-secundario/30 text-texto/30 cursor-not-allowed' 
                    : 'bg-primario/10 text-primario hover:bg-primario hover:text-white'
                }`}
                style={{ 
                  width: fluidSizing.size.floatingButton, 
                  height: fluidSizing.size.floatingButton 
                }}
                aria-label={t('carousel.next')}
              >
                <FiChevronRight style={{ width: fluidSizing.size.iconMd, height: fluidSizing.size.iconMd }} />
              </button>
            )}
          </div>
          
          {/* Controles mobile - abajo del carrusel */}
          {allSocialNetworks.length > 4 && (
            <div 
              className="flex md:hidden justify-center items-center"
              style={{ marginTop: fluidSizing.space.lg, gap: fluidSizing.space.lg }}
            >
              <button 
                onClick={prevSlide} 
                disabled={isAnimating || currentSlide === 0}
                className={`flex items-center justify-center rounded-full transition-colors duration-300 ${
                  currentSlide === 0 
                    ? 'bg-secundario/20 text-texto/50 cursor-not-allowed' 
                    : 'bg-secundario/30 text-primario hover:bg-primario hover:text-white'
                }`}
                style={{ 
                  width: fluidSizing.size.buttonSm, 
                  height: fluidSizing.size.buttonSm, 
                  position: 'relative'
                }}
                aria-label={t('carousel.previous')}
              >
                <FiChevronLeft 
                  className="w-4 h-4"
                  aria-hidden="true"
                  style={{ position: 'absolute', zIndex: 1 }}
                />
              </button>
              
              <span className="text-texto font-medium" style={{ fontSize: fluidSizing.text.sm }}>
                {currentSlide + 1} / {totalSlides}
              </span>
              
              <button 
                onClick={nextSlide} 
                disabled={isAnimating || currentSlide === totalSlides - 1}
                className={`flex items-center justify-center rounded-full transition-colors duration-300 ${
                  currentSlide === totalSlides - 1 
                    ? 'bg-secundario/20 text-texto/50 cursor-not-allowed' 
                    : 'bg-secundario/30 text-primario hover:bg-primario hover:text-white'
                }`}
                style={{ 
                  width: fluidSizing.size.buttonSm, 
                  height: fluidSizing.size.buttonSm, 
                  position: 'relative'
                }}
                aria-label={t('carousel.next')}
              >
                <FiChevronRight 
                  className="w-4 h-4"
                  aria-hidden="true"
                  style={{ position: 'absolute', zIndex: 1 }}
                />
              </button>
            </div>
          )}
        </div>
      </div>
    </section>
  );
};

export default SocialNetworks;
