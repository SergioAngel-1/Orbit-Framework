import React from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useLanguage } from '../../contexts/LanguageContext';
import { PromotionalProduct } from '../../hooks/usePromotionalProducts';
import useMembershipLevels from '../../hooks/useMembershipLevels';
import { buildProductUrl } from '../../utils/membershipRouteUtils';
import { truncateText } from '../../utils/formatters';
import QuantityCounter from '../common/QuantityCounter';
import VirtualCoinPrice from '../common/VirtualCoinPrice';

interface PromotionalProductCardProps {
  product: PromotionalProduct;
  quantity: number;
  onAddToCart: (product: PromotionalProduct) => void;
  onUpdateQuantity: (product: PromotionalProduct, quantity: number) => void;
  compact?: boolean;
}

/**
 * Componente de tarjeta para productos promocionales 
 * con botones de añadir al carrito o ajustar cantidad
 * y manejo de productos variables
 */
const PromotionalProductCard: React.FC<PromotionalProductCardProps> = ({
  product,
  quantity,
  onAddToCart,
  onUpdateQuantity,
  compact = false
}) => {
  const { t } = useTranslation('productDetailPage');
  const { localizedPath } = useLanguage();
  const { levels } = useMembershipLevels();

  // Calcular si hay descuento y el porcentaje
  const hasDiscount = parseFloat(product.sale_price) > 0 && parseFloat(product.regular_price) > parseFloat(product.sale_price);
  
  // Calcular porcentaje de descuento
  let discountPercentage = 0;
  if (hasDiscount && parseFloat(product.regular_price) > 0) {
    discountPercentage = Math.round(
      ((parseFloat(product.regular_price) - parseFloat(product.sale_price)) / parseFloat(product.regular_price)) * 100
    );
  }

  // Obtener la categoría para la URL
  // Si no hay categoría, buildProductUrl generará una URL de fallback que el router resolverá
  const categorySlug = product.categories && product.categories.length > 0 
    ? product.categories[0].slug 
    : undefined;

  // Ruta del producto con prefijo de membresía según la categoría
  const categoryMinMembership = product.categories && product.categories.length > 0 
    ? product.categories[0].min_membership_level ?? 0 
    : 0;
  const productLink = localizedPath(buildProductUrl(categorySlug, product.slug, categoryMinMembership, levels));

  // Determinar si el producto es variable usando la propiedad type
  const isVariableProduct = product.type === 'variable';

  return (
    <div className={`promo-grid-item w-full ${compact ? 'py-3 px-2' : 'p-3 bg-gradient-to-b from-white to-gray-100 border border-gray-200 shadow-md rounded-lg'} flex flex-col transition-all duration-200 hover:shadow-lg hover:border-gray-300 relative overflow-visible`}>
      <div className="flex items-center justify-between">
        <div className="flex items-start flex-grow pr-3">
          {/* Imagen del producto */}
          <div className={`flex-shrink-0 ${compact ? 'w-16 h-16' : 'w-20 h-20'} rounded-lg overflow-hidden border border-gray-200`}>
            <Link to={productLink}>
              {product.images && product.images.length > 0 ? (
                <img 
                  src={product.images[0].src} 
                  alt={product.name} 
                  className="w-full h-full object-cover"
                  loading="lazy"
                />
              ) : (
                <div className="w-full h-full flex items-center justify-center bg-gray-100">
                  <svg xmlns="http://www.w3.org/2000/svg" className="h-6 w-6 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                  </svg>
                </div>
              )}
            </Link>
          </div>
          
          {/* Información del producto */}
          <div className={`${compact ? 'ml-2' : 'ml-4'} flex-grow overflow-hidden`}>
            <Link to={productLink} className="block">
              <h3 className={`${compact ? 'text-xs' : 'text-sm'} font-medium text-gray-800 line-clamp-2 hover:text-primario transition-colors`}>{product.name}</h3>
              
              <div className={`${compact ? 'mt-0.5' : 'mt-1'} flex items-baseline flex-wrap gap-2`}>
                <VirtualCoinPrice 
                  amount={parseFloat(product.price) || 0}
                  size={compact ? 'sm' : 'md'}
                  showLabel={false}
                  className="text-primario font-semibold"
                />
                
                {hasDiscount && (
                  <>
                    <VirtualCoinPrice 
                      amount={parseFloat(product.regular_price) || 0}
                      size="xs"
                      showLabel={false}
                      className="line-through text-gray-400"
                    />
                    {discountPercentage > 0 && (
                      <span className="text-xs font-medium bg-primario/10 text-primario px-1.5 py-0.5 rounded-full">
                        -{discountPercentage}%
                      </span>
                    )}
                  </>
                )}
              </div>
              
              {/* Descripción corta del producto - oculta en modo compacto */}
              {!compact && (
                <p className="mt-1 text-xs text-gray-500 line-clamp-2">
                  {(product.short_description || product.description) 
                    ? truncateText(product.short_description || product.description || '', 60)
                    : t('promotional.noDescription')}
                </p>
              )}
            </Link>
          </div>
        </div>
        
        {/* Botones de carrito a la derecha */}
        <div className="flex-shrink-0 flex items-center justify-center ml-2">
          {/* Para productos normales (no variables), si hay disponibilidad */}
          {!isVariableProduct && parseFloat(product.price) > 0 && (
            quantity === 0 ? (
              <button 
                onClick={() => onAddToCart(product)}
                className="bg-primario hover:bg-hover text-white p-0 rounded-full transition-all duration-300 flex items-center justify-center w-10 h-10 shadow-md hover:shadow-lg hover:scale-105"
                aria-label={t('promotional.addAria', { name: product.name })}
              >
                <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                </svg>
              </button>
            ) : (
              <div className="bg-gray-50 rounded-md flex justify-center items-center shadow-sm w-10 overflow-hidden border border-gray-200">
                <QuantityCounter
                  productId={product.id}
                  quantity={quantity}
                  productName={product.name}
                  size="sm"
                  orientation="vertical"
                  onQuantityChange={(qty) => onUpdateQuantity(product, qty)}
                  maxQuantity={product.stock_quantity ?? undefined}
                  className="transform"
                />
              </div>
            )
          )}
          
          {/* Botón de ver opciones para productos variables - siempre visible */}
          {isVariableProduct && parseFloat(product.price) > 0 && (
            <Link 
              to={productLink} 
              className="bg-primario hover:bg-hover text-white p-0 rounded-full transition-all duration-300 flex items-center justify-center w-10 h-10 shadow-md hover:shadow-lg hover:scale-105 hover:text-white"
              aria-label={t('promotional.viewOptionsAria', { name: product.name })}
            >
              <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
              </svg>
            </Link>
          )}
          
          {/* Productos agotados - Si no hay precio */}
          {parseFloat(product.price) <= 0 && (
            <div className="bg-gray-100 text-gray-500 p-0 rounded-full flex items-center justify-center w-10 h-10 shadow-sm">
              <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </div>
          )}
        </div>
      </div>
    </div>
  );
};

export default PromotionalProductCard;