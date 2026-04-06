import React, { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { Category } from '../../types/woocommerce';
import Select from '../common/Select';

interface ShopFiltersProps {
  searchTerm: string;
  sortBy: string;
  selectedCategory: number | undefined;
  categories: Category[] | undefined;
  onSearchChange: (e: React.ChangeEvent<HTMLInputElement>) => void;
  onSortChange: (e: React.ChangeEvent<HTMLSelectElement>) => void;
  onCategoryChange: (categoryId: number | undefined) => void;
}

/**
 * Componente para los filtros de la tienda (búsqueda, ordenamiento y categorías)
 * Permite a los usuarios filtrar y ordenar productos de manera intuitiva
 */
const ShopFilters: React.FC<ShopFiltersProps> = ({
  searchTerm,
  sortBy,
  selectedCategory,
  categories,
  onSearchChange,
  onSortChange,
  onCategoryChange
}) => {
  const { t } = useTranslation('shopPage');

  // Generar IDs únicos para mejorar la accesibilidad
  const searchId = useId();
  const sortId = useId();
  const categoryId = useId();

  return (
    <div className="bg-white rounded-lg shadow-md p-6 mb-8 relative" data-testid="shop-filters">
      <h2 className="sr-only">{t('filters.srTitle')}</h2>
      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        {/* Búsqueda */}
        <div className="relative">
          <label htmlFor={searchId} className="block text-sm font-medium text-gray-700 mb-1">
            {t('filters.searchLabel')}
          </label>
          <div className="relative">
            <input
              type="text"
              id={searchId}
              className="w-full border border-gray-300 rounded-md pl-10 pr-3 py-2 focus:outline-none focus:border-primario"
              placeholder={t('filters.searchPlaceholder')}
              value={searchTerm}
              onChange={onSearchChange}
              aria-label={t('filters.searchAria')}
            />
            <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
              <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
              </svg>
            </div>
            {searchTerm && (
              <button 
                className="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600"
                onClick={() => onSearchChange({ target: { value: '' } } as React.ChangeEvent<HTMLInputElement>)}
                aria-label={t('filters.clearSearchAria')}
              >
                <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            )}
          </div>
        </div>

        {/* Ordenar por */}
        <div>
          <label htmlFor={sortId} className="block text-sm font-medium text-gray-700 mb-1">
            {t('filters.sortLabel')}
          </label>
          <Select
            id={sortId}
            value={sortBy}
            onChange={(value) => onSortChange({ target: { value } } as React.ChangeEvent<HTMLSelectElement>)}
            options={[
              { value: 'default', label: t('filters.sortNameAZ') },
              { value: 'price-asc', label: t('filters.sortPriceAsc') },
              { value: 'price-desc', label: t('filters.sortPriceDesc') },
              { value: 'date', label: t('filters.sortDate') },
              { value: 'popularity', label: t('filters.sortPopularity') }
            ]}
            placeholder={t('filters.sortPlaceholder')}
          />
        </div>

        {/* Filtrar por variedad */}
        <div>
          <label htmlFor={categoryId} className="block text-sm font-medium text-gray-700 mb-1">
            {t('filters.categoryLabel')}
          </label>
          <Select
            id={categoryId}
            value={selectedCategory?.toString() || ''}
            onChange={(value) => onCategoryChange(value ? Number(value) : undefined)}
            options={[
              { value: '', label: t('filters.allCategories') },
              ...(categories?.map((category) => ({
                value: category.id.toString(),
                label: category.name
              })) || [])
            ]}
            placeholder={t('filters.allCategories')}
          />
        </div>
      </div>
    </div>
  );
};

export default ShopFilters;