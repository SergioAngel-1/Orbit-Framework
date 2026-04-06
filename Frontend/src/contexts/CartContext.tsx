import React, { createContext, useContext, useState, useEffect, useMemo, useCallback, useRef } from 'react';
import { Product } from '../types/woocommerce';
import { CartItem, CartContextType, CartProviderProps, CouponState } from './types/cart.types';
import { 
  loadCartFromStorage, 
  saveCartToStorage, 
  calculateCartTotals, 
  applyCouponToCart, 
  removeCouponFromCart 
} from './utils/cart.utils';
import hybridCartService from '../services/cart/hybridCartService';
import { useAuth } from './AuthContext';
import { useMinimumOrder } from '../hooks/useMinimumOrder';
import logger from '../utils/logger';
import alertService from '../services/alertService';
import i18n from '../config/i18n';

/**
 * Contexto para el carrito de compras
 */
const CartContext = createContext<CartContextType | undefined>(undefined);

/**
 * Hook personalizado para usar el contexto del carrito
 * @returns El contexto del carrito
 * @throws Error si se usa fuera de un CartProvider
 */
export const useCart = () => {
  const context = useContext(CartContext);
  if (context === undefined) {
    throw new Error('useCart debe ser usado dentro de un CartProvider');
  }
  return context;
};

/**
 * Proveedor del contexto del carrito
 * @param children Componentes hijos
 */
