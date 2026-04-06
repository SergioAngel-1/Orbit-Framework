import React, { memo } from 'react';
import { useTranslation } from 'react-i18next';
import { fluidSizing } from '../../utils/fluidSizing';
import useMembershipLevels from '../../hooks/useMembershipLevels';

const HeroSection: React.FC = memo(() => {
  const { t } = useTranslation('landingPage');
  const { levels, loading: levelsLoading } = useMembershipLevels();
  
  // Filtrar niveles 1-5 (excluir nivel 0 público)
  const membershipLevels = levels
    .filter(level => level.id >= 1 && level.id <= 5)
    .sort((a, b) => a.id - b.id);

  const features = t('hero.features', { returnObjects: true }) as string[];

  return (
    <div 
      className="order-2 w-full lg:w-1/2 lg:h-screen lg:fixed lg:right-0 lg:top-0 bg-primario text-white flex flex-col justify-center"
      style={{ 
        padding: `${fluidSizing.space['2xl']} ${fluidSizing.space.xl}`
      }}
    >
      <div className="max-w-md mx-auto w-full">
        <h1 
          className="font-bold leading-tight"
          style={{ 
            fontSize: fluidSizing.text['5xl'],
            marginBottom: fluidSizing.space.lg
          }}
        >
          {t('hero.title')}
        </h1>
        <p 
          className="leading-relaxed"
          style={{ 
            fontSize: fluidSizing.text.lg,
            marginBottom: fluidSizing.space['2xl'],
            opacity: 0.95
          }}
        >
          {t('hero.description')}
        </p>
        <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.lg }}>
          {features.map((feature, index) => (
            <div key={index} className="flex items-center">
              <div 
                className="bg-white text-primario rounded-full flex items-center justify-center flex-shrink-0"
                style={{ 
                  width: fluidSizing.size.iconXl,
                  height: fluidSizing.size.iconXl,
                  marginRight: fluidSizing.space.md
                }}
              >
                <svg 
                  xmlns="http://www.w3.org/2000/svg" 
                  fill="none" 
                  viewBox="0 0 24 24" 
                  stroke="currentColor"
                  strokeWidth={2.5}
                  style={{ 
                    width: fluidSizing.size.iconMd,
                    height: fluidSizing.size.iconMd
                  }}
                >
                  <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                </svg>
              </div>
              <p 
                className="leading-snug"
                style={{ fontSize: fluidSizing.text.base }}
              >
                {feature}
              </p>
            </div>
          ))}
        </div>

        {/* Sección de Membresías */}
        <div 
          className="border-t border-white/20"
          style={{ 
            marginTop: fluidSizing.space['2xl'],
            paddingTop: fluidSizing.space.xl
          }}
        >
          <h2 
            className="font-semibold"
            style={{ 
              fontSize: fluidSizing.text.xl,
              marginBottom: fluidSizing.space.sm
            }}
          >
            {t('hero.membershipTitle')}
          </h2>
          <p 
            className="leading-relaxed"
            style={{ 
              fontSize: fluidSizing.text.sm,
              marginBottom: fluidSizing.space.lg,
              opacity: 0.85
            }}
          >
            {t('hero.membershipDescription')}
          </p>
          
          {/* Iconos de membresías */}
          {!levelsLoading && membershipLevels.length > 0 && (
            <div className="flex items-center justify-start" style={{ gap: fluidSizing.space.md }}>
              {membershipLevels.map((level) => (
                <div 
                  key={level.id}
                  className="rounded-full overflow-hidden bg-white/10 border-2 border-white/30 shadow-lg"
                  title={level.name}
                  style={{ 
                    width: fluidSizing.size.floatingButton,
                    height: fluidSizing.size.floatingButton
                  }}
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
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
});

HeroSection.displayName = 'HeroSection';

export default HeroSection;
