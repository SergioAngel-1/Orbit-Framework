/**
 * Implementación de PaymentGateway para Wompi (Colombia)
 * 
 * Envuelve el wompiService existente adaptándolo a la interfaz genérica.
 * Toda la lógica de widget DOM detection, 3DS grace periods, etc.
 * se mantiene intacta en useWompi.ts — este archivo solo normaliza
 * las llamadas de API core.
 * 
 * @package Starter
 */

import { api } from '../apiConfig';
import logger from '../../utils/logger';
import i18n from '../../config/i18n';
import type {
  PaymentGateway,
  PaymentGatewayConfig,
  PaymentSignatureData,
  PaymentTransaction,
  PaymentWidgetOptions,
  PaymentApiResponse,
  PaymentTransactionStatus,
} from '../../types/payment';
import type {
  WompiTransaction,
  WompiWidgetParams,
  WompiWidgetResult,
} from '../../types/wompi';

const WOMPI_ENDPOINT = '/starter/v1/wompi';
const WOMPI_WIDGET_SCRIPT_URL = 'https://checkout.wompi.co/widget.js';

/**
 * Normalizar una transacción Wompi al formato genérico
 */
function normalizeTransaction(tx: WompiTransaction): PaymentTransaction {
  return {
    id: tx.id,
    reference: tx.reference,
    amountInCents: tx.amount_in_cents,
    currency: tx.currency,
    status: tx.status as PaymentTransactionStatus,
    statusMessage: tx.status_message,
    paymentMethodType: tx.payment_method_type,
    customerEmail: tx.customer_email,
    createdAt: tx.created_at,
    raw: tx as unknown as Record<string, any>,
  };
}

/**
 * Normalizar config de Wompi al formato genérico
 */
function normalizeConfig(data: { public_key: string; sandbox: boolean; currency: string }): PaymentGatewayConfig {
  return {
    publicKey: data.public_key,
    sandbox: data.sandbox,
    currency: data.currency,
  };
}

