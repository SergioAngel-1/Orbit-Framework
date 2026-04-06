import React from 'react';
import { FiArrowRight, FiLogIn } from 'react-icons/fi';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import useMembershipLevels from '../../hooks/useMembershipLevels';
import { useMembership } from '../../contexts/MembershipContext';
import { useAuth } from '../../contexts/AuthContext';
import { fluidSizing } from '../../utils/fluidSizing';
import { MembershipLevel } from '../../services/membership/membershipTypes';
import { openMembershipLevelsModal } from '../common/MembershipBadge';
import { useLanguage } from '../../contexts/LanguageContext';
import Loader from '../ui/Loader';
import logger from '../../utils/logger';

/**
 * Detectar si un nivel es el de antigüedad (nivel 5)
 * Detecta por ID, level, slug o nombre
 */
const isAntiguedadLevel = (level: MembershipLevel): boolean => {
  // Por ID o level
  if (level.id === 5 || level.level === 5) return true;
  // Por slug
  if (level.slug?.toLowerCase().includes('antiguedad')) return true;
  // Por nombre (con y sin tilde)
  const nameLower = level.name?.toLowerCase() || '';
  if (nameLower.includes('antigüedad') || nameLower.includes('antiguedad') || nameLower.includes('fidelidad')) return true;
  return false;
};

/**
 * Sección de niveles de membresía para el Home
 * Muestra los niveles disponibles de forma compacta con fondo fucsia
 */
