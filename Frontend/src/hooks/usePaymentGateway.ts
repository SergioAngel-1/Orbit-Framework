/**
 * Hook genérico para pasarelas de pago
 * 
 * Proporciona la misma funcionalidad que useWompi pero a través de la
 * interfaz PaymentGateway, permitiendo intercambiar pasarelas sin cambiar
 * los componentes consumidores.
 * 
 * Uso:
 *   const { openWidget, generateReference, isConfigured } = usePaymentGateway();
 * 
 * La pasarela se selecciona automáticamente desde SiteConfigContext → payment_gateway.
 * Si no hay configuración, usa Wompi como fallback.
 * 
 * @package Starter
 */

import { useState, useEffect, useCallback, useRef, useMemo } from 'react';
import { getPaymentGateway } from '../services/payments';
import { useSiteConfig, useIdentity } from '../contexts/SiteConfigContext';
import logger from '../utils/logger';
import i18n from '../config/i18n';
import type {
  PaymentGatewayConfig,
  PaymentTransaction,
  PaymentWidgetOptions,
  UsePaymentGatewayReturn,
  PaymentGatewayId,
} from '../types/payment';

export const usePaymentGateway = (): UsePaymentGatewayReturn => {
  const { config: siteConfig } = useSiteConfig();
  const identity = useIdentity();
  const [isLoading, setIsLoading] = useState(true);
  const [isWidgetLoading, setIsWidgetLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [gatewayConfig, setGatewayConfig] = useState<PaymentGatewayConfig | null>(null);
  const [lastTransaction, setLastTransaction] = useState<PaymentTransaction | null>(null);

  const scriptLoadedRef = useRef(false);
  const configLoadedRef = useRef(false);

  // Resolver la pasarela desde la configuración del sitio
  const gatewayId = siteConfig?.payments?.payment_gateway || 'wompi';
  const gateway = useMemo(() => getPaymentGateway(gatewayId), [gatewayId]);

  /**
   * Cargar script del widget
   */
  const loadScript = useCallback(async () => {
    if (scriptLoadedRef.current || !gateway.widgetScriptUrl) return;
    try {
      await gateway.loadWidgetScript();
      scriptLoadedRef.current = true;
    } catch (err: any) {
      logger.error('usePaymentGateway', `Error al cargar script de ${gateway.name}:`, err);
    }
  }, [gateway]);

  /**
   * Cargar configuración de la pasarela
   */
  const loadConfig = useCallback(async () => {
    if (configLoadedRef.current) return;
    try {
      setIsLoading(true);
      setError(null);
      const response = await gateway.getConfig();
      if (response.success && response.data) {
        setGatewayConfig(response.data);
        configLoadedRef.current = true;
        logger.info('usePaymentGateway', `Configuración de ${gateway.name} cargada`);
      } else {
        throw new Error(response.message || i18n.t('errors:wompi.loadConfigError'));
      }
    } catch (err: any) {
      setError(err.message || i18n.t('errors:wompi.loadConfigError'));
      logger.error('usePaymentGateway', 'Error de configuración:', err);
    } finally {
      setIsLoading(false);
    }
  }, [gateway]);

  /**
   * Inicializar: cargar script y configuración
   */
  useEffect(() => {
    scriptLoadedRef.current = false;
    configLoadedRef.current = false;
    const init = async () => {
      try {
        await Promise.all([loadScript(), loadConfig()]);
      } catch (err: any) {
        logger.error('usePaymentGateway', 'Error en inicialización:', err);
      }
    };
    init();
  }, [loadScript, loadConfig]);

  /**
   * Abrir el widget de pago (maneja firma + widget internamente)
   */
  const openWidget = useCallback(async (
    options: PaymentWidgetOptions
  ): Promise<PaymentTransaction | null> => {
    if (!gatewayConfig) {
      setError(i18n.t('errors:wompi.notConfigured'));
      return null;
    }

    setIsWidgetLoading(true);
    setError(null);

    try {
      // Generar firma de integridad
      const sigResponse = await gateway.generateSignature(
        options.reference,
        options.amountInCents,
        options.expirationTime
      );

      if (!sigResponse.success || !sigResponse.data) {
        throw new Error(sigResponse.message || i18n.t('errors:wompi.signatureError'));
      }

      logger.info('usePaymentGateway', `Abriendo widget ${gateway.name}`, {
        reference: options.reference,
        amount: options.amountInCents,
      });

      // Abrir widget
      const transaction = await gateway.openWidget(gatewayConfig, sigResponse.data, options);

      if (transaction) {
        setLastTransaction(transaction);
      }

      return transaction;
    } catch (err: any) {
      const errorMessage = err.message || i18n.t('errors:wompi.paymentError');
      setError(errorMessage);
      logger.error('usePaymentGateway', 'Error al procesar pago:', err);
      return null;
    } finally {
      setIsWidgetLoading(false);
    }
  }, [gatewayConfig, gateway]);

  /**
   * Consultar una transacción
   */
  const getTransaction = useCallback(async (
    transactionId: string
  ): Promise<PaymentTransaction | null> => {
    try {
      const response = await gateway.getTransaction(transactionId);
      if (response.success && response.data) {
        return response.data;
      }
      throw new Error(response.message || i18n.t('errors:wompi.transactionQueryError'));
    } catch (err: any) {
      logger.error('usePaymentGateway', 'Error al consultar transacción:', err);
      return null;
    }
  }, [gateway]);

  /**
   * Generar referencia única
   */
  const defaultPrefix = useMemo(() => {
    const name = identity.site_short_name || identity.site_name || 'TX';
    return name.replace(/[^A-Za-z]/g, '').substring(0, 3).toUpperCase() || 'TX';
  }, [identity.site_short_name, identity.site_name]);

  const generateReference = useCallback((prefix?: string): string => {
    return gateway.generateReference(prefix || defaultPrefix);
  }, [gateway, defaultPrefix]);

  return {
    isLoading,
    isWidgetLoading,
    error,
    config: gatewayConfig,
    lastTransaction,
    openWidget,
    getTransaction,
    generateReference,
    isConfigured: !!gatewayConfig && !!gatewayConfig.publicKey,
    gatewayId: gateway.id as PaymentGatewayId,
  };
};

export default usePaymentGateway;
