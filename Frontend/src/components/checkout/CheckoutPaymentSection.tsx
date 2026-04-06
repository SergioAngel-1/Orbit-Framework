import React, { useState, useRef, useLayoutEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { FiCreditCard, FiLoader } from 'react-icons/fi';
import { PaymentMethod, CARD_PAYMENT_FEE_PERCENTAGE } from './index';
import CollapsibleSection from '../common/CollapsibleSection';
import Loader from '../ui/Loader';
import VirtualCoinPrice from '../common/VirtualCoinPrice';
import { fluidSizing } from '../../utils/fluidSizing';
import { useWompi } from '../../hooks/useWompi';
import wompiService from '../../services/wompiService';
import alertService from '../../services/alertService';
import logger from '../../utils/logger';
import { ceilTo50COP } from '../../utils/formatters';

interface CheckoutPaymentSectionProps {
  paymentMethod: string;
  submitting: boolean;
  disclaimerAccepted: boolean;
  onDisclaimerChange: (accepted: boolean) => void;
  onInputChange: (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => void;
  /** Monto total a pagar */
  totalAmount?: number;
  /** Descuento aplicado por Virtual Coins */
  appliedPointsDiscount?: number;
  /** Referencia de la orden */
  orderReference?: string;
  /** Callback para enviar el formulario de checkout (después del pago si es tarjeta) */
  onSubmitCheckout?: () => void;
  /** Callback para establecer la referencia del pago con tarjeta (para vincular con la orden) */
  onCardPaymentReferenceChange?: (reference: string) => void;
  /** Callback para notificar que el pago con tarjeta quedó PENDING */
  onCardPaymentPendingChange?: (pending: boolean) => void;
  /** Si falta el documento de identidad (cédula) */
  documentIdMissing?: boolean;
  /** Callback para construir los datos del pedido WC (backup server-side si el frontend falla) */
  buildOrderDataForBackup?: () => Record<string, any> | null;
  /** Si el usuario seleccionó envío premium (express o fast) */
  isPremiumShipping?: boolean;
  /** Callback de validación pre-pago: retorna null si válido, o string con mensaje de error */
  validateBeforePayment?: () => string | null;
}

/**
 * Componente que agrupa el método de aporte y el botón de completar retiro
 */
const CheckoutPaymentSection: React.FC<CheckoutPaymentSectionProps> = ({
  paymentMethod,
  submitting,
  disclaimerAccepted,
  onDisclaimerChange,
  onInputChange,
  totalAmount = 0,
  appliedPointsDiscount = 0,
  orderReference = '',
  onSubmitCheckout,
  onCardPaymentReferenceChange,
  onCardPaymentPendingChange,
  documentIdMissing = false,
  buildOrderDataForBackup,
  isPremiumShipping = false,
  validateBeforePayment,
}) => {
  const { t } = useTranslation('checkoutPage');
  const { isConfigured, isWidgetLoading, openWidget, generateReference } = useWompi();
  const [cardPaymentStatus, setCardPaymentStatus] = useState<'idle' | 'processing' | 'success' | 'pending' | 'error'>('idle');
  const isProcessingCardRef = useRef(false);

  // Ref para evitar stale closure: garantiza que el setTimeout siempre llame
  // la versión más reciente de onSubmitCheckout (con cardPaymentReference y
  // cardPaymentPending actualizados tras los setState anteriores al widget).
  const onSubmitCheckoutRef = useRef(onSubmitCheckout);
  useLayoutEffect(() => {
    onSubmitCheckoutRef.current = onSubmitCheckout;
  });
  
  // Calcular monto con fee para tarjeta (restando el descuento de Virtual Coins)
  const baseAmount = ceilTo50COP(Math.max(0, totalAmount - appliedPointsDiscount));
  const feeAmount = ceilTo50COP(baseAmount * (CARD_PAYMENT_FEE_PERCENTAGE / 100));
  const totalWithFee = ceilTo50COP(baseAmount + feeAmount);
  
  // Detectar si el pedido está pagado completamente con Virtual Coins
  const isPaidWithCoins = baseAmount <= 0 && appliedPointsDiscount > 0;
  
  const isCardPayment = paymentMethod === 'card' && !isPaidWithCoins;
  const isProcessingCard = cardPaymentStatus === 'processing' || isWidgetLoading;
  
  // El botón está habilitado si:
  // - No está enviando
  // - El disclaimer está aceptado
  // - Hay un método de pago seleccionado O está pagado con coins
  // - No está procesando tarjeta
  const isCardPaymentPending = cardPaymentStatus === 'pending';
  const isButtonDisabled = submitting || !disclaimerAccepted || (!paymentMethod && !isPaidWithCoins) || isProcessingCard || documentIdMissing;

  // Manejar click en el botón principal
  const handleMainButtonClick = async (e: React.MouseEvent) => {
    e.preventDefault();
    
    if (isCardPaymentPending || cardPaymentStatus === 'success') {
      // Pago ya procesado (PENDING o SUCCESS): reintentar creación de orden sin re-pagar
      onSubmitCheckout?.();
    } else if (isPaidWithCoins) {
      // Pagado con Virtual Coins: validar y enviar pedido directamente
      if (validateBeforePayment) {
        const validationError = validateBeforePayment();
        if (validationError) {
          alertService.error(validationError);
          return;
        }
      }
      onSubmitCheckout?.();
    } else if (isCardPayment) {
      // Para tarjeta: validar ANTES de cobrar, luego procesar pago
      await handleCardPayment();
    } else {
      // Para otros métodos: validar y enviar formulario directamente
      if (validateBeforePayment) {
        const validationError = validateBeforePayment();
        if (validationError) {
          alertService.error(validationError);
          return;
        }
      }
      onSubmitCheckout?.();
    }
  };

  // Manejar pago con tarjeta
  const handleCardPayment = async () => {
    // Guard imperativo: previene doble-click antes de que React flushee el setState
    if (isProcessingCardRef.current) return;
    isProcessingCardRef.current = true;

    if (!isConfigured || totalAmount <= 0) {
      isProcessingCardRef.current = false;
      alertService.error(t('payment.cannotProcess'));
      return;
    }

    // Validar formulario ANTES de cobrar al usuario
    if (validateBeforePayment) {
      const validationError = validateBeforePayment();
      if (validationError) {
        isProcessingCardRef.current = false;
        alertService.error(validationError);
        return;
      }
    }

    setCardPaymentStatus('processing');

    try {
      const paymentReference = orderReference || generateReference('CPY');
      
      // Notificar la referencia del pago para que se pueda vincular con la orden
      onCardPaymentReferenceChange?.(paymentReference);

      logger.info('CheckoutPaymentSection', 'Iniciando pago con tarjeta', {
        reference: paymentReference,
        originalAmount: totalAmount,
        feeAmount,
        totalWithFee,
      });

      // Construir order_data para backup server-side (por si el frontend no puede crear la orden)
      const backupOrderData = buildOrderDataForBackup?.() ?? undefined;

      // Registrar pago pendiente en el backend (enviar monto redondeado a múltiplos de 50 COP)
      const pendingResult = await wompiService.registerPendingCheckoutCardPayment(
        baseAmount,
        paymentReference,
        undefined,
        backupOrderData
      );

      if (!pendingResult.success || !pendingResult.data) {
        throw new Error(pendingResult.message || 'Error al preparar el pago');
      }

      const { amount_in_cents: amountInCents } = pendingResult.data;

      // Abrir widget de Wompi
      const transaction = await openWidget({
        amountInCents,
        reference: paymentReference,
      });

      if (transaction) {
        if (transaction.status === 'APPROVED') {
          // Confirmar el pago con el backend (no depender solo del webhook)
          try {
            await wompiService.confirmCheckoutCardPayment(paymentReference, transaction.id);
          } catch (confirmError) {
            logger.warn('CheckoutPaymentSection', 'Error al confirmar pago (no crítico, webhook como fallback):', confirmError);
          }
          setCardPaymentStatus('success');
          alertService.success(t('payment.paymentSuccess'));
          // Continuar con el checkout después del pago aprobado.
          // Se usa el ref para evitar stale closure: en este punto cardPaymentReference
          // ya fue seteado vía setCardPaymentReference (línea ~106), lo que disparó
          // un re-render. El ref apunta al handleSubmit del render más reciente,
          // que tiene cardPaymentReference y cardPaymentPending con valores frescos.
          setTimeout(() => {
            onSubmitCheckoutRef.current?.();
          }, 0);
          isProcessingCardRef.current = false;
        } else if (transaction.status === 'PENDING') {
          // Pago pendiente (PSE, Nequi, etc.) — Crear la orden WC con estado "wompi-verifying"
          // El webhook de Wompi actualizará el estado cuando se confirme el pago.
          setCardPaymentStatus('pending');
          onCardPaymentPendingChange?.(true);
          alertService.warning(t('payment.paymentPendingWithOrder'));
          // Continuar con el checkout para crear la orden (con estado especial wompi-verifying).
          // El ref garantiza que handleSubmit tenga cardPaymentPending = true (ya seteado
          // arriba vía onCardPaymentPendingChange), lo que produce set_paid=false y
          // status='wompi-verifying' en buildOrderData.
          setTimeout(() => {
            onSubmitCheckoutRef.current?.();
          }, 0);
          isProcessingCardRef.current = false;
        } else if (transaction.status === 'DECLINED') {
          setCardPaymentStatus('error');
          isProcessingCardRef.current = false;
          alertService.error(t('payment.paymentDeclined'));
        } else if (transaction.status === 'VOIDED') {
          setCardPaymentStatus('error');
          isProcessingCardRef.current = false;
          alertService.error(t('payment.paymentVoided', 'El pago fue anulado. Intenta de nuevo.'));
        } else if (transaction.status === 'ERROR') {
          setCardPaymentStatus('error');
          isProcessingCardRef.current = false;
          alertService.error(t('payment.paymentTechnicalError', 'Hubo un error técnico con el pago. Intenta de nuevo.'));
        } else {
          setCardPaymentStatus('error');
          isProcessingCardRef.current = false;
          logger.warn('CheckoutPaymentSection', 'Estado de transacción desconocido:', transaction.status);
          alertService.error(t('payment.paymentError'));
        }
      } else {
        // Widget resuelto sin transacción visible en el frontend.
        // Puede ser cierre manual del usuario O que el grace period de DOM
        // disparó durante 3DS (el iframe del banco no matchea el selector de Wompi).
        // Verificar server-side si el pago fue aprobado antes de resetear el estado.
        let serverConfirmed = false;
        try {
          // Espera breve para que el webhook/confirm procese si llegó justo ahora
          await new Promise(resolve => setTimeout(resolve, 2000));
          const serverStatus = await wompiService.getCheckoutCardPaymentStatus(paymentReference);
          if (serverStatus.success && serverStatus.data) {
            const status = serverStatus.data.status;
            if (status === 'completed') {
              // 3DS completó exitosamente pero DOM detection disparó el grace period antes
              serverConfirmed = true;
              logger.info('CheckoutPaymentSection', 'Pago confirmado server-side (3DS completado):', {
                reference: paymentReference,
                status,
              });
            } else if (status === 'processing') {
              // El pago está siendo procesado por otro hilo (webhook)
              // Esperar un poco más y re-verificar
              await new Promise(resolve => setTimeout(resolve, 3000));
              const recheck = await wompiService.getCheckoutCardPaymentStatus(paymentReference);
              if (recheck.success && recheck.data?.status === 'completed') {
                serverConfirmed = true;
                logger.info('CheckoutPaymentSection', 'Pago confirmado server-side (tras re-verificación):', {
                  reference: paymentReference,
                });
              }
            }
          }
        } catch (statusError) {
          logger.warn('CheckoutPaymentSection', 'Error al verificar estado server-side:', statusError);
        }

        if (serverConfirmed) {
          setCardPaymentStatus('success');
          alertService.success(t('payment.paymentSuccess'));
          setTimeout(() => {
            onSubmitCheckoutRef.current?.();
          }, 0);
        } else {
          // El usuario realmente cerró el widget sin completar el pago
          setCardPaymentStatus('idle');
        }
        isProcessingCardRef.current = false;
      }
    } catch (error: any) {
      logger.error('CheckoutPaymentSection', 'Error en pago con tarjeta:', error);
      setCardPaymentStatus('error');
      isProcessingCardRef.current = false;
      alertService.error(error.message || 'Error al procesar el pago');
    }
  };
  
  return (
    <CollapsibleSection
      title={t('payment.sectionTitle')}
      icon={FiCreditCard}
      collapsible={false}
      showCollapseButton={false}
    >
      <PaymentMethod
        paymentMethod={paymentMethod}
        onInputChange={onInputChange}
        disclaimerAccepted={disclaimerAccepted}
        onDisclaimerChange={onDisclaimerChange}
        totalAmount={totalAmount}
        appliedPointsDiscount={appliedPointsDiscount}
        cardPaymentStatus={cardPaymentStatus}
        isPremiumShipping={isPremiumShipping}
      />
      
      {/* Aviso de pago pendiente — el usuario debe esperar confirmación */}
      {isCardPaymentPending && (
        <div className="flex flex-col items-center gap-3 p-4 bg-yellow-50 border border-yellow-200 rounded-lg text-yellow-800" style={{ marginTop: fluidSizing.space.md }}>
          <div className="flex items-center gap-2">
            <svg className="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span className="font-semibold" style={{ fontSize: fluidSizing.text.sm }}>{t('payment.pendingTitle')}</span>
          </div>
          <p className="text-center" style={{ fontSize: fluidSizing.text.xs }}>
            {t('payment.pendingDescription')}
          </p>
        </div>
      )}
      
      {/* Botón principal - cambia según método de pago */}
      <div style={{ marginTop: fluidSizing.space.xl }}>
        <button
          type="button"
          onClick={handleMainButtonClick}
          disabled={isButtonDisabled}
          className={`w-full rounded-lg font-semibold transition-all flex items-center justify-center ${
            isButtonDisabled
              ? 'bg-gray-400 text-white cursor-not-allowed opacity-60'
              : 'bg-primario text-white hover:bg-hover shadow-lg shadow-primario/30 hover:shadow-primario/50'
          }`}
          style={{ padding: fluidSizing.space.md, fontSize: fluidSizing.text.lg }}
        >
          {submitting ? (
            <div className="flex items-center justify-center">
              <Loader text="" size="small" />
              <span style={{ marginLeft: fluidSizing.space.sm }}>{t('payment.processingOrder')}</span>
            </div>
          ) : isProcessingCard ? (
            <div className="flex items-center justify-center">
              <FiLoader className="animate-spin" style={{ width: fluidSizing.size.iconMd, height: fluidSizing.size.iconMd }} />
              <span style={{ marginLeft: fluidSizing.space.sm }}>{t('payment.processingPayment')}</span>
            </div>
          ) : isPaidWithCoins ? (
            <span className="flex items-center justify-center" style={{ gap: fluidSizing.space.sm }}>
              <svg style={{ width: fluidSizing.size.iconMd, height: fluidSizing.size.iconMd }} xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
              </svg>
              {t('payment.completeWithCoins')}
            </span>
          ) : isCardPaymentPending ? (
            <span className="flex items-center justify-center" style={{ gap: fluidSizing.space.sm }}>
              <FiCreditCard className="w-4 h-4 md:w-5 md:h-5 flex-shrink-0" />
              <span className="text-sm md:text-base">{t('payment.completeOrder')}</span>
            </span>
          ) : isCardPayment ? (
            <span className="flex items-center justify-center flex-wrap" style={{ gap: fluidSizing.space.xs }}>
              <FiCreditCard className="w-4 h-4 md:w-5 md:h-5 flex-shrink-0" />
              <span className="text-sm md:text-base">{t('payment.payAndComplete')}</span>
              <VirtualCoinPrice amount={totalWithFee} size="xs" showLabel={true} inheritColor />
            </span>
          ) : (
            <span className="flex items-center justify-center" style={{ gap: fluidSizing.space.sm }}>
              <svg style={{ width: fluidSizing.size.iconMd, height: fluidSizing.size.iconMd }} xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
              </svg>
              {t('payment.completeOrder')}
            </span>
          )}
        </button>
      </div>
    </CollapsibleSection>
  );
};

export default CheckoutPaymentSection;
