import React from 'react';
import { useTranslation } from 'react-i18next';

interface ShopPageFiltersToggleProps {
  onToggleFilters: () => void;
  filtersVisible: boolean;
}

/**
 * Componente para mostrar el botón de alternar filtros en dispositivos móviles
 */
const ShopPageFiltersToggle: React.FC<ShopPageFiltersToggleProps> = ({ 
  onToggleFilters,
  filtersVisible 
}) => {
  const { t } = useTranslation('shopPage');

  return (
    <button 
      onClick={onToggleFilters}
      className="md:hidden flex items-center justify-center bg-gray-50 hover:bg-gray-100 rounded-md px-2 py-1 border border-gray-200"
      aria-expanded={filtersVisible}
      aria-controls="filter-section"
    >
      <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4 text-primario" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
      </svg>
      <span className="text-xs ml-1 text-primario font-medium">{t('filters.filterButton')}</span>
    </button>
  );
};

export default ShopPageFiltersToggle;