export const wompiGateway: PaymentGateway = {
  id: 'wompi',
  name: 'Wompi',
  widgetScriptUrl: WOMPI_WIDGET_SCRIPT_URL,

  async getConfig(): Promise<PaymentApiResponse<PaymentGatewayConfig>> {
    try {
      logger.info('WompiGateway', 'Obteniendo configuración');
      const response = await api.get(`${WOMPI_ENDPOINT}/config`);
      const raw = response.data;
      if (raw.success && raw.data) {
        return { success: true, data: normalizeConfig(raw.data) };
      }
      return { success: false, message: raw.message };
    } catch (error: any) {
      logger.error('WompiGateway', 'Error al obtener configuración:', error);
      return {
        success: false,
        message: error?.response?.data?.message || i18n.t('errors:wompi.getConfigError'),
      };
    }
  },

  async generateSignature(
    reference: string,
    amountInCents: number,
    expirationTime?: string
  ): Promise<PaymentApiResponse<PaymentSignatureData>> {
    try {
      logger.info('WompiGateway', 'Generando firma', { reference, amountInCents });
      const payload: Record<string, any> = { reference, amount_in_cents: amountInCents };
      if (expirationTime) payload.expiration_time = expirationTime;

      const response = await api.post(`${WOMPI_ENDPOINT}/signature`, payload);
      const raw = response.data;

      if (raw.success && raw.data) {
        return {
          success: true,
          data: {
            signature: raw.data.signature,
            reference: raw.data.reference,
            amountInCents: raw.data.amount_in_cents,
            currency: raw.data.currency,
            publicKey: raw.data.public_key,
          },
        };
      }
      return { success: false, message: raw.message };
    } catch (error: any) {
      logger.error('WompiGateway', 'Error al generar firma:', error);
      return {
        success: false,
        message: error?.response?.data?.message || i18n.t('errors:wompi.generateSignatureError'),
      };
    }
  },

  async getTransaction(transactionId: string): Promise<PaymentApiResponse<PaymentTransaction>> {
    try {
      logger.info('WompiGateway', 'Consultando transacción:', transactionId);
      const response = await api.get(`${WOMPI_ENDPOINT}/transaction/${transactionId}`);
      const raw = response.data;
      if (raw.success && raw.data) {
        return { success: true, data: normalizeTransaction(raw.data) };
      }
      return { success: false, message: raw.message };
    } catch (error: any) {
      logger.error('WompiGateway', 'Error al consultar transacción:', error);
      return {
        success: false,
        message: error?.response?.data?.message || i18n.t('errors:wompi.getTransactionError'),
      };
    }
  },

  generateReference(prefix: string = 'FI'): string {
    const timestamp = Date.now().toString(36).toUpperCase();
    const random = Array.from(crypto.getRandomValues(new Uint8Array(6)), b => b.toString(36))
      .join('')
      .substring(0, 8)
      .toUpperCase();
    return `${prefix}-${timestamp}-${random}`;
  },

  loadWidgetScript(): Promise<void> {
    return new Promise((resolve, reject) => {
      if ((window as any).WidgetCheckout) {
        resolve();
        return;
      }

      const existing = document.querySelector(`script[src="${WOMPI_WIDGET_SCRIPT_URL}"]`);
      if (existing) {
        existing.addEventListener('load', () => resolve());
        existing.addEventListener('error', () =>
          reject(new Error(i18n.t('errors:wompi.loadScriptError')))
        );
        return;
      }

      const script = document.createElement('script');
      script.src = WOMPI_WIDGET_SCRIPT_URL;
      script.async = true;
      script.type = 'text/javascript';
      script.onload = () => {
        logger.info('WompiGateway', 'Script cargado correctamente');
        resolve();
      };
      script.onerror = () => {
        logger.error('WompiGateway', 'Error al cargar script');
        reject(new Error(i18n.t('errors:wompi.loadScriptError')));
      };
      document.head.appendChild(script);
    });
  },

  openWidget(
    _config: PaymentGatewayConfig,
    signatureData: PaymentSignatureData,
    options: PaymentWidgetOptions
  ): Promise<PaymentTransaction | null> {
    return new Promise((resolve) => {
      if (!window.WidgetCheckout) {
        logger.error('WompiGateway', 'Widget no disponible');
        resolve(null);
        return;
      }

      const widgetParams: WompiWidgetParams = {
        currency: signatureData.currency,
        amountInCents: signatureData.amountInCents,
        reference: signatureData.reference,
        publicKey: signatureData.publicKey,
        signature: { integrity: signatureData.signature },
        redirectUrl: options.redirectUrl,
        expirationTime: options.expirationTime,
        customerData: options.customerData as any,
        shippingAddress: options.shippingAddress as any,
      };

      if (options.paymentMethods && options.paymentMethods.length > 0) {
        widgetParams.paymentMethods = options.paymentMethods as any;
      }

      let resolved = false;
      let widgetWasOpened = false;
      let checkWidgetClosed: ReturnType<typeof setInterval> | null = null;
      let timeoutId: ReturnType<typeof setTimeout> | null = null;
      let domGraceTimeout: ReturnType<typeof setTimeout> | null = null;

      const cleanup = () => {
        if (checkWidgetClosed) clearInterval(checkWidgetClosed);
        if (timeoutId) clearTimeout(timeoutId);
        if (domGraceTimeout) clearTimeout(domGraceTimeout);
      };

      const resolveOnce = (result: PaymentTransaction | null) => {
        if (resolved) return;
        resolved = true;
        cleanup();
        resolve(result);
      };

      // Timeout de seguridad: 3 minutos
      timeoutId = setTimeout(() => {
        logger.warn('WompiGateway', 'Widget timeout (3min)');
        resolveOnce(null);
      }, 3 * 60 * 1000);

      const checkout = new window.WidgetCheckout(widgetParams);

      checkout.open((result: WompiWidgetResult) => {
        if (result && result.transaction) {
          const normalized = normalizeTransaction(result.transaction);
          logger.info('WompiGateway', 'Transacción completada:', {
            id: normalized.id,
            status: normalized.status,
            reference: normalized.reference,
          });
          resolveOnce(normalized);
        } else {
          logger.warn('WompiGateway', 'Widget cerrado sin transacción');
          resolveOnce(null);
        }
      });

      // DOM detection para cierre de widget (misma lógica probada de useWompi)
      checkWidgetClosed = setInterval(() => {
        if (resolved) { cleanup(); return; }

        const widgetIframe = document.querySelector('iframe[src*="wompi"], iframe[src*="checkout.wompi"]');
        const widgetContainer = document.querySelector('[class*="wompi"], [id*="wompi"], [class*="Wompi"]');
        const widgetExists = widgetIframe || widgetContainer;

        if (widgetExists) {
          widgetWasOpened = true;
          if (domGraceTimeout) { clearTimeout(domGraceTimeout); domGraceTimeout = null; }
        } else if (widgetWasOpened && !widgetExists && !domGraceTimeout) {
          logger.info('WompiGateway', 'Widget DOM removido, grace period 10s...');
          domGraceTimeout = setTimeout(() => {
            if (!resolved) {
              logger.warn('WompiGateway', 'Widget cerrado sin callback (DOM grace)');
              resolveOnce(null);
            }
          }, 10000);
        }
      }, 500);
    });
  },
};

export default wompiGateway;
