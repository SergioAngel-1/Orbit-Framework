import React from 'react';
import { RelatedProducts } from './';

interface ProductRecommendationsSectionProps {
  productId: number;
  categories: any[];
}

/**
 * Componente que muestra la sección de recomendaciones de productos
 * Incluye la grilla promocional y los productos relacionados
 */
const ProductRecommendationsSection: React.FC<ProductRecommendationsSectionProps> = ({
  productId,
  categories
}) => {
  if (!categories || categories.length === 0) {
    return null;
  }

  return (
    <div className="mt-12 mb-6">
      {/* Productos relacionados */}
      <div className="mt-12">
        <RelatedProducts
          productId={productId}
          categoryIds={categories.map((cat: { id: number }) => cat.id)}
          hideOutOfStock={true} // No mostrar productos agotados
        />
      </div>
    </div>
  );
};

export default ProductRecommendationsSection;
