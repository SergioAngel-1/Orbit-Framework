/**
 * FreeDeliveriesBanner - Sección de entregas gratis por membresía
 * Permite al usuario elegir si usar una entrega gratis en este pedido
 * Usa CollapsibleSection para consistencia visual con MembershipDiscountSection
 */

import React from 'react';
import { useTranslation } from 'react-i18next';
import { FiTruck, FiCheck } from 'react-icons/fi';
import { FreeDeliveriesData } from '../../services/membership/membershipTypes';
import { fluidSizing } from '../../utils/fluidSizing';
import CollapsibleSection from '../common/CollapsibleSection';
import MembershipBadge from '../common/MembershipBadge';
import { useMembership } from '../../contexts/MembershipContext';

interface FreeDeliveriesBannerProps {
  freeDeliveries: FreeDeliveriesData;
  useFreeDelivery: boolean;
  onToggleFreeDelivery: (use: boolean) => void;
}

const FreeDeliveriesBanner: React.FC<FreeDeliveriesBannerProps> = ({
  freeDeliveries,
  useFreeDelivery,
  onToggleFreeDelivery
}) => {
  const { t } = useTranslation('checkoutComponents');
  const { total_allowed, used, remaining, can_use } = freeDeliveries;
  const { currentLevel, membershipName } = useMembership();

  if (!can_use || remaining <= 0) {
    return null;
  }

  // Header extra: badge con cantidad disponible
  const headerExtra = (
    <span 
      className="font-bold rounded-full"
      style={{ 
        fontSize: fluidSizing.text.xs,
        backgroundColor: 'white',
        color: '#C72C6C',
        paddingLeft: fluidSizing.space.sm,
        paddingRight: fluidSizing.space.sm,
        paddingTop: '2px',
        paddingBottom: '2px'
      }}
    >
      {remaining}x
    </span>
  );

  return (
    <CollapsibleSection
      title={t('freeDeliveries.title', { name: membershipName })}
      subtitle={t('freeDeliveries.subtitle', { used, total: total_allowed })}
      variant="default"
      collapsible={true}
      defaultExpanded={true}
      headerLayout="stacked"
      headerRight={headerExtra}
    >
      {/* Badge de membresía del usuario */}
      <div className="flex items-center" style={{ gap: fluidSizing.space.sm, marginBottom: fluidSizing.space.md }}>
        <MembershipBadge 
          level={currentLevel} 
          size="sm" 
        />
        <p 
          className="text-texto flex items-center"
          style={{ fontSize: fluidSizing.text.xs, gap: fluidSizing.space.xs }}
        >
          <FiTruck style={{ width: fluidSizing.size.iconXs, height: fluidSizing.size.iconXs }} />
          {t('freeDeliveries.membershipBenefit')}
        </p>
      </div>

      {/* Checkbox para usar entrega gratis */}
      <label 
        className={`
          flex items-center cursor-pointer transition-all duration-200 rounded-lg
          ${useFreeDelivery 
            ? 'bg-primario/15 border-2 border-primario' 
            : 'bg-gray-50 border-2 border-gray-200 hover:border-primario/40'
          }
        `}
        style={{ gap: fluidSizing.space.sm, padding: fluidSizing.space.md }}
      >
        <div 
          className={`
            rounded flex items-center justify-center flex-shrink-0 transition-all duration-200
            ${useFreeDelivery 
              ? 'bg-primario text-white' 
              : 'bg-white border-2 border-gray-300'
            }
          `}
          style={{ width: fluidSizing.size.iconMd, height: fluidSizing.size.iconMd }}
        >
          {useFreeDelivery && <FiCheck style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />}
        </div>
        
        <input
          type="checkbox"
          checked={useFreeDelivery}
          onChange={(e) => onToggleFreeDelivery(e.target.checked)}
          className="sr-only"
        />
        
        <div className="flex-1">
          <span 
            className={`font-medium ${useFreeDelivery ? 'text-primario' : 'text-oscuro'}`}
            style={{ fontSize: fluidSizing.text.sm }}
          >
            {t('freeDeliveries.useFreeDelivery')}
          </span>
          {useFreeDelivery && (
            <p className="text-primario/70" style={{ fontSize: fluidSizing.text.xs, marginTop: '2px' }}>
              {t('freeDeliveries.willDeduct', { total: total_allowed })}
            </p>
          )}
        </div>
      </label>
    </CollapsibleSection>
  );
};

export default FreeDeliveriesBanner;
