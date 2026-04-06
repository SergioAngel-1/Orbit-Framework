/**
 * BenefitCard - Tarjeta para mostrar un beneficio de membresía
 * Usa CollapsibleSection con variante soft
 */

import { useTranslation } from 'react-i18next';
import { IconType } from 'react-icons';
import { FiGift } from 'react-icons/fi';
import { ActiveBenefit } from '../../services/membership/benefitsApiService';
import CollapsibleSection from '../common/CollapsibleSection';
import { fluidSizing } from '../../utils/fluidSizing';
import { getBenefitDetailsTranslated } from '../../config/benefitsConfig';
import { transformClubText, transformClubTextFull } from '../../utils/clubNarrative';

interface BenefitCardProps {
  benefit: ActiveBenefit;
  icon?: IconType;
  defaultExpanded?: boolean;
  variant?: 'default' | 'soft';
}

const BenefitCard = ({ benefit, icon: Icon = FiGift, defaultExpanded = false, variant = 'soft' }: BenefitCardProps) => {
  const { t } = useTranslation('benefitsConfig');
  const { t: tMembership } = useTranslation('membershipComponents');
  const details = getBenefitDetailsTranslated(benefit.key, t);
  
  /**
   * Formatea el valor del beneficio de referidos para hacerlo más legible
   * El backend ahora envía "1% (N1) / 0.2% (N2)" - solo expandimos las abreviaturas
   */
  const formatReferralBonusValue = (value: string): string => {
    return value
      .replace(/\(N1\)/g, `(${tMembership('levelCard.referralLevel1', 'Nivel 1')})`)
      .replace(/\(N2\)/g, `(${tMembership('levelCard.referralLevel2', 'Nivel 2')})`);
  };

  // Formatear el display_value para beneficios de referidos
  const displayValue = benefit.display_value 
    ? (benefit.key === 'referral_bonus' ? formatReferralBonusValue(benefit.display_value) : benefit.display_value)
    : benefit.description;
  
  return (
    <CollapsibleSection
      title={transformClubText(benefit.name)}
      subtitle={displayValue ? transformClubTextFull(displayValue) : undefined}
      icon={Icon}
      variant={variant}
      collapsible={true}
      defaultExpanded={defaultExpanded}
    >
      <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.sm }}>
        {/* Descripción principal */}
        <p className="text-texto" style={{ fontSize: fluidSizing.text.sm }}>
          {details?.description || benefit.description}
        </p>
        
        {/* Lista de características */}
        {details?.features && (
          <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.xs }}>
            {details.features.map((feature, index) => {
              const FeatureIcon = feature.icon;
              return (
                <div 
                  key={index}
                  className="flex items-center text-texto"
                  style={{ gap: fluidSizing.space.xs, fontSize: fluidSizing.text.xs }}
                >
                  <FeatureIcon 
                    className="text-primario flex-shrink-0" 
                    style={{ width: fluidSizing.size.iconXs, height: fluidSizing.size.iconXs }} 
                  />
                  <span>{feature.text}</span>
                </div>
              );
            })}
          </div>
        )}
      </div>
    </CollapsibleSection>
  );
};

export default BenefitCard;
