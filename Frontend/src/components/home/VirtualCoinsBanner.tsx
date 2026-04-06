import React, { useRef, useCallback, memo } from 'react';
import VirtualCoinsImage from '../../assets/images/virtual-coins.png';
import gsap from 'gsap';
import { useGSAP } from '@gsap/react';
import { useTranslation } from 'react-i18next';

// Registrar el plugin useGSAP (solo una vez)
let gsapPluginRegistered = false;
if (!gsapPluginRegistered) {
  gsap.registerPlugin(useGSAP);
  gsapPluginRegistered = true;
}

// SVG del icono de ayuda (constante para evitar recreación)
const HelpIcon = () => (
  <svg 
    xmlns="http://www.w3.org/2000/svg" 
    className="h-4 w-4 flex-shrink-0" 
    fill="none" 
    viewBox="0 0 24 24" 
    stroke="currentColor"
    strokeWidth={2}
  >
    <path strokeLinecap="round" strokeLinejoin="round" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const VirtualCoinsBannerNew: React.FC = memo(() => {
  const { t } = useTranslation('homeVirtualCoinsBanner');
  const containerRef = useRef<HTMLDivElement>(null);
  
  // Handler memoizado para abrir el modal
  const handleOpenHelpModal = useCallback(() => {
    document.dispatchEvent(new CustomEvent('openHelpModal', { 
      detail: { initialTab: 'coinsSystem' } 
    }));
  }, []);

  // Usar useGSAP para manejar la animación de rotación
  useGSAP(() => {
    // Crear la animación de rotación con GSAP en lugar de CSS
    gsap.to('.coin-image', {
      rotateZ: 360,
      repeat: -1, // Repetir infinitamente
      duration: 5,
      ease: 'linear',
      transformOrigin: 'center center'
    });
  }, { scope: containerRef });

  return (
    <div ref={containerRef} className="mt-4 sm:mt-6">
      <div className="bg-gradient-to-r from-primario to-primario/90 rounded-lg sm:rounded-xl shadow-lg overflow-hidden">
        <div className="relative">
          {/* Patrón de fondo sutil */}
          <div className="absolute inset-0 opacity-10">
            <div className="absolute top-0 right-0 w-32 h-32 sm:w-40 sm:h-40 bg-white rounded-full transform translate-x-16 -translate-y-16 sm:translate-x-20 sm:-translate-y-20"></div>
            <div className="absolute bottom-0 left-0 w-24 h-24 sm:w-32 sm:h-32 bg-white rounded-full transform -translate-x-12 translate-y-12 sm:-translate-x-16 sm:translate-y-16"></div>
            <div className="absolute top-1/2 left-1/4 w-12 h-12 sm:w-16 sm:h-16 bg-white rounded-full transform -translate-y-6 sm:-translate-y-8"></div>
          </div>
          
          <div className="relative px-4 py-4 sm:px-6 sm:py-5">
            {/* Layout Mobile */}
            <div className="flex flex-col items-center sm:hidden gap-4">
              {/* Imagen */}
              <div className="flex-shrink-0">
                <div className="relative">
                  <div className="absolute inset-0 bg-white/20 rounded-full blur-md"></div>
                  <img 
                    src={VirtualCoinsImage} 
                    alt={t('imageAlt')}
                    className="relative w-14 h-14 coin-image drop-shadow-lg" 
                  />
                </div>
              </div>
              
              {/* Texto */}
              <div className="text-center max-w-[280px]">
                <h3 className="text-lg font-bold text-white mb-1">
                  {t('title')}
                </h3>
                <p className="text-white/90 text-xs leading-relaxed">
                  {t('description')}
                </p>
              </div>
            </div>
            
            {/* Layout principal - Desktop y Tablet */}
            <div className="hidden sm:flex items-center gap-4 md:gap-6">
              
              {/* Grupo izquierdo: Imagen + Texto */}
              <div className="flex items-center gap-3 md:gap-4 flex-shrink-0">
                {/* Imagen */}
                <div className="flex-shrink-0">
                  <div className="relative">
                    <div className="absolute inset-0 bg-white/20 rounded-full blur-md"></div>
                    <img 
                      src={VirtualCoinsImage} 
                      alt={t('imageAlt')}
                      className="relative w-12 h-12 md:w-14 md:h-14 lg:w-16 lg:h-16 coin-image drop-shadow-lg" 
                    />
                  </div>
                </div>
                
                {/* Texto */}
                <div className="text-left max-w-[200px] md:max-w-[240px] lg:max-w-[280px]">
                  <h3 className="text-base md:text-lg lg:text-xl font-bold text-white mb-0.5 md:mb-1">
                    {t('title')}
                  </h3>
                  <p className="text-white/90 text-xs md:text-sm leading-relaxed">
                    {t('description')}
                  </p>
                </div>
              </div>
              
              {/* Grupo derecho: Chips + Botón */}
              <div className="flex items-center gap-3 flex-1 justify-end">
                {/* Chips - ocultos en tablet, visibles en lg+ */}
                <div className="hidden lg:flex items-center gap-2.5 flex-nowrap flex-1 justify-end">
                  <div 
                    className="flex items-center gap-2 bg-white/10 backdrop-blur-sm px-2.5 py-1.5 rounded-full border border-white/20"
                    title={t('chips.participation.tooltip')}
                  >
                    <svg className="w-4 h-4 xl:w-3 xl:h-3 text-white/80 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                      <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                    </svg>
                    <span className="hidden xl:inline text-xs text-white/90 font-medium">{t('chips.participation.label')}</span>
                  </div>
                  
                  <div 
                    className="flex items-center gap-2 bg-white/10 backdrop-blur-sm px-2.5 py-1.5 rounded-full border border-white/20"
                    title={t('chips.membership.tooltip')}
                  >
                    <svg className="w-4 h-4 xl:w-3 xl:h-3 text-white/80 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                      <path fillRule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" clipRule="evenodd" />
                    </svg>
                    <span className="hidden xl:inline text-xs text-white/90 font-medium">{t('chips.membership.label')}</span>
                  </div>
                  
                  <div 
                    className="flex items-center gap-2 bg-white/10 backdrop-blur-sm px-2.5 py-1.5 rounded-full border border-white/20"
                    title={t('chips.guests.tooltip')}
                  >
                    <svg className="w-4 h-4 xl:w-3 xl:h-3 text-white/80 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                      <path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3z" />
                    </svg>
                    <span className="hidden xl:inline text-xs text-white/90 font-medium">{t('chips.guests.label')}</span>
                  </div>
                </div>
                
                {/* Botón */}
                <button 
                  onClick={handleOpenHelpModal}
                  className="flex items-center justify-center gap-2 px-4 py-2 md:px-5 md:py-2.5 bg-white text-primario rounded-lg text-xs md:text-sm font-semibold hover:bg-gray-50 hover:text-primario/90 transition-all duration-300 shadow-sm hover:shadow-md transform hover:scale-105 focus:outline-none focus:ring-2 focus:ring-white focus:ring-offset-2 focus:ring-offset-primario whitespace-nowrap flex-shrink-0"
                >
                  <HelpIcon />
                  <span>{t('buttons.desktop')}</span>
                </button>
              </div>
            </div>
            
            {/* Botón para móvil */}
            <div className="mt-3 sm:hidden">
              <button 
                onClick={handleOpenHelpModal}
                className="flex items-center justify-center gap-2 w-full px-4 py-2.5 bg-white text-primario rounded-lg text-sm font-semibold hover:bg-gray-50 transition-all duración-300 shadow-sm"
              >
                <HelpIcon />
                <span>{t('buttons.mobile')}</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
});

VirtualCoinsBannerNew.displayName = 'VirtualCoinsBanner';

export default VirtualCoinsBannerNew;
