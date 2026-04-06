import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { gsap } from 'gsap';
import { Product } from '../../types/woocommerce';
import ProductCard from '../products/ProductCard';
import Pagination from '../common/Pagination';
import ResultCounter from '../common/ResultCounter';
import Loader from '../ui/Loader';


interface ProductGridProps {
  products: Product[];
  loading: boolean;
  currentPage: number;
  totalPages: number;
  onPageChange: (page: number) => void;
  searchTerm: string;
  selectedCategory: number | undefined;
}

/**
 * Componente para mostrar la grilla de productos con paginación
 * Incluye animaciones, estados de carga y mensajes cuando no hay resultados
 */
const ProductGrid: React.FC<ProductGridProps> = ({
  products,
  loading,
  currentPage,
  totalPages,
  onPageChange,
  searchTerm,
  selectedCategory
}) => {
  const { t } = useTranslation(['shopComponents', 'uiComponents']);
  // Estado para controlar la visualización de productos (para animaciones)
  const [isVisible, setIsVisible] = useState(false);

  // Efecto para animar los productos cuando se cargan
  useEffect(() => {
    if (!loading && products.length > 0) {
      // Retrasar ligeramente la animación para que se vea mejor
      setTimeout(() => {
        setIsVisible(true);
        gsap.fromTo(
          '.product-animate',
          {
            opacity: 0,
            y: 20
          },
          {
            opacity: 1,
            y: 0,
            duration: 0.5,
            stagger: 0.1,
            ease: 'power2.out'
          }
        );
      }, 100);
    } else {
      setIsVisible(false);
    }

    // Limpieza al desmontar
    return () => {
      gsap.killTweensOf('.product-animate');
    };
  }, [loading, products]);

  // Renderizado del estado de carga
  if (loading) {
    return (
      <div className="flex flex-col justify-center items-center h-60 bg-gray-50 rounded-lg" data-testid="loading-state">
        <Loader text={t('uiComponents:loading.loadingBenefits')} size="large" />
      </div>
    );
  }

  // Renderizado cuando no hay productos
  if (products.length === 0) {
    return (
      <div className="bg-gray-50 rounded-lg p-8 text-center shadow-sm border border-gray-100" data-testid="empty-state" data-component-name="ProductGrid">
        <svg className="w-16 h-16 mx-auto text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.5" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path>
        </svg>
        <h3 className="text-xl font-medium text-gray-700 mb-2">{t('shopComponents:productGrid.noResultsTitle')}</h3>
        <p className="text-base text-gray-600 mb-4">
          {searchTerm
            ? t('shopComponents:productGrid.noResultsSearch')
            : selectedCategory
              ? t('shopComponents:productGrid.noResultsCategory')
              : t('shopComponents:productGrid.noResultsDefault')
          }
        </p>
        <p className="text-sm text-gray-500">
          {searchTerm && t('shopComponents:productGrid.hintSearch')}
          {!searchTerm && selectedCategory && t('shopComponents:productGrid.hintCategory')}
          {!searchTerm && !selectedCategory && t('shopComponents:productGrid.hintDefault')}
        </p>
      </div>
    );
  }

  // Renderizado de la grilla de productos
  return (
    <div className="product-grid-container" data-testid="product-grid">
      {/* Contador de resultados */}
      <ResultCounter
        total={products.length}
        currentPage={currentPage}
        totalPages={totalPages}
      />

      {/* Grilla de productos con animación */}
      <div 
        className={`grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 md:gap-6 product-grid transition-opacity duration-300 ${isVisible ? 'opacity-100' : 'opacity-0'}`}
        aria-live="polite"
      >
        {products.map((product) => (
          <ProductCard
            key={product.id}
            product={product}
            className="h-full transform transition-transform hover:scale-[1.02] hover:shadow-md"
            animationClass="product-animate"
          />
        ))}
      </div>
      
      {/* Paginación */}
      {totalPages > 1 && (
        <div className="mt-8">
          <Pagination 
            currentPage={currentPage}
            totalPages={totalPages}
            onPageChange={onPageChange}
          />
        </div>
      )}
    </div>
  );
};

export default ProductGrid;
