import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import Breadcrumbs from '../ui/Breadcrumbs';
import { 
  ProductHeader, 
  ProductVariableSection,
  ProductActions
} from './';
import { useCart } from '../../contexts/CartContext';
import alertService from '../../services/alertService';

interface ProductDetailContentProps {
  product: any;
  displayProduct: any;
  categories: any[];
  quantity: number;
  selectedVariation: number | null;
  variationData: any;
  preselectedVariationId: number | null;
  loading: boolean;
  minMembershipLevel?: number;
  onQuantityChange: (quantity: number) => void;
  onVariationSelect: (variationId: number, variationData: any) => void;
}

/**
 * Componente que muestra el contenido principal del detalle de producto
 */
const ProductDetailContent: React.FC<ProductDetailContentProps> = ({
  product,
  displayProduct,
  categories,
  quantity,
  selectedVariation,
  variationData,
  preselectedVariationId,
  minMembershipLevel,
  onQuantityChange,
  onVariationSelect
}) => {
  const { t } = useTranslation('productDetailPage');
  const { items, addItem, updateItemQuantity } = useCart();
  const [addingToCart, setAddingToCart] = useState(false);

  const handleAddToCart = async () => {
    // Añadir al carrito con la cantidad seleccionada
    if (!product) return;

    // Usar el producto para mostrar si está disponible
    const productToAdd = displayProduct || product;

    // Obtener el stock disponible (de la variación si aplica, o del producto)
    const stockQuantity = selectedVariation && variationData 
      ? variationData.stock_quantity 
      : product.stock_quantity;
    
    // Verificar si el producto ya está en el carrito
    // Importante: en el carrito usamos el ID del padre para productos variables y
    // usamos variation_id para diferenciar la variación.
    const existingItem = items.find((item) => (
      selectedVariation
        ? (item.id === product.id && item.variation_id === selectedVariation)
        : (item.id === product.id)
    ));

    // Calcular la cantidad total que tendría en el carrito
    const currentQuantityInCart = existingItem ? existingItem.quantity : 0;
    const totalQuantity = currentQuantityInCart + quantity;

    // Validar contra el stock disponible
    if (stockQuantity !== undefined && stockQuantity !== null && totalQuantity > stockQuantity) {
      const availableToAdd = stockQuantity - currentQuantityInCart;
      if (availableToAdd <= 0) {
        alertService.warning(t('content.maxInCart', { stock: stockQuantity }));
        return;
      }
      alertService.warning(t('content.limitedStock', { available: availableToAdd, stock: stockQuantity }));
      return;
    }

    try {
      setAddingToCart(true);
      if (existingItem) {
        // Si ya existe, SUMAR la cantidad nueva a la existente
        const newQuantity = existingItem.quantity + quantity;
        // Siempre usar el ID del producto padre y pasar la variación aparte
        await updateItemQuantity(product.id, newQuantity, selectedVariation || undefined, true);
      } else {
        // Si no existe, añadir como nuevo
        // Para productos variables, incluir la información de la variación
        if (selectedVariation && variationData) {
          // Pasar SIEMPRE el producto padre para mantener id del padre en el carrito
          await addItem(product, quantity, selectedVariation, variationData);
        } else {
          // Para productos simples
          await addItem(productToAdd, quantity);
        }
      }
    } finally {
      setAddingToCart(false);
    }

    // No es necesario mostrar alerta aquí ya que la función addItem en cart.utils.ts
    // ya muestra una alerta cuando se agrega un producto al carrito
  };

  return (
    <div className="flex flex-col h-full">
      {/* Migas de pan - Solo visibles en desktop */}
      <div className="hidden md:block mb-2 overflow-x-auto">
        <Breadcrumbs
          categories={categories}
          currentProduct={product.name}
          currentCategory={categories.length > 0 ? categories[0].name : undefined}
          currentCategoryMinMembership={categories.length > 0 ? categories[0].min_membership_level ?? 0 : 0}
        />
      </div>
      
      <ProductHeader
        name={product.name}
        className="mb-4"
        minMembershipLevel={minMembershipLevel}
      />

      {/* Selector de variaciones para productos variables */}
      {product && product.type === 'variable' && (
        <div id="product-variations" className="relative" style={{ zIndex: 20, overflow: 'visible' }}>
          <ProductVariableSection
            product={product}
            quantity={quantity}
            onQuantityChange={onQuantityChange}
            preselectedVariationId={preselectedVariationId}
            onAddToCart={handleAddToCart}
            addingToCart={addingToCart}
            footerDescription={product.short_description}
            onVariationSelect={onVariationSelect}
          />
        </div>
      )}
      
      {/* Sección para productos simples */}
      {product && product.type === 'simple' && (
        <ProductActions
          quantity={quantity}
          onQuantityChange={onQuantityChange}
          onAddToCart={handleAddToCart}
          addingToCart={addingToCart}
          maxQuantity={10}
          price={displayProduct?.price || product.price}
          regularPrice={displayProduct?.regular_price || product.regular_price}
          stockQuantity={product.stock_quantity ?? undefined}
          stockStatus={product.stock_status as 'instock' | 'outofstock' | 'onbackorder' | undefined}
        />
      )}
    </div>
  );
};

export default ProductDetailContent;
