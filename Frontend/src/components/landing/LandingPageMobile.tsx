/**
 * LandingPageMobile - Versión mobile de la Landing Page
 * Diseño optimizado para pantallas pequeñas con scroll vertical
 * Usa estilos consistentes con CollapsibleSection y VirtualCoinsBanner
 */

import React, { memo } from 'react';
import { useTranslation } from 'react-i18next';
import { fluidSizing } from '../../utils/fluidSizing';
import useMembershipLevels from '../../hooks/useMembershipLevels';
import LanguageSwitch from '../common/LanguageSwitch';

interface LandingPageMobileProps {
  children: React.ReactNode;
  isLoginForm: boolean;
  onGoBack: () => void;
}

const LandingPageMobile: React.FC<LandingPageMobileProps> = memo(({
  children,
  isLoginForm,
  onGoBack
}) => {
  const { t } = useTranslation('landingPage');
  const { levels, loading: levelsLoading } = useMembershipLevels();
  
  const membershipLevels = levels
    .filter(level => level.id >= 1 && level.id <= 5)
    .sort((a, b) => a.id - b.id);

  return (
    <div className="bg-gray-50 flex flex-col">
      {/* Botón Volver + Idioma */}
      <div 
        className="fixed z-50 flex items-center justify-between w-full"
        style={{ top: fluidSizing.space.sm, left: 0, paddingLeft: fluidSizing.space.sm, paddingRight: fluidSizing.space.sm }}
      >
        <button
          onClick={onGoBack}
          className="flex items-center bg-white/95 hover:bg-white rounded-lg shadow-md text-primario font-medium"
          style={{ 
            gap: fluidSizing.space.xs,
            padding: `${fluidSizing.space.xs} ${fluidSizing.space.sm}`,
            fontSize: fluidSizing.text.sm
          }}
        >
          <svg 
            fill="none" 
            stroke="currentColor" 
            viewBox="0 0 24 24"
            style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }}
          >
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
          <span>{t('mobile.backButton')}</span>
        </button>
        <LanguageSwitch variant="mobile" />
      </div>

      {/* Logo */}
      <div 
        className="bg-white flex-shrink-0"
        style={{ padding: fluidSizing.space.sm, paddingTop: fluidSizing.space.xl }}
      >
        <div className="max-w-sm mx-auto text-center">
          <img
            src="/assets/images/logo-flores.png"
            alt={t('mobile.logoAlt')}
            className="mx-auto object-contain"
            style={{ height: fluidSizing.size.floatingButton }}
          />
        </div>
      </div>

      {/* Formulario - Centrado verticalmente, ocupa espacio disponible */}
      <div 
        className="bg-white shadow-sm border-t border-b border-gray-100 flex-1 flex items-center"
        style={{ paddingTop: fluidSizing.space.lg, paddingBottom: fluidSizing.space.xl, paddingLeft: fluidSizing.space['2xl'], paddingRight: fluidSizing.space['2xl'] }}
      >
        <div className="w-full">
          <h2 
            className="font-bold text-primario text-center"
            style={{ fontSize: fluidSizing.text['3xl'], marginBottom: fluidSizing.space.xs }}
          >
            {isLoginForm ? t('mobile.loginTitle') : t('mobile.registerTitle')}
          </h2>
          <p 
            className="text-texto text-center"
            style={{ fontSize: fluidSizing.text.xs, marginBottom: fluidSizing.space.lg }}
          >
            {isLoginForm ? t('mobile.loginSubtitle') : t('mobile.registerSubtitle')}
          </p>
          {children}
        </div>
      </div>

      {/* Hero + Membresías */}
      <div 
        className="bg-primario text-white flex-shrink-0"
        style={{ padding: fluidSizing.space.md, paddingTop: fluidSizing.space.lg, paddingBottom: fluidSizing.space.lg }}
      >
        <div className="max-w-sm mx-auto text-center">
          <h3 
            className="font-bold text-white"
            style={{ fontSize: fluidSizing.text.xl, marginBottom: fluidSizing.space.sm }}
          >
            {t('mobile.membershipLabel')}
          </h3>
          <p 
            className="leading-relaxed text-white/90"
            style={{ fontSize: fluidSizing.text.sm, marginBottom: fluidSizing.space.md }}
          >
            {t('mobile.heroText')}
          </p>
          
          {!levelsLoading && membershipLevels.length > 0 && (
            <div 
              className="flex items-center justify-center overflow-x-auto hide-scrollbar"
              style={{ gap: fluidSizing.space.md }}
            >
              {membershipLevels.map((level) => (
                <div 
                  key={level.id}
                  className="flex-shrink-0"
                  title={level.name}
                >
                  <div 
                    className="rounded-full overflow-hidden bg-white/15 border-2 border-white/30"
                    style={{ width: fluidSizing.size.floatingButton, height: fluidSizing.size.floatingButton }}
                  >
                    {level.icon_url ? (
                      <img 
                        src={level.icon_url} 
                        alt={level.name}
                        className="w-full h-full object-cover"
                        loading="lazy"
                      />
                    ) : (
                      <span 
                        className="w-full h-full flex items-center justify-center"
                        style={{ fontSize: fluidSizing.text.xl }}
                      >
                        {level.icon}
                      </span>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>

      <style>{`
        .hide-scrollbar::-webkit-scrollbar { display: none; }
        .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
      `}</style>
    </div>
  );
});

LandingPageMobile.displayName = 'LandingPageMobile';

export default LandingPageMobile;
