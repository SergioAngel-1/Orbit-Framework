import React, { useState, useEffect } from 'react';
import BannerCarousel, { Banner } from './BannerCarousel';
import FeaturedCategories, { Category } from './FeaturedCategories';
import VirtualCoinsBannerNew from './VirtualCoinsBanner';
import HelpModal from '../help/HelpModal';
import { fluidSizing } from '../../utils/fluidSizing';
import './css/HeroSection.css';

interface HeroSectionProps {
  banners: Banner[];
  bannersLoading: boolean;
  bannersError: string | null;
  featuredCategories: Category[];
  featuredCategoriesLoading: boolean;
  featuredCategoriesError: string | null;
}

const HeroSection: React.FC<HeroSectionProps> = ({
  banners,
  bannersLoading,
  bannersError,
  featuredCategories,
  featuredCategoriesLoading,
  featuredCategoriesError
}) => {
  const [isHelpModalOpen, setIsHelpModalOpen] = useState(false);
  const [helpModalInitialTab, setHelpModalInitialTab] = useState<'help' | 'howToRequest' | 'coinsSystem'>('help');
  const bannerRef = React.useRef<HTMLDivElement>(null);
  const categoriesRef = React.useRef<HTMLDivElement>(null);

  useEffect(() => {
    const handleOpenHelpModal = (event: CustomEvent) => {
      const { initialTab } = event.detail;
      setHelpModalInitialTab(initialTab || 'help');
      setIsHelpModalOpen(true);
    };

    // Agregar el event listener
    document.addEventListener('openHelpModal', handleOpenHelpModal as EventListener);

    // Cleanup
    return () => {
      document.removeEventListener('openHelpModal', handleOpenHelpModal as EventListener);
    };
  }, []);

  // Sincronizar altura de categorías con banner en desktop
  useEffect(() => {
    const syncHeights = () => {
      if (!bannerRef.current || !categoriesRef.current) return;
      
      // Solo en desktop (>= 1024px)
      if (window.innerWidth >= 1024) {
        const bannerHeight = bannerRef.current.offsetHeight;
        if (bannerHeight > 0) {
          categoriesRef.current.style.height = `${bannerHeight}px`;
        }
      } else {
        // En mobile/tablet, resetear altura para usar CSS
        categoriesRef.current.style.height = '';
      }
    };

    // Ejecutar en mount y en resize
    syncHeights();
    window.addEventListener('resize', syncHeights);

    // Observar cambios de tamaño del banner (más robusto que solo onload)
    const resizeObserver = new ResizeObserver(syncHeights);
    if (bannerRef.current) {
      resizeObserver.observe(bannerRef.current);
    }

    // Forzar sync cuando carguen imágenes del banner
    const imgs = bannerRef.current?.querySelectorAll('img') ?? [];
    imgs.forEach((img) => {
      if (!img.complete) {
        img.addEventListener('load', syncHeights);
      }
    });

    return () => {
      window.removeEventListener('resize', syncHeights);
      resizeObserver.disconnect();
      imgs.forEach((img) => {
        img.removeEventListener('load', syncHeights);
      });
    };
  }, [banners, bannersLoading]);

  return (
    <section className="bg-gradient-to-b from-[var(--claro)] to-white w-full hero-section-container" style={{ paddingTop: fluidSizing.space.xs, paddingBottom: fluidSizing.space.md }}>
      <div className="w-full max-w-[1920px] mx-auto px-2 sm:px-3 md:px-4 max-w-full">
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
          {/* Hero Banner - Ocupa 2/3 en desktop */}
          <div className="lg:col-span-2">
            <div ref={bannerRef} className="hero-banner-height">
              <BannerCarousel 
                banners={banners} 
                loading={bannersLoading} 
                error={bannersError} 
              />
            </div>
          </div>

          {/* Categorías Destacadas - Ocupa 1/3 en desktop */}
          <div className="lg:col-span-1">
            <div ref={categoriesRef} className="bg-white rounded-lg shadow-md p-4 hero-categories-height flex flex-col">
              <div className="flex-1 flex flex-col h-full">
                <FeaturedCategories 
                  categories={featuredCategories} 
                  loading={featuredCategoriesLoading} 
                  error={featuredCategoriesError} 
                />
              </div>
            </div>
          </div>
        </div>
        {/* Banner Virtual Coins debajo del carrusel y categorías */}
        <div className="mt-4">
          <VirtualCoinsBannerNew />
        </div>
      </div>

      {/* Modal de Ayuda */}
      <HelpModal
        isOpen={isHelpModalOpen}
        onClose={() => setIsHelpModalOpen(false)}
        initialTab={helpModalInitialTab}
      />
    </section>
  );
};

export default HeroSection;
