import React from 'react';
import { useTranslation } from 'react-i18next';

interface BackButtonProps {
  onClick: () => void;
  label?: string;
  href?: string;
}

const BackButton: React.FC<BackButtonProps> = ({ 
  onClick, 
  label,
  href
}) => {
  const { t } = useTranslation('productDetailPage');
  const resolvedLabel = label || t('backButton.label');
  // Determinar qué tipo de elemento renderizar basado en si hay un href
  const Element = href ? 'a' : 'button';
  
  // Props específicos basados en el tipo de elemento
  const elementProps = href 
    ? { href, onClick } // Para <a>
    : { onClick };     // Para <button>
  
  return (
    <Element
      {...elementProps}
      className="inline-flex items-center text-primario hover:text-primario-dark hover:border-transparent transition-colors duration-200 font-medium md:mb-4 pl-0 text-[10px] sm:text-xs md:text-sm"
      aria-label={resolvedLabel}
    >
      <svg xmlns="http://www.w3.org/2000/svg" className="h-3 w-3 sm:h-4 sm:w-4 md:h-5 md:w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 19l-7-7m0 0l7-7m-7 7h18" />
      </svg>
      <span>{resolvedLabel}</span>
    </Element>
  );
};

export default BackButton;