export const CartProvider: React.FC<CartProviderProps> = ({ children }) => {
  // Obtener estado de autenticación
  const { isAuthenticated } = useAuth();
  
  // Obtener configuración de pedido mínimo
  const { 
    minimumAmount, 
    meetsMinimum: checkMinimum, 
    getMissingAmount, 
    getProgress 
  } = useMinimumOrder();
  
  // Estado del carrito
  const [items, setItems] = useState<CartItem[]>([]);
  const [itemCount, setItemCount] = useState(0);
  const [subtotal, setSubtotal] = useState(0);
  const [total, setTotal] = useState(0);
  const [discount, setDiscount] = useState(0);
  const [shipping, setShipping] = useState(0);
  
  // Estado del cupón
  const [couponApplied, setCouponApplied] = useState(false);
  const [couponCode, setCouponCode] = useState('');
  const [couponDiscount, setCouponDiscount] = useState(0);
  
  // Estado de carga
  const [isLoading, setIsLoading] = useState(true);
  
  // Estado de sincronización
  const [isSyncing, setIsSyncing] = useState(false);
  
  // Refs para acceso fresco en useCallbacks sin recrear funciones
  const isAuthenticatedRef = useRef(isAuthenticated);
  const itemsRef = useRef(items);
  useEffect(() => { isAuthenticatedRef.current = isAuthenticated; }, [isAuthenticated]);
  useEffect(() => { itemsRef.current = items; }, [items]);
  
  // Calcular propiedades de pedido mínimo basadas en el subtotal (sin envío)
  const meetsMinimum = checkMinimum(subtotal);
  const missingAmount = getMissingAmount(subtotal);
  const minimumProgress = getProgress(subtotal);

  /**
   * Carga el carrito al iniciar (solo localStorage)
   * La sincronización con servidor se maneja en useCartSync
   */
  useEffect(() => {
    const loadInitialCart = async () => {
      try {
        setIsLoading(true);
        
        // Siempre cargar desde localStorage primero
        // La sincronización con servidor se maneja en useCartSync hook
        logger.info('CartContext', 'Cargando reserva desde localStorage');
        const { items: loadedItems, couponState, isLightweight } = loadCartFromStorage();
        
        let finalItems: CartItem[] = [];
        
        if (loadedItems.length > 0) {
          // Siempre hidratar vía proxy (el proxy filtra por membresía del usuario actual)
          logger.info('CartContext', `Hidratando ${loadedItems.length} items ${isLightweight ? 'ligeros' : 'completos'}`);
          try {
            // Convertir a formato ligero si es necesario para hidratar uniformemente
            const lightweightItems = isLightweight 
              ? loadedItems 
              : loadedItems.map((item: any) => ({ id: item.id, variation_id: item.variation_id || 0, quantity: item.quantity }));
            finalItems = await hybridCartService.hydrateItems(lightweightItems);
          } catch (hydrateError) {
            logger.error('CartContext', 'Error al hidratar items, usando vacío:', hydrateError);
            finalItems = [];
          }
          
          // Validar stock y acceso por membresía (filtra productos sin acceso)
          if (finalItems.length > 0) {
            try {
              const { filtered, removed, membershipRemoved } = await hybridCartService.filterItemsByStock(finalItems);
              if (removed > 0) {
                logger.info('CartContext', `${removed} items removidos por stock agotado`);
              }
              if (membershipRemoved > 0) {
                logger.info('CartContext', `${membershipRemoved} items removidos por restricción de membresía`);
              }
              finalItems = filtered;
            } catch (filterError) {
              logger.warn('CartContext', 'Error al filtrar por stock/membresía:', filterError);
            }
          }
        }
        
        // Normalizar estructura: id del padre para variaciones y asegurar variation_id
        const normalizedItems = hybridCartService.normalizeItems(finalItems);
        setItems(normalizedItems);
        setCouponApplied(couponState.applied);
        setCouponCode(couponState.code);
        setCouponDiscount(couponState.discount);
        
        logger.info('CartContext', `Reserva cargada: ${normalizedItems.length} items`);
      } catch (error) {
        logger.error('CartContext', 'Error al cargar reserva inicial:', error);
        // En caso de error, inicializar vacío
        setItems([]);
        setCouponApplied(false);
        setCouponCode('');
        setCouponDiscount(0);
      } finally {
        setIsLoading(false);
      }
    };
    
    loadInitialCart();
  }, []); // Solo ejecutar una vez al montar

  /**
   * Actualiza localStorage y recalcula totales cuando cambian los items o el cupón
   */
  useEffect(() => {
    if (!isLoading) {
      // Guardar en localStorage
      const couponState: CouponState = {
        applied: couponApplied,
        code: couponCode,
        discount: couponDiscount
      };
      saveCartToStorage(items, couponState);
      
      // Calcular totales
      const totals = calculateCartTotals(items, couponApplied, couponDiscount);
      setSubtotal(totals.subtotal);
      setDiscount(totals.discount);
      setShipping(totals.shipping);
      setTotal(totals.total);
      setItemCount(totals.itemCount);
    }
  }, [items, couponApplied, couponDiscount, isLoading]);

  /**
   * Añade un item al carrito (con sincronización al servidor)
   */
  const addItem = useCallback(async (product: Product, quantity = 1, variation_id?: number, variation?: any) => {
    try {
      const updatedItems = await hybridCartService.addItem(
        product, 
        quantity, 
        variation_id, 
        variation, 
        isAuthenticatedRef.current,
        itemsRef.current
      );
      setItems(updatedItems);
    } catch (error) {
      logger.error('CartContext', 'Error al añadir item:', error);
    }
  }, []);

  /**
   * Actualiza la cantidad de un item en el carrito (con sincronización al servidor)
   */
  const updateItemQuantity = useCallback(async (productId: number, quantity: number, variation_id?: number, showAlert: boolean = false) => {
    try {
      // Obtener el item actual para saber la cantidad anterior
      const currentItem = itemsRef.current.find(item => 
        item.id === productId && 
        (variation_id ? item.variation_id === variation_id : true)
      );
      
      const updatedItems = await hybridCartService.updateItemQuantity(
        productId, 
        quantity, 
        variation_id, 
        isAuthenticatedRef.current,
        itemsRef.current
      );
      setItems(updatedItems);
      
      // Mostrar alerta si se solicita
      if (showAlert && currentItem) {
        const quantityDiff = quantity - currentItem.quantity;
        const productName = currentItem.product?.name || i18n.t('commonComponents:fallbacks.productName');
        if (quantityDiff > 0) {
          alertService.success(i18n.t('alerts:cart.quantityAdded', { diff: quantityDiff, name: productName }));
        } else if (quantityDiff < 0) {
          alertService.info(i18n.t('alerts:cart.quantityRemoved', { diff: Math.abs(quantityDiff), name: productName }));
        }
      }
    } catch (error) {
      logger.error('CartContext', 'Error al actualizar cantidad:', error);
    }
  }, []);

  /**
   * Elimina un item del carrito (con sincronización al servidor)
   */
  const removeItem = useCallback(async (productId: number, variation_id?: number) => {
    try {
      // Obtener el item antes de eliminarlo para mostrar el nombre en la alerta
      const itemToRemove = itemsRef.current.find(item => 
        item.id === productId && 
        (variation_id ? item.variation_id === variation_id : true)
      );
      
      const updatedItems = await hybridCartService.removeItem(
        productId, 
        variation_id, 
        isAuthenticatedRef.current,
        itemsRef.current
      );
      setItems(updatedItems);
      
      // Mostrar alerta de producto eliminado
      if (itemToRemove) {
        const productName = itemToRemove.product?.name || i18n.t('commonComponents:fallbacks.productName');
        alertService.info(i18n.t('alerts:cart.itemRemoved', { name: productName }));
      }
    } catch (error) {
      logger.error('CartContext', 'Error al eliminar item:', error);
    }
  }, []);

  /**
   * Vacía el carrito (con sincronización al servidor)
   * @param silent Si es true, no muestra alerta (útil para logout)
   */
  const clearCart = useCallback(async (silent: boolean = false) => {
    try {
      const emptyItems = await hybridCartService.clearCart(isAuthenticatedRef.current, silent);
      setItems(emptyItems);
    } catch (error) {
      logger.error('CartContext', 'Error al vaciar la reserva:', error);
    }
  }, []);

  /**
   * Aplica un cupón al carrito
   */
  const applyCoupon = useCallback((code: string, discountPercentage: number) => {
    const couponState = applyCouponToCart(code, discountPercentage);
    setCouponApplied(couponState.applied);
    setCouponCode(couponState.code);
    setCouponDiscount(couponState.discount);
  }, []);

  /**
   * Elimina el cupón del carrito
   */
  const removeCoupon = useCallback(() => {
    const couponState = removeCouponFromCart();
    setCouponApplied(couponState.applied);
    setCouponCode(couponState.code);
    setCouponDiscount(couponState.discount);
  }, []);

  /**
   * Recuperar carrito al iniciar sesión
   */
  const recoverCart = useCallback(async () => {
    try {
      setIsSyncing(true);
      logger.info('CartContext', 'Recuperando la reserva al iniciar sesión');
      const recoveredItems = await hybridCartService.recoverCartOnLogin();
      setItems(recoveredItems);
      logger.info('CartContext', `Reserva recuperada: ${recoveredItems.length} items`);
    } catch (error) {
      logger.error('CartContext', 'Error al recuperar la reserva:', error);
    } finally {
      setIsSyncing(false);
    }
  }, []);

  /**
   * Valor del contexto - memoizado para evitar re-renders innecesarios
   */
  const contextValue = useMemo<CartContextType>(() => ({
    items,
    itemCount,
    subtotal,
    total,
    discount,
    shipping,
    couponApplied,
    couponCode,
    couponDiscount,
    addItem,
    updateItemQuantity,
    removeItem,
    clearCart,
    applyCoupon,
    removeCoupon,
    isLoading,
    isSyncing,
    recoverCart,
    // Propiedades de pedido mínimo
    minimumAmount,
    meetsMinimum,
    missingAmount,
    minimumProgress
  }), [
    items,
    itemCount,
    subtotal,
    total,
    discount,
    shipping,
    couponApplied,
    couponCode,
    couponDiscount,
    addItem,
    updateItemQuantity,
    removeItem,
    clearCart,
    applyCoupon,
    removeCoupon,
    isLoading,
    isSyncing,
    recoverCart,
    minimumAmount,
    meetsMinimum,
    missingAmount,
    minimumProgress
  ]);

  return (
    <CartContext.Provider value={contextValue}>
      {children}
    </CartContext.Provider>
  );
};

export default CartContext;
