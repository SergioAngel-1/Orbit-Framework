import React from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import ProductCard from '../products/ProductCard';
import { Product } from '../../types/woocommerce';
import { useLanguage } from '../../contexts/LanguageContext';
import useMembershipLevels from '../../hooks/useMembershipLevels';
import { buildCatalogUrl } from '../../utils/membershipRouteUtils';

interface ProductGridProps {
  products: Product[];
  gridType: 'standard' | 'wide' | 'compact';
  categorySlug?: string;
  minProducts?: number;
  /** Nivel mínimo de membresía requerido por la categoría */
  minMembershipLevel?: number;
}

/**
 * Componente que renderiza una cuadrícula de productos con diferentes layouts
 * según el tipo de sección
 */
const ProductGrid: React.FC<ProductGridProps> = ({ 
  products = [], 
  gridType, 
  categorySlug,
  minProducts = 6,
  minMembershipLevel = 0
}) => {
  const { t } = useTranslation('homeProductGrid');
  const { localizedPath } = useLanguage();
  const { levels } = useMembershipLevels();
  // Determinar si mostrar tarjeta "Ver más" cuando hay menos productos del mínimo
  const showViewMore = categorySlug && products.length > 0 && products.length < minProducts;
  if (!products.length) return null;
  
  // Definir el layout según el tipo de sección
  switch (gridType) {
    case 'compact': // Para secciones bottom (2x2)
      return (
        <div className="grid grid-cols-2 gap-3">
          {products.slice(0, 4).map((product) => (
            <ProductCard key={product.id} product={product} className="h-full" />
          ))}
          {showViewMore && (
            <Link 
              to={localizedPath(buildCatalogUrl(categorySlug!, minMembershipLevel, levels))}
              className="flex flex-col items-center justify-center border border-gray-200 rounded-lg p-4 h-full hover:border-primario hover:shadow-md transition-all duration-200 min-h-[200px]"
            >
              <div className="flex-1 flex items-center justify-center">
                <div className="rounded-full bg-primario/10 p-4">
                  <svg xmlns="http://www.w3.org/2000/svg" className="h-8 w-8 text-primario" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                  </svg>
                </div>
              </div>
              <div className="mt-4 text-center">
                <p className="font-medium text-primario">{t('viewMore')}</p>
              </div>
            </Link>
          )}
        </div>
      );
      
    case 'wide': // Para secciones top_2 y middle_2 (fila adaptable)
      return (
        <div className="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-8 gap-2">
          {products.slice(0, 8).map((product) => (
            <ProductCard key={product.id} product={product} className="h-full" />
          ))}
          {showViewMore && (
            <Link 
              to={localizedPath(buildCatalogUrl(categorySlug!, minMembershipLevel, levels))}
              className="flex flex-col items-center justify-center border border-gray-200 rounded-lg p-4 h-full hover:border-primario hover:shadow-md transition-all duration-200 min-h-[200px]"
            >
              <div className="flex-1 flex items-center justify-center">
                <div className="rounded-full bg-primario/10 p-4">
                  <svg xmlns="http://www.w3.org/2000/svg" className="h-8 w-8 text-primario" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                  </svg>
                </div>
              </div>
              <div className="mt-4 text-center">
                <p className="font-medium text-primario">{t('viewMore')}</p>
              </div>
            </Link>
          )}
        </div>
      );
      
    case 'standard': // Para secciones top_1 y middle_1 (grid adaptable)
    default:
      return (
        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-2">
          {products.map((product) => (
            <ProductCard key={product.id} product={product} className="h-full" />
          ))}
          
          {/* Mostrar "Ver más" cuando hay menos productos del mínimo requerido */}
          {showViewMore && (
            <Link 
              to={localizedPath(buildCatalogUrl(categorySlug!, minMembershipLevel, levels))}
              className="flex flex-col items-center justify-center border border-gray-200 rounded-lg p-4 h-full hover:border-primario hover:shadow-md transition-all duration-200 min-h-[200px]"
            >
              <div className="flex-1 flex items-center justify-center">
                <div className="rounded-full bg-primario/10 p-4">
                  <svg xmlns="http://www.w3.org/2000/svg" className="h-8 w-8 text-primario" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                  </svg>
                </div>
              </div>
              <div className="mt-4 text-center">
                <p className="font-medium text-primario">{t('viewMore')}</p>
              </div>
            </Link>
          )}
        </div>
      );
  }
};

export default ProductGrid;
