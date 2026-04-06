import { wooCommerceApi } from '../apiConfig';
import logger from '../../utils/logger';
import CryptoJS from 'crypto-js';

/**
 * Servicio de API para pedidos
 */
const orderApiService = {
  /**
   * Crear un nuevo pedido en WooCommerce
   * @param orderData Datos del pedido
   * @returns Promesa con el pedido creado
   */
  createOrder(orderData: any) {
    logger.info('orderApiService', 'Creando nuevo pedido');
    // Generar una clave de idempotencia estable por contenido del pedido
    const stableStringify = (obj: any): string => {
      if (obj === null || typeof obj !== 'object') return String(obj);
      if (Array.isArray(obj)) return `[${obj.map(stableStringify).join(',')}]`;
      const keys = Object.keys(obj).sort();
      return `{${keys.map(k => `${JSON.stringify(k)}:${stableStringify(obj[k])}`).join(',')}}`;
    };
    const bodyStr = stableStringify(orderData);
    const bodyHash = CryptoJS.SHA256(bodyStr).toString(CryptoJS.enc.Hex);
    const storageKey = `fi:ord:idk:${bodyHash}`;
    let idempotencyKey = '';
    try {
      const existing = sessionStorage.getItem(storageKey);
      if (existing) {
        const parsed = JSON.parse(existing);
        if (parsed && typeof parsed.key === 'string') {
          idempotencyKey = parsed.key;
        }
      }
    } catch {}
    if (!idempotencyKey) {
      const random = Math.random().toString(36).slice(2, 10);
      idempotencyKey = `ord-${bodyHash}-${random}`;
      try {
        sessionStorage.setItem(storageKey, JSON.stringify({ key: idempotencyKey, ts: Date.now() }));
      } catch {}
    }
    return wooCommerceApi.post('/orders', orderData, {
      headers: {
        'X-Idempotency-Key': idempotencyKey,
      }
    });
  },

  /**
   * Obtener un pedido por su ID
   * @param id ID del pedido
   * @returns Promesa con el pedido
   */
  getOrderById(id: number) {
    logger.info('orderApiService', 'Obteniendo pedido por ID:', id);
    return wooCommerceApi.get(`/orders/${id}`);
  },

  /**
   * Obtener los pedidos de un cliente
   * @param customerId ID del cliente
   * @returns Promesa con los pedidos del cliente
   */
  getCustomerOrders(customerId: number) {
    logger.info('orderApiService', 'Obteniendo pedidos del cliente:', customerId);
    return wooCommerceApi.get('/orders', {
      params: {
        customer: customerId,
        per_page: 100
      }
    });
  },

};

export default orderApiService;
