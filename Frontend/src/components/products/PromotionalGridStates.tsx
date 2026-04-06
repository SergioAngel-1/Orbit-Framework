import React from 'react';
import { useTranslation } from 'react-i18next';
import Loader from '../ui/Loader';
import MembershipBadge from '../common/MembershipBadge';

interface PromotionalGridHeaderProps {
  title: string;
  className?: string;
  minMembershipLevel?: number;
}

/**
 * Encabezado para la sección promocional
 */
export const PromotionalGridHeader: React.FC<PromotionalGridHeaderProps> = ({ title, className = '', minMembershipLevel }) => (
  <header className="mb-4">
    <div className="flex items-center justify-between">
      <div className="flex items-center">
        <div className="w-1.5 h-6 bg-primario rounded-sm mr-2"></div>
        <h3 className={`text-lg md:text-xl font-semibold text-primario ${className}`}>{title}</h3>
      </div>
      {minMembershipLevel !== undefined && minMembershipLevel > 0 && (
        <MembershipBadge level={minMembershipLevel} size="xs" />
      )}
    </div>
    <div className="h-0.5 w-16 bg-primario/30 mt-1.5"></div>
  </header>
);

/**
 * Estado de carga para la grilla promocional
 */
export const PromotionalGridLoading: React.FC<PromotionalGridHeaderProps> = ({ title }) => (
  <div className="mt-8 pt-6 border-t border-gray-100">
    <PromotionalGridHeader title={title} />
    <div className="flex justify-center items-center p-6">
      <Loader size="medium" text="" />
    </div>
  </div>
);

interface PromotionalGridErrorProps {
  title: string;
  errorMessage: string;
}

/**
 * Estado de error para la grilla promocional
 */
export const PromotionalGridError: React.FC<PromotionalGridErrorProps> = ({ 
  title, 
  errorMessage 
}) => (
  <div className="mt-8 pt-6 border-t border-gray-100 product-animate">
    <PromotionalGridHeader title={title} />
    <div className="p-4 bg-white border border-gray-200 shadow-sm rounded-lg">
      <p className="text-sm text-gray-500">{errorMessage}</p>
    </div>
  </div>
);

/**
 * Estado vacío (sin productos) para la grilla promocional
 */
export const PromotionalGridEmpty: React.FC<PromotionalGridHeaderProps> = ({ title }) => {
  const { t } = useTranslation('productComponents');
  return (
    <div className="h-full flex flex-col justify-center product-animate">
      {title && <PromotionalGridHeader title={title} />}
      <div className="p-4 rounded-lg flex flex-col items-center justify-center text-center h-full">
        <div className="mb-2 text-gray-400">
          <svg xmlns="http://www.w3.org/2000/svg" className="h-12 w-12 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M4 6h16M4 10h16M4 14h16M4 18h16" />
          </svg>
        </div>
        <p className="text-sm text-gray-500">{t('promotionalGrid.emptyState')}</p>
      </div>
    </div>
  );
};
