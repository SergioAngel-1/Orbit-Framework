import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import QuantityCounter from '../../common/QuantityCounter';

interface VariationQuantityProps {
  productId: number;
  quantity: number;
  onQuantityChange: (quantity: number) => void;
  hasVariation: boolean;
  maxQuantity?: number; // Stock disponible de la variación
}

/**
 * Componente para seleccionar la cantidad de un producto o variación
 */
const VariationQuantity: React.FC<VariationQuantityProps> = ({
  productId,
  quantity,
  onQuantityChange,
  hasVariation,
  maxQuantity
}) => {
  const { t } = useTranslation('productComponents');
  const [showTooltip, setShowTooltip] = useState(false);

  return (
    <div className="mb-4 md:mb-0">
      <div className="flex items-center gap-2">
        <label className="block text-sm font-medium text-gray-700">
          {t('variationQuantity.quantityLabel')}
        </label>
        {hasVariation && (
          <div className="relative">
            <div
              onClick={() => setShowTooltip(!showTooltip)}
              onBlur={() => setTimeout(() => setShowTooltip(false), 150)}
              tabIndex={0}
              style={{ 
                width: '16px', 
                height: '16px', 
                borderRadius: '50%',
                backgroundColor: '#fef3c7',
                color: '#b45309',
                border: '1px solid #fcd34d',
                fontSize: '10px',
                fontWeight: 'bold',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                cursor: 'pointer',
                lineHeight: 1
              }}
              aria-label={t('variationQuantity.quantityInfoAria')}
            >
              i
            </div>
            {showTooltip && (
              <div 
                className="absolute left-0 bottom-full mb-2 px-3 py-2 bg-gray-800 text-white text-xs rounded-md shadow-lg z-50 text-left"
                style={{ width: 'max-content', maxWidth: 'min(200px, calc(100vw - 2rem))' }}
              >
                {t('variationQuantity.quantityTooltip')}
                <div className="absolute left-2 top-full w-0 h-0 border-l-4 border-r-4 border-t-4 border-transparent border-t-gray-800"></div>
              </div>
            )}
          </div>
        )}
      </div>
      <div className="mt-2">
        <QuantityCounter
          productId={productId}
          quantity={quantity}
          size="lg"
          className="w-fit"
          onQuantityChange={onQuantityChange}
          maxQuantity={maxQuantity}
        />
      </div>
    </div>
  );
};

export default VariationQuantity;
