import React from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { useLanguage } from '../../contexts/LanguageContext';

interface VerMasButtonProps {
  to: string;
  className?: string;
  text?: string;
}

const VerMasButton: React.FC<VerMasButtonProps> = ({ 
  to, 
  className = '', 
  text 
}) => {
  const { t } = useTranslation('verMasButton');
  const { localizedPath } = useLanguage();
  const displayText = text ?? t('defaultText');
  return (
    <Link 
      to={localizedPath(to)}
      className={`
        group
        inline-flex items-center
        ${className}
      `}
    >
      {/* Desktop: Enlace con underline animado */}
      <span className="hidden sm:inline-flex items-center">
        <span className="
          relative
          text-primario 
          font-semibold
          text-sm
          transition-colors duration-300
          hover:text-hover
          after:content-['']
          after:absolute
          after:bottom-0
          after:left-0
          after:w-0
          after:h-0.5
          after:bg-primario
          after:transition-all
          after:duration-300
          hover:after:w-full
        ">
          {displayText}
        </span>
        
        {/* Flecha con animación */}
        <svg 
          xmlns="http://www.w3.org/2000/svg" 
          className="h-4 w-4 ml-1 text-primario transition-all duration-300 group-hover:translate-x-1 group-hover:text-hover" 
          fill="none" 
          viewBox="0 0 24 24" 
          stroke="currentColor"
          strokeWidth={2.5}
        >
          <path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" />
        </svg>
      </span>
      
      {/* Mobile: Flecha circular sin bordes */}
      <span className="sm:hidden inline-flex items-center justify-center">
        <svg 
          xmlns="http://www.w3.org/2000/svg" 
          className="h-6 w-6 text-primario transition-all duration-300 group-hover:translate-x-1 group-hover:scale-110 group-hover:text-hover" 
          fill="none" 
          viewBox="0 0 24 24" 
          stroke="currentColor"
          strokeWidth={2.5}
        >
          <path strokeLinecap="round" strokeLinejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" />
        </svg>
      </span>
    </Link>
  );
};

export default VerMasButton;
