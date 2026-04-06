import React, { useEffect, useState, useRef, memo } from 'react';
import { useTranslation } from 'react-i18next';
import { gsap } from 'gsap';
import { Product } from '../../types/woocommerce';
import ProductCard from '../products/ProductCard';
import Loader from '../ui/Loader';
import ResultCounter from '../common/ResultCounter';

interface ProductGridSimpleProps {
  products: Product[];
  loading?: boolean;
  searchTerm?: string;
  selectedCategory?: number | undefined;
  totalItems?: number; // Total de productos encontrados
}

/**
 * Versión simplificada del componente ProductGrid sin paginación interna
 * Muestra una grilla de productos con animaciones y estados de carga
 */
const ProductGridSimple: React.FC<ProductGridSimpleProps> = memo(({
  products,
  loading = false,
  searchTerm = '',
  selectedCategory,
  totalItems = 0
}) => {
  const { t } = useTranslation('shopPage');

  // Estado para controlar la visualización de productos (para animaciones)
  const [isVisible, setIsVisible] = useState(false);
  const previousProductCountRef = useRef(0);
  const gridRef = useRef<HTMLDivElement>(null);

  // Efecto para animar solo los productos nuevos (scroll infinito)
  useEffect(() => {
    if (!loading && products.length > 0) {
      setIsVisible(true);
      
      // Solo animar productos nuevos en scroll infinito
      const previousCount = previousProductCountRef.current;
      if (products.length > previousCount && gridRef.current) {
        // Animar solo los productos nuevos usando el ref del grid
        requestAnimationFrame(() => {
          const allCards = gridRef.current?.querySelectorAll('.product-animate');
          if (allCards) {
            const productsToAnimate = Array.from(allCards).slice(previousCount);
            
            if (productsToAnimate.length > 0) {
              gsap.fromTo(
                productsToAnimate,
                { opacity: 0, y: 20 },
                { opacity: 1, y: 0, duration: 0.4, stagger: 0.05, ease: 'power2.out' }
              );
            }
          }
        });
      }
      
      previousProductCountRef.current = products.length;
    } else if (products.length === 0) {
      setIsVisible(false);
      previousProductCountRef.current = 0;
    }
  }, [loading, products.length]);

  // Cleanup de GSAP solo al desmontar el componente
  useEffect(() => {
    return () => {
      gsap.killTweensOf('.product-animate');
    };
  }, []);

  // Renderizado del estado de carga
  if (loading) {
    return (
      <div className="flex flex-col justify-center items-center h-60 bg-gray-50 rounded-lg" data-testid="loading-state">
        <Loader text={t('productGrid.loading')} size="large" />
      </div>
    );
  }

  // Renderizado cuando no hay productos (solo si no está cargando)
  if (!loading && products.length === 0) {
    return (
      <div className="bg-gray-50 rounded-lg p-8 text-center shadow-sm border border-gray-100" data-testid="empty-state" data-component-name="ProductGridSimple">
        <svg className="w-16 h-16 mx-auto text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.5" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path>
        </svg>
        <h3 className="text-xl font-medium text-gray-700 mb-2">{t('productGrid.emptyTitle')}</h3>
        <p className="text-base text-gray-600 mb-4">
          {searchTerm
            ? t('productGrid.emptySearch')
            : selectedCategory
              ? t('productGrid.emptyCategory')
              : t('productGrid.emptyCatalog')
          }
        </p>
        <p className="text-sm text-gray-500">
          {searchTerm && t('productGrid.hintSearch')}
          {!searchTerm && selectedCategory && t('productGrid.hintCategory')}
          {!searchTerm && !selectedCategory && t('productGrid.hintCatalog')}
        </p>
      </div>
    );
  }

  // Renderizado de la grilla de productos
  return (
    <div className="product-grid-container" data-testid="product-grid">
      {/* Contador de resultados - Adaptado para scroll infinito */}
      <ResultCounter
        total={totalItems || 0}
        showing={products.length}
        searchTerm={searchTerm}
      />

      {/* Grilla de productos con animación */}
      <div 
        ref={gridRef}
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
    </div>
  );
});

ProductGridSimple.displayName = 'ProductGridSimple';

export default ProductGridSimple;
