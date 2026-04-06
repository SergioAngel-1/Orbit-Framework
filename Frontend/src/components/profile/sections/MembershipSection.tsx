import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { useMembership } from '../../../contexts/MembershipContext';
import { useActiveBenefits } from '../../../hooks/useActiveBenefits';
import { useAuth } from '../../../contexts/AuthContext';
import useMembershipLevels from '../../../hooks/useMembershipLevels';
import Loader from '../../ui/Loader';
import CollapsibleSection from '../../common/CollapsibleSection';
import VirtualCoinPrice from '../../common/VirtualCoinPrice';
import FreeSamplesProgress from '../../checkout/FreeSamplesProgress';
import { useLanguage } from '../../../contexts/LanguageContext';
import { fluidSizing } from '../../../utils/fluidSizing';
import { FiAward, FiArrowRight, FiCheck, FiCalendar, FiTrendingUp, FiTruck } from 'react-icons/fi';
import { getBenefitIcon } from '../../../config/benefitsConfig';

interface MembershipSectionProps {
  onClose?: () => void;
}


const MembershipSection = ({ onClose }: MembershipSectionProps) => {
  const { t } = useTranslation('membershipSection');
  const { localizedPath } = useLanguage();
  const { isAuthenticated } = useAuth();
  const { 
    membership, 
    membershipName, 
    membershipColor, 
    currentLevel, 
    isActive, 
    daysRemaining,
    freeSamples,
    freeDeliveries,
    loading: membershipLoading,
    refreshMembership
  } = useMembership();
  const { getLevelById } = useMembershipLevels();
  const { benefits: activeBenefits, loading: benefitsLoading } = useActiveBenefits(isAuthenticated);
  
  // Refrescar datos de membresía cada vez que se abre esta sección
  useEffect(() => {
    if (isAuthenticated) {
      refreshMembership();
    }
  }, [isAuthenticated, refreshMembership]);
  
  const currentLevelData = getLevelById(currentLevel);
  const loading = membershipLoading || benefitsLoading;

  return (
    <CollapsibleSection
      title={t('title')}
      icon={FiAward}
      collapsible={false}
      showCollapseButton={false}
    >
      {loading ? (
        <div className="flex justify-center items-center" style={{ padding: fluidSizing.space.xl }}>
          <Loader size="medium" />
        </div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.md }}>
        
        {/* Estado actual de membresía */}
        <div 
          className="flex items-center"
          style={{ gap: fluidSizing.space.sm }}
        >
          {/* Icono de nivel */}
          <div 
            className="rounded-full flex items-center justify-center flex-shrink-0 overflow-hidden border-2 border-secundario"
            style={{ 
              backgroundColor: `${membershipColor}15`, 
              width: fluidSizing.size.buttonLg, 
              height: fluidSizing.size.buttonLg 
            }}
          >
            {currentLevelData?.icon_url ? (
              <img 
                src={currentLevelData.icon_url} 
                alt={membershipName}
                className="w-full h-full object-cover"
              />
            ) : (
              <FiAward className="text-primario" style={{ width: '60%', height: '60%' }} />
            )}
          </div>
          
          {/* Info de membresía */}
          <div className="flex-1 min-w-0">
            <p className="font-bold text-oscuro truncate" style={{ fontSize: fluidSizing.text.sm }}>
              {membershipName}
            </p>
            <div className="flex flex-wrap items-center" style={{ gap: '4px', marginTop: '2px' }}>
              {isActive ? (
                <span 
                  className="inline-flex items-center rounded-full font-medium bg-acento/20 text-green-700"
                  style={{ padding: '2px 6px', fontSize: fluidSizing.text['2xs'] }}
                >
                  <FiCheck className="mr-1" style={{ width: '10px', height: '10px' }} />
                  {t('status.active')}
                </span>
              ) : (
                <span 
                  className="inline-flex items-center rounded-full font-medium bg-secundario/50 text-texto"
                  style={{ padding: '2px 6px', fontSize: fluidSizing.text['2xs'] }}
                >
                  {t('status.basic')}
                </span>
              )}
              {daysRemaining !== null && daysRemaining > 0 && (
                <span 
                  className="inline-flex items-center rounded-full font-medium bg-secundario/50 text-oscuro"
                  style={{ padding: '2px 6px', fontSize: fluidSizing.text['2xs'] }}
                >
                  <FiCalendar className="mr-1" style={{ width: '10px', height: '10px' }} />
                  {t('status.daysRemaining', { count: daysRemaining })}
                </span>
              )}
            </div>
          </div>
        </div>

        {/* Stats compactos */}
        <div 
          className="grid grid-cols-2"
          style={{ gap: '6px' }}
        >
          {/* Nivel */}
          <div 
            className="bg-secundario/30 rounded-lg text-center"
            style={{ padding: '6px' }}
          >
            <div 
              className="flex items-center justify-center text-texto"
              style={{ gap: '3px', marginBottom: '2px' }}
            >
              <FiTrendingUp style={{ width: '12px', height: '12px' }} />
              <span style={{ fontSize: fluidSizing.text['2xs'] }}>{t('stats.level')}</span>
            </div>
            <p className="font-bold text-primario" style={{ fontSize: fluidSizing.text.lg }}>
              {currentLevel}
            </p>
          </div>
          
          {/* Virtual Coins por periodo */}
          <div 
            className="bg-secundario/30 rounded-lg text-center"
            style={{ padding: '6px' }}
          >
            <div 
              className="flex items-center justify-center text-texto"
              style={{ gap: '3px', marginBottom: '2px' }}
            >
              <span style={{ fontSize: fluidSizing.text['2xs'] }}>
                {t('stats.fcPer', { period: t(`renewalPeriod.${currentLevelData?.product_info?.renewal_period || membership?.renewal_period || 'default'}`) })}
              </span>
            </div>
            <div className="flex justify-center">
              <VirtualCoinPrice 
                amount={currentLevelData?.product_info?.monthly_points ?? currentLevelData?.monthly_points ?? membership?.monthly_points ?? 0} 
                size="xs" 
                showLabel={false} 
              />
            </div>
          </div>
        </div>

        {/* Muestras gratis */}
        {freeSamples && freeSamples.total_grams > 0 && (
          <FreeSamplesProgress 
            freeSamples={freeSamples} 
            isAuthenticated={isAuthenticated} 
          />
        )}

        {/* Envíos gratis */}
        {freeDeliveries && freeDeliveries.total_allowed > 0 && (
          <div 
            className="bg-primario/5 border border-primario/15 rounded flex items-center"
            style={{ padding: '6px 8px', gap: '6px' }}
          >
            <FiTruck className="text-primario flex-shrink-0" style={{ width: '14px', height: '14px' }} />
            <div className="flex-1 min-w-0" style={{ lineHeight: 1.3 }}>
              <span className="font-medium text-primario" style={{ fontSize: fluidSizing.text['2xs'] }}>
                {t('freeDeliveries.title')}
              </span>
              <span className="text-primario/70" style={{ fontSize: fluidSizing.text['2xs'] }}>
                {' · '}{t('freeDeliveries.remaining', { remaining: freeDeliveries.remaining, total: freeDeliveries.total_allowed })}
                {freeDeliveries.pending_orders_count != null && freeDeliveries.pending_orders_count > 0 && (
                  <span> · 📦 {t('freeDeliveries.inProcess', { count: freeDeliveries.pending_orders_count })}</span>
                )}
                {freeDeliveries.last_used_at && freeDeliveries.used > 0 && (
                  <span className="text-primario/50">
                    {' · '}{t('freeDeliveries.lastUsed', { date: new Date(freeDeliveries.last_used_at).toLocaleDateString('es-CO', { day: 'numeric', month: 'short' }) })}
                  </span>
                )}
              </span>
            </div>
          </div>
        )}

        {/* Beneficios activos */}
        {currentLevel > 0 && activeBenefits.length > 0 && (
          <div>
            <p 
              className="text-texto font-medium"
              style={{ fontSize: fluidSizing.text['2xs'], marginBottom: '4px' }}
            >
              {t('benefits.title', { count: activeBenefits.length })}
            </p>
            <div className="grid grid-cols-3" style={{ gap: '4px' }}>
              {activeBenefits.slice(0, 8).map((benefit) => {
                const IconComponent = getBenefitIcon(benefit.key);
                return (
                  <span
                    key={benefit.key}
                    className="inline-flex items-center bg-secundario/30 text-texto rounded-full"
                    style={{ 
                      fontSize: fluidSizing.text['2xs'], 
                      padding: '2px 6px',
                      gap: '3px'
                    }}
                    title={benefit.description}
                  >
                    <IconComponent style={{ width: '10px', height: '10px', flexShrink: 0 }} />
                    <span className="truncate">{benefit.name}</span>
                  </span>
                );
              })}
              {activeBenefits.length > 8 && (
                <span
                  className="inline-flex items-center justify-center bg-primario/10 text-primario rounded-full font-medium cursor-default"
                  style={{ 
                    fontSize: fluidSizing.text['2xs'], 
                    padding: '2px 6px'
                  }}
                  title={activeBenefits.slice(8).map(b => b.name).join(', ')}
                >
                  {t('benefits.more', { count: activeBenefits.length - 8 })}
                </span>
              )}
            </div>
          </div>
        )}

        {/* Botón mejorar nivel (si puede) */}
        {membership?.can_upgrade && (
          <Link 
            to={localizedPath('/membresias#niveles-section')} 
            className="flex items-center justify-center bg-secundario/30 text-primario hover:bg-secundario/50 rounded-lg transition-all duration-300 font-medium group"
            style={{ padding: fluidSizing.space.sm, fontSize: fluidSizing.text.sm, gap: fluidSizing.space.xs }}
            onClick={onClose}
          >
            <FiTrendingUp style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
            <span>{t('buttons.upgrade')}</span>
          </Link>
        )}

        {/* Enlace a página completa */}
        <Link 
          to={localizedPath('/membresias')} 
          className="flex items-center justify-center bg-primario text-white hover:bg-hover hover:!text-white rounded-lg transition-all duration-300 font-medium group"
          style={{ padding: fluidSizing.space.sm, fontSize: fluidSizing.text.sm, gap: fluidSizing.space.xs }}
          onClick={onClose}
        >
          <span>{t('buttons.viewMemberships')}</span>
          <FiArrowRight 
            className="group-hover:translate-x-1 transition-transform" 
            style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }}
          />
        </Link>
      </div>
      )}
    </CollapsibleSection>
  );
};

export default MembershipSection;
