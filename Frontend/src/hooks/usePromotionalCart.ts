import { useState, useEffect } from 'react';
import { useCart } from '../contexts/CartContext';
import { PromotionalProduct } from './usePromotionalProducts';
import alertService from '../services/alertService';
import i18n from '../config/i18n';
import logger from '../utils/logger';
import { Product } from '../types/woocommerce';

/**
 * Hook personalizado para manejar las operaciones del carrito en grillas promocionales
 */
const usePromotionalCart = (products: PromotionalProduct[]) => {
  const [quantities, setQuantities] = useState<{ [key: number]: number }>({});
  const { items, addItem, updateItemQuantity, removeItem } = useCart();

  // Inicializar las cantidades desde el carrito cuando se cargan los productos
  useEffect(() => {
    if (products.length > 0) {
      const newQuantities: { [key: number]: number } = {};

      products.forEach(product => {
        // Manejar tanto formato ligero (solo IDs) como formato completo
        const cartItem = items.find(item => {
          const itemProductId = item.product?.id || item.id;
          return itemProductId === product.id;
        });
        newQuantities[product.id] = cartItem ? cartItem.quantity : 0;
      });

      setQuantities(newQuantities);
    }
  }, [products, items]);

  /**
   * Añadir un producto al carrito
   */
  const handleAddToCart = (product: PromotionalProduct) => {
    // Verificar si el producto ya está en el carrito
    // Manejar tanto formato ligero (solo IDs) como formato completo
    const existingItem = items.find(item => {
      const itemProductId = item.product?.id || item.id;
      return itemProductId === product.id;
    });
    if (existingItem) {
      // Si ya está en el carrito, solo actualizar la cantidad sin mostrar alerta desde el contexto
      updateItemQuantity(product.id, (quantities[product.id] || 0) + 1, undefined, false);
      setQuantities(prev => ({ ...prev, [product.id]: (prev[product.id] || 0) + 1 }));
      // Mostrar alerta manualmente
      alertService.success(i18n.t('alerts:cart.addedToCart', { name: product.name }));
      return;
    }
    
    // Convertir el precio a número para usarlo correctamente
    let numericPrice: number;
    
    if (typeof product.price === 'string') {
      // Eliminar cualquier carácter no numérico, excepto punto y coma
      const cleanedPrice = product.price.replace(/[^\d.,]/g, '');
      // Reemplazar puntos por nada (asumiendo que son separadores de miles)
      // y comas por puntos (asumiendo que son separadores decimales)
      const normalizedPrice = cleanedPrice.replace(/\./g, '').replace(',', '.');
      numericPrice = parseFloat(normalizedPrice);
      
      if (isNaN(numericPrice)) {
        numericPrice = 0;
        logger.warn('usePromotionalCart', `No se pudo convertir el precio "${product.price}" a número`);
      }
    } else {
      numericPrice = parseFloat(product.price) || 0;
    }
    
    logger.info('usePromotionalCart', 'Agregando beneficio a la reserva:', {
      id: product.id,
      name: product.name,
      price: numericPrice
    });

    // Usar el producto original y solo actualizar los campos necesarios
    const productToAdd = {
      ...product,
      // Convertir el precio a número
      price: numericPrice.toString(),
      // Asegurarse de que siempre haya imágenes disponibles
      // Asegurarse de que siempre haya un array de imágenes aunque esté vacío
      images: product.images && product.images.length > 0 
        ? product.images 
        : []
    } as unknown as Product;
    
    // Añadir al carrito
    addItem(productToAdd, 1);
    
    // Actualizar la cantidad local
    setQuantities(prev => ({ ...prev, [product.id]: 1 }));
  };

  /**
   * Actualizar la cantidad de un producto en el carrito
   */
  const handleUpdateQuantity = (product: PromotionalProduct, newQuantity: number) => {
    if (newQuantity <= 0) {
      // Eliminar del carrito si la cantidad es 0 o menos
      removeItem(product.id);
      setQuantities(prev => ({ ...prev, [product.id]: 0 }));
      return;
    }
    
    const currentQuantity = quantities[product.id] || 0;
    
    // Actualizar la cantidad en el carrito
    updateItemQuantity(product.id, newQuantity, undefined, false);
    
    // Actualizar la cantidad local
    setQuantities(prev => ({ ...prev, [product.id]: newQuantity }));
    
    // Mostrar alerta si la cantidad aumentó
    if (newQuantity > currentQuantity) {
      alertService.success(i18n.t('alerts:cart.addedToCart', { name: product.name }));
    }
  };

  return {
    quantities,
    handleAddToCart,
    handleUpdateQuantity
  };
};

export default usePromotionalCart;
