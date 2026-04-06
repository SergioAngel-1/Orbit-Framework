import React from 'react';
import { useTranslation } from 'react-i18next';
import { ShippingOptionType, ShippingOption } from '../../hooks/useShippingOptions';
import PriorityShippingCollapsible from './PriorityShippingCollapsible';
import ShippingOptionCard from './ShippingOptionCard';
import CollapsibleSection from '../common/CollapsibleSection';
import FreeDeliveriesBanner from './FreeDeliveriesBanner';
import { fluidSizing } from '../../utils/fluidSizing';
import { FreeDeliveriesData } from '../../services/membership/membershipTypes';

interface ShippingOptionsProps {
  selectedShipping: ShippingOptionType;
  shippingOptions: ShippingOption[];
  onShippingChange: (optionId: ShippingOptionType) => void;
  freeDeliveries?: FreeDeliveriesData | null;
  useFreeDelivery?: boolean;
  onToggleFreeDelivery?: (use: boolean) => void;
}

const ShippingOptions: React.FC<ShippingOptionsProps> = ({ 
  selectedShipping,
  shippingOptions,
  onShippingChange,
  freeDeliveries,
  useFreeDelivery = false,
  onToggleFreeDelivery
}) => {
  const { t } = useTranslation('checkoutPage');
  const priorityOptions = shippingOptions.filter(opt => opt.id === 'express' || opt.id === 'fast');
  const standardOptions = shippingOptions.filter(opt => opt.id === 'standard');

  return (
    <CollapsibleSection
      title={t('shippingOptions.title')}
      variant="soft"
      collapsible={false}
      showCollapseButton={false}
    >
      <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.md }}>
        {/* Banner de entregas gratis disponibles */}
        {freeDeliveries && freeDeliveries.remaining > 0 && onToggleFreeDelivery && (
          <FreeDeliveriesBanner
            freeDeliveries={freeDeliveries}
            useFreeDelivery={useFreeDelivery}
            onToggleFreeDelivery={onToggleFreeDelivery}
          />
        )}

        {/* Envíos Prioritarios - Componente Desplegable */}
        <PriorityShippingCollapsible
          options={priorityOptions}
          selectedShipping={selectedShipping}
          onShippingChange={onShippingChange}
          disabled={useFreeDelivery}
          isPremiumSelected={selectedShipping === 'express' || selectedShipping === 'fast'}
        />

        {/* Envío Estándar - Componente Individual */}
        {standardOptions.map((option) => (
          <div key={option.id}>
            <ShippingOptionCard
              option={option}
              isSelected={selectedShipping === option.id}
              onSelect={onShippingChange}
              variant="green"
              disabled={useFreeDelivery}
            />
          </div>
        ))}

        {/* Footnote sutil: posibles demoras (aplica a todas las opciones) */}
        <p className="text-[10px] text-gray-400 leading-tight px-1">
          * {t('checkoutComponents:priorityShipping.delaysDisclaimer')}
        </p>
      </div>
    </CollapsibleSection>
  );
};

export default ShippingOptions;
