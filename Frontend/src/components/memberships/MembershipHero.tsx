/**
 * MembershipHero - Sección hero de la página de membresías
 * Muestra el título principal y descripción del sistema de membresías
 */

import { useTranslation } from 'react-i18next';
import { useMembership } from '../../contexts/MembershipContext';
import { useAuth } from '../../contexts/AuthContext';

interface MembershipHeroProps {
  title?: string;
  subtitle?: string;
}

const MembershipHero = ({ 
  title,
  subtitle
}: MembershipHeroProps) => {
  const { t } = useTranslation('membershipComponents');
  const { membershipName, membershipIcon, currentLevel } = useMembership();
  const { isAuthenticated } = useAuth();
  const resolvedTitle = title ?? t('hero.defaultTitle');
  const resolvedSubtitle = subtitle ?? t('hero.defaultSubtitle');

  return (
    <section className="relative bg-gradient-to-br from-primario via-primario to-hover overflow-hidden">
      {/* Patrón decorativo */}
      <div className="absolute inset-0 opacity-10">
        <div className="absolute top-0 left-0 w-72 h-72 bg-white rounded-full -translate-x-1/2 -translate-y-1/2" />
        <div className="absolute bottom-0 right-0 w-96 h-96 bg-white rounded-full translate-x-1/3 translate-y-1/3" />
      </div>
      
      <div className="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 sm:py-20 lg:py-24">
        <div className="text-center">
          {/* Badge de membresía actual */}
          {isAuthenticated && currentLevel >= 0 && (
            <div className="inline-flex items-center gap-2 bg-white/20 backdrop-blur-sm rounded-full px-4 py-2 mb-6">
              <span className="text-2xl">{membershipIcon}</span>
              <span className="text-white font-medium">
                {t('hero.yourMembership', { name: membershipName })}
              </span>
            </div>
          )}
          
          {/* Título */}
          <h1 className="text-3xl sm:text-4xl lg:text-5xl font-bold text-white mb-4">
            {resolvedTitle}
          </h1>
          
          {/* Subtítulo */}
          <p className="text-lg sm:text-xl text-white/90 max-w-2xl mx-auto mb-8">
            {resolvedSubtitle}
          </p>
          
          {/* Estadísticas rápidas */}
          <div className="flex flex-wrap justify-center gap-8 mt-8">
            <div className="text-center">
              <div className="text-3xl sm:text-4xl font-bold text-white">6</div>
              <div className="text-white/80 text-sm">{t('hero.statsLevels')}</div>
            </div>
            <div className="text-center">
              <div className="text-3xl sm:text-4xl font-bold text-white">+50</div>
              <div className="text-white/80 text-sm">{t('hero.statsBenefits')}</div>
            </div>
            <div className="text-center">
              <div className="text-3xl sm:text-4xl font-bold text-white">∞</div>
              <div className="text-white/80 text-sm">{t('hero.statsPossibilities')}</div>
            </div>
          </div>
        </div>
      </div>
      
      {/* Onda decorativa inferior */}
      <div className="absolute bottom-0 left-0 right-0">
        <svg viewBox="0 0 1440 120" fill="none" xmlns="http://www.w3.org/2000/svg" className="w-full h-auto">
          <path d="M0 120L60 105C120 90 240 60 360 45C480 30 600 30 720 37.5C840 45 960 60 1080 67.5C1200 75 1320 75 1380 75L1440 75V120H1380C1320 120 1200 120 1080 120C960 120 840 120 720 120C600 120 480 120 360 120C240 120 120 120 60 120H0Z" fill="white"/>
        </svg>
      </div>
    </section>
  );
};

export default MembershipHero;
