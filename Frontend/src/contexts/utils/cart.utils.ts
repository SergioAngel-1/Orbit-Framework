import { CartItem, CouponState } from '../types/cart.types';
import alertService from '../../services/alertService';
import i18n from '../../config/i18n';
import logger from '../../utils/logger';
import { Product } from '../../types/woocommerce';

/**
 * Carga el carrito desde el localStorage
 * @returns Un objeto con los items del carrito y el estado del cupón
 */
export const loadCartFromStorage = (): { items: any[], couponState: CouponState, isLightweight: boolean } => {
  try {
    const cartItemsJson = localStorage.getItem('cart_items');
    let items: any[] = [];
    let isLightweight = false;
    let couponState: CouponState = {
      applied: false,
      code: '',
      discount: 0
    };

    if (cartItemsJson) {
      const loadedItems = JSON.parse(cartItemsJson);
      
      if (Array.isArray(loadedItems) && loadedItems.length > 0) {
        // Detectar formato: lightweight (solo IDs) vs full (con product)
        const firstItem = loadedItems[0];
        isLightweight = firstItem && !firstItem.product && firstItem.id && typeof firstItem.quantity === 'number';
        
        if (isLightweight) {
          // Formato ligero: {id, variation_id, quantity}
          logger.info('CartUtils', 'Detectado formato ligero en localStorage');
          items = loadedItems.filter((item: any) => 
            item && item.id && item.quantity > 0
          );
        } else {
          // Formato completo: {id, product, variation, quantity}
          logger.info('CartUtils', 'Detectado formato completo en localStorage');
          items = loadedItems.filter((item: CartItem) => 
            item && item.id && item.product && item.quantity > 0
          );
        }
      }
      
      // Cargar estado del cupón si existe
      const couponJson = localStorage.getItem('cart_coupon');
      if (couponJson) {
        couponState = JSON.parse(couponJson);
      }
    }

    return { items, couponState, isLightweight };
  } catch (error) {
    logger.error('CartUtils', 'Error al cargar la reserva:', error);
    return { 
      items: [], 
      couponState: { applied: false, code: '', discount: 0 },
      isLightweight: false
    };
  }
};

/**
 * Guarda el carrito en el localStorage
 * @param items Items del carrito
 * @param couponState Estado del cupón
 */
export const saveCartToStorage = (items: CartItem[], couponState: CouponState): void => {
  try {
    // Guardar siempre en formato ligero (solo IDs + cantidad) para:
    // 1. Reducir tamaño en localStorage
    // 2. No persistir datos de productos restringidos por membresía
    // 3. Forzar re-hidratación vía proxy al recargar (el proxy filtra por membresía)
    const lightweight = items.map(item => ({
      id: item.id,
      variation_id: item.variation_id || 0,
      quantity: item.quantity
    }));
    localStorage.setItem('cart_items', JSON.stringify(lightweight));
    localStorage.setItem('cart_coupon', JSON.stringify(couponState));
  } catch (error) {
    logger.error('CartUtils', 'Error al guardar la reserva:', error);
  }
};

/**
 * Calcula los totales del carrito
 * @param items Items del carrito
 * @param couponApplied Si hay un cupón aplicado
 * @param couponDiscount Porcentaje de descuento del cupón
 * @returns Objeto con los totales calculados
 */
export const calculateCartTotals = (
  items: CartItem[], 
  couponApplied: boolean, 
  couponDiscount: number
) => {
  // Calcular subtotal
  const subtotal = items.reduce((acc, item) => {
    // Manejar tanto formato ligero como completo
    const unitPriceStr = (item.variation_id && item.variation && item.variation.price)
      ? item.variation.price
      : (item.product?.price || '0');
    const price = parseFloat(unitPriceStr) || 0;
    return acc + (price * item.quantity);
  }, 0);
  
  // Calcular descuento
  const discount = couponApplied ? (subtotal * (couponDiscount / 100)) : 0;
  
  // Envío se calculará más adelante en el checkout; por ahora siempre 0
  const shipping = 0;
  
  // Calcular total (sin incluir envío)
  const total = subtotal - discount;
  
  // Calcular cantidad de items
  const itemCount = items.reduce((count, item) => count + item.quantity, 0);

  return {
    subtotal,
    discount,
    shipping,
    total,
    itemCount
  };
};

/**
 * Añade un item al carrito
 * @param items Items actuales del carrito
 * @param product Producto a añadir
 * @param quantity Cantidad a añadir (por defecto 1)
 * @param variation_id ID de la variación (opcional)
 * @param variation Datos de la variación (opcional)
 * @returns Los items actualizados
 */
