import React from 'react';
import { useTranslation } from 'react-i18next';
import { ShippingOption, ShippingOptionType } from '../../hooks/useShippingOptions';
import VirtualCoinPrice from '../common/VirtualCoinPrice';

interface ShippingOptionCardProps {
  option: ShippingOption;
  isSelected: boolean;
  onSelect: (optionId: ShippingOptionType) => void;
  variant?: 'default' | 'green';
  disabled?: boolean;
}

const ShippingOptionCard: React.FC<ShippingOptionCardProps> = ({
  option,
  isSelected,
  onSelect,
  variant = 'default',
  disabled = false
}) => {
  const { t } = useTranslation('checkoutComponents');
  const getBorderColor = () => {
    if (variant === 'green') {
      return isSelected ? 'border-green-500' : 'border-gray-200';
    }
    return isSelected ? 'border-primario' : 'border-gray-200';
  };

  const getBackgroundColor = () => {
    if (variant === 'green') {
      return isSelected ? 'bg-green-50/50' : 'bg-white';
    }
    return isSelected ? 'bg-primario/5' : 'bg-white';
  };

  const getRadioColor = () => {
    if (variant === 'green') {
      return isSelected ? 'border-green-500' : 'border-gray-300';
    }
    return isSelected ? 'border-primario' : 'border-gray-300';
  };

  const getRadioDotColor = () => {
    if (variant === 'green') {
      return 'bg-green-500';
    }
    return 'bg-primario';
  };

  return (
    <label 
      className={`relative flex items-start space-x-3 p-4 rounded-lg border-2 transition-all ${
        disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'
      } ${
        getBackgroundColor()
      } ${
        getBorderColor()
      } ${
        isSelected && !disabled ? 'shadow-md' : ''
      }`}
      onClick={(e) => disabled && e.preventDefault()}
    >
      {/* Radio button visual */}
      <div className="flex items-center pt-0.5">
        <div className={`w-5 h-5 rounded-full border-2 flex items-center justify-center transition-all ${
          getRadioColor()
        }`}>
          {isSelected && (
            <div className={`w-3 h-3 rounded-full ${getRadioDotColor()}`} />
          )}
        </div>
      </div>
      
      <input
        type="radio"
        name="shippingOption"
        checked={isSelected}
        onChange={() => !disabled && onSelect(option.id)}
        disabled={disabled}
        className="sr-only"
      />
      
      <div className="flex-1">
        {/* Header con nombre y precio */}
        <div className="flex justify-between items-start mb-1">
          <div className="flex items-center gap-2">
            <span className="font-semibold text-gray-900 text-sm md:text-base">
              {option.name}
            </span>
          </div>
          {option.price === 0 ? (
            <span className="font-bold text-sm md:text-base ml-2 text-gray-600 whitespace-nowrap flex-shrink-0">
              {t('shippingOption.toBeDefine')}
            </span>
          ) : (
            <VirtualCoinPrice amount={option.price} size="sm" showLabel={false} className="font-bold text-primario ml-2" />
          )}
        </div>
        
        {/* Tiempo de entrega */}
        <div className="flex items-center gap-1.5 mb-1">
          <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <p className="text-gray-600 text-xs md:text-sm">
            {option.description}
          </p>
        </div>

        {/* Badges integrados — solo visibles cuando la opción está seleccionada */}
        {isSelected && (option.badges || (option.badge ? [option.badge] : [])).length > 0 && (
          <div className="flex flex-col gap-1 mt-2 pt-2 border-t border-gray-100">
            {(option.badges || (option.badge ? [option.badge] : [])).map((b, i) => (
              <div key={i} className="flex items-center gap-1.5">
                {b.type === 'success' ? (
                  <svg xmlns="http://www.w3.org/2000/svg" className="h-3.5 w-3.5 text-green-600 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor">
                    <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                  </svg>
                ) : (
                  <svg xmlns="http://www.w3.org/2000/svg" className="h-3.5 w-3.5 text-amber-500 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor">
                    <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clipRule="evenodd" />
                  </svg>
                )}
                <p className={`text-[11px] leading-tight ${
                  b.type === 'success' ? 'text-green-700 font-medium' : 'text-amber-700 font-medium'
                }`}>
                  {b.text}
                </p>
              </div>
            ))}
          </div>
        )}
      </div>
    </label>
  );
};

export default ShippingOptionCard;
