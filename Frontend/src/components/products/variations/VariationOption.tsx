import React from 'react';
import VirtualCoinPrice from '../../common/VirtualCoinPrice';

interface VariationOptionProps {
  option: string;
  isSelected: boolean;
  variationToShow: any | null;
  onClick: () => void;
}

/**
 * Componente para mostrar una opción de variación con su precio y descuentos
 */
const VariationOption: React.FC<VariationOptionProps> = ({
  option,
  isSelected,
  variationToShow,
  onClick
}) => {
  return (
    <div
      className={`p-2.5 md:p-3 cursor-pointer hover:bg-gray-50 ${isSelected ? 'bg-secundario/20 text-primario font-medium' : ''} flex justify-between items-center h-10 md:h-12 text-sm md:text-base`}
      onClick={onClick}
    >
      <span>{option}</span>
      {variationToShow && (
        <div className="flex items-center gap-2">
          <VirtualCoinPrice 
            amount={parseFloat(variationToShow.price)} 
            size="sm" 
            showLabel={false}
            className="text-primario font-medium"
          />
          {/* Mostrar precio tachado si está en oferta */}
          {variationToShow.on_sale && variationToShow.regular_price && 
            parseFloat(variationToShow.regular_price) > parseFloat(variationToShow.price) && (
            <VirtualCoinPrice 
              amount={parseFloat(variationToShow.regular_price)} 
              size="xs" 
              showLabel={false}
              className="line-through text-gray-500"
            />
          )}
        </div>
      )}
    </div>
  );
};

export default VariationOption;