export const addItemToCart = (
  items: CartItem[], 
  product: Product, 
  quantity = 1, 
  variation_id?: number, 
  variation?: any
): CartItem[] => {
  // Buscar si el producto ya existe en el carrito
  const existingItemIndex = items.findIndex(item => 
    item.id === product.id && 
    (variation_id ? item.variation_id === variation_id : true)
  );
  
  let updatedItems = [...items];
  
  if (existingItemIndex !== -1) {
    // Actualizar cantidad si el producto ya existe
    const newQuantity = updatedItems[existingItemIndex].quantity + quantity;
    updatedItems[existingItemIndex].quantity = newQuantity;
    
    // Si se agrega más de una unidad a la vez, usar mensaje plural
    if (quantity > 1) {
      alertService.success(i18n.t('alerts:cart.addedMultipleToCart', { quantity, name: product.name }));
    } else {
      alertService.success(i18n.t('alerts:cart.addedToCart', { name: product.name }));
    }
  } else {
    // Añadir nuevo producto al carrito
    const newItem: CartItem = {
      id: product.id,
      product,
      quantity,
      variation_id,
      variation,
    };
    
    updatedItems = [...updatedItems, newItem];
    
    // Si se agrega más de una unidad a la vez, usar mensaje plural
    if (quantity > 1) {
      alertService.success(i18n.t('alerts:cart.addedMultipleToCart', { quantity, name: product.name }));
    } else {
      alertService.success(i18n.t('alerts:cart.addedToCart', { name: product.name }));
    }
  }
  
  return updatedItems;
};

/**
 * Actualiza la cantidad de un item en el carrito
 * @param items Items actuales del carrito
 * @param productId ID del producto a actualizar
 * @param quantity Nueva cantidad
 * @param variation_id ID de la variación (opcional)
 * @param showAlert Si se debe mostrar una alerta
 * @returns Los items actualizados
 */
export const updateCartItemQuantity = (
  items: CartItem[], 
  productId: number, 
  quantity: number, 
  variation_id?: number, 
  showAlert: boolean = false
): CartItem[] => {
  if (quantity <= 0) {
    return removeCartItem(items, productId, variation_id, !showAlert);
  }
  
  const itemToUpdate = items.find(item => 
    item.id === productId && 
    (variation_id ? item.variation_id === variation_id : true)
  );
  
  if (itemToUpdate) {
    const currentQuantity = itemToUpdate.quantity;
    const updatedItems = items.map(item => 
      (item.id === productId && 
       (variation_id ? item.variation_id === variation_id : true))
        ? { ...item, quantity }
        : item
    );
    
    // Mostrar alerta solo si se solicita explícitamente
    if (showAlert) {
      if (quantity > currentQuantity) {
        // Si se incrementa la cantidad
        if (currentQuantity === 0) {
          alertService.success(i18n.t('alerts:cart.addedToCart', { name: itemToUpdate.product.name }));
        } else {
          alertService.success(i18n.t('alerts:cart.quantityAdded', { diff: quantity - currentQuantity, name: itemToUpdate.product.name }));
        }
      } else if (quantity < currentQuantity) {
        // Si se disminuye la cantidad
        alertService.info(i18n.t('alerts:cart.quantityRemoved', { diff: currentQuantity - quantity, name: itemToUpdate.product.name }));
      }
    }
    
    return updatedItems;
  }
  
  return items;
};

/**
 * Elimina un item del carrito
 * @param items Items actuales del carrito
 * @param productId ID del producto a eliminar
 * @param variation_id ID de la variación (opcional)
 * @param skipAlert Si se debe omitir la alerta
 * @returns Los items actualizados
 */
export const removeCartItem = (
  items: CartItem[], 
  productId: number, 
  variation_id?: number, 
  skipAlert: boolean = false
): CartItem[] => {
  const itemToRemove = items.find(item => 
    item.id === productId && 
    (variation_id ? item.variation_id === variation_id : true)
  );
  
  if (itemToRemove) {
    const productName = itemToRemove.product.name;
    
    const updatedItems = items.filter(item => 
      !(item.id === productId && 
        (variation_id ? item.variation_id === variation_id : true))
    );
    
    // Mostrar alerta solo si no viene de updateItemQuantity
    if (!skipAlert) {
      alertService.info(i18n.t('alerts:cart.itemRemoved', { name: productName }));
    }
    
    return updatedItems;
  }
  
  return items;
};

/**
 * Vacía el carrito
 * @returns Un array vacío
 */
export const clearCartItems = (): CartItem[] => {
  localStorage.removeItem('cart_items');
  alertService.info(i18n.t('alerts:cart.cartCleared'));
  return [];
};

/**
 * Aplica un cupón al carrito
 * @param code Código del cupón
 * @param discountPercentage Porcentaje de descuento
 * @returns Estado del cupón actualizado
 */
export const applyCouponToCart = (
  code: string, 
  discountPercentage: number
): CouponState => {
  const couponState: CouponState = {
    applied: true,
    code,
    discount: discountPercentage
  };
  
  alertService.success(i18n.t('alerts:cart.couponApplied', { discount: discountPercentage }));
  return couponState;
};

/**
 * Elimina el cupón del carrito
 * @returns Estado del cupón por defecto
 */
export const removeCouponFromCart = (): CouponState => {
  const couponState: CouponState = {
    applied: false,
    code: '',
    discount: 0
  };
  
  alertService.info(i18n.t('alerts:cart.couponRemoved'));
  return couponState;
};
