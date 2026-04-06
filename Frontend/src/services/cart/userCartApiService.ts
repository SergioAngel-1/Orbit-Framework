import { api, createApiRequest } from '../apiConfig';
import logger from '../../utils/logger';
import { CartItem } from '../../types/woocommerce';

/**
 * Servicio de carrito usando user meta de WordPress
 * 
 * Este servicio guarda el carrito en el meta del usuario autenticado,
 * permitiendo persistencia entre dispositivos sin depender de sesiones.
 */
const userCartApiService = {
  /**
   * Obtener el carrito del usuario desde user meta
   * @returns Promesa con los items del carrito
   */
  async getUserCartWithMeta(): Promise<{ items: CartItem[]; removed: number }> {
    try {
      logger.info('userCartApiService', 'Obteniendo carrito del usuario');
      const response: any = await createApiRequest(() => api.get('/starter/v1/cart'));
      logger.info('userCartApiService', 'Carrito obtenido:', response.data);
      const items: CartItem[] = (response?.data?.items || []) as CartItem[];
      const removed: number = Number(response?.data?.removed || 0);
      return { items, removed };
    } catch (error) {
      logger.error('userCartApiService', 'Error al obtener carrito:', error);
      return { items: [], removed: 0 };
    }
  },

  async getUserCart(): Promise<CartItem[]> {
    const { items } = await this.getUserCartWithMeta();
    return items;
  },

  /**
   * Guardar el carrito del usuario en user meta
   * @param items Items del carrito a guardar
   * @returns Promesa con confirmación
   */
  async saveUserCart(items: CartItem[]): Promise<boolean> {
    try {
      logger.info('userCartApiService', `Guardando carrito del usuario: ${items.length} items`);
      
      // Endpoint personalizado que debe crearse en el backend
      const response = await createApiRequest(() => api.post('/starter/v1/cart', { items }));
      
      const count = typeof response.data?.count === 'number' ? response.data.count : undefined;
      const ok = !!response.data?.success && (count === undefined || count === items.length);
      logger.info('userCartApiService', `Carrito guardado: ok=${ok} (count=${count}, expected=${items.length})`);
      return ok;
    } catch (error) {
      logger.error('userCartApiService', 'Error al guardar carrito:', error);
      return false;
    }
  },

  /**
   * Limpiar el carrito del usuario en el servidor
   * @returns Promesa con confirmación
   */
  async clearUserCart(): Promise<boolean> {
    try {
      logger.info('userCartApiService', 'Limpiando carrito del usuario');
      
      const response = await createApiRequest(() => api.delete('/starter/v1/cart'));
      
      const ok = !!response.data?.success;
      logger.info('userCartApiService', `Carrito limpiado: ok=${ok}`);
      return ok;
    } catch (error) {
      logger.error('userCartApiService', 'Error al limpiar carrito:', error);
      return false;
    }
  }
};

export default userCartApiService;
