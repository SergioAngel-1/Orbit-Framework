import React from 'react';
import { useTranslation } from 'react-i18next';
import { Product } from '../../types/woocommerce';
import QuantityCounter from '../common/QuantityCounter';
import VirtualCoinPrice from '../common/VirtualCoinPrice';
import logger from '../../utils/logger';
import { IoMdPricetag, IoMdFlash } from 'react-icons/io';
import { CartAddSection } from './variations';

interface ProductSimpleSectionProps {
  product: Product;
  quantity: number;
  onQuantityChange: (quantity: number) => void;
  onAddToCart: () => void;
  footerDescription?: string;
}

const ProductSimpleSection: React.FC<ProductSimpleSectionProps> = ({
  product,
  quantity,
  onQuantityChange,
  onAddToCart,
  /* footerDescription */
}) => {
  const { t } = useTranslation('productComponents');
  // Verificar si hay descuento para mostrar precio tachado
  const hasDiscount = product.regular_price && 
    product.price && 
    parseFloat(product.regular_price) > parseFloat(product.price);

  // Función optimizada para calcular el subtotal
  const calculateSubtotal = () => {
    try {
      // Obtener el precio base del producto
      const basePrice = product.price || '0';
      
      // Convertir a número y multiplicar por la cantidad (limitando a 2 decimales)
      const price = parseFloat(basePrice);
      if (isNaN(price)) return 0;
      
      // Limitar la cantidad a un valor razonable para evitar problemas de rendimiento
      const safeQuantity = Math.min(quantity, 9999);
      
      return price * safeQuantity;
    } catch (error) {
      logger.error('ProductSimpleSection:','Error calculando subtotal', error);
      return 0;
    }
  };

  // Calcular el porcentaje de descuento si existe
  const calculateDiscountPercentage = () => {
    if (hasDiscount) {
      const regularPrice = parseFloat(product.regular_price || '0');
      const salePrice = parseFloat(product.price || '0');
      if (regularPrice > 0) {
        return Math.round((1 - (salePrice / regularPrice)) * 100);
      }
    }
    return 0;
  };

  const discountPercentage = calculateDiscountPercentage();
  const subtotal = calculateSubtotal();
  const regularSubtotal = hasDiscount ? parseFloat(product.regular_price || '0') * quantity : 0;

  return (
    <div className="product-animate mb-6 md:mb-8 bg-white p-4 md:p-6 rounded-lg shadow-sm border border-gray-100">
      {/* Contenedor principal con diseño de tarjeta */}
      <div className="flex flex-col md:flex-row md:gap-6 md:items-start">
        {/* Columna izquierda: Información del producto */}
        <div className="md:flex-1 mb-4 md:mb-0">
          {/* Mostrar precio unitario */}
          <div className="mb-4">
            <div className="flex items-center gap-2 flex-wrap">
              <span className="text-sm font-medium text-gray-700">{t('productSimple.unitPrice')}</span>
              <VirtualCoinPrice 
                amount={parseFloat(product.price || '0')} 
                size="lg" 
                className="text-primario"
              />
              {hasDiscount && (
                <VirtualCoinPrice 
                  amount={parseFloat(product.regular_price || '0')} 
                  size="sm" 
                  showLabel={false}
                  className="line-through text-gray-500"
                />
              )}
            </div>

            {/* Mostrar descuento si existe */}
            {hasDiscount && discountPercentage > 0 && (
              <div className="flex items-center mt-1">
                <span className="text-xs bg-red-700 text-white px-2 py-1 rounded-md flex items-center shadow-sm">
                  <IoMdPricetag className="mr-1" size={12} />
                  <span className="font-bold">{t('productSimple.discount')}</span>
                  <IoMdFlash className="mx-1" size={12} />
                  {discountPercentage}%
                </span>
              </div>
            )}
          </div>
        </div>

        {/* Columna derecha: Selector de cantidad */}
        <div className="md:w-1/4">
          <div className="flex flex-col">
            <label className="block text-sm font-medium text-gray-700 mb-1.5 md:mb-2">
              {t('productSimple.quantity')}
            </label>
            <QuantityCounter
              productId={product.id}
              quantity={quantity}
              size="lg"
              className="w-fit"
              onQuantityChange={onQuantityChange}
              maxQuantity={product.stock_quantity ?? undefined}
            />
          </div>
        </div>
      </div>
      
      {/* Usar el componente compartido para botón de agregar al carrito y subtotal */}
      <CartAddSection 
        subtotal={subtotal}
        regularSubtotal={regularSubtotal}
        hasDiscount={hasDiscount ? true : false}
        onAddToCart={onAddToCart}
        stockQuantity={product.stock_quantity ?? undefined}
        stockStatus={product.stock_status as 'instock' | 'outofstock' | 'onbackorder' | undefined}
      />
    </div>
  );
};

export default ProductSimpleSection;
