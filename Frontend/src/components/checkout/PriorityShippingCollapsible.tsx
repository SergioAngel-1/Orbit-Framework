import React, { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { ShippingOption, ShippingOptionType } from '../../hooks/useShippingOptions';
import ShippingOptionCard from './ShippingOptionCard';

interface PriorityShippingCollapsibleProps {
  options: ShippingOption[];
  selectedShipping: ShippingOptionType;
  onShippingChange: (optionId: ShippingOptionType) => void;
  disabled?: boolean;
  isPremiumSelected?: boolean;
}

const PriorityShippingCollapsible: React.FC<PriorityShippingCollapsibleProps> = ({
  options,
  selectedShipping,
  onShippingChange,
  disabled = false,
  isPremiumSelected = false
}) => {
  const { t } = useTranslation('checkoutComponents');
  // Iniciar expandido siempre, solo colapsar cuando el usuario selecciona estándar manualmente
  const [isExpanded, setIsExpanded] = useState(true);
  const [hasUserInteracted, setHasUserInteracted] = useState(false);

  // Colapsar automáticamente solo cuando el usuario selecciona envío estándar manualmente
  useEffect(() => {
    if (hasUserInteracted && selectedShipping === 'standard') {
      setIsExpanded(false);
    }
  }, [selectedShipping, hasUserInteracted]);

  const handleShippingSelect = (optionId: ShippingOptionType) => {
    setHasUserInteracted(true);
    onShippingChange(optionId);
  };

  return (
    <div className="list-none bg-gradient-to-br from-primario/5 to-blue-50/50 border-2 border-primario/20 rounded-xl overflow-hidden">
      {/* Header desplegable */}
      <button
        type="button"
        onClick={() => setIsExpanded(!isExpanded)}
        className="w-full flex items-center justify-between p-3 border-0 outline-none focus:outline-none hover:outline-none active:outline-none bg-transparent"
      >
        <div className="flex items-center gap-2">
          <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4 text-primario" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" />
          </svg>
          <span className="text-xs font-semibold text-primario uppercase tracking-wide">{t('priorityShipping.title')}</span>
        </div>
        <svg 
          xmlns="http://www.w3.org/2000/svg" 
          className={`h-5 w-5 text-primario transition-transform ${isExpanded ? 'rotate-180' : ''}`}
          fill="none" 
          viewBox="0 0 24 24" 
          stroke="currentColor"
        >
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
        </svg>
      </button>

      {/* Contenido desplegable */}
      <div 
        className={`overflow-hidden transition-all duration-300 ease-in-out ${
          isExpanded ? 'max-h-[1000px] opacity-100' : 'max-h-0 opacity-0'
        }`}
      >
        <div className="p-3 pt-0 space-y-3">
          {options.map((option) => (
            <ShippingOptionCard
              key={option.id}
              option={option}
              isSelected={selectedShipping === option.id}
              onSelect={handleShippingSelect}
              disabled={disabled}
            />
          ))}

          {/* Disclaimers — solo si se seleccionó envío prioritario */}
          {isPremiumSelected && (
            <>
              {/* Plataformas de terceros, sin responsabilidad por pérdidas */}
              <div className="flex items-start space-x-1.5 border rounded-md p-2 bg-amber-50 border-amber-200 text-amber-800">
                <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4 flex-shrink-0 mt-0.5" viewBox="0 0 20 20" fill="currentColor">
                  <path fillRule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
                </svg>
                <p className="text-[11px] leading-tight">
                  {t('priorityShipping.thirdPartyDisclaimer')}
                </p>
              </div>

              {/* Pago anticipado con tarjeta */}
              <div className="flex items-start space-x-1.5 border rounded-md p-2 bg-primario/10 border-primario/30 text-primario">
                <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4 flex-shrink-0 mt-0.5" viewBox="0 0 20 20" fill="currentColor">
                  <path d="M4 4a2 2 0 00-2 2v1h16V6a2 2 0 00-2-2H4z" />
                  <path fillRule="evenodd" d="M18 9H2v5a2 2 0 002 2h12a2 2 0 002-2V9zM4 13a1 1 0 011-1h1a1 1 0 110 2H5a1 1 0 01-1-1zm5-1a1 1 0 100 2h1a1 1 0 100-2H9z" clipRule="evenodd" />
                </svg>
                <p className="text-xs leading-tight font-medium">
                  {t('checkoutPage:shippingOptions.premiumPaymentNotice')}
                </p>
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
};

export default PriorityShippingCollapsible;
