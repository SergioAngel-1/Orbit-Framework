/**
 * Servicio para la integración con Wompi
 * 
 * Maneja las llamadas a la API del backend para:
 * - Obtener configuración pública
 * - Generar firma de integridad
 * - Consultar transacciones
 */

import { api } from './apiConfig';
import logger from '../utils/logger';
import i18n from '../config/i18n';
import type {
  WompiConfigResponse,
  WompiSignatureResponse,
  WompiTransactionResponse,
  VirtualCoinsPackagesResponse,
} from '../types/wompi';

const WOMPI_ENDPOINT = '/starter/v1/wompi';
const STARTER_ENDPOINT = '/starter/v1';

/**
 * Servicio de Wompi
 */
const wompiService = {
  /**
   * Obtener configuración pública de Wompi
   */
  async getConfig(): Promise<WompiConfigResponse> {
    try {
      logger.info('wompiService', 'Obteniendo configuración de Wompi');
      const response = await api.get<WompiConfigResponse>(`${WOMPI_ENDPOINT}/config`);
      return response.data;
    } catch (error: any) {
      logger.error('wompiService', 'Error al obtener configuración:', error);
      return {
        success: false,
        message: error?.response?.data?.message || i18n.t('errors:wompi.getConfigError'),
      };
    }
  },

  /**
   * Generar firma de integridad para una transacción
   */
  async generateSignature(
    reference: string,
    amountInCents: number,
    expirationTime?: string
  ): Promise<WompiSignatureResponse> {
    try {
      logger.info('wompiService', 'Generando firma de integridad', { reference, amountInCents });
      
      const payload: Record<string, any> = {
        reference,
        amount_in_cents: amountInCents,
      };
      
      if (expirationTime) {
        payload.expiration_time = expirationTime;
      }
      
      const response = await api.post<WompiSignatureResponse>(
        `${WOMPI_ENDPOINT}/signature`,
        payload
      );
      
      return response.data;
    } catch (error: any) {
      logger.error('wompiService', 'Error al generar firma:', error);
      return {
        success: false,
        message: error?.response?.data?.message || i18n.t('errors:wompi.generateSignatureError'),
      };
    }
  },

  /**
   * Consultar estado de una transacción
   */
  async getTransaction(transactionId: string): Promise<WompiTransactionResponse> {
    try {
      logger.info('wompiService', 'Consultando transacción:', transactionId);
      
      const response = await api.get<WompiTransactionResponse>(
        `${WOMPI_ENDPOINT}/transaction/${transactionId}`
      );
      
      return response.data;
    } catch (error: any) {
      logger.error('wompiService', 'Error al consultar transacción:', error);
      return {
        success: false,
        message: error?.response?.data?.message || i18n.t('errors:wompi.getTransactionError'),
      };
    }
  },

  /**
   * Generar una referencia única para la transacción
   */
  generateReference(prefix: string = 'FI'): string {
    const timestamp = Date.now().toString(36).toUpperCase();
    const random = Array.from(crypto.getRandomValues(new Uint8Array(6)), b => b.toString(36)).join('').substring(0, 8).toUpperCase();
    return `${prefix}-${timestamp}-${random}`;
  },

  /**
   * Obtener paquetes de Virtual Coins disponibles
   * @param showAll - Si es true, devuelve todos los paquetes sin filtrar por membresía
   */
  async getVirtualCoinsPackages(showAll: boolean = false): Promise<VirtualCoinsPackagesResponse> {
    try {
      logger.info('wompiService', 'Obteniendo paquetes de Virtual Coins', { showAll });
      const params = showAll ? '?show_all=true' : '';
      const response = await api.get<VirtualCoinsPackagesResponse>(`${STARTER_ENDPOINT}/virtual-coins/packages${params}`);
      return response.data;
    } catch (error: any) {
      logger.error('wompiService', 'Error al obtener paquetes:', error);
      return {
        success: false,
        message: error?.response?.data?.message || i18n.t('errors:wompi.getPackagesError'),
      };
    }
  },

  /**
   * Registrar compra pendiente de Virtual Coins
   * Se debe llamar ANTES de abrir el widget de Wompi
   */
  async registerPendingPurchase(
    productId: number,
    reference: string
  ): Promise<{ success: boolean; data?: { id: number; reference: string; total_coins: number; price: number }; message?: string }> {
    try {
      logger.info('wompiService', 'Registrando compra pendiente de FC', { productId, reference });
      const response = await api.post(`${STARTER_ENDPOINT}/virtual-coins/pending-purchase`, {
        product_id: productId,
        reference,
      });
      return response.data;
    } catch (error: any) {
      logger.error('wompiService', 'Error al registrar compra pendiente:', error);
      return {
        success: false,
        message: error?.response?.data?.message || i18n.t('errors:wompi.registerPendingError'),
      };
    }
  },

  /**
   * Verificar estado de una compra de FC
   */
  async getPurchaseStatus(
    reference: string
  ): Promise<{ success: boolean; data?: { reference: string; status: string; total_coins: number; price: number }; message?: string }> {
    try {
      logger.info('wompiService', 'Consultando estado de compra FC:', reference);
      const response = await api.get(`${STARTER_ENDPOINT}/virtual-coins/purchase-status/${reference}`);
      return response.data;
    } catch (error: any) {
      logger.error('wompiService', 'Error al consultar estado de compra:', error);
      return {
        success: false,
        message: error?.response?.data?.message || i18n.t('errors:wompi.getPurchaseStatusError'),
      };
    }
  },

  /**
   * Confirmar compra de Virtual Coins después de pago aprobado
   * Se llama DESPUÉS de que el widget de Wompi retorna APPROVED
   * para acreditar los FC inmediatamente sin depender del webhook
   */
  async confirmFCPurchase(
    reference: string,
    transactionId: string
  ): Promise<{ 
    success: boolean; 
    data?: { 
      reference: string; 
      status: string; 
      total_coins: number;
      already_processed?: boolean;
    }; 
    message?: string 
  }> {
    try {
      logger.info('wompiService', 'Confirmando compra de FC', { reference, transactionId });
      const response = await api.post(`${STARTER_ENDPOINT}/virtual-coins/confirm-purchase`, {
        reference,
        transaction_id: transactionId,
      });
      return response.data;
    } catch (error: any) {
      logger.error('wompiService', 'Error al confirmar compra de FC:', error);
      return {
        success: false,
        message: error?.response?.data?.message || i18n.t('errors:wompi.confirmPurchaseError'),
      };
    }
  },

  /**
   * Registrar compra pendiente de membresía
   * Se debe llamar ANTES de abrir el widget de Wompi
   */
  async registerPendingMembershipPurchase(
    productId: number,
    reference: string
  ): Promise<{ 
    success: boolean; 
    data?: { 
      id: number; 
      reference: string; 
      membership_level: number;
      duration_days: number;
      monthly_points: number;
      price: number;
    }; 
    message?: string 
  }> {
    try {
      logger.info('wompiService', 'Registrando compra pendiente de membresía', { productId, reference });
      const response = await api.post(`${STARTER_ENDPOINT}/membership/pending-purchase`, {
        product_id: productId,
        reference,
      });
      return response.data;
    } catch (error: any) {
      logger.error('wompiService', 'Error al registrar compra pendiente de membresía:', error);
      return {
        success: false,
        message: error?.response?.data?.message || i18n.t('errors:wompi.registerPendingError'),
      };
    }
  },

  /**
   * Verificar estado de una compra de membresía
   */
  async getMembershipPurchaseStatus(
    reference: string
  ): Promise<{ 
    success: boolean; 
    data?: { 
      reference: string; 
      status: string; 
      membership_level: number;
      duration_days: number;
      monthly_points: number;
      price: number;
    }; 
    message?: string 
  }> {
    try {
      logger.info('wompiService', 'Consultando estado de compra de membresía:', reference);
      const response = await api.get(`${STARTER_ENDPOINT}/membership/purchase-status/${reference}`);
      return response.data;
    } catch (error: any) {
      logger.error('wompiService', 'Error al consultar estado de compra de membresía:', error);
      return {
        success: false,
        message: error?.response?.data?.message || i18n.t('errors:wompi.getPurchaseStatusError'),
      };
    }
  },

  /**
   * Confirmar compra de membresía después de pago aprobado
   * Se llama DESPUÉS de que el widget de Wompi retorna APPROVED
   * para activar la membresía inmediatamente sin depender del webhook
   */
  async confirmMembershipPurchase(
    reference: string,
    transactionId: string
  ): Promise<{ 
    success: boolean; 
    data?: { 
      reference: string; 
      status: string; 
      membership_level: number;
      duration_days?: number;
      monthly_points?: number;
      already_processed?: boolean;
    }; 
    message?: string 
  }> {
    try {
      logger.info('wompiService', 'Confirmando compra de membresía', { reference, transactionId });
      const response = await api.post(`${STARTER_ENDPOINT}/membership/confirm-purchase`, {
        reference,
        transaction_id: transactionId,
      });
      return response.data;
    } catch (error: any) {
      logger.error('wompiService', 'Error al confirmar compra de membresía:', error);
      return {
        success: false,
        message: error?.response?.data?.message || i18n.t('errors:wompi.confirmPurchaseError'),
      };
    }
  },

  /**
   * Registrar pago pendiente con tarjeta en checkout
   * Se usa para el flujo de "transacciones invisibles" de FC
   * El usuario paga con tarjeta (+5%) y el sistema:
   * 1. Compra FC equivalentes al monto
   * 2. Usa esos FC para pagar el pedido
   */
  async registerPendingCheckoutCardPayment(
    orderTotal: number,
    reference: string,
    orderId?: number,
    orderData?: Record<string, any>
  ): Promise<{
    success: boolean;
    data?: {
      id: number;
      reference: string;
      order_total: number;
      fee_percentage: number;
      fee_amount: number;
      total_with_fee: number;
      fc_for_order: number;
      amount_in_cents: number;
    };
    message?: string;
  }> {
    try {
      logger.info('wompiService', 'Registrando pago pendiente de checkout con tarjeta', {
        orderTotal,
        reference,
        orderId,
      });
      
      const payload: Record<string, any> = {
        order_total: orderTotal,
        reference,
      };
      
      if (orderId) {
        payload.order_id = orderId;
      }

      if (orderData) {
        payload.order_data = orderData;
      }
      
      const response = await api.post(`${STARTER_ENDPOINT}/checkout/card-payment/pending`, payload);
      return response.data;
    } catch (error: any) {
      logger.error('wompiService', 'Error al registrar pago pendiente de checkout:', error);
      return {
        success: false,
        message: error?.response?.data?.message || i18n.t('errors:wompi.registerPendingPaymentError'),
      };
    }
  },

  /**
   * Obtener estado de pago con tarjeta en checkout
   */
  async getCheckoutCardPaymentStatus(
    reference: string
  ): Promise<{
    success: boolean;
    data?: {
      reference: string;
      status: string;
      order_total: number;
      total_with_fee: number;
      fc_for_order: number;
      fc_transaction_id?: number;
      fc_payment_transaction_id?: number;
      wompi_transaction_id?: string;
      order_id?: number | null;
    };
    message?: string;
  }> {
    try {
      logger.info('wompiService', 'Consultando estado de pago de checkout:', reference);
      const response = await api.get(`${STARTER_ENDPOINT}/checkout/card-payment/status/${reference}`);
      return response.data;
    } catch (error: any) {
      logger.error('wompiService', 'Error al consultar estado de pago de checkout:', error);
      return {
        success: false,
        message: error?.response?.data?.message || i18n.t('errors:wompi.getPaymentStatusError'),
      };
    }
  },

  /**
   * Vincular order_id a un pago con tarjeta después de crear la orden WC
   * Esto permite que las descripciones de las transacciones de FC incluyan el número de orden
   */
  async linkOrderToCardPayment(
    reference: string,
    orderId: number
  ): Promise<{ success: boolean; message?: string }> {
    try {
      logger.info('wompiService', 'Vinculando orden a pago con tarjeta', { reference, orderId });
      const response = await api.post(`${STARTER_ENDPOINT}/checkout/card-payment/link-order`, {
        reference,
        order_id: orderId,
      });
      return response.data;
    } catch (error: any) {
      logger.error('wompiService', 'Error al vincular orden:', error);
      return {
        success: false,
        message: error?.response?.data?.message || i18n.t('errors:wompi.linkOrderError'),
      };
    }
  },

  /**
   * Confirmar pago con tarjeta en checkout después de pago aprobado
   * Se llama DESPUÉS de que el widget de Wompi retorna APPROVED
   * para procesar el pago inmediatamente sin depender del webhook
   */
  async confirmCheckoutCardPayment(
    reference: string,
    transactionId: string
  ): Promise<{
    success: boolean;
    data?: {
      reference: string;
      status: string;
      already_processed?: boolean;
    };
    message?: string;
  }> {
    try {
      logger.info('wompiService', 'Confirmando pago de checkout con tarjeta', { reference, transactionId });
      const response = await api.post(`${STARTER_ENDPOINT}/checkout/card-payment/confirm`, {
        reference,
        transaction_id: transactionId,
      });
      return response.data;
    } catch (error: any) {
      logger.error('wompiService', 'Error al confirmar pago de checkout:', error);
      return {
        success: false,
        message: error?.response?.data?.message || i18n.t('errors:wompi.confirmPurchaseError'),
      };
    }
  },
};

export default wompiService;
