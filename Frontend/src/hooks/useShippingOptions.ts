import { useState } from 'react';
import i18n from '../config/i18n';

export type ShippingOptionType = 'express' | 'fast' | 'standard';

export interface ShippingOption {
  id: ShippingOptionType;
  name: string;
  price: number;
  timeRange: string;
  description: string;
  methodId: string;
  badge?: {
    text: string;
    type: 'success' | 'warning' | 'info';
  };
  badges?: Array<{
    text: string;
    type: 'success' | 'warning' | 'info';
  }>;
}

const t = (key: string) => i18n.t(key);

export const getShippingOptions = (): ShippingOption[] => [
  {
    id: 'express',
    name: t('checkoutComponents:shipping.expressName'),
    price: 20000,
    timeRange: t('checkoutComponents:shipping.expressTime'),
    description: t('checkoutComponents:shipping.expressDescription'),
    methodId: 'express_shipping'
  },
  {
    id: 'fast',
    name: t('checkoutComponents:shipping.fastName'),
    price: 15000,
    timeRange: t('checkoutComponents:shipping.fastTime'),
    description: t('checkoutComponents:shipping.fastDescription'),
    methodId: 'fast_shipping'
  },
  {
    id: 'standard',
    name: t('checkoutComponents:shipping.standardName'),
    price: 0,
    timeRange: t('checkoutComponents:shipping.standardTime'),
    description: t('checkoutComponents:shipping.standardDescription'),
    methodId: 'flat_rate',
    badge: {
      text: t('checkoutComponents:shipping.standardBadge'),
      type: 'success'
    },
    badges: [
      {
        text: t('checkoutComponents:shipping.standardRouteBadge'),
        type: 'info'
      },
      {
        text: t('checkoutComponents:shipping.standardBadge'),
        type: 'success'
      }
    ]
  }
];

// Mantener compatibilidad con imports existentes
export const SHIPPING_OPTIONS = getShippingOptions();

interface UseShippingOptionsReturn {
  selectedShipping: ShippingOptionType;
  selectedOption: ShippingOption;
  shippingOptions: ShippingOption[];
  handleShippingChange: (optionId: ShippingOptionType) => void;
  getShippingPrice: () => number;
  getShippingMethodId: () => string;
  getShippingMethodTitle: () => string;
}

export const useShippingOptions = (): UseShippingOptionsReturn => {
  const [selectedShipping, setSelectedShipping] = useState<ShippingOptionType>('standard');

  const shippingOptions = getShippingOptions();
  const selectedOption = shippingOptions.find(opt => opt.id === selectedShipping) || shippingOptions[2];

  const handleShippingChange = (optionId: ShippingOptionType) => {
    setSelectedShipping(optionId);
  };

  const getShippingPrice = (): number => {
    return selectedOption.price;
  };

  const getShippingMethodId = (): string => {
    return selectedOption.methodId;
  };

  const getShippingMethodTitle = (): string => {
    return selectedOption.name;
  };

  return {
    selectedShipping,
    selectedOption,
    shippingOptions,
    handleShippingChange,
    getShippingPrice,
    getShippingMethodId,
    getShippingMethodTitle,
  };
};
