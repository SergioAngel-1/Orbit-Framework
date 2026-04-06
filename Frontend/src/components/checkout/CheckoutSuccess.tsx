import React, { useState, useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { FiPackage, FiCheckCircle, FiMail, FiClock, FiLoader, FiXCircle, FiAlertTriangle } from 'react-icons/fi';
import ProfileModal from '../profile/ProfileModal';
import CollapsibleSection from '../common/CollapsibleSection';
import { fluidSizing } from '../../utils/fluidSizing';
import { useLanguage } from '../../contexts/LanguageContext';
import { useMembership } from '../../contexts/MembershipContext';
import wompiService from '../../services/wompiService';
import alertService from '../../services/alertService';
import { logger } from '../../utils/logger';

interface OrderHistoryDetails {
  placedAt?: string;
  deliveryDate?: string;
  deliveryStatus?: string;
  shippingMethod?: string;
  shippingAddress?: string;
  paymentMethod?: string;
  totalFormatted?: string;
}

interface CheckoutSuccessProps {
  orderId?: number | null;
  showNextSteps?: boolean;
  onBackToOrders?: () => void;
  onReorder?: () => void;
  historyDetails?: OrderHistoryDetails;
  enableProfileModal?: boolean;
  /** Si el pago con tarjeta quedó PENDING (PSE, Nequi) */
  cardPaymentPending?: boolean;
  /** Referencia del pago para polling */
  cardPaymentReference?: string;
  /** Si el pago fue cobrado pero la orden WC no se pudo crear */
  orderCreationFailed?: boolean;
}

const CheckoutSuccess: React.FC<CheckoutSuccessProps> = ({
  orderId,
  showNextSteps = true,
  onBackToOrders,
  onReorder,
  historyDetails,
  enableProfileModal = true,
  cardPaymentPending = false,
  cardPaymentReference,
  orderCreationFailed = false,
}) => {
  const finalOrderId = orderId ?? null;
  const [isProfileModalOpen, setIsProfileModalOpen] = useState(false);
  const [paymentConfirmed, setPaymentConfirmed] = useState(false);
  const [paymentDeclined, setPaymentDeclined] = useState(false);
  const { refreshMembership } = useMembership();
  const { t } = useTranslation('checkoutSuccess');
  const { localizedPath } = useLanguage();

  // Polling para verificar pago pendiente con tarjeta
  const pollingRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const pollingCountRef = useRef(0);

  useEffect(() => {
    if (!cardPaymentPending || !cardPaymentReference || paymentConfirmed || paymentDeclined) return;

    logger.info('CheckoutSuccess', 'Iniciando polling para pago pendiente', {
      reference: cardPaymentReference,
      orderId,
    });
    pollingCountRef.current = 0;

    const poll = async () => {
      pollingCountRef.current += 1;
      if (pollingCountRef.current > 20) {
        logger.info('CheckoutSuccess', 'Polling detenido: m\u00e1ximo de intentos alcanzado');
        if (pollingRef.current) clearInterval(pollingRef.current);
        alertService.warning(t('wompiVerifying.pollingExhausted'));
        return;
      }
      try {
        const result = await wompiService.getCheckoutCardPaymentStatus(cardPaymentReference);
        if (result.success && result.data) {
          logger.info('CheckoutSuccess', 'Polling intento ' + pollingCountRef.current + ': estado = ' + result.data.status);
          if (result.data.status === 'completed') {
            setPaymentConfirmed(true);
            alertService.success(t('wompiVerifying.confirmed'));
            if (pollingRef.current) clearInterval(pollingRef.current);
          } else if (result.data.status === 'declined' || result.data.status === 'voided') {
            setPaymentDeclined(true);
            alertService.error(t('wompiVerifying.declinedAlert'));
            if (pollingRef.current) clearInterval(pollingRef.current);
          }
        }
      } catch (err) {
        logger.warn('CheckoutSuccess', 'Error en polling:', err);
      }
    };

    // Primera verificaci\u00f3n inmediata, luego cada 15 segundos
    poll();
    pollingRef.current = setInterval(poll, 15000);

    return () => {
      if (pollingRef.current) clearInterval(pollingRef.current);
    };
  }, [cardPaymentPending, cardPaymentReference, paymentConfirmed, paymentDeclined, orderId, t]);

  // Scroll al inicio y refrescar membresía cuando se monta el componente (pedido completado)
  useEffect(() => {
    window.scrollTo({
      top: 0,
      behavior: 'instant'
    });
    
    // Refrescar datos de membresía para actualizar beneficios (free_samples, etc.)
    if (showNextSteps) {
      refreshMembership();
    }
  }, [showNextSteps, refreshMembership]);

  const normalizeStatus = (status?: string) =>
    status?.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase() ?? '';

  // Determinar si estamos en estado de verificación de pago Wompi
  const isWompiVerifying = cardPaymentPending && !paymentConfirmed && !paymentDeclined;
  const isWompiDeclined = cardPaymentPending && paymentDeclined;
  const isOrderFailed = orderCreationFailed;

  const statusTheme = (() => {
    if (isOrderFailed) return 'danger';
    if (isWompiDeclined) return 'danger';
    if (isWompiVerifying) return 'warning';
    if (cardPaymentPending && paymentConfirmed) return 'success';
    const normalized = normalizeStatus(historyDetails?.deliveryStatus);
    if (!normalized) {
      return 'success';
    }
    if (normalized.includes('cancel') || normalized.includes('fallid') || normalized.includes('reembol')) {
      return 'danger';
    }
    if (normalized.includes('pend') || normalized.includes('esper')) {
      return 'warning';
    }
    if (normalized.includes('proce')) {
      return 'info';
    }
    return 'success';
  })();

  const statusVisuals: Record<
    'success' | 'warning' | 'info' | 'danger',
    { bgClass: string; iconColor: string; icon: React.ReactElement }
  > = {
    success: {
      bgClass: 'bg-green-100',
      iconColor: 'text-green-600',
      icon: <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
    },
    warning: {
      bgClass: 'bg-yellow-100',
      iconColor: 'text-yellow-600',
      icon: (
        <path
          strokeLinecap="round"
          strokeLinejoin="round"
          strokeWidth={2}
          d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
        />
      )
    },
    info: {
      bgClass: 'bg-blue-100',
      iconColor: 'text-blue-600',
      icon: (
        <path
          strokeLinecap="round"
          strokeLinejoin="round"
          strokeWidth={2}
          d="M13 10V3L4 14h7v7l9-11h-7z"
        />
      )
    },
    danger: {
      bgClass: 'bg-red-100',
      iconColor: 'text-red-600',
      icon: (
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
      )
    }
  };

  const { bgClass: statusBgClass, iconColor: statusIconColor, icon: statusIcon } = statusVisuals[statusTheme];
  const isCompactLayout = !showNextSteps;
  const containerPadding = isCompactLayout ? 'py-4 md:py-6' : 'py-6 md:py-16';

  const headerTitle = isOrderFailed
    ? t('wompiVerifying.orderFailedTitle')
    : isWompiDeclined
    ? t('wompiVerifying.declinedTitle')
    : isWompiVerifying
      ? t('wompiVerifying.title')
      : (cardPaymentPending && paymentConfirmed)
        ? t('wompiVerifying.confirmedTitle')
        : historyDetails?.deliveryStatus
          ? t('header.statusTitle', { status: historyDetails.deliveryStatus.toLowerCase() })
          : showNextSteps
            ? t('header.receivedTitle')
            : t('header.waitingTitle');
  const headerSubtitle = isOrderFailed
    ? t('wompiVerifying.orderFailedSubtitle')
    : isWompiDeclined
    ? t('wompiVerifying.declinedSubtitle')
    : isWompiVerifying
      ? t('wompiVerifying.subtitle')
      : (cardPaymentPending && paymentConfirmed)
        ? t('wompiVerifying.confirmedSubtitle')
        : historyDetails?.deliveryStatus
          ? t('header.statusSubtitle')
          : t('header.defaultSubtitle');
  
  // Función para abrir el modal de perfil en la pestaña de retiros
  const handleViewOrders = () => {
    if (!enableProfileModal) return;
    setIsProfileModalOpen(true);
  };
  
  // Función para cerrar el modal de perfil
  const handleCloseProfileModal = () => {
    setIsProfileModalOpen(false);
  };
  
  // Función para abrir WhatsApp con mensaje de ayuda
  const handleWhatsAppHelp = () => {
    const message = t('whatsapp.message', { id: finalOrderId });
    const whatsappUrl = `https://api.whatsapp.com/send/?phone=573223237785&text=${encodeURIComponent(message)}`;
    window.open(whatsappUrl, '_blank');
  };
  
  // Función para ir al inicio
  const handleGoHome = () => {
    window.location.href = localizedPath('/');
  };
  
  return (
    <>
      <div className={`container mx-auto px-3 md:px-4 ${containerPadding}`}>
        <div className="max-w-2xl mx-auto">
          {/* Header */}
          <div className="text-center mb-8">
            <div className={`inline-flex items-center justify-center w-20 h-20 rounded-full mb-4 ${statusBgClass}`}>
              <svg
                xmlns="http://www.w3.org/2000/svg"
                className={`h-10 w-10 ${statusIconColor}`}
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
              >
                {statusIcon}
              </svg>
            </div>
            <h1 className="text-2xl md:text-3xl font-bold text-primario mb-2 text-capitalize">{headerTitle}</h1>
            <p className="text-gray-600">{headerSubtitle}</p>
          </div>

          {/* Order Details Card */}
          <CollapsibleSection
            title={t('details.title')}
            subtitle={finalOrderId ? t('details.subtitle', { id: finalOrderId }) : undefined}
            icon={FiPackage}
            variant="soft"
            collapsible={false}
            showCollapseButton={false}
            className="mb-6"
          >
            {isOrderFailed ? (
              <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.md }}>
                <div className="flex items-start" style={{ gap: fluidSizing.space.sm }}>
                  <div 
                    className="flex-shrink-0 rounded-full bg-green-100 flex items-center justify-center"
                    style={{ width: fluidSizing.size.buttonSm, height: fluidSizing.size.buttonSm }}
                  >
                    <FiCheckCircle className="text-green-600" style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                  </div>
                  <div>
                    <p className="font-medium text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>{t('wompiVerifying.orderFailedStep1')}</p>
                    <p className="text-texto" style={{ fontSize: fluidSizing.text.xs }}>{t('wompiVerifying.orderFailedStep1Desc')}</p>
                  </div>
                </div>
                
                <div className="flex items-start" style={{ gap: fluidSizing.space.sm }}>
                  <div 
                    className="flex-shrink-0 rounded-full bg-red-100 flex items-center justify-center"
                    style={{ width: fluidSizing.size.buttonSm, height: fluidSizing.size.buttonSm }}
                  >
                    <FiAlertTriangle className="text-red-600" style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                  </div>
                  <div>
                    <p className="font-medium text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>{t('wompiVerifying.orderFailedStep2')}</p>
                    <p className="text-texto" style={{ fontSize: fluidSizing.text.xs }}>{t('wompiVerifying.orderFailedStep2Desc')}</p>
                  </div>
                </div>
              </div>
            ) : isWompiDeclined ? (
              <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.md }}>
                <div className="flex items-start" style={{ gap: fluidSizing.space.sm }}>
                  <div 
                    className="flex-shrink-0 rounded-full bg-red-100 flex items-center justify-center"
                    style={{ width: fluidSizing.size.buttonSm, height: fluidSizing.size.buttonSm }}
                  >
                    <FiXCircle className="text-red-600" style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                  </div>
                  <div>
                    <p className="font-medium text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>{t('wompiVerifying.declinedStep1')}</p>
                    <p className="text-texto" style={{ fontSize: fluidSizing.text.xs }}>{t('wompiVerifying.declinedStep1Desc')}</p>
                  </div>
                </div>
                
                <div className="flex items-start" style={{ gap: fluidSizing.space.sm }}>
                  <div 
                    className="flex-shrink-0 rounded-full bg-red-100 flex items-center justify-center"
                    style={{ width: fluidSizing.size.buttonSm, height: fluidSizing.size.buttonSm }}
                  >
                    <FiAlertTriangle className="text-red-600" style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                  </div>
                  <div>
                    <p className="font-medium text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>{t('wompiVerifying.declinedStep2')}</p>
                    <p className="text-texto" style={{ fontSize: fluidSizing.text.xs }}>{t('wompiVerifying.declinedStep2Desc')}</p>
                  </div>
                </div>
              </div>
            ) : isWompiVerifying ? (
              <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.md }}>
                <div className="flex items-start" style={{ gap: fluidSizing.space.sm }}>
                  <div 
                    className="flex-shrink-0 rounded-full bg-yellow-100 flex items-center justify-center"
                    style={{ width: fluidSizing.size.buttonSm, height: fluidSizing.size.buttonSm }}
                  >
                    <FiLoader className="text-yellow-600 animate-spin" style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                  </div>
                  <div>
                    <p className="font-medium text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>{t('wompiVerifying.step1')}</p>
                    <p className="text-texto" style={{ fontSize: fluidSizing.text.xs }}>{t('wompiVerifying.step1Desc')}</p>
                  </div>
                </div>
                
                <div className="flex items-start" style={{ gap: fluidSizing.space.sm }}>
                  <div 
                    className="flex-shrink-0 rounded-full bg-acento/20 flex items-center justify-center"
                    style={{ width: fluidSizing.size.buttonSm, height: fluidSizing.size.buttonSm }}
                  >
                    <FiCheckCircle className="text-acento" style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                  </div>
                  <div>
                    <p className="font-medium text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>{t('wompiVerifying.step2')}</p>
                    <p className="text-texto" style={{ fontSize: fluidSizing.text.xs }}>{t('wompiVerifying.step2Desc')}</p>
                  </div>
                </div>
                
                <div className="flex items-start" style={{ gap: fluidSizing.space.sm }}>
                  <div 
                    className="flex-shrink-0 rounded-full bg-primario/20 flex items-center justify-center"
                    style={{ width: fluidSizing.size.buttonSm, height: fluidSizing.size.buttonSm }}
                  >
                    <FiClock className="text-primario" style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                  </div>
                  <div>
                    <p className="font-medium text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>{t('wompiVerifying.step3')}</p>
                    <p className="text-texto" style={{ fontSize: fluidSizing.text.xs }}>{t('wompiVerifying.step3Desc')}</p>
                  </div>
                </div>
              </div>
            ) : (cardPaymentPending && paymentConfirmed) ? (
              <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.md }}>
                <div className="flex items-start" style={{ gap: fluidSizing.space.sm }}>
                  <div 
                    className="flex-shrink-0 rounded-full bg-acento/20 flex items-center justify-center"
                    style={{ width: fluidSizing.size.buttonSm, height: fluidSizing.size.buttonSm }}
                  >
                    <FiCheckCircle className="text-acento" style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                  </div>
                  <div>
                    <p className="font-medium text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>{t('wompiVerifying.confirmedStep1')}</p>
                    <p className="text-texto" style={{ fontSize: fluidSizing.text.xs }}>{t('wompiVerifying.confirmedStep1Desc')}</p>
                  </div>
                </div>
                
                <div className="flex items-start" style={{ gap: fluidSizing.space.sm }}>
                  <div 
                    className="flex-shrink-0 rounded-full bg-primario/20 flex items-center justify-center"
                    style={{ width: fluidSizing.size.buttonSm, height: fluidSizing.size.buttonSm }}
                  >
                    <FiMail className="text-primario" style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                  </div>
                  <div>
                    <p className="font-medium text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>{t('details.emailSent')}</p>
                    <p className="text-texto" style={{ fontSize: fluidSizing.text.xs }}>{t('details.emailSentDesc')}</p>
                  </div>
                </div>
                
                <div className="flex items-start" style={{ gap: fluidSizing.space.sm }}>
                  <div 
                    className="flex-shrink-0 rounded-full bg-yellow-100 flex items-center justify-center"
                    style={{ width: fluidSizing.size.buttonSm, height: fluidSizing.size.buttonSm }}
                  >
                    <FiClock className="text-yellow-600" style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                  </div>
                  <div>
                    <p className="font-medium text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>{t('details.preparing')}</p>
                    <p className="text-texto" style={{ fontSize: fluidSizing.text.xs }}>{t('details.preparingDesc')}</p>
                  </div>
                </div>
              </div>
            ) : showNextSteps ? (
              <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.md }}>
                <div className="flex items-start" style={{ gap: fluidSizing.space.sm }}>
                  <div 
                    className="flex-shrink-0 rounded-full bg-acento/20 flex items-center justify-center"
                    style={{ width: fluidSizing.size.buttonSm, height: fluidSizing.size.buttonSm }}
                  >
                    <FiCheckCircle className="text-acento" style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                  </div>
                  <div>
                    <p className="font-medium text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>{t('details.confirmed')}</p>
                    <p className="text-texto" style={{ fontSize: fluidSizing.text.xs }}>{t('details.confirmedDesc')}</p>
                  </div>
                </div>
                
                <div className="flex items-start" style={{ gap: fluidSizing.space.sm }}>
                  <div 
                    className="flex-shrink-0 rounded-full bg-primario/20 flex items-center justify-center"
                    style={{ width: fluidSizing.size.buttonSm, height: fluidSizing.size.buttonSm }}
                  >
                    <FiMail className="text-primario" style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                  </div>
                  <div>
                    <p className="font-medium text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>{t('details.emailSent')}</p>
                    <p className="text-texto" style={{ fontSize: fluidSizing.text.xs }}>{t('details.emailSentDesc')}</p>
                  </div>
                </div>
                
                <div className="flex items-start" style={{ gap: fluidSizing.space.sm }}>
                  <div 
                    className="flex-shrink-0 rounded-full bg-yellow-100 flex items-center justify-center"
                    style={{ width: fluidSizing.size.buttonSm, height: fluidSizing.size.buttonSm }}
                  >
                    <FiClock className="text-yellow-600" style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                  </div>
                  <div>
                    <p className="font-medium text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>{t('details.preparing')}</p>
                    <p className="text-texto" style={{ fontSize: fluidSizing.text.xs }}>{t('details.preparingDesc')}</p>
                  </div>
                </div>
              </div>
            ) : historyDetails ? (
              <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.md }}>
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between" style={{ gap: fluidSizing.space.xs }}>
                  <span className="text-texto" style={{ fontSize: fluidSizing.text.sm }}>{t('history.placedAt')}</span>
                  <span className="font-medium text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>{historyDetails.placedAt ?? t('history.notAvailable')}</span>
                </div>
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between" style={{ gap: fluidSizing.space.xs }}>
                  <span className="text-texto" style={{ fontSize: fluidSizing.text.sm }}>{t('history.deliveryDate')}</span>
                  <span className="font-medium text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>{historyDetails.deliveryDate ?? t('history.pendingSchedule')}</span>
                </div>
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between" style={{ gap: fluidSizing.space.xs }}>
                  <span className="text-texto" style={{ fontSize: fluidSizing.text.sm }}>{t('history.status')}</span>
                  <span className="font-medium text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>{historyDetails.deliveryStatus ?? t('history.tracking')}</span>
                </div>
                {historyDetails.paymentMethod && (
                  <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between" style={{ gap: fluidSizing.space.xs }}>
                    <span className="text-texto" style={{ fontSize: fluidSizing.text.sm }}>{t('history.paymentMethod')}</span>
                    <span className="font-medium text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>{historyDetails.paymentMethod}</span>
                  </div>
                )}
                {historyDetails.shippingMethod && (
                  <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between" style={{ gap: fluidSizing.space.xs }}>
                    <span className="text-texto" style={{ fontSize: fluidSizing.text.sm }}>{t('history.shippingMethod')}</span>
                    <span className="font-medium text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>{historyDetails.shippingMethod}</span>
                  </div>
                )}
                {historyDetails.shippingAddress && (
                  <div className="flex flex-col" style={{ gap: fluidSizing.space.xs }}>
                    <span className="text-texto" style={{ fontSize: fluidSizing.text.sm }}>{t('history.shippingAddress')}</span>
                    <p className="font-medium text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>
                      {historyDetails.shippingAddress}
                    </p>
                  </div>
                )}
                {historyDetails.totalFormatted && (
                  <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between border-t border-secundario/30" style={{ paddingTop: fluidSizing.space.sm, gap: fluidSizing.space.xs }}>
                    <span className="font-semibold text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>{t('history.total')}</span>
                    <span className="font-semibold text-primario" style={{ fontSize: fluidSizing.text.sm }}>{historyDetails.totalFormatted}</span>
                  </div>
                )}
              </div>
            ) : (
              <p className="text-texto" style={{ fontSize: fluidSizing.text.sm }}>{t('history.noHistory')}</p>
            )}
          </CollapsibleSection>

          {/* Next Steps Card */}
          {isOrderFailed ? (
            <div className="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
              <h3 className="font-semibold text-red-900 mb-2">{t('wompiVerifying.orderFailedNextStepsTitle')}</h3>
              <ul className="text-sm text-red-800 space-y-1">
                <li>• {t('wompiVerifying.orderFailedNextStep1')}</li>
                <li>• {t('wompiVerifying.orderFailedNextStep2')}</li>
                <li>• {t('wompiVerifying.orderFailedNextStep3')}</li>
              </ul>
            </div>
          ) : isWompiDeclined ? (
            <div className="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
              <h3 className="font-semibold text-red-900 mb-2">{t('wompiVerifying.declinedNextStepsTitle')}</h3>
              <ul className="text-sm text-red-800 space-y-1">
                <li>• {t('wompiVerifying.declinedNextStep1')}</li>
                <li>• {t('wompiVerifying.declinedNextStep2')}</li>
                <li>• {t('wompiVerifying.declinedNextStep3')}</li>
              </ul>
            </div>
          ) : isWompiVerifying ? (
            <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
              <h3 className="font-semibold text-yellow-900 mb-2">{t('wompiVerifying.nextStepsTitle')}</h3>
              <ul className="text-sm text-yellow-800 space-y-1">
                <li>• {t('wompiVerifying.nextStep1')}</li>
                <li>• {t('wompiVerifying.nextStep2')}</li>
                <li>• {t('wompiVerifying.nextStep3')}</li>
              </ul>
            </div>
          ) : showNextSteps && (
            <div className="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
              <h3 className="font-semibold text-blue-900 mb-2">{t('nextSteps.title')}</h3>
              <ul className="text-sm text-blue-800 space-y-1">
                <li>• {t('nextSteps.step1')}</li>
                <li>• {t('nextSteps.step2')}</li>
                <li>• {t('nextSteps.step3')}</li>
              </ul>
            </div>
          )}

          {/* Action Buttons */}
          {showNextSteps ? (
            <div className="flex flex-col gap-3 justify-center">
              <button
                onClick={handleViewOrders}
                className="bg-primario text-white py-3 px-6 rounded-md hover:bg-hover transition-colors font-medium flex items-center justify-center"
              >
                <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                </svg>
                {t('buttons.viewOrders')}
              </button>
              <button
                onClick={handleWhatsAppHelp}
                className="bg-green-600 text-white py-3 px-6 rounded-md hover:bg-green-700 transition-colors font-medium flex items-center justify-center"
              >
                <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 mr-2" fill="currentColor" viewBox="0 0 24 24">
                  <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.821 11.821 0 0020.485 3.516z"/>
                </svg>
                {t('buttons.whatsappHelp')}
              </button>
              <button
                onClick={handleGoHome}
                className="bg-white text-primario border border-primario py-3 px-6 rounded-md hover:bg-gray-50 transition-colors font-medium flex items-center justify-center"
              >
                <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                </svg>
                {t('buttons.goHome')}
              </button>
            </div>
          ) : (
            <div className="flex flex-col gap-3 justify-center">
              {onBackToOrders && (
                <button
                  onClick={onBackToOrders}
                  className="text-primario border border-primario py-3 px-6 rounded-md hover:bg-gray-50 transition-colors font-medium flex items-center justify-center"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                  </svg>
                  {t('buttons.backToOrders')}
                </button>
              )}
              <button
                onClick={handleWhatsAppHelp}
                className="text-white border border-transparent py-3 px-6 rounded-md shadow-sm font-medium flex items-center justify-center bg-green-600 hover:bg-green-700"
              >
                <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 mr-2" fill="currentColor" viewBox="0 0 24 24">
                  <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.821 11.821 0 0020.485 3.516z"/>
                </svg>
                {t('buttons.whatsappHelp')}
              </button>
              {onReorder && (
                <button
                  onClick={onReorder}
                  className="text-white border border-transparent py-3 px-6 rounded-md shadow-sm font-medium flex items-center justify-center bg-primario hover:bg-hover"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2 9m5-9v9m4-9v9m4-9l2 9" />
                  </svg>
                  {t('buttons.repeatOrder')}
                </button>
              )}
            </div>
          )}

          {/* Footer Message */}
          <div className="text-center mt-8 p-4 bg-gray-50 rounded-lg">
            <p className="text-sm text-gray-600">
              <span className="font-semibold">{t('footer.thanks')}</span><br/>
              {t('footer.message')}
            </p>
          </div>
        </div>
      </div>

      {/* Modal de perfil */}
      {enableProfileModal && (
        <ProfileModal
          isOpen={isProfileModalOpen}
          onClose={handleCloseProfileModal}
          activeSection="orders"
        />
      )}
    </>
  );
};

export default CheckoutSuccess;
