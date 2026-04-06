import React from 'react';
import { useTranslation } from 'react-i18next';
import { IoMdCart } from 'react-icons/io';
import { FiAlertCircle } from 'react-icons/fi';
import VirtualCoinPrice from '../../common/VirtualCoinPrice';
import Loader from '../../ui/Loader';
import { fluidSizing } from '../../../utils/fluidSizing';
import { ceilTo50COP } from '../../../utils/formatters';

interface CartAddSectionProps {
  subtotal: number;
  regularSubtotal?: number;
  hasDiscount?: boolean;
  onAddToCart: () => void;
  disabled?: boolean;
  disabledText?: string;
  stockQuantity?: number;
  stockStatus?: 'instock' | 'outofstock' | 'onbackorder';
  priceRange?: { min: number; max: number | null } | null;
  loading?: boolean;
}

/**
 * Componente compartido para mostrar el botón de agregar al carrito y el subtotal
 * Usado tanto en productos simples como en productos variables
 */
const CartAddSection: React.FC<CartAddSectionProps> = ({
  subtotal,
  regularSubtotal,
  hasDiscount = false,
  onAddToCart,
  disabled = false,
  disabledText,
  stockQuantity,
  stockStatus,
  priceRange,
  loading = false
}) => {
  const { t } = useTranslation('productDetailPage');
  const resolvedDisabledText = disabledText || t('cart.selectOptions');
  // Determinar si el producto está sin stock
  // Priorizar stockStatus ya que es el indicador más confiable en WooCommerce
  const isOutOfStock = stockStatus === 'outofstock' || 
    (stockStatus !== 'instock' && stockQuantity !== undefined && stockQuantity !== null && stockQuantity <= 0);
  
  // El botón se deshabilita si está disabled por otras razones O si no hay stock
  const isButtonDisabled = disabled || isOutOfStock;
  
  // Determinar el texto del botón
  const getButtonText = () => {
    if (isOutOfStock) {
      return t('cart.outOfStock');
    }
    if (disabled) {
      return resolvedDisabledText;
    }
    return t('cart.addToCart');
  };

  // Determinar el icono del botón
  const ButtonIcon = isOutOfStock ? FiAlertCircle : IoMdCart;

  return (
    <div 
      className="border-t border-gray-100 bg-opacity-50"
      style={{ marginTop: fluidSizing.space.md, paddingTop: fluidSizing.space.md }}
    >
      {/* Rango de precios cuando no hay variación seleccionada */}
      {disabled && !isOutOfStock && priceRange && (
        <div className="flex justify-center md:justify-end" style={{ marginBottom: fluidSizing.space.sm }}>
          <div className="flex items-center flex-wrap justify-center" style={{ gap: fluidSizing.space.xs }}>
            {!priceRange.max && (
              <span className="text-gray-400" style={{ fontSize: fluidSizing.text.sm }}>{t('cart.from')}</span>
            )}
            <VirtualCoinPrice amount={ceilTo50COP(priceRange.min)} size="lg" className="text-primario font-bold" />
            {priceRange.max && (
              <>
                <span className="text-gray-400" style={{ fontSize: fluidSizing.text.sm }}>–</span>
                <VirtualCoinPrice amount={ceilTo50COP(priceRange.max)} size="lg" className="text-primario font-bold" />
              </>
            )}
          </div>
        </div>
      )}

      <div 
        className={`flex flex-col ${isButtonDisabled ? '' : 'md:flex-row md:items-center md:justify-between'}`}
        style={{ gap: fluidSizing.space.md }}
      >
        {/* Botón de agregar al carrito */}
        <div className={`${isButtonDisabled ? 'w-full' : 'md:flex-shrink-0 order-2 md:order-1'}`}>
          <button 
            onClick={(isButtonDisabled || loading) ? undefined : onAddToCart}
            disabled={isButtonDisabled || loading}
            className={`relative w-full font-bold rounded-md transition-all flex items-center justify-center shadow-md ${
              isOutOfStock
                ? 'bg-gray-200 text-gray-500 cursor-not-allowed'
                : (isButtonDisabled || loading)
                  ? 'bg-gray-300 text-gray-500 cursor-not-allowed'
                  : 'bg-primario hover:bg-primario-dark text-white hover:shadow-lg transform hover:-translate-y-0.5'
            }`}
            style={{ 
              padding: `${fluidSizing.space.md} ${fluidSizing.space.xl}`,
              fontSize: fluidSizing.text.base
            }}
          >
            {loading ? (
              <Loader text={t('cart.adding')} size="small" />
            ) : (
              <>
                <ButtonIcon 
                  className="mr-2" 
                  style={{ width: fluidSizing.size.iconLg, height: fluidSizing.size.iconLg }}
                />
                {getButtonText()}
              </>
            )}
          </button>
        </div>

        {/* Subtotal - Solo mostrar cuando no esté disabled */}
        {!isButtonDisabled && (
          <div className="md:flex-1 flex justify-end order-1 md:order-2">
            <div className="flex flex-col items-end">
              <div className="flex items-center" style={{ gap: fluidSizing.space.sm }}>
                <span 
                  className="font-medium text-gray-700"
                  style={{ fontSize: fluidSizing.text.sm }}
                >
                  {t('cart.subtotal')}
                </span>
                <VirtualCoinPrice 
                  amount={subtotal} 
                  size="xl" 
                  className="text-primario font-bold"
                />
              </div>
              
              {/* Solo mostrar el precio tachado si hay un descuento real */}
              {hasDiscount && regularSubtotal && regularSubtotal > subtotal && (
                <div 
                  className="flex items-center text-gray-500"
                  style={{ gap: fluidSizing.space.xs, fontSize: fluidSizing.text.sm, marginTop: fluidSizing.space.xs }}
                >
                  <span>{t('cart.regularPrice')}</span>
                  <VirtualCoinPrice 
                    amount={regularSubtotal} 
                    size="sm" 
                    showLabel={false}
                    className="line-through text-gray-500"
                  />
                </div>
              )}
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

export default CartAddSection;
