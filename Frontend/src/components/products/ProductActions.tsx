import React from "react";
import { useTranslation } from 'react-i18next';
import { CartAddSection } from "./variations";
import QuantityCounter from "../common/QuantityCounter";
import VirtualCoinPrice from "../common/VirtualCoinPrice";
import logger from "../../utils/logger";
import { fluidSizing } from "../../utils/fluidSizing";

interface ProductActionsProps {
  quantity: number;
  onQuantityChange: (newQuantity: number) => void;
  onAddToCart: () => void;
  addingToCart?: boolean;
  maxQuantity?: number;
  inStock?: boolean;
  price?: string;
  regularPrice?: string;
  stockQuantity?: number;
  stockStatus?: 'instock' | 'outofstock' | 'onbackorder';
}

/**
 * Componente para acciones de producto simple (cantidad y agregar al carrito)
 * Usa el componente compartido CartAddSection para mantener consistencia con productos variables
 */
const ProductActions: React.FC<ProductActionsProps> = ({
  quantity,
  onQuantityChange,
  onAddToCart,
  addingToCart = false,
  maxQuantity = 10,
  price = "0",
  regularPrice,
  stockQuantity,
  stockStatus,
}) => {
  const { t } = useTranslation('productDetailPage');
  // Calcular el subtotal basado en el precio y la cantidad
  const calculateSubtotal = () => {
    try {
      const numericPrice = parseFloat(price || "0");
      if (isNaN(numericPrice)) return 0;
      return numericPrice * quantity;
    } catch (error) {
      logger.error("ProductActions", "Error calculando subtotal", error);
      return 0;
    }
  };

  // Calcular el subtotal regular (para mostrar el precio tachado si hay descuento)
  const calculateRegularSubtotal = () => {
    if (!regularPrice) return 0;
    try {
      const numericRegularPrice = parseFloat(regularPrice || "0");
      if (isNaN(numericRegularPrice)) return 0;
      return numericRegularPrice * quantity;
    } catch (error) {
      logger.error(
        "ProductActions",
        "Error calculando subtotal regular",
        error
      );
      return 0;
    }
  };

  // Verificar si hay descuento
  const hasDiscount =
    regularPrice && price && parseFloat(regularPrice) > parseFloat(price);

  // Mostrar selector de cantidad solo si hay stock
  const showQuantity = stockStatus === 'instock' && (typeof stockQuantity !== 'number' || stockQuantity > 0);

  return (
    <div 
      className="product-animate rounded-lg shadow-none border border-gray-100 bg-opacity-50"
      style={{ padding: fluidSizing.space.lg }}
    >
      <div 
        className="flex flex-col md:flex-row md:items-center"
        style={{ gap: fluidSizing.space.lg }}
      >
        {/* Columna izquierda: Información del producto */}
        <div className="md:flex-1">
          {/* Mostrar precio unitario */}
          <div className="flex items-center flex-wrap" style={{ gap: fluidSizing.space.sm }}>
            <span 
              className="font-medium text-gray-700"
              style={{ fontSize: fluidSizing.text.sm }}
            >
              {t('actions.unitPrice')}
            </span>
            <VirtualCoinPrice 
              amount={parseFloat(price)} 
              size="lg" 
              className="text-primario"
            />
            {hasDiscount && (
              <VirtualCoinPrice 
                amount={parseFloat(regularPrice || "0")} 
                size="sm" 
                showLabel={false}
                className="line-through text-gray-500"
              />
            )}
          </div>
        </div>

        {/* Columna derecha: Selector de cantidad (solo si hay stock) */}
        {showQuantity && (
          <div className="md:w-auto">
            <div className="flex flex-col">
              <label 
                className="block font-medium text-gray-700"
                style={{ fontSize: fluidSizing.text.sm }}
              >
                {t('actions.quantity')}
              </label>
              <div style={{ marginTop: fluidSizing.space.sm }}>
                <QuantityCounter
                  productId={0}
                  quantity={quantity}
                  size="lg"
                  className="w-fit"
                  maxQuantity={stockQuantity !== undefined && stockQuantity !== null ? Math.min(stockQuantity, maxQuantity) : maxQuantity}
                  onQuantityChange={(newQuantity) => {
                    // Usar el stock real como límite si está disponible
                    const effectiveMax = stockQuantity !== undefined && stockQuantity !== null 
                      ? Math.min(stockQuantity, maxQuantity) 
                      : maxQuantity;
                    const validQuantity = Math.min(
                      Math.max(1, newQuantity),
                      effectiveMax
                    );
                    onQuantityChange(validQuantity);
                  }}
                />
              </div>
            </div>
          </div>
        )}
      </div>

      {/* Usar el componente compartido para botón de agregar al carrito y subtotal */}
      <CartAddSection
        subtotal={calculateSubtotal()}
        regularSubtotal={hasDiscount ? calculateRegularSubtotal() : undefined}
        hasDiscount={hasDiscount ? true : false}
        onAddToCart={onAddToCart}
        loading={addingToCart}
        stockQuantity={stockQuantity}
        stockStatus={stockStatus}
      />
    </div>
  );
};

export default ProductActions;
