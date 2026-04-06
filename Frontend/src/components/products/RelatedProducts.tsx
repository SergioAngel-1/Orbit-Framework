import React, { useEffect, useState, memo } from 'react';
import { useTranslation } from 'react-i18next';
import { gsap } from 'gsap';
import { Product } from '../../types/woocommerce';
import { productService } from '../../services/api';
import ProductCard from './ProductCard';
import logger from '../../utils/logger';
import Loader from '../ui/Loader';

interface RelatedProductsProps {
  productId: number;
  categoryIds: number[];
  hideOutOfStock?: boolean;
}

const RelatedProducts: React.FC<RelatedProductsProps> = ({ productId, categoryIds, hideOutOfStock = false }) => {
  const { t } = useTranslation('productDetailPage');
  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const TARGET = 4;

    const isValid = (product: Product) => {
      if (product.id === productId) return false;
      if (hideOutOfStock && product.stock_status === 'outofstock') return false;
      return true;
    };

    const fetchRelatedProducts = async () => {
      if (!categoryIds.length) return;
      
      try {
        setLoading(true);
        const collected = new Map<number, Product>();

        // 1. Recorrer cada categoría del producto hasta completar TARGET
        for (const catId of categoryIds) {
          if (collected.size >= TARGET) break;
          try {
            const response = await productService.getByCategory(catId, { per_page: TARGET + 5 });
            for (const p of response.data as Product[]) {
              if (collected.size >= TARGET) break;
              if (!collected.has(p.id) && isValid(p)) {
                collected.set(p.id, p);
              }
            }
          } catch {
            // Si falla una categoría, intentar la siguiente
          }
        }

        // 2. Fallback: si aún faltan, buscar productos generales
        if (collected.size < TARGET) {
          try {
            const response = await productService.getAll({ per_page: TARGET + 10 });
            for (const p of response.data as Product[]) {
              if (collected.size >= TARGET) break;
              if (!collected.has(p.id) && isValid(p)) {
                collected.set(p.id, p);
              }
            }
          } catch {
            // Ignorar error del fallback
          }
        }

        setProducts(Array.from(collected.values()));
        setLoading(false);
      } catch (err) {
        logger.error('RelatedProducts', 'Error fetching related products:', err);
        setLoading(false);
      }
    };

    fetchRelatedProducts();
  }, [productId, categoryIds, hideOutOfStock]);

  // Animaciones con GSAP
  useEffect(() => {
    if (!loading && products.length > 0) {
      const productCards = document.querySelectorAll('.related-product-card');
      
      gsap.fromTo(
        productCards,
        { opacity: 0, y: 20 },
        { 
          opacity: 1, 
          y: 0, 
          duration: 0.5, 
          stagger: 0.1,
          ease: 'power2.out' 
        }
      );
    }
  }, [loading, products]);

  if (loading) {
    return (
      <div className="flex justify-center items-center h-40">
        <Loader size="medium" />
      </div>
    );
  }

  if (products.length === 0) {
    return null; // No mostrar nada si no hay productos relacionados
  }

  return (
    <div className="mt-10 md:mt-16">
      <h2 className="text-xl md:text-2xl font-bold text-oscuro mb-4 md:mb-6">{t('related.title')}</h2>
      
      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 md:gap-6">
        {products.map(product => (
          <ProductCard 
            key={product.id}
            product={product}
            animationClass="related-product-card"
          />
        ))}
      </div>
    </div>
  );
};

// Usar React.memo para evitar re-renderizados innecesarios
export default memo(RelatedProducts, (prevProps, nextProps) => {
  // Solo re-renderizar si cambia el ID del producto, las categorías o hideOutOfStock
  return (
    prevProps.productId === nextProps.productId &&
    prevProps.hideOutOfStock === nextProps.hideOutOfStock &&
    prevProps.categoryIds.length === nextProps.categoryIds.length &&
    prevProps.categoryIds.every((id, index) => id === nextProps.categoryIds[index])
  );
});
