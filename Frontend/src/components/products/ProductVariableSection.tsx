import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Product } from '../../types/woocommerce';
import VariationSelector from './VariationSelector';
import Loader from '../ui/Loader';

interface ProductVariableSectionProps {
  product: Product;
  onVariationSelect: (variationId: number, variationData: any) => void;
  onAddToCart: () => void;
  addingToCart?: boolean;
  quantity: number;
  onQuantityChange: (quantity: number) => void;
  footerDescription?: string;
  preselectedVariationId?: number | null; // ID de variación preseleccionada desde la URL
}

const ProductVariableSection: React.FC<ProductVariableSectionProps> = ({
  product,
  onVariationSelect,
  onAddToCart,
  addingToCart = false,
  quantity,
  onQuantityChange,
  footerDescription,
  preselectedVariationId
}) => {
  const { t } = useTranslation('productDetailPage');
  // Usamos el ID del producto como clave única para forzar la reinicialización
  // del componente solo cuando cambia el producto, no en cada renderizado
  const productKey = `product-${product.id}`;
  const [isVariationsLoading, setVariationsLoading] = useState(true);
  
  return (
    <div id="product-variations" className="mb-6 product-animate">
      {isVariationsLoading && (
        <div className="py-6">
          <Loader text={t('variations.loading')} />
        </div>
      )}
      
      <div className={isVariationsLoading ? 'opacity-0 h-0 overflow-hidden' : 'opacity-100 transition-opacity duration-300'}>
        {/* La clave key fuerza la recreación completa del componente cuando cambia */}
        <VariationSelector 
          key={productKey}
          product={product} 
          quantity={quantity}
          onQuantityChange={onQuantityChange}
          onAddToCart={onAddToCart}
          addingToCart={addingToCart}
          footerDescription={footerDescription}
          onVariationSelect={onVariationSelect}
          preselectedVariationId={preselectedVariationId}
          onLoadingChange={setVariationsLoading}
        />
      </div>
    </div>
  );
};

export default ProductVariableSection;
