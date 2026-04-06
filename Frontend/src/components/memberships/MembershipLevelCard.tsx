/**
 * MembershipLevelCard - Tarjeta individual de nivel de membresía
 * Muestra información de un nivel con sus beneficios incluidos
 * Usa fluidSizing y paleta de colores del tema
 * 
 * Los datos de niveles (incluyendo icon_url) vienen de la API
 * para mantener consistencia con el backend.
 * 
 * Los beneficios se obtienen dinámicamente desde la API del backend
 * a través del endpoint /starter/v1/memberships/levels/{level}/benefits
 */

import { useTranslation } from 'react-i18next';
import { FiCheck, FiStar, FiLock, FiClock, FiArrowUp } from 'react-icons/fi';
import { MembershipLevel, MembershipBenefit } from '../../services/membership/membershipTypes';
import VirtualCoinPrice from '../common/VirtualCoinPrice';
import { fluidSizing } from '../../utils/fluidSizing';
import { formatCurrency } from '../../utils/formatters';
import { transformClubText } from '../../utils/clubNarrative';

/**
 * Obtiene la etiqueta de periodicidad para mostrar los Virtual Coins
 * @param renewalPeriod - Periodo de renovación de la membresía
 * @param t - Función de traducción
 * @returns Etiqueta traducida para la periodicidad
 */
const getRenewalPeriodLabel = (renewalPeriod: string | undefined, t: (key: string) => string): string => {
  const key = renewalPeriod || 'default';
  return t(`purchaseModal.periods.${key}`);
};

/**
 * Transforma los nombres de beneficios a la narrativa del club
 * Usa la utilidad centralizada de clubNarrative
 */
const transformBenefitName = transformClubText;

/**
 * Formatea el valor del beneficio de referidos para hacerlo más legible
 * El backend ahora envía "1% (N1) / 0.2% (N2)" - solo expandimos las abreviaturas
 */
const formatReferralBonusValue = (value: string, t: (key: string, opts?: any) => string): string => {
  // Expandir abreviaturas N1 -> Nivel 1, N2 -> Nivel 2
  return value
    .replace(/\(N1\)/g, `(${t('levelsModal.level', { id: 1 })})`)
    .replace(/\(N2\)/g, `(${t('levelsModal.level', { id: 2 })})`);
};

interface MembershipLevelCardProps {
  level: MembershipLevel;
  /** Beneficios del nivel (obtenidos de la API) */
  benefits?: MembershipBenefit[];
  isCurrentLevel?: boolean;
  isUpgrade?: boolean;
  onSelect?: (level: MembershipLevel) => void;
  showBenefits?: boolean;
}

