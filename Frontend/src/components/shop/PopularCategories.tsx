import React, { useMemo, memo } from 'react';
import { useTranslation } from 'react-i18next';
import { Category } from '../../types/woocommerce';

/**
 * Icono de paquete como fallback cuando la categoría no tiene imagen
 */
const PackageIcon: React.FC<{ className?: string }> = memo(({ className }) => (
  <svg 
    xmlns="http://www.w3.org/2000/svg" 
    viewBox="0 0 24 24" 
    fill="none" 
    stroke="currentColor" 
    strokeWidth="2" 
    strokeLinecap="round" 
    strokeLinejoin="round"
    className={className}
  >
    <path d="M16.5 9.4l-9-5.19M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
    <polyline points="3.27 6.96 12 12.01 20.73 6.96" />
    <line x1="12" y1="22.08" x2="12" y2="12" />
  </svg>
));

interface PopularCategoriesProps {
  categories: Category[] | undefined;
  onCategoryChange: (categoryId: number) => void;
  showCondition: boolean;
}

/**
 * Componente para mostrar categorías populares como sugerencia
 * cuando no hay resultados de búsqueda o para ayudar en la navegación
 */
const PopularCategories: React.FC<PopularCategoriesProps> = ({
  categories,
  onCategoryChange,
  showCondition
}) => {
  const { t } = useTranslation('shopPage');

  // Seleccionar las categorías más relevantes (las que tienen más productos)
  // o simplemente las primeras si no tienen count
  // IMPORTANTE: useMemo DEBE estar antes de cualquier return condicional (Rules of Hooks)
  const popularCategories = useMemo(() => {
    if (!categories || categories.length === 0) return [];
    // Ordenar por count (número de productos) si está disponible
    const sorted = [...categories].sort((a, b) => {
      if (a.count && b.count) {
        return b.count - a.count;
      }
      return 0;
    });
    
    // Tomar las primeras 6 categorías
    return sorted.slice(0, 6);
  }, [categories]);

  // No renderizar si no hay categorías disponibles
  if (popularCategories.length === 0) {
    return null;
  }

  return (
    <div className={`bg-gradient-to-b from-gray-50/80 to-white rounded-2xl border border-gray-100 transition-all duration-300 ${showCondition ? 'mt-3 md:mt-8 p-4 md:p-8 opacity-100' : 'mt-0 p-0 max-h-0 opacity-0 overflow-hidden pointer-events-none border-transparent'}`}>
      <h3 className="text-lg md:text-xl font-bold text-primario mb-4 md:mb-5 text-center">
        {t('popularCategories.title')}
      </h3>
      <div className="flex flex-wrap justify-center gap-2.5 md:gap-3">
        {popularCategories.map(category => (
          <button
            key={category.id}
            onClick={() => onCategoryChange(category.id)}
            className="px-3 md:px-5 py-1.5 md:py-2.5 bg-white border border-gray-200 rounded-xl text-[11px] md:text-sm font-medium text-gray-700 hover:bg-primario hover:text-white hover:border-primario hover:shadow-md transition-all duration-300 flex items-center gap-2 md:gap-2.5 w-[calc(50%-0.375rem)] sm:w-auto group"
            aria-label={t('popularCategories.categoryAria', { name: category.name })}
          >
            {/* Icono de categoría */}
            <span className="flex-shrink-0 w-7 h-7 rounded-lg bg-white group-hover:bg-white flex items-center justify-center overflow-hidden transition-colors">
              {category.image?.src ? (
                <img 
                  src={category.image.src} 
                  alt=""
                  role="presentation"
                  className="w-5 h-5 object-contain"
                  loading="lazy"
                />
              ) : (
                <PackageIcon className="w-4 h-4 text-gray-400 group-hover:text-white transition-colors" />
              )}
            </span>
            <span className="line-clamp-2 text-left leading-tight flex-1">{category.name}</span>
          </button>
        ))}
      </div>
    </div>
  );
};

export default PopularCategories;
