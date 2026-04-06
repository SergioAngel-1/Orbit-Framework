import { useEffect, useRef } from 'react';
import { useAuth } from '../contexts/AuthContext';
import { useCart } from '../contexts/CartContext';
import logger from '../utils/logger';

/**
 * Hook personalizado para sincronizar el carrito con el estado de autenticación
 * 
 * Este hook:
 * 1. Detecta cuando el usuario inicia sesión y recupera su carrito del servidor
 * 2. Detecta cuando el usuario cierra sesión y limpia el carrito local
 * 3. Previene múltiples llamadas simultáneas de sincronización
 */
export const useCartSync = () => {
  const { isAuthenticated } = useAuth();
  const { recoverCart, clearCart } = useCart();
  
  // Ref para rastrear el estado anterior de autenticación
  const prevAuthRef = useRef<boolean>(isAuthenticated);
  
  // Ref para prevenir múltiples sincronizaciones simultáneas
  const isSyncingRef = useRef<boolean>(false);
  
  useEffect(() => {
    const prevAuth = prevAuthRef.current;
    
    // Detectar transición de no autenticado → autenticado (login)
    if (!prevAuth && isAuthenticated) {
      // Prevenir múltiples llamadas simultáneas
      if (isSyncingRef.current) {
        logger.warn('useCartSync', 'Sincronización ya en progreso, ignorando llamada duplicada');
        return;
      }
      
      logger.info('useCartSync', 'Usuario autenticado, recuperando reserva del servidor');
      
      // Marcar como sincronizando
      isSyncingRef.current = true;
      
      // Recuperar carrito del servidor
      if (recoverCart) {
        recoverCart()
          .catch(error => {
            logger.error('useCartSync', 'Error al recuperar reserva:', error);
          })
          .finally(() => {
            // Liberar flag de sincronización
            isSyncingRef.current = false;
          });
      } else {
        isSyncingRef.current = false;
      }
    }
    
    // Detectar transición de autenticado → no autenticado (logout)
    if (prevAuth && !isAuthenticated) {
      logger.info('useCartSync', 'Usuario cerró sesión, limpiando reserva local');
      
      // Limpiar localStorage del carrito al cerrar sesión
      localStorage.removeItem('cart_items');
      localStorage.removeItem('cart_coupon');
      localStorage.removeItem('cart_sync_pending');
      
      // Limpiar estado del carrito en el contexto (actualiza la UI)
      // Nota: clearCart ya no sincroniza con servidor porque isAuthenticated es false
      // Pasamos silent=true para evitar mostrar alerta "Reserva vaciada" en logout
      if (clearCart) {
        clearCart(true).catch(error => {
          logger.error('useCartSync', 'Error al limpiar reserva:', error);
        });
      }
      
      // Resetear flag de sincronización
      isSyncingRef.current = false;
    }
    
    // Actualizar ref
    prevAuthRef.current = isAuthenticated;
  }, [isAuthenticated, recoverCart, clearCart]);
};

export default useCartSync;
