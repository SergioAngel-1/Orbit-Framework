import React from 'react';
import { useTranslation } from 'react-i18next';
import VirtualCoinPrice from '../../common/VirtualCoinPrice';

interface VariationSubtotalProps {
  subtotal: number;
  currentVariation: number | null;
  variationsMap: Map<number, any>;
  quantity: number;
}

/**
 * Componente para mostrar el subtotal y descuentos de una variación
 */
const VariationSubtotal: React.FC<VariationSubtotalProps> = ({
  subtotal,
  currentVariation,
  variationsMap,
  quantity
}) => {
  const { t } = useTranslation('productComponents');

  // Verificar si hay descuento para mostrar precio tachado
  const hasDiscount = currentVariation && 
    variationsMap.get(currentVariation)?.regular_price && 
    parseFloat(variationsMap.get(currentVariation)?.regular_price) > 0 &&
    parseFloat(variationsMap.get(currentVariation)?.price || '0') > 0 &&
    parseFloat(variationsMap.get(currentVariation)?.regular_price) > parseFloat(variationsMap.get(currentVariation)?.price || '0');
  

  return (
    <div className="flex flex-col items-end justify-center h-full">
      <div className="flex flex-col items-end">
        <div className="flex items-center gap-2">
          <span className="text-sm font-medium text-gray-700">{t('variationSubtotal.subtotal')}</span>
          <VirtualCoinPrice 
            amount={subtotal} 
            size="xl" 
            className="text-primario font-bold"
          />
        </div>
        
        {/* Solo mostrar el precio tachado si hay un descuento real */}
        {hasDiscount && (
          <div className="flex items-center gap-1 text-sm text-gray-500 mt-1">
            <span>{t('variationSubtotal.regularPrice')}</span>
            <VirtualCoinPrice 
              amount={parseFloat(variationsMap.get(currentVariation)?.regular_price || '0') * quantity} 
              size="sm" 
              showLabel={false}
              className="line-through text-gray-500"
            />
          </div>
        )}
      </div>
    </div>
  );
};

export default VariationSubtotal;
