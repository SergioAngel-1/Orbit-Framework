/**
 * BuyCoinsModal - Modal para comprar Virtual Coins
 * Mini-checkout con integración de Wompi
 * Los paquetes se cargan dinámicamente desde productos WooCommerce tipo "virtual_coins"
 */

import { FC, useState, useMemo, useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { useLanguage } from '../../contexts/LanguageContext';
import { FiCheck, FiAlertCircle, FiAlertTriangle, FiCreditCard, FiArrowUp, FiXCircle } from 'react-icons/fi';
import AnimatedModal from '../ui/AnimatedModal';
import VirtualCoinPrice from '../common/VirtualCoinPrice';
import MembershipBadge from '../common/MembershipBadge';
import PackageCard from './PackageCard';
import PurchaseConfirmation from './PurchaseConfirmation';
import Loader from '../ui/Loader';
import { useWompi } from '../../hooks/useWompi';
import { useAuth } from '../../contexts/AuthContext';
import { useMembership } from '../../contexts/MembershipContext';
import { sanitizeInlineHtml } from '../../utils/sanitizeHtml';
import useMembershipLevels from '../../hooks/useMembershipLevels';
// useWallet removido - se usa onPurchaseSuccess callback para refrescar
import { fluidSizing } from '../../utils/fluidSizing';
import { ceilTo50COP } from '../../utils/formatters';
import alertService from '../../services/alertService';
import logger from '../../utils/logger';
import wompiService from '../../services/wompiService';
import type { VirtualCoinsPackage } from '../../types/wompi';

interface BuyCoinsModalProps {
  isOpen: boolean;
  onClose: () => void;
  onPurchaseSuccess?: (amount: number) => void;
}

type PurchaseStep = 'loading' | 'select' | 'confirm' | 'processing' | 'success' | 'delayed' | 'pending' | 'declined' | 'error';

const BuyCoinsModal: FC<BuyCoinsModalProps> = ({
  isOpen,
  onClose,
  onPurchaseSuccess,
}) => {
  const { t } = useTranslation('walletComponents');
  const { user } = useAuth();
  const { membership } = useMembership();
  const { getLevelName } = useMembershipLevels();
  const navigate = useNavigate();
  const { localizedPath } = useLanguage();
  const { 
    isConfigured, 
    isWidgetLoading, 
    openWidget, 
    generateReference,
    error: wompiError 
  } = useWompi();
  
  // Nivel de membresía del usuario actual
  const userMembershipLevel = membership?.level ?? 0;

  const [packages, setPackages] = useState<VirtualCoinsPackage[]>([]);
  const [selectedPackage, setSelectedPackage] = useState<VirtualCoinsPackage | null>(null);
  const [step, setStep] = useState<PurchaseStep>('loading');
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [purchasedCoins, setPurchasedCoins] = useState<number>(0);
  const [paymentReference, setPaymentReference] = useState<string | null>(null);
  const [paymentConfirmed, setPaymentConfirmed] = useState(false);
  const pollingRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const pollingCountRef = useRef(0);
  const isProcessingPurchaseRef = useRef(false);

  // Cargar paquetes cuando se abre el modal
  useEffect(() => {
    if (isOpen && packages.length === 0) {
      loadPackages();
    }
    if (isOpen) {
      setPaymentReference(null);
      setPaymentConfirmed(false);
    }
    return () => {
      if (pollingRef.current) clearInterval(pollingRef.current);
    };
  }, [isOpen, packages.length]);

  // Polling para verificar pago pendiente de FC
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
        const result = await wompiService.getPurchaseStatus(paymentReference);
        if (result.success && result.data && result.data.status === 'completed') {
          setPaymentConfirmed(true);
          if (pollingRef.current) clearInterval(pollingRef.current);

          setStep('success');
          alertService.success(t('buyCoins.purchaseSuccess', { amount: purchasedCoins.toLocaleString() }));
          if (onPurchaseSuccess) onPurchaseSuccess(purchasedCoins);
        } else if (result.success && result.data && (result.data.status === 'declined' || result.data.status === 'voided')) {
          if (pollingRef.current) clearInterval(pollingRef.current);
          setStep('declined');
          alertService.error(t('buyCoins.declinedAlert'));
        }
      } catch (_err) {
        logger.warn('BuyCoinsModal', 'Error en polling:', _err);
      }
    };

    poll();
    pollingRef.current = setInterval(poll, 15000);

    return () => {
      if (pollingRef.current) clearInterval(pollingRef.current);
    };
  }, [step, paymentReference, paymentConfirmed, purchasedCoins, onPurchaseSuccess, t]);

  const loadPackages = async () => {
    setStep('loading');

    try {
      // showAll=true para obtener todos los paquetes sin filtrar por membresía
      const response = await wompiService.getVirtualCoinsPackages(true);
      
      if (response.success && response.data) {
        setPackages(response.data);
        setStep('select');
      } else {
        setErrorMessage(response.message || t('buyCoins.couldNotLoadPackages'));
        setStep('error');
      }
    } catch (error: any) {
      logger.error('BuyCoinsModal', 'Error al cargar paquetes:', error);
      setErrorMessage(t('buyCoins.errorLoadingPackages'));
      setStep('error');
    }
  };

  const customerData = useMemo(() => {
    if (!user) return undefined;
    return {
      email: user.email || '',
      fullName: `${user.firstName || ''} ${user.lastName || ''}`.trim() || user.name || '',
      phoneNumber: user.phone || '',
      phoneNumberPrefix: '+57',
    };
  }, [user]);

  const handleSelectPackage = (pkg: VirtualCoinsPackage) => {
    setSelectedPackage(pkg);
    setStep('confirm');
    setErrorMessage(null);
  };

  const handleBack = () => {
    setStep('select');
    setSelectedPackage(null);
    setErrorMessage(null);
  };

  const handlePurchase = async () => {
    // Guard imperativo: previene doble-click antes de que React flushee el setState
    if (isProcessingPurchaseRef.current) return;
    isProcessingPurchaseRef.current = true;

    if (!selectedPackage || !isConfigured) {
      isProcessingPurchaseRef.current = false;
      setErrorMessage(t('buyCoins.cannotProcessPayment'));
      return;
    }

    setStep('processing');
    setErrorMessage(null);

    try {
      const reference = generateReference('FC');
      const amountInCents = Math.round(ceilTo50COP(selectedPackage.price) * 100);

      logger.info('BuyCoinsModal', 'Iniciando compra', {
        reference,
        coins: selectedPackage.total_coins,
        price: selectedPackage.price,
      });

      // PASO 1: Registrar compra pendiente en el backend ANTES de abrir Wompi
      // Esto garantiza que si el pago se completa, el backend puede acreditar los FC
      const pendingResult = await wompiService.registerPendingPurchase(
        selectedPackage.id,
        reference
      );

      if (!pendingResult.success) {
        logger.error('BuyCoinsModal', 'Error al registrar compra pendiente:', pendingResult.message);
        setErrorMessage(pendingResult.message || 'Error al preparar la compra');
        setStep('error');
        return;
      }

      logger.info('BuyCoinsModal', 'Compra pendiente registrada, abriendo widget Wompi');

      // PASO 2: Abrir widget de Wompi
      const transaction = await openWidget({
        amountInCents,
        reference,
        customerData,
      });

      if (transaction) {
        if (transaction.status === 'APPROVED') {
          // IMPORTANTE: Confirmar la compra en el backend para acreditar los FC
          // No dependemos solo del webhook asíncrono de Wompi
          logger.info('BuyCoinsModal', 'Pago aprobado, confirmando FC en backend...', {
            reference,
            transactionId: transaction.id,
          });
          
          const confirmResult = await wompiService.confirmFCPurchase(reference, transaction.id);
          
          if (confirmResult.success) {
            setPurchasedCoins(selectedPackage.total_coins);
            setStep('success');
            alertService.success(t('buyCoins.purchaseSuccess', { amount: selectedPackage.total_coins.toLocaleString() }));
            
            // Notificar al padre para que refresque el wallet
            // Esto actualiza la instancia correcta de useWallet en WalletPage
            if (onPurchaseSuccess) {
              onPurchaseSuccess(selectedPackage.total_coins);
            }
          } else {
            // El pago fue exitoso pero hubo error al acreditar los FC
            // Esto no debería pasar, pero si pasa, el webhook lo procesará después
            logger.error('BuyCoinsModal', 'Error al confirmar FC:', confirmResult.message);
            setPurchasedCoins(selectedPackage.total_coins);
            setStep('delayed');
            
            if (onPurchaseSuccess) {
              onPurchaseSuccess(selectedPackage.total_coins);
            }
          }
        } else if (transaction.status === 'DECLINED') {
          setErrorMessage(t('buyCoins.paymentDeclined'));
          setStep('error');
        } else if (transaction.status === 'VOIDED') {
          setErrorMessage(t('buyCoins.paymentVoided', 'El pago fue anulado. Intenta de nuevo.'));
          setStep('error');
        } else if (transaction.status === 'ERROR') {
          setErrorMessage(t('buyCoins.paymentTechnicalError', 'Hubo un error técnico con el pago. Intenta de nuevo.'));
          setStep('error');
        } else if (transaction.status === 'PENDING') {
          // Pago pendiente - mostrar estado específico con polling
          setPurchasedCoins(selectedPackage.total_coins);
          setPaymentReference(reference);
          setStep('pending');
        } else {
          logger.warn('BuyCoinsModal', 'Estado de transacción desconocido:', transaction.status);
          setErrorMessage(t('buyCoins.paymentError'));
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
          const serverStatus = await wompiService.getPurchaseStatus(reference);
          if (serverStatus.success && serverStatus.data) {
            const status = serverStatus.data.status;
            if (status === 'completed') {
              serverConfirmed = true;
              logger.info('BuyCoinsModal', 'Pago confirmado server-side (3DS completado):', { reference, status });
            } else if (status === 'processing') {
              await new Promise(resolve => setTimeout(resolve, 3000));
              const recheck = await wompiService.getPurchaseStatus(reference);
              if (recheck.success && recheck.data?.status === 'completed') {
                serverConfirmed = true;
                logger.info('BuyCoinsModal', 'Pago confirmado server-side (tras re-verificación):', { reference });
              }
            }
          }
        } catch (statusError) {
          logger.warn('BuyCoinsModal', 'Error al verificar estado server-side:', statusError);
        }

        if (serverConfirmed) {
          setPurchasedCoins(selectedPackage.total_coins);
          setStep('success');
          alertService.success(t('buyCoins.purchaseSuccess', { amount: selectedPackage.total_coins.toLocaleString() }));
          if (onPurchaseSuccess) {
            onPurchaseSuccess(selectedPackage.total_coins);
          }
        } else {
          // El usuario realmente cerró el widget sin completar el pago
          setStep('confirm');
        }
      }
    } catch (error: any) {
      logger.error('BuyCoinsModal', 'Error en compra:', error);
      setErrorMessage(error.message || 'Error al procesar el pago');
      setStep('error');
    } finally {
      isProcessingPurchaseRef.current = false;
    }
  };

  const handleClose = () => {
    setStep('select');
    setSelectedPackage(null);
    setErrorMessage(null);
    setPurchasedCoins(0);
    onClose();
  };

  const renderLoading = () => (
    <div className="flex flex-col items-center justify-center py-12">
      <Loader text={t('buyCoins.loadingPackages')} size="large" />
    </div>
  );

  const renderPackageSelection = () => (
    <div>
      {packages.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-8 text-center">
          <FiAlertCircle className="text-texto/50 mb-2" style={{ width: 32, height: 32 }} />
          <p className="text-texto/70" style={{ fontSize: fluidSizing.text.sm }}>
            {t('buyCoins.noPackages')}
          </p>
          <button
            onClick={loadPackages}
            className="mt-4 text-primario hover:underline"
            style={{ fontSize: fluidSizing.text.sm }}
          >
            {t('buyCoins.retry')}
          </button>
        </div>
      ) : (() => {
        // Agrupar paquetes por nivel de membresía
        const groupedPackagesRaw = Object.entries(
          [...packages].reduce((groups, pkg) => {
            const level = pkg.min_membership ?? 2;
            if (!groups[level]) groups[level] = [];
            groups[level].push(pkg);
            return groups;
          }, {} as Record<number, typeof packages>)
        );
        
        // Ordenar: primero la membresía del usuario, luego el resto en orden ascendente
        const groupedPackages = groupedPackagesRaw.sort(([a], [b]) => {
          const levelA = Number(a);
          const levelB = Number(b);
          
          // Si uno es el nivel del usuario, va primero
          if (levelA === userMembershipLevel) return -1;
          if (levelB === userMembershipLevel) return 1;
          
          // El resto en orden ascendente
          return levelA - levelB;
        });
        
        const groupCount = groupedPackages.length;
        
        // Clases de grilla según cantidad de grupos (siempre mostrar todos)
        const gridClasses = groupCount === 1 
          ? 'flex justify-center' 
          : groupCount === 2 
            ? 'grid grid-cols-1 sm:grid-cols-2 justify-items-center'
            : groupCount === 3
              ? 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 justify-items-center'
              : 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 justify-items-center';
        
        return (
        <div 
          className={gridClasses}
          style={{ 
            gap: fluidSizing.space.xl,
            padding: `0 ${fluidSizing.space.md}`
          }}
        >
          {groupedPackages.map(([level, levelPackages]) => {
              const levelNum = Number(level);
              const membershipName = getLevelName(levelNum);
              
              // Verificar si el usuario puede comprar paquetes de este nivel
              // Nivel 5 (Antigüedad) es especial, solo para usuarios con ese nivel
              const canPurchase = levelNum === 5 
                ? userMembershipLevel === 5 
                : userMembershipLevel >= levelNum;
              
              // Degradados para el header por nivel de membresía
              const headerGradients: Record<number, string> = {
                2: 'bg-gradient-to-r from-gray-200 to-gray-100',      // Plata
                3: 'bg-gradient-to-r from-yellow-200 to-yellow-100',  // Dorada
                4: 'bg-gradient-to-r from-cyan-200 to-cyan-100',      // Diamante
                5: 'bg-gradient-to-r from-amber-200 to-amber-100',    // Antigüedad/Destacado
              };
              const headerGradient = headerGradients[levelNum] || headerGradients[2];
              
              const displayName = levelNum === 5 ? t('buyCoins.featured') : membershipName;
              
              return (
              <div 
                key={level} 
                className="w-full max-w-[280px] h-full"
              >
                {/* Contenedor principal con altura completa */}
                <div className="rounded-lg overflow-hidden shadow-sm border border-gray-200 bg-white h-full flex flex-col">
                  {/* Header con degradado por nivel */}
                  <div className={`flex flex-col ${headerGradient}`}>
                    {/* Icono centrado */}
                    <div 
                      className="flex justify-center"
                      style={{ padding: fluidSizing.space.sm }}
                    >
                      <MembershipBadge 
                        level={levelNum} 
                        size="lg" 
                      />
                    </div>
                    {/* Texto en subcontenedor de borde a borde */}
                    <div 
                      className="bg-white/50 border-t border-b border-gray-200 text-center whitespace-nowrap"
                      style={{ 
                        padding: `${fluidSizing.space.xs} ${fluidSizing.space.sm}`,
                        fontSize: fluidSizing.text.xs
                      }}
                    >
                      <span className="font-semibold text-oscuro">
                        {levelNum === 5 ? t('buyCoins.packageSingular') : t('buyCoins.packagePlural')} {displayName}
                      </span>
                    </div>
                  </div>
                  
                  {/* Contenido - flex-1 para ocupar espacio disponible */}
                  <div className="flex-1 flex flex-col" style={{ padding: fluidSizing.space.md }}>
                    {/* Contenedor de paquetes */}
                    <div className={`flex-1 ${!canPurchase ? 'opacity-50' : ''}`}>
                      <div className="flex flex-col" style={{ gap: fluidSizing.space.sm }}>
                        {levelPackages
                          .sort((a, b) => b.total_coins - a.total_coins)
                          .map((pkg) => (
                            <PackageCard
                              key={pkg.id}
                              package={pkg}
                              onSelect={() => canPurchase ? handleSelectPackage(pkg) : null}
                              isLegacy={levelNum === 5}
                              disabled={!canPurchase}
                            />
                          ))}
                      </div>
                    </div>
                    
                    {/* Footer con estado o botón */}
                    <div style={{ marginTop: fluidSizing.space.md }}>
                      {canPurchase ? (
                        /* Usuario puede comprar - mostrar estado disponible */
                        <div 
                          className="bg-green-50 text-green-700 font-medium rounded-lg w-full flex items-center justify-center text-center border border-green-200"
                          style={{ 
                            fontSize: fluidSizing.text['2xs'],
                            padding: `${fluidSizing.space.sm} ${fluidSizing.space.xs}`,
                            gap: fluidSizing.space.xs
                          }}
                        >
                          <FiCheck className="flex-shrink-0" style={{ width: 12, height: 12 }} />
                          <span>{t('buyCoins.packagesAvailable')}</span>
                        </div>
                      ) : levelNum !== 5 ? (
                        /* Usuario no puede comprar - mostrar botón de mejorar */
                        <button
                          onClick={() => {
                            handleClose();
                            navigate(localizedPath('/membresias#niveles-section'));
                          }}
                          className="bg-primario text-white font-medium rounded-lg hover:bg-hover transition-colors w-full flex items-center justify-center shadow-md text-center"
                          style={{ 
                            fontSize: fluidSizing.text['2xs'],
                            padding: `${fluidSizing.space.sm} ${fluidSizing.space.xs}`,
                            gap: fluidSizing.space.xs
                          }}
                        >
                          <FiArrowUp className="flex-shrink-0" style={{ width: 12, height: 12 }} />
                          <span className="truncate">{t('buyCoins.viewMemberships')}</span>
                        </button>
                      ) : (
                        /* Paquete destacado - exclusivo */
                        <div 
                          className="bg-amber-50 text-amber-700 font-medium rounded-lg w-full flex items-center justify-center text-center border border-amber-200"
                          style={{ 
                            fontSize: fluidSizing.text['2xs'],
                            padding: `${fluidSizing.space.sm} ${fluidSizing.space.xs}`
                          }}
                        >
                          <span>{t('buyCoins.exclusiveFeatured')}</span>
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              </div>
            );
            })}
        </div>
        );
      })()}

      {!isConfigured && (
        <div className="flex items-center gap-2 p-3 bg-yellow-50 border border-yellow-200 rounded-lg text-yellow-700 text-sm">
          <FiAlertCircle className="flex-shrink-0" />
          <span>{t('buyCoins.paymentUnavailable')}</span>
        </div>
      )}
    </div>
  );

  const renderConfirmation = () => (
    selectedPackage ? (
      <PurchaseConfirmation
        package={selectedPackage}
        isLoading={isWidgetLoading}
        isConfigured={isConfigured}
        onBack={handleBack}
        onConfirm={handlePurchase}
      />
    ) : null
  );

  const renderProcessing = () => (
    <div className="flex flex-col items-center justify-center py-12">
      <Loader text={t('buyCoins.processingPayment')} size="large" />
      <p className="text-texto/70 mt-4 text-center" style={{ fontSize: fluidSizing.text.sm }}>
        {t('buyCoins.doNotClose')}
      </p>
    </div>
  );

  const renderSuccess = () => (
    <div className="flex flex-col items-center justify-center py-8 text-center">
      <div className="w-16 h-16 rounded-full bg-acento/20 flex items-center justify-center mb-4">
        <FiCheck className="text-acento" style={{ width: 32, height: 32 }} />
      </div>
      
      <h3 className="font-bold text-oscuro mb-2" style={{ fontSize: fluidSizing.text.xl }}>
        {t('buyCoins.successTitle')}
      </h3>
      
      <p className="text-texto mb-4" style={{ fontSize: fluidSizing.text.sm }}>
        {t('buyCoins.youAcquired')}
      </p>
      
      <VirtualCoinPrice amount={purchasedCoins} size="xl" showLabel className="text-primario font-bold mb-6" />
      
      <p className="text-texto/70 mb-6" style={{ fontSize: fluidSizing.text.xs }}>
        {t('buyCoins.coinsAvailable')}
      </p>
      
      <button
        onClick={handleClose}
        className="w-full py-3 px-4 bg-primario text-white rounded-lg hover:bg-hover transition-colors font-semibold"
        style={{ fontSize: fluidSizing.text.sm }}
      >
        {t('buyCoins.continue')}
      </button>
    </div>
  );

  const renderError = () => (
    <div className="flex flex-col items-center justify-center py-8 text-center">
      <div className="w-16 h-16 rounded-full bg-red-100 flex items-center justify-center mb-4">
        <FiAlertCircle className="text-red-500" style={{ width: 32, height: 32 }} />
      </div>
      
      <h3 className="font-bold text-oscuro mb-2" style={{ fontSize: fluidSizing.text.xl }}>
        {t('buyCoins.errorTitle')}
      </h3>
      
      <p className="text-texto mb-6" style={{ fontSize: fluidSizing.text.sm }}>
        {errorMessage || wompiError || t('buyCoins.unexpectedError')}
      </p>
      
      <div className="flex gap-3 w-full">
        <button
          onClick={handleBack}
          className="flex-1 py-3 px-4 border-2 border-secundario/50 text-texto rounded-lg hover:bg-secundario/10 transition-colors"
          style={{ fontSize: fluidSizing.text.sm }}
        >
          {t('buyCoins.back')}
        </button>
        <button
          onClick={handlePurchase}
          className="flex-1 py-3 px-4 bg-primario text-white rounded-lg hover:bg-hover transition-colors font-semibold"
          style={{ fontSize: fluidSizing.text.sm }}
        >
          {t('buyCoins.retry')}
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
        {t('buyCoins.delayedTitle')}
      </h3>
      
      <p className="text-texto mb-4" style={{ fontSize: fluidSizing.text.sm }}>
        {t('buyCoins.delayedDesc')}
      </p>
      
      <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6 w-full">
        <p className="text-yellow-800" style={{ fontSize: fluidSizing.text.xs }} dangerouslySetInnerHTML={{ __html: sanitizeInlineHtml(t('buyCoins.delayedNotice')) }} />
      </div>
      
      <button
        onClick={handleClose}
        className="w-full py-3 px-4 bg-primario text-white rounded-lg hover:bg-hover transition-colors font-semibold"
        style={{ fontSize: fluidSizing.text.sm }}
      >
        {t('buyCoins.understood')}
      </button>
    </div>
  );

  const renderDeclined = () => (
    <div className="flex flex-col items-center justify-center py-8 text-center">
      <div className="w-16 h-16 rounded-full bg-red-100 flex items-center justify-center mb-4">
        <FiXCircle className="text-red-600" style={{ width: 32, height: 32 }} />
      </div>
      
      <h3 className="font-bold text-oscuro mb-2" style={{ fontSize: fluidSizing.text.xl }}>
        {t('buyCoins.declinedTitle')}
      </h3>
      
      <p className="text-texto mb-4" style={{ fontSize: fluidSizing.text.sm }}>
        {t('buyCoins.declinedDesc')}
      </p>
      
      <div className="bg-red-50 border border-red-200 rounded-lg p-4 mb-6 w-full">
        <p className="text-red-800" style={{ fontSize: fluidSizing.text.xs }}>
          {t('buyCoins.declinedNotice')}
        </p>
      </div>
      
      <button
        onClick={handleClose}
        className="w-full py-3 px-4 bg-primario text-white rounded-lg hover:bg-hover transition-colors font-semibold"
        style={{ fontSize: fluidSizing.text.sm }}
      >
        {t('buyCoins.understood')}
      </button>
    </div>
  );

  const renderPending = () => (
    <div className="flex flex-col items-center justify-center py-8 text-center">
      <div className="w-16 h-16 rounded-full bg-yellow-100 flex items-center justify-center mb-4">
        <FiAlertCircle className="text-yellow-600" style={{ width: 32, height: 32 }} />
      </div>
      
      <h3 className="font-bold text-oscuro mb-2" style={{ fontSize: fluidSizing.text.xl }}>
        {t('buyCoins.pendingTitle')}
      </h3>
      
      <p className="text-texto mb-4" style={{ fontSize: fluidSizing.text.sm }}>
        {t('buyCoins.pendingDesc')}
      </p>
      
      <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6 w-full">
        <p className="text-yellow-800" style={{ fontSize: fluidSizing.text.xs }} dangerouslySetInnerHTML={{ __html: sanitizeInlineHtml(t('buyCoins.pendingNotice')) }} />
      </div>
      
      <VirtualCoinPrice amount={purchasedCoins} size="lg" showLabel className="text-texto/70 mb-6" />
      
      <button
        onClick={handleClose}
        className="w-full py-3 px-4 bg-primario text-white rounded-lg hover:bg-hover transition-colors font-semibold"
        style={{ fontSize: fluidSizing.text.sm }}
      >
        {t('buyCoins.understood')}
      </button>
    </div>
  );

  const renderContent = () => {
    switch (step) {
      case 'loading':
        return renderLoading();
      case 'select':
        return renderPackageSelection();
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
        return renderPackageSelection();
    }
  };

  const getTitle = () => {
    switch (step) {
      case 'select':
        return t('buyCoins.titleSelect');
      case 'confirm':
        return t('buyCoins.titleConfirm');
      case 'processing':
        return t('buyCoins.titleProcessing');
      case 'success':
        return t('buyCoins.titleSuccess');
      case 'delayed':
        return t('buyCoins.titleDelayed');
      case 'pending':
        return t('buyCoins.titlePending');
      case 'declined':
        return t('buyCoins.titleDeclined');
      case 'error':
        return t('buyCoins.titleError');
      default:
        return t('buyCoins.titleSelect');
    }
  };

  // Calcular cantidad de grupos de membresía
  const groupCount = useMemo(() => {
    const groups = packages.reduce((acc, pkg) => {
      const level = pkg.min_membership ?? 2;
      acc[level] = true;
      return acc;
    }, {} as Record<number, boolean>);
    return Object.keys(groups).length;
  }, [packages]);

  // Ancho del modal según el paso y cantidad de grupos
  const getModalWidth = () => {
    switch (step) {
      case 'loading':
        return 'max-w-sm';
      case 'select':
        // Siempre mostrar todos los grupos (4), usar ancho amplio
        if (groupCount <= 1) return 'max-w-sm';
        if (groupCount === 2) return 'max-w-2xl';
        if (groupCount === 3) return 'max-w-4xl';
        return 'max-w-7xl';
      case 'confirm':
      case 'processing':
      case 'success':
      case 'delayed':
      case 'error':
        return 'max-w-md';
      default:
        return 'max-w-sm';
    }
  };

  return (
    <AnimatedModal
      isOpen={isOpen}
      onClose={step === 'processing' ? () => {} : handleClose}
      title={
        <span className="flex items-center gap-2">
          <FiCreditCard className="text-primario" />
          {getTitle()}
        </span>
      }
      maxWidth={getModalWidth()}
      hideCloseButton={step === 'processing'}
    >
      <div className="p-1">
        {renderContent()}
      </div>
    </AnimatedModal>
  );
};

export default BuyCoinsModal;