const MembershipLevelCard = ({
  level,
  benefits = [],
  isCurrentLevel = false,
  isUpgrade = false,
  onSelect,
  showBenefits = true
}: MembershipLevelCardProps) => {
  const { t } = useTranslation('membershipComponents');
  // Determinar el número de nivel para comparaciones (necesario antes de handleClick)
  const levelNum = level.level !== undefined ? Number(level.level) : Number(level.id);
  const isFreeLevel = level.is_free === true || levelNum === 0;
  const hasProduct = level.has_product === true;
  
  // Verificar si el usuario cumple con la antigüedad mínima requerida
  const meetsMinRegistration = level.user_meets_seniority !== false;
  const minRegistrationDays = level.min_registration_days || 0;
  const isBlockedBySeniority = minRegistrationDays > 0 && !meetsMinRegistration;
  
  // Estados adicionales del botón - movidos antes de handleClick para poder usarlos
  // isDowngrade: NO es el nivel actual, NO es upgrade, y el nivel es mayor a 0 (no es el gratuito)
  const isDowngrade = !isCurrentLevel && !isUpgrade && !isFreeLevel;
  const isPurchasable = level.purchasable !== false && !level.admin_only;
  
  // Nivel 5 (Antigüedad) no está a la venta - detectar por nivel, slug o nombre
  const isAntiguedad = level.level === 5 || 
    level.slug?.toLowerCase().includes('antiguedad') || 
    level.name?.toLowerCase().includes('antigüedad') ||
    level.name?.toLowerCase().includes('antiguedad');

  const handleClick = () => {
    // No hacer nada en estados deshabilitados
    if (!onSelect) return;
    if (isCurrentLevel) return;
    if (isFreeLevel) return;
    if (!hasProduct) return;
    if (isBlockedBySeniority) return;
    if (isDowngrade) return; // Bloquear si ya superó este nivel
    if (!isPurchasable || isAntiguedad) return; // Bloquear si no es comprable
    
    onSelect(level);
  };

  const daysUntilEligible = level.user_days_until_eligible || 0;

  // Determinar si la card es clickeable
  const isClickable = !isCurrentLevel && !isFreeLevel && hasProduct && !isBlockedBySeniority && !isDowngrade && isPurchasable && !isAntiguedad;

  return (
    <div
      className={`
        relative bg-white rounded-lg shadow-sm border overflow-hidden transition-all duration-200 flex flex-col h-full
        ${isCurrentLevel 
          ? 'border-primario/30 ring-2 ring-primario/20' 
          : isClickable
            ? 'border-gray-100 hover:shadow-md hover:border-secundario cursor-pointer'
            : 'border-gray-100 opacity-75 cursor-not-allowed'
        }
      `}
      onClick={handleClick}
    >
      {/* Header con gradiente del color del nivel */}
      <div 
        className="relative flex-shrink-0"
        style={{ 
          background: `linear-gradient(135deg, ${level.color}15 0%, ${level.color}30 100%)`,
          padding: fluidSizing.space.lg,
          borderBottom: `3px solid ${level.color}`
        }}
      >
        {/* Badge de nivel actual - solo estrella en mobile */}
        {isCurrentLevel && (
          <>
            {/* Mobile: solo estrella */}
            <div 
              className="absolute top-2 right-2 bg-primario text-white font-bold rounded-full flex items-center justify-center md:hidden"
              style={{ 
                width: fluidSizing.size.iconLg, 
                height: fluidSizing.size.iconLg
              }}
            >
              <FiStar style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
            </div>
            {/* Desktop: estrella + texto */}
            <div 
              className="absolute top-2 right-2 bg-primario text-white font-bold rounded-full hidden md:flex items-center"
              style={{ 
                fontSize: fluidSizing.text['2xs'], 
                paddingLeft: fluidSizing.space.sm, 
                paddingRight: fluidSizing.space.sm, 
                paddingTop: fluidSizing.space.xs, 
                paddingBottom: fluidSizing.space.xs,
                gap: fluidSizing.space.xs
              }}
            >
              <FiStar style={{ width: fluidSizing.size.iconXs, height: fluidSizing.size.iconXs }} />
              {t('levelCard.yourLevel')}
            </div>
          </>
        )}
        
        {/* Badge de upgrade - oculto en mobile */}
        {isUpgrade && !isCurrentLevel && (
          <div 
            className="absolute top-2 right-2 bg-primario text-white font-semibold rounded-full hidden md:flex items-center justify-center"
            style={{ 
              width: fluidSizing.size.iconLg, 
              height: fluidSizing.size.iconLg
            }}
          >
            <FiArrowUp style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
          </div>
        )}

        {/* Icono y nombre */}
        <div className="flex items-center" style={{ gap: fluidSizing.space.md }}>
          <div 
            className="flex items-center justify-center rounded-full flex-shrink-0 overflow-hidden border-2"
            style={{ 
              backgroundColor: 'white', 
              borderColor: level.color,
              width: fluidSizing.size.floatingButton, 
              height: fluidSizing.size.floatingButton 
            }}
          >
            <img 
              src={level.icon_url || ''} 
              alt={level.name}
              className="w-full h-full object-cover"
            />
          </div>
          <div>
            <h3 className="font-bold text-oscuro" style={{ fontSize: fluidSizing.text.lg }}>{level.name}</h3>
            {isAntiguedad ? (
              <span className="text-texto font-medium flex items-center" style={{ fontSize: fluidSizing.text.xs, gap: fluidSizing.space.xs }}>
                <FiLock style={{ width: fluidSizing.size.iconXs, height: fluidSizing.size.iconXs }} />
                {t('levelCard.byLoyalty')}
              </span>
            ) : (
              <div className="font-bold text-primario" style={{ fontSize: fluidSizing.text.base }}>
                {level.product_info?.product_sale_price !== null && level.product_info?.product_sale_price !== undefined ? (
                  <>
                    <span className="text-texto/50 line-through font-normal" style={{ fontSize: fluidSizing.text.sm }}>
                      {formatCurrency(level.product_info.product_regular_price, false)}
                    </span>{' '}
                    {level.product_info.product_sale_price === 0 ? (
                      <span className="text-primario">{t('levelCard.free')}</span>
                    ) : (
                      formatCurrency(level.product_info.product_sale_price, false)
                    )}
                  </>
                ) : level.is_free ? (
                  <span className="text-acento font-semibold" style={{ fontSize: fluidSizing.text.sm }}>{t('levelCard.free')}</span>
                ) : (
                  formatCurrency(level.product_info?.product_price ?? level.price_min, false)
                )}
                {level.product_info?.product_sale_price !== 0 && !level.is_free && (
                  <span className="text-texto font-normal" style={{ fontSize: fluidSizing.text.xs }}>
                    {' COP'}
                    {level.product_info?.renewal_period === 'none' ? '' :
                     level.product_info?.renewal_period === 'monthly' ? t('levelCard.periodMonthly') :
                     level.product_info?.renewal_period === 'bimonthly' ? t('levelCard.periodBimonthly') :
                     level.product_info?.renewal_period === 'quarterly' ? t('levelCard.periodQuarterly') :
                     level.product_info?.renewal_period === 'biannual' ? t('levelCard.periodBiannual') :
                     level.product_info?.renewal_period === 'annual' ? t('levelCard.periodAnnual') : t('levelCard.periodMonthly')}
                  </span>
                )}
              </div>
            )}
          </div>
        </div>
      </div>

      <div className="flex flex-col flex-1" style={{ padding: fluidSizing.space.lg }}>
        {/* Puntos por periodo */}
        <div 
          className="flex items-center justify-center bg-secundario/30 rounded-lg"
          style={{ 
            gap: fluidSizing.space.sm, 
            paddingTop: fluidSizing.space.sm, 
            paddingBottom: fluidSizing.space.sm, 
            paddingLeft: fluidSizing.space.md, 
            paddingRight: fluidSizing.space.md,
            marginBottom: fluidSizing.space.md
          }}
        >
          <VirtualCoinPrice amount={level.product_info?.monthly_points ?? level.monthly_points} size="sm" showLabel={false} />
          <span className="text-texto" style={{ fontSize: fluidSizing.text.xs }}>{t('levelCard.VirtualCoinsPerPeriod', { period: getRenewalPeriodLabel(level.product_info?.renewal_period, t) })}</span>
        </div>

        {/* Lista de beneficios */}
        {showBenefits && (
          <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.xs, marginBottom: fluidSizing.space.md }}>
            {/* Si es nivel 0 (gratuito), mostrar beneficios si existen o mensaje especial */}
            {(levelNum === 0 || level.is_free === true) ? (
              <>
                {/* Mostrar beneficios del nivel 0 si existen */}
                {benefits.length > 0 ? (
                  benefits.map((benefit) => {
                    const valueIsObject = benefit.value && typeof benefit.value === 'object' && 'text' in benefit.value;
                    const displayValue: string | undefined = valueIsObject 
                      ? (benefit.value as { text: string }).text 
                      : (typeof benefit.value === 'string' ? benefit.value : undefined);
                    const categories = valueIsObject ? (benefit.value as { text: string; categories?: string[] }).categories : undefined;
                    
                    return (
                      <div
                        key={benefit.key}
                        className="flex items-start text-texto border-b border-secundario/30 last:border-0"
                        style={{ 
                          gap: fluidSizing.space.xs, 
                          fontSize: fluidSizing.text.xs,
                          paddingBottom: fluidSizing.space.xs,
                          paddingTop: fluidSizing.space.xs
                        }}
                      >
                        <span className="flex-shrink-0 mt-0.5" style={{ width: fluidSizing.size.iconSm }}>{benefit.icon}</span>
                        <div className="flex-1 min-w-0">
                          <span className="font-medium truncate block">{transformBenefitName(benefit.name)}</span>
                          {displayValue && (
                            <span className="text-primario font-medium block" style={{ fontSize: fluidSizing.text['2xs'] }}>
                              {benefit.key === 'referral_bonus' ? formatReferralBonusValue(displayValue, t) : displayValue}
                            </span>
                          )}
                          {categories && categories.length > 0 && (
                            <span className="text-texto/60 block" style={{ fontSize: fluidSizing.text['2xs'] }}>
                              {t('levelCard.categoriesIn', { categories: categories.join(', ') })}
                            </span>
                          )}
                        </div>
                        <FiCheck className="text-primario flex-shrink-0 mt-0.5" style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                      </div>
                    );
                  })
                ) : (
                  <div className="bg-secundario/20 rounded-lg text-center" style={{ padding: fluidSizing.space.md }}>
                    <p className="text-texto" style={{ fontSize: fluidSizing.text.sm }}>{t('levelCard.basicAccess')}</p>
                  </div>
                )}
                
                {/* Disclaimer incentivando upgrade - siempre visible en nivel 0 */}
                <div 
                  className="bg-gradient-to-r from-primario/10 to-secundario/30 rounded-lg border border-primario/20"
                  style={{ padding: fluidSizing.space.md, marginTop: fluidSizing.space.sm }}
                >
                  <div className="flex items-start" style={{ gap: fluidSizing.space.sm }}>
                    <FiArrowUp 
                      className="text-primario flex-shrink-0 mt-0.5" 
                      style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} 
                    />
                    <div>
                      <p className="text-primario font-semibold" style={{ fontSize: fluidSizing.text.xs }}>
                        {t('levelCard.upgradeTitle')}
                      </p>
                      <p className="text-texto/70" style={{ fontSize: fluidSizing.text['2xs'], marginTop: '2px' }}>
                        {t('levelCard.upgradeDesc')}
                      </p>
                    </div>
                  </div>
                </div>
              </>
            ) : benefits.length > 0 ? (
              // Mostrar beneficios dinámicos de la API
              benefits.map((benefit) => {
                // Determinar si el valor es un objeto con categorías
                const valueIsObject = benefit.value && typeof benefit.value === 'object' && 'text' in benefit.value;
                const displayValue: string | undefined = valueIsObject 
                  ? (benefit.value as { text: string }).text 
                  : (typeof benefit.value === 'string' ? benefit.value : undefined);
                const categories = valueIsObject ? (benefit.value as { text: string; categories?: string[] }).categories : undefined;
                
                return (
                  <div
                    key={benefit.key}
                    className="flex items-start text-texto border-b border-secundario/30 last:border-0"
                    style={{ 
                      gap: fluidSizing.space.xs, 
                      fontSize: fluidSizing.text.xs,
                      paddingBottom: fluidSizing.space.xs,
                      paddingTop: fluidSizing.space.xs
                    }}
                  >
                    {/* Icono */}
                    <span className="flex-shrink-0 mt-0.5" style={{ width: fluidSizing.size.iconSm }}>{benefit.icon}</span>
                    
                    {/* Contenido */}
                    <div className="flex-1 min-w-0">
                      <span className="font-medium truncate block">{transformBenefitName(benefit.name)}</span>
                      {/* Valor en línea separada si existe */}
                      {displayValue && (
                        <span className="text-primario font-medium block" style={{ fontSize: fluidSizing.text['2xs'] }}>
                          {benefit.key === 'referral_bonus' ? formatReferralBonusValue(displayValue, t) : displayValue}
                        </span>
                      )}
                      {/* Categorías si existen */}
                      {categories && categories.length > 0 && (
                        <span className="text-texto/60 block" style={{ fontSize: fluidSizing.text['2xs'] }}>
                          {t('levelCard.categoriesIn', { categories: categories.join(', ') })}
                        </span>
                      )}
                    </div>
                    {/* Check siempre al extremo derecho */}
                    <FiCheck className="text-primario flex-shrink-0 mt-0.5" style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                  </div>
                );
              })
            ) : (
              // Fallback si no hay beneficios cargados
              <div className="bg-secundario/20 rounded-lg text-center" style={{ padding: fluidSizing.space.md }}>
                <p className="text-texto/70" style={{ fontSize: fluidSizing.text.xs }}>{t('levelCard.loadingBenefits')}</p>
              </div>
            )}
          </div>
        )}

        {/* Espaciador para empujar el botón al final */}
        <div className="flex-1" />

        {/* Botón de acción - siempre al final */}
        {isCurrentLevel ? (
          // Estado: Nivel actual del usuario
          <button
            disabled
            className="w-full rounded-md font-medium text-center bg-secundario text-primario mt-auto cursor-default flex items-center justify-center"
            style={{ 
              paddingTop: fluidSizing.space.sm, 
              paddingBottom: fluidSizing.space.sm, 
              paddingLeft: fluidSizing.space.md, 
              paddingRight: fluidSizing.space.md, 
              fontSize: fluidSizing.text.sm,
              gap: fluidSizing.space.xs
            }}
          >
            <FiCheck style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
            {t('levelCard.currentMembership')}
          </button>
        ) : isFreeLevel ? (
          // Estado: Nivel gratuito (nivel 0)
          <div 
            className="w-full rounded-md font-medium text-center bg-gray-100 text-texto/60 mt-auto cursor-default flex items-center justify-center"
            style={{ 
              paddingTop: fluidSizing.space.sm, 
              paddingBottom: fluidSizing.space.sm, 
              paddingLeft: fluidSizing.space.md, 
              paddingRight: fluidSizing.space.md, 
              fontSize: fluidSizing.text.sm,
              gap: fluidSizing.space.xs
            }}
          >
            {t('levelCard.basicLevel')}
          </div>
        ) : !hasProduct ? (
          // Estado: Sin producto WC asociado (PRIMERO - antes de cualquier otra validación)
          <div 
            className="w-full rounded-md font-medium text-center bg-gray-100 text-texto/50 mt-auto cursor-not-allowed flex items-center justify-center"
            style={{ 
              paddingTop: fluidSizing.space.sm, 
              paddingBottom: fluidSizing.space.sm, 
              paddingLeft: fluidSizing.space.md, 
              paddingRight: fluidSizing.space.md, 
              fontSize: fluidSizing.text.sm,
              gap: fluidSizing.space.xs
            }}
          >
            {t('levelCard.comingSoon')}
          </div>
        ) : isBlockedBySeniority ? (
          // Estado: Bloqueado por antigüedad
          <div 
            className="w-full rounded-md font-medium text-center bg-amber-50 text-amber-700 mt-auto cursor-not-allowed flex flex-col items-center justify-center"
            style={{ 
              paddingTop: fluidSizing.space.sm, 
              paddingBottom: fluidSizing.space.sm, 
              paddingLeft: fluidSizing.space.md, 
              paddingRight: fluidSizing.space.md, 
              fontSize: fluidSizing.text.sm,
              gap: fluidSizing.space.xs
            }}
          >
            <div className="flex items-center" style={{ gap: fluidSizing.space.xs }}>
              <FiClock style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
              {t('levelCard.requiresSeniority')}
            </div>
            <span style={{ fontSize: fluidSizing.text.xs }}>
              {daysUntilEligible > 365 
                ? t('levelCard.yearsRemaining', { count: Math.ceil(daysUntilEligible / 365) })
                : daysUntilEligible > 30 
                  ? t('levelCard.monthsRemaining', { count: Math.ceil(daysUntilEligible / 30) })
                  : t('levelCard.daysRemaining', { count: daysUntilEligible })
              }
            </span>
          </div>
        ) : !isPurchasable || isAntiguedad ? (
          // Estado: No comprable (admin_only o purchasable: false)
          <div 
            className="w-full rounded-md font-medium text-center bg-gray-100 text-texto/50 mt-auto cursor-not-allowed flex items-center justify-center"
            style={{ 
              paddingTop: fluidSizing.space.sm, 
              paddingBottom: fluidSizing.space.sm, 
              paddingLeft: fluidSizing.space.md, 
              paddingRight: fluidSizing.space.md, 
              fontSize: fluidSizing.text.sm,
              gap: fluidSizing.space.xs
            }}
          >
            <FiLock style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
            {isAntiguedad ? t('levelCard.loyaltyOnly') : t('levelCard.notAvailable')}
          </div>
        ) : isDowngrade ? (
          // Estado: Downgrade (nivel inferior al actual)
          <div 
            className="w-full rounded-md font-medium text-center bg-gray-100 text-texto/50 mt-auto cursor-not-allowed flex items-center justify-center"
            style={{ 
              paddingTop: fluidSizing.space.sm, 
              paddingBottom: fluidSizing.space.sm, 
              paddingLeft: fluidSizing.space.md, 
              paddingRight: fluidSizing.space.md, 
              fontSize: fluidSizing.text.sm,
              gap: fluidSizing.space.xs
            }}
          >
            {t('levelCard.alreadySurpassed')}
          </div>
        ) : (
          // Estado: Disponible para compra (upgrade)
          <button
            className="w-full rounded-md font-medium transition-colors bg-primario hover:bg-hover text-white mt-auto"
            style={{ 
              paddingTop: fluidSizing.space.sm, 
              paddingBottom: fluidSizing.space.sm, 
              paddingLeft: fluidSizing.space.md, 
              paddingRight: fluidSizing.space.md, 
              fontSize: fluidSizing.text.sm 
            }}
            onClick={(e) => {
              e.stopPropagation();
              handleClick();
            }}
          >
            {isUpgrade ? t('levelCard.upgradeMembership') : t('levelCard.selectMembership')}
          </button>
        )}
      </div>
    </div>
  );
};

export default MembershipLevelCard;
