/**
 * CardPaymentWidget - Widget de Wompi en modo tokenización
 * Solo muestra el formulario de tarjeta de crédito/débito
 */

import React, { useState, useEffect, useCallback, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { FiCreditCard, FiLoader, FiCheck, FiAlertCircle } from 'react-icons/fi';
import wompiService from '../../services/wompiService';
import alertService from '../../services/alertService';
import logger from '../../utils/logger';

interface CardPaymentWidgetProps {
  /** Monto total a pagar en pesos */
  amount: number;
  /** Referencia única para la transacción */
  reference: string;
  /** Email del cliente */
  customerEmail: string;
  /** Número de cuotas (default: 1) */
  installments?: number;
  /** Callback cuando el pago es exitoso */
  onPaymentSuccess: (transactionId: string, status: string) => void;
  /** Callback cuando el pago falla */
  onPaymentError?: (error: string) => void;
  /** Callback cuando el usuario cancela */
  onPaymentCancel?: () => void;
  /** Si el botón está deshabilitado */
  disabled?: boolean;
  /** Texto personalizado para el botón */
  buttonText?: string;
  /** Clase CSS adicional */
  className?: string;
}

type PaymentStatus = 'idle' | 'tokenizing' | 'processing' | 'success' | 'error';

const WOMPI_WIDGET_SCRIPT_URL = 'https://checkout.wompi.co/widget.js';

const CardPaymentWidget: React.FC<CardPaymentWidgetProps> = ({
  amount,
  reference,
  customerEmail: _customerEmail,
  installments: _installments = 1,
  onPaymentSuccess,
  onPaymentError,
  onPaymentCancel,
  disabled = false,
  buttonText,
  className = '',
}) => {
  const { t } = useTranslation('checkoutComponents');
  const resolvedButtonText = buttonText || t('cardPayment.defaultButton');
  const [status, setStatus] = useState<PaymentStatus>('idle');
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [publicKey, setPublicKey] = useState<string | null>(null);
  const [scriptLoaded, setScriptLoaded] = useState(false);
  const formRef = useRef<HTMLFormElement>(null);

  // Cargar configuración de Wompi
  useEffect(() => {
    const loadConfig = async () => {
      try {
        const response = await wompiService.getConfig();
        if (response.success && response.data) {
          setPublicKey(response.data.public_key);
        }
      } catch (error) {
        logger.error('CardPaymentWidget', 'Error al cargar configuración:', error);
      }
    };
    loadConfig();
  }, []);

  // Cargar script de Wompi
  useEffect(() => {
    if (scriptLoaded) return;

    const existingScript = document.querySelector(`script[src="${WOMPI_WIDGET_SCRIPT_URL}"]`);
    if (existingScript) {
      setScriptLoaded(true);
      return;
    }

    const script = document.createElement('script');
    script.src = WOMPI_WIDGET_SCRIPT_URL;
    script.async = true;
    script.onload = () => setScriptLoaded(true);
    script.onerror = () => {
      logger.error('CardPaymentWidget', 'Error al cargar script de Wompi');
      setErrorMessage(t('cardPayment.scriptLoadError'));
    };
    document.head.appendChild(script);
  }, [scriptLoaded]);

  // Abrir widget de tokenización
  const openTokenizeWidget = useCallback(() => {
    if (!publicKey || !scriptLoaded || !window.WidgetCheckout) {
      setErrorMessage(t('cardPayment.systemNotAvailable'));
      return;
    }

    setStatus('tokenizing');
    setErrorMessage(null);

    try {
      // Crear widget en modo tokenización
      const checkout = new window.WidgetCheckout({
        currency: 'COP',
        amountInCents: Math.round(amount * 100),
        reference: reference,
        publicKey: publicKey,
        // No se necesita firma para tokenización
        signature: { integrity: '' },
      });

      // El widget de Wompi no tiene modo tokenización directo via JS
      // Usamos el widget normal pero solo procesamos el token
      checkout.open((result: any) => {
        if (result && result.transaction) {
          // El usuario completó el pago en el widget
          const transaction = result.transaction;
          
          if (transaction.status === 'APPROVED') {
            setStatus('success');
            alertService.success(t('cardPayment.paymentSuccess'));
            onPaymentSuccess(transaction.id, transaction.status);
          } else if (transaction.status === 'DECLINED') {
            setErrorMessage(transaction.status_message || t('cardPayment.paymentRejected'));
            setStatus('error');
            onPaymentError?.(transaction.status_message || t('cardPayment.paymentRejected'));
          } else if (transaction.status === 'PENDING') {
            setStatus('success');
            alertService.info(t('cardPayment.paymentInProcess'));
            onPaymentSuccess(transaction.id, transaction.status);
          } else {
            setStatus('idle');
            onPaymentCancel?.();
          }
        } else {
          // Usuario cerró el widget
          setStatus('idle');
          onPaymentCancel?.();
        }
      });
    } catch (error: any) {
      logger.error('CardPaymentWidget', 'Error al abrir widget:', error);
      setErrorMessage(error.message || t('cardPayment.openFormError'));
      setStatus('error');
    }
  }, [publicKey, scriptLoaded, amount, reference, onPaymentSuccess, onPaymentError, onPaymentCancel]);

  const isLoading = status === 'tokenizing' || status === 'processing';
  const isDisabled = disabled || isLoading || !publicKey || !scriptLoaded || amount <= 0;

  return (
    <div className={`card-payment-widget ${className}`}>
      {/* Mensaje de error */}
      {status === 'error' && errorMessage && (
        <div className="flex items-center gap-2 p-3 mb-4 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
          <FiAlertCircle className="flex-shrink-0" />
          <span>{errorMessage}</span>
        </div>
      )}

      {/* Mensaje de éxito */}
      {status === 'success' && (
        <div className="flex items-center gap-2 p-3 mb-4 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm">
          <FiCheck className="flex-shrink-0" />
          <span>{t('cardPayment.successMessage')}</span>
        </div>
      )}

      {/* Botón de pago */}
      {status !== 'success' && (
        <button
          type="button"
          onClick={openTokenizeWidget}
          disabled={isDisabled}
          className={`
            w-full flex items-center justify-center gap-2 py-3 px-4 rounded-lg
            font-medium transition-all duration-200
            ${isDisabled
              ? 'bg-gray-300 text-gray-500 cursor-not-allowed'
              : 'bg-primario text-white hover:bg-hover'
            }
          `}
        >
          {isLoading ? (
            <>
              <FiLoader className="animate-spin" />
              <span>{status === 'tokenizing' ? t('cardPayment.opening') : t('cardPayment.processing')}</span>
            </>
          ) : (
            <>
              <FiCreditCard />
              <span>{resolvedButtonText}</span>
            </>
          )}
        </button>
      )}

      {/* Info de seguridad */}
      <div className="mt-3 flex items-center justify-center gap-2 text-xs text-gray-500">
        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
        </svg>
        <span>{t('cardPayment.securePayment')}</span>
      </div>

      {/* Form oculto para el widget de tokenización */}
      <form ref={formRef} style={{ display: 'none' }} />
    </div>
  );
};

export default CardPaymentWidget;
