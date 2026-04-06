/**
 * MembershipPurchaseModal - Modal para comprar/mejorar membresía
 * Mini-checkout con integración de Wompi para membresías
 */

import { FC, useState, useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { FiCheck, FiAlertCircle, FiCreditCard, FiAward, FiCalendar, FiAlertTriangle, FiXCircle } from 'react-icons/fi';
import { useMembership } from '../../contexts/MembershipContext';
import AnimatedModal from '../ui/AnimatedModal';
import VirtualCoinPrice from '../common/VirtualCoinPrice';
import Loader from '../ui/Loader';
import { useWompi } from '../../hooks/useWompi';
import { useAuth } from '../../contexts/AuthContext';
import { fluidSizing } from '../../utils/fluidSizing';
import { formatCurrency, ceilTo50COP } from '../../utils/formatters';
import alertService from '../../services/alertService';
import wompiService from '../../services/wompiService';
import logger from '../../utils/logger';
import { cacheManager } from '../../services/query/cacheManager';
import { sanitizeInlineHtml } from '../../utils/sanitizeHtml';
import { clearMembershipLevelsCache } from '../../hooks/useMembershipLevels';
import type { MembershipLevel } from '../../services/membership/membershipTypes';

interface MembershipPurchaseModalProps {
  isOpen: boolean;
  onClose: () => void;
  /** Nivel de membresía seleccionado */
  membership: MembershipLevel | null;
  /** Callback cuando la compra es exitosa */
  onPurchaseSuccess?: (membership: MembershipLevel) => void;
}

type PurchaseStep = 'confirm' | 'processing' | 'success' | 'delayed' | 'pending' | 'declined' | 'error';

/**
 * Obtiene la etiqueta de periodicidad
 */
// getRenewalPeriodLabel is now handled via t() inside the component

const MembershipPurchaseModal: FC<MembershipPurchaseModalProps> = ({
  isOpen,
  onClose,
  membership,
  onPurchaseSuccess,
}) => {
  const { t } = useTranslation('membershipComponents');
  // Contextos necesarios
  useAuth();
  const { currentLevel, membershipName: currentMembershipName, isActive: hasActiveMembership, refreshMembership } = useMembership();

  const getRenewalPeriodLabel = (renewalPeriod?: string): string => {
    switch (renewalPeriod) {
      case 'monthly': return t('purchaseModal.periods.monthly');
      case 'bimonthly': return t('purchaseModal.periods.bimonthly');
      case 'quarterly': return t('purchaseModal.periods.quarterly');
      case 'biannual': return t('purchaseModal.periods.biannual');
      case 'annual': return t('purchaseModal.periods.annual');
      default: return t('purchaseModal.periods.default');
    }
  };
  
  // Validación de seguridad: verificar si es un upgrade válido
  // IMPORTANTE: Usar level ?? id porque la API puede enviar el nivel en cualquiera de los dos campos
  const targetLevel = membership?.level !== undefined ? Number(membership.level) : Number(membership?.id ?? 0);
  const isValidUpgrade = targetLevel > currentLevel;
  const { 
    isConfigured, 
    isWidgetLoading, 
    openWidget, 
    generateReference,
  } = useWompi();

  const [step, setStep] = useState<PurchaseStep>('confirm');
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [paymentReference, setPaymentReference] = useState<string | null>(null);
  const [paymentConfirmed, setPaymentConfirmed] = useState(false);
  const pollingRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const pollingCountRef = useRef(0);
  const processingTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const isProcessingPurchaseRef = useRef(false);

  // Timeout de recuperación: si Wompi no responde en 5 min durante 'processing', mostrar error
  useEffect(() => {
    if (step === 'processing') {
      processingTimeoutRef.current = setTimeout(() => {
        setStep('error');
        setErrorMessage(t('purchaseModal.timeoutMessage'));
      }, 300000); // 5 minutos: tiempo máximo para PSE/Nequi antes de considerar el widget colgado
    }
    return () => {
      if (processingTimeoutRef.current) {
        clearTimeout(processingTimeoutRef.current);
        processingTimeoutRef.current = null;
      }
    };
  }, [step, t]);

  // Reset al abrir/cerrar
  useEffect(() => {
    if (isOpen) {
      setStep('confirm');
      setErrorMessage(null);
      setPaymentReference(null);
      setPaymentConfirmed(false);
    }
    return () => {
      if (pollingRef.current) clearInterval(pollingRef.current);
    };
  }, [isOpen]);

  // Polling para verificar pago pendiente de membresía
  useEffect(() => {
    if (step !== 'pending' || !paymentReference || paymentConfirmed) return;

    pollingCountRef.current = 0;

    const poll = async () => {
      pollingCountRef.current += 1;
      if (pollingCountRef.current > 20) {
        if (pollingRef.current) clearInterval(pollingRef.current);
        setStep('delayed');
        return;
      }
      try {
        const result = await wompiService.getMembershipPurchaseStatus(paymentReference);
        if (result.success && result.data && result.data.status === 'completed') {
          setPaymentConfirmed(true);
          if (pollingRef.current) clearInterval(pollingRef.current);

          // Invalidar caché y refrescar membresía
          cacheManager.clearAll();
          clearMembershipLevelsCache();
          try { await refreshMembership(); } catch (_e) { /* continuar */ }

          setStep('success');
          alertService.success(t('purchaseModal.purchaseSuccess', { name: membership?.name }));
          if (onPurchaseSuccess && membership) onPurchaseSuccess(membership);
        } else if (result.success && result.data && (result.data.status === 'declined' || result.data.status === 'voided')) {
          if (pollingRef.current) clearInterval(pollingRef.current);
          setStep('declined');
          alertService.error(t('purchaseModal.declinedAlert'));
        }
      } catch (_err) {
        logger.warn('MembershipPurchaseModal', 'Error en polling:', _err);
      }
    };

    poll();
    pollingRef.current = setInterval(poll, 15000);

    return () => {
      if (pollingRef.current) clearInterval(pollingRef.current);
    };
  }, [step, paymentReference, paymentConfirmed, membership, onPurchaseSuccess, refreshMembership, t]);

  const handlePurchase = async () => {
    // Guard imperativo: previene doble-click antes de que React flushee el setState
    if (isProcessingPurchaseRef.current) return;
    isProcessingPurchaseRef.current = true;

    if (!membership || !isConfigured) {
      isProcessingPurchaseRef.current = false;
      setErrorMessage(t('purchaseModal.cannotProcess'));
      return;
    }
    
    // VALIDACIÓN DE SEGURIDAD: Solo permitir upgrades (nivel superior al actual)
    if (!isValidUpgrade) {
      isProcessingPurchaseRef.current = false;
      setErrorMessage(t('purchaseModal.cannotDowngrade', { current: currentMembershipName }));
      setStep('error');
      return;
    }

    const price = membership.product_info?.product_sale_price ?? 
                  membership.product_info?.product_price ?? 
                  membership.price_min ?? 0;

    if (price <= 0) {
      isProcessingPurchaseRef.current = false;
      setErrorMessage(t('purchaseModal.invalidPrice'));
      return;
    }

    setStep('processing');
    setErrorMessage(null);

    try {
      const reference = generateReference('MB');
      const amountInCents = Math.round(ceilTo50COP(price) * 100);

      logger.info('MembershipPurchaseModal', 'Iniciando compra de membresía', {
        reference,
        membershipLevel: membership.level,
        membershipName: membership.name,
        price,
      });

      // Registrar compra pendiente en el backend ANTES de abrir Wompi
      const productId = membership.product_info?.product_id;
      if (productId) {
        const pendingResult = await wompiService.registerPendingMembershipPurchase(productId, reference);
        if (!pendingResult.success) {
          throw new Error(pendingResult.message || 'Error al registrar la compra pendiente');
        }
        logger.info('MembershipPurchaseModal', 'Compra pendiente registrada', pendingResult.data);
      } else {
        throw new Error(t('purchaseModal.noProductId'));
      }

      // Abrir widget de Wompi (sin customerData, Wompi lo pedirá)
      const transaction = await openWidget({
        amountInCents,
        reference,
      });

      if (transaction) {
        if (transaction.status === 'APPROVED') {
          // IMPORTANTE: Confirmar la compra en el backend para activar la membresía
          // No dependemos solo del webhook asíncrono de Wompi
          logger.info('MembershipPurchaseModal', 'Pago aprobado, confirmando membresía en backend...', {
            reference,
            transactionId: transaction.id,
          });
          
          const confirmResult = await wompiService.confirmMembershipPurchase(reference, transaction.id);
          
          if (confirmResult.success) {
            // CRÍTICO: Invalidar TODO el caché antes de refrescar
            // Esto garantiza que productos, categorías, beneficios, etc. se recarguen con el nuevo nivel
            logger.info('MembershipPurchaseModal', 'Invalidando caché completo...');
            cacheManager.clearAll(); // También notifica a otras pestañas via BroadcastChannel
            clearMembershipLevelsCache();
            
            // Refrescar el contexto de membresía ANTES de mostrar éxito
            // Esto garantiza que la UI refleje el nuevo nivel inmediatamente
            try {
              logger.info('MembershipPurchaseModal', 'Refrescando contexto de membresía...');
              await refreshMembership();
              logger.info('MembershipPurchaseModal', 'Contexto de membresía actualizado');
            } catch (refreshError) {
              logger.warn('MembershipPurchaseModal', 'Error al refrescar membresía (continuando):', refreshError);
            }
            
            setStep('success');
            alertService.success(t('purchaseModal.purchaseSuccess', { name: membership.name }));
            
            if (onPurchaseSuccess) {
              onPurchaseSuccess(membership);
            }
          } else {
            // El pago fue exitoso pero hubo error al activar la membresía
            // Esto no debería pasar, pero si pasa, el webhook lo procesará después
            logger.error('MembershipPurchaseModal', 'Error al confirmar membresía:', confirmResult.message);
            setStep('delayed');
            
            if (onPurchaseSuccess) {
              onPurchaseSuccess(membership);
            }
          }
        } else if (transaction.status === 'DECLINED') {
          setErrorMessage(t('purchaseModal.paymentDeclined'));
          setStep('error');
        } else if (transaction.status === 'VOIDED') {
          setErrorMessage(t('purchaseModal.paymentVoided', 'El pago fue anulado. Intenta de nuevo.'));
          setStep('error');
        } else if (transaction.status === 'ERROR') {
          setErrorMessage(t('purchaseModal.paymentTechnicalError', 'Hubo un error técnico con el pago. Intenta de nuevo.'));
          setStep('error');
        } else if (transaction.status === 'PENDING') {
          // Pago pendiente - mostrar estado específico con polling
          setPaymentReference(reference);
          setStep('pending');
        } else {
          logger.warn('MembershipPurchaseModal', 'Estado de transacción desconocido:', transaction.status);
          setErrorMessage(t('purchaseModal.paymentError'));
          setStep('error');
        }
      } else {
        // Widget resuelto sin transacción visible en el frontend.
        // Puede ser cierre manual del usuario O que el grace period de DOM
        // disparó durante 3DS (el iframe del banco no matchea el selector de Wompi).
        // Verificar server-side si el pago fue aprobado antes de resetear el estado.
        let serverConfirmed = false;
        try {
          await new Promise(resolve => setTimeout(resolve, 2000));
          const serverStatus = await wompiService.getMembershipPurchaseStatus(reference);
          if (serverStatus.success && serverStatus.data) {
            const status = serverStatus.data.status;
            if (status === 'completed') {
              serverConfirmed = true;
              logger.info('MembershipPurchaseModal', 'Pago confirmado server-side (3DS completado):', { reference, status });
            } else if (status === 'processing') {
              await new Promise(resolve => setTimeout(resolve, 3000));
              const recheck = await wompiService.getMembershipPurchaseStatus(reference);
              if (recheck.success && recheck.data?.status === 'completed') {
                serverConfirmed = true;
                logger.info('MembershipPurchaseModal', 'Pago confirmado server-side (tras re-verificación):', { reference });
              }
            }
          }
        } catch (statusError) {
          logger.warn('MembershipPurchaseModal', 'Error al verificar estado server-side:', statusError);
        }

        if (serverConfirmed) {
          // Invalidar caché y refrescar membresía
          cacheManager.clearAll();
          clearMembershipLevelsCache();
          try { await refreshMembership(); } catch (_e) { /* continuar */ }

          setStep('success');
          alertService.success(t('purchaseModal.purchaseSuccess', { name: membership.name }));
          if (onPurchaseSuccess) {
            onPurchaseSuccess(membership);
          }
        } else {
          // El usuario realmente cerró el widget sin completar el pago
          setStep('confirm');
        }
      }
    } catch (error: any) {
      logger.error('MembershipPurchaseModal', 'Error en compra:', error);
      setErrorMessage(error.message || 'Error al procesar el pago');
      setStep('error');
    } finally {
      isProcessingPurchaseRef.current = false;
    }
  };

  const handleClose = () => {
    setStep('confirm');
    setErrorMessage(null);
    onClose();
  };

  const handleBack = () => {
    setStep('confirm');
    setErrorMessage(null);
  };

  // Datos de la membresía
  const price = membership?.product_info?.product_sale_price ?? 
                membership?.product_info?.product_price ?? 
                membership?.price_min ?? 0;
  const regularPrice = membership?.product_info?.product_regular_price;
  const hasDiscount = regularPrice && regularPrice > price;
  const renewalPeriod = membership?.product_info?.renewal_period;
  const monthlyPoints = membership?.product_info?.monthly_points ?? membership?.monthly_points ?? 0;

  const renderConfirmation = () => (
    <div className="flex flex-col" style={{ gap: fluidSizing.space.lg }}>
      {/* Header con icono de membresía */}
      <div className="flex flex-col items-center text-center">
        <div 
          className="rounded-full flex items-center justify-center overflow-hidden border-2"
          style={{ 
            backgroundColor: membership?.color ? `${membership.color}15` : 'white',
            borderColor: membership?.color || '#ccc',
            width: fluidSizing.size.floatingButton, 
            height: fluidSizing.size.floatingButton 
          }}
        >
          {membership?.icon_url ? (
            <img 
              src={membership.icon_url} 
              alt={membership.name}
              className="w-full h-full object-cover"
            />
          ) : (
            <FiAward className="text-primario" style={{ width: '60%', height: '60%' }} />
          )}
        </div>
        <h3 
          className="text-oscuro font-semibold"
          style={{ fontSize: fluidSizing.text.lg, marginTop: fluidSizing.space.sm }}
        >
          {t('purchaseModal.confirmTitle')}
        </h3>
        <p className="text-texto/70" style={{ fontSize: fluidSizing.text.sm }}>
          {membership?.name}
        </p>
      </div>

      {/* Aviso de reemplazo de membresía (solo si tiene membresía activa y es upgrade) */}
      {hasActiveMembership && isValidUpgrade && (
        <div 
          className="bg-amber-50 border border-amber-200 rounded-xl flex items-start"
          style={{ padding: fluidSizing.space.md, gap: fluidSizing.space.sm }}
        >
          <FiAlertTriangle className="text-amber-600 flex-shrink-0 mt-0.5" style={{ width: 18, height: 18 }} />
          <div>
            <p className="text-amber-800 font-medium" style={{ fontSize: fluidSizing.text.sm }}>
              {t('purchaseModal.replaceWarningTitle')}
            </p>
            <p className="text-amber-700" style={{ fontSize: fluidSizing.text.xs }} dangerouslySetInnerHTML={{ __html: sanitizeInlineHtml(t('purchaseModal.replaceWarningDesc', { newName: membership?.name, currentName: currentMembershipName })) }} />
          </div>
        </div>
      )}

      {/* Card de resumen */}
      <div 
        className="bg-gradient-to-br from-primario/5 to-primario/10 rounded-2xl border border-primario/20"
        style={{ padding: fluidSizing.space.lg }}
      >
        {/* Membresía */}
        <div className="flex flex-col" style={{ gap: fluidSizing.space.xs }}>
          <div className="flex items-center" style={{ gap: fluidSizing.space.sm }}>
            <FiAward className="text-primario flex-shrink-0" style={{ width: 18, height: 18 }} />
            <span className="text-texto/70" style={{ fontSize: fluidSizing.text.sm }}>{t('purchaseModal.membershipLabel')}</span>
          </div>
          <span className="font-semibold text-oscuro" style={{ fontSize: fluidSizing.text.base, paddingLeft: `calc(18px + ${fluidSizing.space.sm})` }}>{membership?.name}</span>
        </div>

        {/* Virtual Coins incluidos */}
        {monthlyPoints > 0 && (
          <div 
            className="flex items-center justify-between text-primario flex-wrap"
            style={{ marginTop: fluidSizing.space.sm, gap: fluidSizing.space.xs }}
          >
            <span style={{ fontSize: fluidSizing.text.xs }}>{t('purchaseModal.coinsIncluded')}</span>
            <div className="flex items-center" style={{ gap: fluidSizing.space.xs }}>
              <VirtualCoinPrice amount={monthlyPoints} size="sm" showLabel={false} />
              <span style={{ fontSize: fluidSizing.text.xs }}>/ {getRenewalPeriodLabel(renewalPeriod)}</span>
            </div>
          </div>
        )}

        {/* Periodicidad */}
        {renewalPeriod && renewalPeriod !== 'none' && (
          <div 
            className="flex items-center justify-between text-texto/70"
            style={{ marginTop: fluidSizing.space.sm }}
          >
            <div className="flex items-center" style={{ gap: fluidSizing.space.xs }}>
              <FiCalendar style={{ width: 14, height: 14 }} />
              <span style={{ fontSize: fluidSizing.text.sm }}>{t('purchaseModal.renewal')}</span>
            </div>
            <span style={{ fontSize: fluidSizing.text.sm }}>
              {getRenewalPeriodLabel(renewalPeriod).charAt(0).toUpperCase() + getRenewalPeriodLabel(renewalPeriod).slice(1)}
            </span>
          </div>
        )}

        {/* Separador */}
        <div 
          className="border-t border-primario/20"
          style={{ marginTop: fluidSizing.space.md, marginBottom: fluidSizing.space.md }}
        />

        {/* Total */}
        <div className="flex items-center justify-between flex-wrap" style={{ gap: fluidSizing.space.xs }}>
          <span className="text-oscuro font-semibold" style={{ fontSize: fluidSizing.text.sm }}>{t('purchaseModal.totalToPay')}</span>
          <div className="text-right">
            {hasDiscount && (
              <div 
                className="text-texto/50 line-through"
                style={{ fontSize: fluidSizing.text.xs }}
              >
                {formatCurrency(regularPrice)}
              </div>
            )}
            <div 
              className="font-bold text-primario"
              style={{ fontSize: fluidSizing.text.xl }}
            >
              {formatCurrency(price)}
            </div>
          </div>
        </div>
      </div>

      {/* Método de pago */}
      <div 
        className="flex items-center bg-gray-50 rounded-xl"
        style={{ padding: fluidSizing.space.md, gap: fluidSizing.space.sm }}
      >
        <div 
          className="bg-white rounded-full flex items-center justify-center shadow-sm"
          style={{ width: 40, height: 40 }}
        >
          <FiCreditCard className="text-primario" style={{ width: 20, height: 20 }} />
        </div>
        <div className="flex-1">
          <div className="font-medium text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>
            {t('purchaseModal.securePayment')}
          </div>
          <div className="text-texto/60" style={{ fontSize: fluidSizing.text['2xs'] }}>
            {t('purchaseModal.paymentMethods')}
          </div>
        </div>
        <FiCheck className="text-acento" style={{ width: 18, height: 18 }} />
      </div>

      {/* Botones */}
      <div className="flex flex-col" style={{ gap: fluidSizing.space.sm }}>
        <button
          onClick={handlePurchase}
          disabled={isWidgetLoading || !isConfigured}
          className="w-full py-3 px-4 bg-primario text-white rounded-xl hover:bg-hover transition-colors disabled:opacity-50 disabled:cursor-not-allowed font-semibold flex items-center justify-center"
          style={{ fontSize: fluidSizing.text.base, gap: fluidSizing.space.sm }}
        >
          {isWidgetLoading ? t('purchaseModal.processing') : t('purchaseModal.payNow')}
        </button>
        
        <button
          onClick={handleClose}
          className="w-full py-2.5 px-4 text-texto/70 hover:text-texto rounded-xl hover:bg-gray-100 transition-colors flex items-center justify-center"
          style={{ fontSize: fluidSizing.text.sm }}
        >
          {t('purchaseModal.cancel')}
        </button>
      </div>
    </div>
  );

  const renderProcessing = () => (
    <div className="flex flex-col items-center justify-center py-12">
      <Loader text={t('purchaseModal.processingPayment')} size="large" />
      <p className="text-texto/70 mt-4 text-center" style={{ fontSize: fluidSizing.text.sm }}>
        {t('purchaseModal.doNotClose')}
      </p>
    </div>
  );

  const renderSuccess = () => (
    <div className="flex flex-col items-center justify-center py-8 text-center">
      <div className="w-16 h-16 rounded-full bg-acento/20 flex items-center justify-center mb-4">
        <FiCheck className="text-acento" style={{ width: 32, height: 32 }} />
      </div>
      
      <h3 className="font-bold text-oscuro mb-2" style={{ fontSize: fluidSizing.text.xl }}>
        {t('purchaseModal.successTitle')}
      </h3>
      
      <p className="text-texto mb-4" style={{ fontSize: fluidSizing.text.sm }}>
        {t('purchaseModal.nowMember')}
      </p>
      
      <div 
        className="flex items-center justify-center bg-primario/10 rounded-lg mb-6"
        style={{ padding: fluidSizing.space.md, gap: fluidSizing.space.sm }}
      >
        {membership?.icon_url && (
          <img 
            src={membership.icon_url} 
            alt={membership?.name}
            className="w-8 h-8 rounded-full"
          />
        )}
        <span className="font-bold text-primario" style={{ fontSize: fluidSizing.text.lg }}>
          {membership?.name}
        </span>
      </div>
      
      <p className="text-texto/70 mb-6" style={{ fontSize: fluidSizing.text.xs }}>
        {t('purchaseModal.membershipActive')}
      </p>
      
      <button
        onClick={handleClose}
        className="w-full py-3 px-4 bg-primario text-white rounded-lg hover:bg-hover transition-colors font-semibold"
        style={{ fontSize: fluidSizing.text.sm }}
      >
        {t('purchaseModal.continue')}
      </button>
    </div>
  );

  const renderError = () => (
    <div className="flex flex-col items-center justify-center py-8 text-center">
      <div className="w-16 h-16 rounded-full bg-red-100 flex items-center justify-center mb-4">
        <FiAlertCircle className="text-red-500" style={{ width: 32, height: 32 }} />
      </div>
      
      <h3 className="font-bold text-oscuro mb-2" style={{ fontSize: fluidSizing.text.xl }}>
        {t('purchaseModal.errorTitle')}
      </h3>
      
      <p className="text-texto/70 mb-6" style={{ fontSize: fluidSizing.text.sm }}>
        {errorMessage || t('purchaseModal.errorDefault')}
      </p>
      
      <div className="flex gap-3 w-full">
        <button
          onClick={handleClose}
          className="flex-1 py-3 px-4 border-2 border-secundario/50 text-texto rounded-lg hover:bg-secundario/10 transition-colors"
          style={{ fontSize: fluidSizing.text.sm }}
        >
          {t('purchaseModal.cancel')}
        </button>
        <button
          onClick={handleBack}
          className="flex-1 py-3 px-4 bg-primario text-white rounded-lg hover:bg-hover transition-colors font-semibold"
          style={{ fontSize: fluidSizing.text.sm }}
        >
          {t('purchaseModal.retry')}
        </button>
      </div>
    </div>
  );

  const renderDelayed = () => (
    <div className="flex flex-col items-center justify-center py-8 text-center">
      <div className="w-16 h-16 rounded-full bg-yellow-100 flex items-center justify-center mb-4">
        <FiAlertTriangle className="text-yellow-600" style={{ width: 32, height: 32 }} />
      </div>
      
      <h3 className="font-bold text-oscuro mb-2" style={{ fontSize: fluidSizing.text.xl }}>
        {t('purchaseModal.delayedTitle')}
      </h3>
      
      <p className="text-texto mb-4" style={{ fontSize: fluidSizing.text.sm }}>
        {t('purchaseModal.delayedDesc')}
      </p>
      
      <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6 w-full">
        <p className="text-yellow-800" style={{ fontSize: fluidSizing.text.xs }} dangerouslySetInnerHTML={{ __html: sanitizeInlineHtml(t('purchaseModal.delayedNotice')) }} />
      </div>
      
      <button
        onClick={handleClose}
        className="w-full py-3 px-4 bg-primario text-white rounded-lg hover:bg-hover transition-colors font-semibold"
        style={{ fontSize: fluidSizing.text.sm }}
      >
        {t('purchaseModal.understood')}
      </button>
    </div>
  );

  const renderDeclined = () => (
    <div className="flex flex-col items-center justify-center py-8 text-center">
      <div className="w-16 h-16 rounded-full bg-red-100 flex items-center justify-center mb-4">
        <FiXCircle className="text-red-600" style={{ width: 32, height: 32 }} />
      </div>
      
      <h3 className="font-bold text-oscuro mb-2" style={{ fontSize: fluidSizing.text.xl }}>
        {t('purchaseModal.declinedTitle')}
      </h3>
      
      <p className="text-texto mb-4" style={{ fontSize: fluidSizing.text.sm }}>
        {t('purchaseModal.declinedDesc')}
      </p>
      
      <div className="bg-red-50 border border-red-200 rounded-lg p-4 mb-6 w-full">
        <p className="text-red-800" style={{ fontSize: fluidSizing.text.xs }}>
          {t('purchaseModal.declinedNotice')}
        </p>
      </div>
      
      <button
        onClick={handleClose}
        className="w-full py-3 px-4 bg-primario text-white rounded-lg hover:bg-hover transition-colors font-semibold"
        style={{ fontSize: fluidSizing.text.sm }}
      >
        {t('purchaseModal.understood')}
      </button>
    </div>
  );

  const renderPending = () => (
    <div className="flex flex-col items-center justify-center py-8 text-center">
      <div className="w-16 h-16 rounded-full bg-yellow-100 flex items-center justify-center mb-4">
        <FiAlertCircle className="text-yellow-600" style={{ width: 32, height: 32 }} />
      </div>
      
      <h3 className="font-bold text-oscuro mb-2" style={{ fontSize: fluidSizing.text.xl }}>
        {t('purchaseModal.pendingTitle')}
      </h3>
      
      <p className="text-texto mb-4" style={{ fontSize: fluidSizing.text.sm }}>
        {t('purchaseModal.pendingDesc')}
      </p>
      
      <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6 w-full">
        <p className="text-yellow-800" style={{ fontSize: fluidSizing.text.xs }} dangerouslySetInnerHTML={{ __html: sanitizeInlineHtml(t('purchaseModal.pendingNotice')) }} />
      </div>
      
      <div 
        className="flex items-center justify-center bg-primario/10 rounded-lg mb-6"
        style={{ padding: fluidSizing.space.md, gap: fluidSizing.space.sm }}
      >
        {membership?.icon_url && (
          <img 
            src={membership.icon_url} 
            alt={membership?.name}
            className="w-8 h-8 rounded-full"
          />
        )}
        <span className="font-bold text-primario" style={{ fontSize: fluidSizing.text.lg }}>
          {membership?.name}
        </span>
      </div>
      
      <button
        onClick={handleClose}
        className="w-full py-3 px-4 bg-primario text-white rounded-lg hover:bg-hover transition-colors font-semibold"
        style={{ fontSize: fluidSizing.text.sm }}
      >
        {t('purchaseModal.understood')}
      </button>
    </div>
  );

  const renderContent = () => {
    switch (step) {
      case 'confirm':
        return renderConfirmation();
      case 'processing':
        return renderProcessing();
      case 'success':
        return renderSuccess();
      case 'delayed':
        return renderDelayed();
      case 'pending':
        return renderPending();
      case 'declined':
        return renderDeclined();
      case 'error':
        return renderError();
      default:
        return renderConfirmation();
    }
  };

  const getTitle = () => {
    switch (step) {
      case 'confirm':
        return t('purchaseModal.titleConfirm');
      case 'processing':
        return t('purchaseModal.titleProcessing');
      case 'success':
        return t('purchaseModal.titleSuccess');
      case 'delayed':
        return t('purchaseModal.titleDelayed');
      case 'pending':
        return t('purchaseModal.titlePending');
      case 'declined':
        return t('purchaseModal.titleDeclined');
      case 'error':
        return t('purchaseModal.titleError');
      default:
        return t('purchaseModal.titleConfirm');
    }
  };

  if (!membership) return null;

  return (
    <AnimatedModal
      isOpen={isOpen}
      onClose={step === 'processing' ? () => {} : handleClose}
      title={
        <span className="flex items-center gap-2">
          <FiAward className="text-primario" />
          {getTitle()}
        </span>
      }
      maxWidth="max-w-md"
      hideCloseButton={step === 'processing'}
    >
      <div className="p-1 pb-4">
        {renderContent()}
      </div>
    </AnimatedModal>
  );
};

export default MembershipPurchaseModal;
