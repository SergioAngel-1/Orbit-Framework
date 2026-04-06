import React from 'react';
import Loader from '../ui/Loader';
import SectionHeader from './SectionHeader';
import ProductGrid from './ProductGrid';
import { useProductSection } from '../../hooks/useProductSection';
import logger from '../../utils/logger';

interface ProductSectionItemProps {
  sectionId: string;
  className?: string;
}

/**
 * Componente para renderizar una sección individual de productos
 */
const ProductSectionItem: React.FC<ProductSectionItemProps> = ({ 
  sectionId, 
  className = '' 
}) => {
  const { section, loading, error } = useProductSection(sectionId);

  if (loading) {
    return (
      <div className="py-6 text-center">
        <Loader size="small" text="" />
      </div>
    );
  }

  if (error || !section) {
    return null; // No mostrar nada si hay error o no hay datos
  }

  // Determinar si hay productos disponibles en cada grilla
  const hasPrimaryProducts = Boolean(section.products && section.products.length > 0);
  const hasSecondaryProducts = Boolean(
    section.category_id_2 && section.products_2 && section.products_2.length > 0
  );

  // Usar el grid_type que viene del backend
  const gridType = section.grid_type || 'standard';
  const isCompactPair = gridType === 'compact_pair';

  // Para secciones compactas permitimos renderizar mientras al menos una grilla tenga productos
  if (!hasPrimaryProducts && (!isCompactPair || !hasSecondaryProducts)) {
    logger.info('ProductSection', `No se muestra la sección ${section.id} porque no tiene productos disponibles`);
    return null;
  }

  // Si es compact_pair, renderizar 2 grillas lado a lado
  if (isCompactPair) {
    const columnsClass = hasPrimaryProducts && hasSecondaryProducts ? 'md:grid-cols-2' : 'md:grid-cols-1';

    return (
      <section className={`py-4 sm:py-8 bg-white ${className}`}>
        <div className="container mx-auto px-2 sm:px-3 md:px-4 max-w-full">
          <div className={`grid ${columnsClass} gap-8 md:gap-12`}>
            {/* Primera grilla */}
            {hasPrimaryProducts && (
              <div>
                <SectionHeader 
                  title={section.title} 
                  subtitle={section.subtitle} 
                  categorySlug={section.category_slug}
                  isCompact={true}
                  minMembershipLevel={section.min_membership_level}
                />
                <ProductGrid 
                  products={section.products ?? []} 
                  gridType="compact" 
                  categorySlug={section.category_slug}
                  minProducts={section.min_products}
                  minMembershipLevel={section.min_membership_level}
                />
              </div>
            )}
            {/* Segunda grilla (si hay segunda categoría) */}
            {hasSecondaryProducts && (
              <div>
                <SectionHeader 
                  title={section.title_2 || section.category_name_2 || ''} 
                  subtitle={section.subtitle_2 || ''} 
                  categorySlug={section.category_slug_2 || ''}
                  isCompact={true}
                  minMembershipLevel={section.min_membership_level}
                />
                <ProductGrid 
                  products={section.products_2 ?? []} 
                  gridType="compact" 
                  categorySlug={section.category_slug_2 || ''}
                  minProducts={section.min_products}
                  minMembershipLevel={section.min_membership_level}
                />
              </div>
            )}
          </div>
        </div>
      </section>
    );
  }

  return (
    <section className={`py-4 sm:py-8 bg-white ${className}`}>
      <div className="container mx-auto px-2 sm:px-3 md:px-4 max-w-full">
        <SectionHeader 
          title={section.title} 
          subtitle={section.subtitle} 
          categorySlug={section.category_slug}
          isCompact={false}
          minMembershipLevel={section.min_membership_level}
        />

        <ProductGrid 
          products={section.products} 
          gridType={gridType} 
          categorySlug={section.category_slug}
          minProducts={section.min_products}
          minMembershipLevel={section.min_membership_level}
        />
      </div>
    </section>
  );
};

export default ProductSectionItem;