const MembershipLevelsSection: React.FC = () => {
  const { levels, loading } = useMembershipLevels();
  const { currentLevel } = useMembership();
  const { isAuthenticated } = useAuth();
  const navigate = useNavigate();
  const { t } = useTranslation('homeMembershipLevels');
  const { localizedPath } = useLanguage();

  // Debug: ver qué niveles llegan de la API
  logger.debug('MembershipLevelsSection', 'Levels from API:', levels.map(l => ({ id: l.id, name: l.name, admin_only: l.admin_only, purchasable: l.purchasable })));

  // Filtrar niveles visibles:
  // - Nivel 0 (Zanahoria gratuito)
  // - Niveles 1-4 (comprables)
  // - Nivel 5 (antigüedad - siempre mostrar aunque sea admin_only)
  const displayLevels = levels.filter(level => {
    // Siempre mostrar el nivel de antigüedad (nivel 5)
    if (isAntiguedadLevel(level)) return true;
    // Nivel 0 gratuito
    if (level.is_free) return true;
    // Niveles comprables (no admin_only)
    return !level.admin_only && level.purchasable;
  });
  
  logger.debug('MembershipLevelsSection', 'Display levels:', displayLevels.map(l => ({ id: l.id, name: l.name })));

  if (loading) {
    return (
      <section 
        className="bg-gradient-to-r from-primario to-hover"
        style={{ paddingTop: fluidSizing.space['2xl'], paddingBottom: fluidSizing.space['2xl'] }}
      >
        <div className="container mx-auto" style={{ paddingLeft: fluidSizing.space.md, paddingRight: fluidSizing.space.md }}>
          <div className="flex justify-center items-center" style={{ minHeight: '200px' }}>
            <Loader size="small" text="" />
          </div>
        </div>
      </section>
    );
  }

  if (displayLevels.length === 0) {
    return null;
  }

  return (
    <section 
      className="bg-gradient-to-r from-primario to-hover relative overflow-hidden"
      style={{ paddingTop: fluidSizing.space['2xl'], paddingBottom: fluidSizing.space['2xl'] }}
    >
      {/* Decoración de fondo */}
      <div className="absolute inset-0 opacity-10">
        <div className="absolute top-0 left-0 w-64 h-64 bg-white rounded-full -translate-x-1/2 -translate-y-1/2" />
        <div className="absolute bottom-0 right-0 w-96 h-96 bg-white rounded-full translate-x-1/3 translate-y-1/3" />
      </div>

      <div className="container mx-auto relative z-10" style={{ paddingLeft: fluidSizing.space.md, paddingRight: fluidSizing.space.md }}>
        {/* Header */}
        <div className="max-w-3xl mx-auto text-center" style={{ marginBottom: fluidSizing.space.xl }}>
          <h2 
            className="font-bold text-white"
            style={{ fontSize: fluidSizing.text['3xl'], marginBottom: fluidSizing.space.sm }}
          >
            {t('title')}
          </h2>
          <p className="text-white/80" style={{ fontSize: fluidSizing.text.base }}>
            {isAuthenticated ? t('subtitle.authenticated') : t('subtitle.guest')}
          </p>
          <div className="w-24 h-1 bg-white/30 mx-auto mt-6 rounded-full" />
        </div>

        {/* Grid de niveles - 6 en desktop, 3 en mobile */}
        <div 
          className="grid grid-cols-3 md:grid-cols-6 max-w-6xl mx-auto"
          style={{ gap: fluidSizing.space.md }}
        >
          {displayLevels.map((level) => {
            const isCurrentLevel = level.id === currentLevel;
            
            return (
              <button
                key={level.id}
                onClick={openMembershipLevelsModal}
                className="group flex flex-col items-center text-center transition-all duration-300 hover:-translate-y-1 cursor-pointer bg-transparent border-none"
                style={{ padding: fluidSizing.space.xs }}
              >
                {/* Icono del nivel - Grande */}
                <div 
                  className={`rounded-full flex items-center justify-center overflow-hidden transition-all duration-300 ${
                    isCurrentLevel 
                      ? 'border-4 border-white shadow-lg shadow-white/30' 
                      : 'border-2 border-white/20 group-hover:border-white/50'
                  }`}
                  style={{ 
                    width: 'clamp(80px, 10vw, 120px)', 
                    height: 'clamp(80px, 10vw, 120px)'
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
                    <span style={{ fontSize: fluidSizing.text['3xl'] }}>{level.icon}</span>
                  )}
                </div>

                {/* Badge nivel actual o espaciador */}
                {isCurrentLevel ? (
                  <span 
                    className="inline-flex items-center bg-white text-primario rounded-full font-medium"
                    style={{ 
                      fontSize: fluidSizing.text.xs,
                      paddingLeft: fluidSizing.space.sm,
                      paddingRight: fluidSizing.space.sm,
                      paddingTop: '2px',
                      paddingBottom: '2px',
                      marginTop: fluidSizing.space.sm
                    }}
                  >
                    {t('badgeCurrent')}
                  </span>
                ) : (
                  <div style={{ marginTop: fluidSizing.space.sm }} />
                )}
              </button>
            );
          })}
        </div>

        {/* CTA - Diferente para usuarios logueados y no logueados */}
        <div 
          className="text-center flex flex-wrap justify-center items-center" 
          style={{ marginTop: fluidSizing.space.xl, gap: fluidSizing.space.md }}
        >
          <button
            onClick={() => navigate(localizedPath('/membresias'))}
            className="inline-flex items-center bg-white text-primario hover:bg-white/90 rounded-full font-semibold transition-all duration-300 group shadow-lg hover:shadow-xl cursor-pointer border-none"
            style={{ 
              paddingLeft: fluidSizing.space.lg, 
              paddingRight: fluidSizing.space.lg,
              paddingTop: fluidSizing.space.sm,
              paddingBottom: fluidSizing.space.sm,
              fontSize: fluidSizing.text.base,
              gap: fluidSizing.space.sm
            }}
          >
            <span>{t('buttons.viewBenefits')}</span>
            <FiArrowRight 
              className="group-hover:translate-x-1 transition-transform" 
              style={{ width: fluidSizing.size.iconMd, height: fluidSizing.size.iconMd }}
            />
          </button>
          
          {/* Botón de iniciar sesión para usuarios no logueados */}
          {!isAuthenticated && (
            <button
              onClick={() => navigate(localizedPath('/iniciar-sesion'))}
              className="inline-flex items-center bg-white/20 text-white hover:bg-white/30 rounded-full font-semibold transition-all duration-300 group cursor-pointer border-2 border-white/40 hover:border-white/60"
              style={{ 
                paddingLeft: fluidSizing.space.lg, 
                paddingRight: fluidSizing.space.lg,
                paddingTop: fluidSizing.space.sm,
                paddingBottom: fluidSizing.space.sm,
                fontSize: fluidSizing.text.base,
                gap: fluidSizing.space.sm
              }}
            >
              <FiLogIn 
                style={{ width: fluidSizing.size.iconMd, height: fluidSizing.size.iconMd }}
              />
              <span>{t('buttons.login')}</span>
            </button>
          )}
        </div>
      </div>
    </section>
  );
};

export default MembershipLevelsSection;
