/**
 * Hook para integración con Wompi
 * 
 * Proporciona funcionalidades para:
 * - Cargar el script del widget
 * - Obtener configuración
 * - Abrir el widget de pago
 * - Consultar transacciones
 * 
 * NOTA: Para nuevos componentes, considerar usar usePaymentGateway() que provee
 * una interfaz genérica compatible con múltiples pasarelas. Este hook se mantiene
 * para los flujos existentes (checkout, wallet, memberships) que dependen de
 * tipos y operaciones Wompi-específicas.
 * 
 * @see usePaymentGateway
 */

import { useState, useEffect, useCallback, useRef } from 'react';
import wompiService from '../services/wompiService';
import logger from '../utils/logger';
import i18n from '../config/i18n';
import type {
  WompiConfig,
  WompiTransaction,
  WompiOpenWidgetOptions,
  UseWompiReturn,
} from '../types/wompi';

const WOMPI_WIDGET_SCRIPT_URL = 'https://checkout.wompi.co/widget.js';

/**
 * Hook para manejar la integración con Wompi
 */
export const useWompi = (): UseWompiReturn => {
  const [isLoading, setIsLoading] = useState(true);
  const [isWidgetLoading, setIsWidgetLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [config, setConfig] = useState<WompiConfig | null>(null);
  const [lastTransaction, setLastTransaction] = useState<WompiTransaction | null>(null);
  
  const scriptLoadedRef = useRef(false);
  const configLoadedRef = useRef(false);

  /**
   * Cargar el script del widget de Wompi
   */
  const loadWidgetScript = useCallback((): Promise<void> => {
    return new Promise((resolve, reject) => {
      // Si ya está cargado, resolver inmediatamente
      if (scriptLoadedRef.current || window.WidgetCheckout) {
        scriptLoadedRef.current = true;
        resolve();
        return;
      }

      // Verificar si el script ya existe en el DOM
      const existingScript = document.querySelector(`script[src="${WOMPI_WIDGET_SCRIPT_URL}"]`);
      if (existingScript) {
        // Esperar a que cargue
        existingScript.addEventListener('load', () => {
          scriptLoadedRef.current = true;
          resolve();
        });
        existingScript.addEventListener('error', () => {
          reject(new Error(i18n.t('errors:wompi.loadScriptError')));
        });
        return;
      }

      // Crear y agregar el script
      const script = document.createElement('script');
      script.src = WOMPI_WIDGET_SCRIPT_URL;
      script.async = true;
      script.type = 'text/javascript';

      script.onload = () => {
        logger.info('useWompi', 'Script de Wompi cargado correctamente');
        scriptLoadedRef.current = true;
        resolve();
      };

      script.onerror = () => {
        logger.error('useWompi', 'Error al cargar el script de Wompi');
        reject(new Error(i18n.t('errors:wompi.loadScriptError')));
      };

      document.head.appendChild(script);
    });
  }, []);

  /**
   * Cargar configuración de Wompi
   */
  const loadConfig = useCallback(async () => {
    if (configLoadedRef.current) return;

    try {
      setIsLoading(true);
      setError(null);

      const response = await wompiService.getConfig();

      if (response.success && response.data) {
        setConfig(response.data);
        configLoadedRef.current = true;
        logger.info('useWompi', 'Configuración cargada:', response.data);
      } else {
        throw new Error(response.message || i18n.t('errors:wompi.loadConfigError'));
      }
    } catch (err: any) {
      const errorMessage = err.message || i18n.t('errors:wompi.loadConfigError');
      setError(errorMessage);
      logger.error('useWompi', errorMessage);
    } finally {
      setIsLoading(false);
    }
  }, []);

  /**
   * Inicializar: cargar script y configuración
   */
  useEffect(() => {
    const init = async () => {
      try {
        await Promise.all([
          loadWidgetScript(),
          loadConfig(),
        ]);
      } catch (err: any) {
        logger.error('useWompi', 'Error en inicialización:', err);
      }
    };

    init();
  }, [loadWidgetScript, loadConfig]);

  /**
   * Abrir el widget de pago
   */
  const openWidget = useCallback(async (
    options: WompiOpenWidgetOptions
  ): Promise<WompiTransaction | null> => {
    if (!config) {
      setError(i18n.t('errors:wompi.notConfigured'));
      return null;
    }

    if (!window.WidgetCheckout) {
      setError(i18n.t('errors:wompi.widgetNotAvailable'));
      return null;
    }

    setIsWidgetLoading(true);
    setError(null);

    try {
      // Generar firma de integridad desde el backend
      const signatureResponse = await wompiService.generateSignature(
        options.reference,
        options.amountInCents,
        options.expirationTime
      );

      if (!signatureResponse.success || !signatureResponse.data) {
        throw new Error(signatureResponse.message || i18n.t('errors:wompi.signatureError'));
      }

      const { signature, public_key, currency } = signatureResponse.data;

      logger.info('useWompi', 'Abriendo widget con referencia:', options.reference);
      logger.info('useWompi', 'Datos del widget:', {
        currency,
        amountInCents: options.amountInCents,
        reference: options.reference,
        publicKey: public_key,
        hasSignature: !!signature,
      });

      if (!window.WidgetCheckout) {
        throw new Error(i18n.t('errors:wompi.widgetNotLoaded'));
      }

      // Crear instancia del widget
      const widgetParams: any = {
        currency,
        amountInCents: options.amountInCents,
        reference: options.reference,
        publicKey: public_key,
        signature: {
          integrity: signature,
        },
        redirectUrl: options.redirectUrl,
        expirationTime: options.expirationTime,
        customerData: options.customerData,
        shippingAddress: options.shippingAddress,
      };
      
      // Agregar filtro de métodos de pago si se especifica
      if (options.paymentMethods && options.paymentMethods.length > 0) {
        widgetParams.paymentMethods = options.paymentMethods;
      }
      
      const checkout = new window.WidgetCheckout(widgetParams);

      // Abrir widget y esperar resultado
      return new Promise((resolve) => {
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

        const resolveOnce = (result: WompiTransaction | null) => {
          if (resolved) return;
          resolved = true;
          cleanup();
          setIsWidgetLoading(false);
          resolve(result);
        };

        // Timeout de seguridad: si el widget no responde en 3 minutos, resolver como null.
        // 3 min cubre incluso los flujos 3DS más lentos (OTP bancario puede tomar 2+ min).
        timeoutId = setTimeout(() => {
          logger.warn('useWompi', 'Widget timeout (3min) - resolviendo como cerrado');
          resolveOnce(null);
        }, 3 * 60 * 1000);

        // Abrir el widget
        checkout.open((result: any) => {
          if (result && result.transaction) {
            const transaction = result.transaction;
            setLastTransaction(transaction);
            
            logger.info('useWompi', 'Transacción completada:', {
              id: transaction.id,
              status: transaction.status,
              reference: transaction.reference,
            });

            resolveOnce(transaction);
          } else {
            // Widget cerrado sin completar transacción (callback normal de Wompi)
            logger.warn('useWompi', 'Widget cerrado sin transacción (callback)', result);
            resolveOnce(null);
          }
        });

        // Detectar cuando el widget se abre y luego se cierra
        // IMPORTANTE: No resolver inmediatamente al detectar remoción del DOM.
        // El callback de Wompi puede llegar milisegundos después de que el iframe
        // desaparezca (especialmente con 3DS, PSE, o en dispositivos lentos).
        // Usamos un buffer de gracia de 3 segundos para evitar race conditions.
        checkWidgetClosed = setInterval(() => {
          if (resolved) {
            cleanup();
            return;
          }
          
          // Buscar elementos del widget de Wompi
          const widgetIframe = document.querySelector('iframe[src*="wompi"], iframe[src*="checkout.wompi"]');
          const widgetContainer = document.querySelector('[class*="wompi"], [id*="wompi"], [class*="Wompi"]');
          const widgetExists = widgetIframe || widgetContainer;
          
          if (widgetExists) {
            // El widget se abrió (o sigue abierto)
            widgetWasOpened = true;
            // Si había un grace timeout pendiente (ej: 3DS que removió y re-creó el iframe), cancelarlo
            if (domGraceTimeout) {
              clearTimeout(domGraceTimeout);
              domGraceTimeout = null;
            }
          } else if (widgetWasOpened && !widgetExists && !domGraceTimeout) {
            // El widget desapareció del DOM — iniciar grace period
            // NO resolver inmediatamente: durante 3DS el iframe puede cambiar de dominio
            // (checkout.wompi.co → dominio del banco) y el callback llega cuando el
            // usuario completa el OTP. 10s de grace cubre el tiempo de propagación
            // del callback; si 3DS está en curso, el iframe reaparece y cancela el grace.
            logger.info('useWompi', 'Widget DOM removido, esperando callback de Wompi (10s grace)...');
            domGraceTimeout = setTimeout(() => {
              if (!resolved) {
                logger.warn('useWompi', 'Widget cerrado sin callback después de grace period (DOM)');
                resolveOnce(null);
              }
            }, 10000);
          }
        }, 500);
      });
    } catch (err: any) {
      const errorMessage = err.message || i18n.t('errors:wompi.paymentError');
      setError(errorMessage);
      setIsWidgetLoading(false);
      logger.error('useWompi', 'Error al procesar el pago:', err);
      logger.error('useWompi', 'Detalles del error:', {
        message: err.message,
        stack: err.stack,
        name: err.name,
      });
      return null;
    }
  }, [config]);

  /**
   * Consultar estado de una transacción
   */
  const getTransaction = useCallback(async (
    transactionId: string
  ): Promise<WompiTransaction | null> => {
    try {
      const response = await wompiService.getTransaction(transactionId);

      if (response.success && response.data) {
        return response.data;
      }

      throw new Error(response.message || i18n.t('errors:wompi.transactionQueryError'));
    } catch (err: any) {
      logger.error('useWompi', 'Error al consultar transacción:', err);
      return null;
    }
  }, []);

  /**
   * Generar referencia única
   */
  const generateReference = useCallback((prefix: string = 'FI'): string => {
    return wompiService.generateReference(prefix);
  }, []);

  return {
    isLoading,
    isWidgetLoading,
    error,
    config,
    lastTransaction,
    openWidget,
    getTransaction,
    generateReference,
    isConfigured: !!config && !!config.public_key,
  };
};

export default useWompi;
