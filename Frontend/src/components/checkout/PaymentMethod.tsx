import React, { useMemo, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { ceilTo50COP } from '../../utils/formatters';
import { FiAlertCircle } from 'react-icons/fi';
import VirtualCoinPrice from '../common/VirtualCoinPrice';
import CollapsibleSection from '../common/CollapsibleSection';
import { sanitizeInlineHtml } from '../../utils/sanitizeHtml';

interface PaymentMethodProps {
  paymentMethod: string;
  onInputChange: (e: React.ChangeEvent<HTMLInputElement>) => void;
  disclaimerAccepted: boolean;
  onDisclaimerChange: (accepted: boolean) => void;
  /** Monto total a pagar (para pago con tarjeta) */
  totalAmount?: number;
  /** Descuento aplicado por Virtual Coins */
  appliedPointsDiscount?: number;
  /** Estado del pago con tarjeta (controlado desde el padre) */
  cardPaymentStatus?: 'idle' | 'processing' | 'success' | 'pending' | 'error';
  /** Si el usuario seleccionó envío premium (express o fast) — restringe a solo tarjeta */
  isPremiumShipping?: boolean;
}

// Constante para el porcentaje de incremento por pago con tarjeta (exportada para uso externo)
export const CARD_PAYMENT_FEE_PERCENTAGE = 5;

const PaymentMethod: React.FC<PaymentMethodProps> = ({ 
  paymentMethod, 
  onInputChange,
  disclaimerAccepted,
  onDisclaimerChange,
  totalAmount = 0,
  appliedPointsDiscount = 0,
  cardPaymentStatus = 'idle',
  isPremiumShipping = false,
}) => {
  const { t } = useTranslation('checkoutPage');
  const { t: tLegal } = useTranslation('layoutLegalFramework');
  const withdrawalTitle = tLegal('withdrawal.title');
  const withdrawalItems = tLegal('withdrawal.items', { returnObjects: true }) as Array<{ id: string; title: string; description: string }>;

  // Auto-switch a tarjeta cuando se activa envío premium y el método actual es efectivo
  useEffect(() => {
    if (isPremiumShipping && paymentMethod === 'cash') {
      const syntheticEvent = {
        target: { name: 'paymentMethod', value: 'card', type: 'radio', checked: true },
      } as React.ChangeEvent<HTMLInputElement>;
      onInputChange(syntheticEvent);
    }
  }, [isPremiumShipping]);

  // Calcular el monto final después del descuento de Virtual Coins
  const finalAmount = totalAmount - appliedPointsDiscount;
  
  // Si el total es 0 o menor, el pedido está completamente pagado con Virtual Coins
  const isPaidWithCoins = finalAmount <= 0 && appliedPointsDiscount > 0;
  
  // Forzar el valor si está vacío (o 'virtual_coins' si está pagado con coins)
  const currentPaymentMethod = isPaidWithCoins ? 'virtual_coins' : (paymentMethod || 'bank');
  
  // Calcular el monto con incremento del 5% para pago con tarjeta
  // El monto base es el total menos el descuento de Virtual Coins
  const cardPaymentDetails = useMemo(() => {
    const baseAmount = ceilTo50COP(Math.max(0, totalAmount - appliedPointsDiscount));
    const feeAmount = ceilTo50COP(baseAmount * (CARD_PAYMENT_FEE_PERCENTAGE / 100));
    const totalWithFee = ceilTo50COP(baseAmount + feeAmount);
    return {
      originalAmount: baseAmount,
      feePercentage: CARD_PAYMENT_FEE_PERCENTAGE,
      feeAmount,
      totalWithFee,
      pointsDiscount: appliedPointsDiscount,
    };
  }, [totalAmount, appliedPointsDiscount]);
  
  // Si está pagado completamente con Virtual Coins, mostrar UI especial
  if (isPaidWithCoins) {
    return (
      <div className="mb-8 checkout-animate">
        <h2 className="text-lg md:text-xl font-medium text-oscuro mb-4 md:mb-6">{t('payment.methodTitle')}</h2>
        
        {/* Disclaimer obligatorio */}
        <div className={`relative p-4 rounded-lg border-2 transition-all mb-6 ${
          disclaimerAccepted 
            ? 'bg-secundario/30 border-primario shadow-md' 
            : 'bg-secundario/20 border-secundario'
        }`}>
          <h3 className="font-semibold text-primario text-base mb-3">{withdrawalTitle}</h3>
          
          <ul className="space-y-2 mb-4">
            {withdrawalItems.map((item) => (
              <li key={item.id} className="text-sm text-gray-700">
                <span className="font-medium text-primario">{item.title}:</span>{' '}
                {item.description}
              </li>
            ))}
          </ul>
          
          <label className="flex items-center gap-3 cursor-pointer pt-3 border-t border-primario/20">
            <div className="flex items-center">
              <div className={`w-5 h-5 rounded border-2 flex items-center justify-center transition-all ${
                disclaimerAccepted ? 'border-primario bg-primario' : 'border-primario/40 bg-white'
              }`}>
                {disclaimerAccepted && (
                  <svg className="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                  </svg>
                )}
              </div>
            </div>
            
            <input
              type="checkbox"
              checked={disclaimerAccepted}
              onChange={(e) => onDisclaimerChange(e.target.checked)}
              className="sr-only"
            />
            
            <span className="text-sm font-medium text-gray-700">
              {t('payment.acceptDeclaration')}
            </span>
          </label>
        </div>
        
        {/* Método de pago: Virtual Coins (único disponible) */}
        <div className="bg-green-50 border-2 border-green-300 rounded-lg p-4">
          <div className="flex items-center gap-3">
            <div className="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center">
              <svg className="w-6 h-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
              </svg>
            </div>
            <div>
              <p className="font-semibold text-green-700 text-base">
                {t('payment.paidWithCoins')}
              </p>
              <p className="text-sm text-green-600">
                {t('payment.paidWithCoinsDesc')}
              </p>
            </div>
          </div>
          
          <div className="mt-3 pt-3 border-t border-green-200">
            <div className="flex justify-between items-center text-sm">
              <span className="text-green-600">{t('payment.coinsUsed')}</span>
              <VirtualCoinPrice amount={appliedPointsDiscount} size="sm" showLabel={false} className="text-green-700 font-semibold" />
            </div>
          </div>
        </div>
        
        {/* Input oculto para mantener el valor del método de pago */}
        <input
          type="hidden"
          name="paymentMethod"
          value="virtual_coins"
        />
      </div>
    );
  }
  
  return (
    <div className="mb-8 checkout-animate">
      <h2 className="text-lg md:text-xl font-medium text-oscuro mb-4 md:mb-6">{t('payment.methodTitle')}</h2>
      
      {/* Disclaimer obligatorio sobre cultivo colectivo */}
      <div className={`relative p-4 rounded-lg border-2 transition-all mb-6 ${
        disclaimerAccepted 
          ? 'bg-secundario/30 border-primario shadow-md' 
          : 'bg-secundario/20 border-secundario'
      }`}>
        <h3 className="font-semibold text-primario text-base mb-3">{withdrawalTitle}</h3>
        
        <ul className="space-y-2 mb-4">
          {withdrawalItems.map((item) => (
            <li key={item.id} className="text-sm text-gray-700">
              <span className="font-medium text-primario">{item.title}:</span>{' '}
              {item.description}
            </li>
          ))}
        </ul>
        
        <label className="flex items-center gap-3 cursor-pointer pt-3 border-t border-primario/20">
          {/* Checkbox visual personalizado */}
          <div className="flex items-center">
            <div className={`w-5 h-5 rounded border-2 flex items-center justify-center transition-all ${
              disclaimerAccepted ? 'border-primario bg-primario' : 'border-primario/40 bg-white'
            }`}>
              {disclaimerAccepted && (
                <svg className="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                </svg>
              )}
            </div>
          </div>
          
          <input
            type="checkbox"
            checked={disclaimerAccepted}
            onChange={(e) => onDisclaimerChange(e.target.checked)}
            className="sr-only"
          />
          
          <span className="text-sm font-medium text-gray-700">
            {t('payment.acceptDeclaration')}
          </span>
        </label>
      </div>
      
      {/* Aviso de restricción por envío premium */}
      {isPremiumShipping && (
        <div className="flex items-start gap-2 p-3 mb-4 bg-amber-50 border border-amber-200 rounded-lg text-amber-800">
          <FiAlertCircle className="flex-shrink-0 mt-0.5" size={16} />
          <p className="text-xs leading-tight">
            {t('payment.premiumShippingRestriction')}
          </p>
        </div>
      )}

      {/* Opción: Tarjeta de crédito/débito */}
      <div className="mb-4">
        <label className={`flex items-center ${disclaimerAccepted ? 'cursor-pointer' : 'opacity-40 cursor-not-allowed'}`}>
          {/* Radio button visual */}
          <div className="flex items-center">
            <div className={`w-5 h-5 rounded-full border-2 flex items-center justify-center transition-all ${
              currentPaymentMethod === 'card' && disclaimerAccepted ? 'border-primario' : 'border-gray-300'
            }`}>
              {currentPaymentMethod === 'card' && disclaimerAccepted && (
                <div className="w-3 h-3 rounded-full bg-primario" />
              )}
            </div>
          </div>
          
          <input
            type="radio"
            name="paymentMethod"
            value="card"
            checked={currentPaymentMethod === 'card'}
            onChange={onInputChange}
            disabled={!disclaimerAccepted}
            className="sr-only"
          />
          
          <span className="ml-2 flex items-center gap-2">
            {t('payment.creditDebitCard')}
            <span className="text-xs bg-primario/10 text-primario font-medium px-2 py-0.5 rounded-full">
              +5%
            </span>
          </span>
        </label>
      </div>

      {/* Formulario de pago con tarjeta */}
      {currentPaymentMethod === 'card' && disclaimerAccepted && (
        <CollapsibleSection
          title={t('payment.cardDetailsTitle')}
          variant="soft"
          collapsible={false}
          showCollapseButton={false}
          className="mt-4 mb-4"
        >
          <p className="text-xs md:text-sm text-gray-600 mb-4">
            {t('payment.cardSecureDesc')}
          </p>
          
          {/* Desglose del monto con incremento */}
          <div className="bg-white border border-gray-200 rounded-lg p-3 md:p-4 mb-3 md:mb-4">
            <div className="flex items-start gap-2 mb-2 md:mb-3">
              <FiAlertCircle className="text-primario flex-shrink-0 mt-0.5" size={14} />
              <p className="text-xs text-primario leading-tight">
                {t('payment.cardFeeNotice', { fee: cardPaymentDetails.feePercentage })}
              </p>
            </div>
            
            <div className="space-y-1.5 md:space-y-2 text-xs md:text-sm">
              <div className="flex justify-between items-center text-gray-600">
                <span>{t('payment.orderSubtotal')}</span>
                <VirtualCoinPrice amount={cardPaymentDetails.originalAmount} size="xs" showLabel={true} />
              </div>
              <div className="flex justify-between items-center text-primario">
                <span>{t('payment.cardFee', { fee: cardPaymentDetails.feePercentage })}</span>
                <div className="flex items-center gap-1">
                  <span>+</span>
                  <VirtualCoinPrice amount={cardPaymentDetails.feeAmount} size="xs" showLabel={true} inheritColor />
                </div>
              </div>
              <div className="border-t border-gray-200 pt-1.5 md:pt-2 mt-1.5 md:mt-2">
                <div className="flex justify-between items-center font-semibold text-oscuro">
                  <span>{t('payment.totalToPay')}</span>
                  <VirtualCoinPrice amount={cardPaymentDetails.totalWithFee} size="sm" showLabel={true} className="text-primario" />
                </div>
              </div>
            </div>
          </div>
          
          {cardPaymentStatus === 'success' && (
            <div className="flex items-center gap-2 p-3 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm">
              <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
              </svg>
              <span>{t('payment.paymentProcessed')}</span>
            </div>
          )}
          
          {/* Info de seguridad */}
          <div className="mt-3 flex items-center justify-center gap-2 text-xs text-gray-500">
            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
            </svg>
            <span>{t('payment.securePayment')}</span>
          </div>
        </CollapsibleSection>
      )}

      <div className="mt-4">
        <label className={`flex items-center ${
          disclaimerAccepted ? 'cursor-pointer' : 'opacity-40 cursor-not-allowed'
        }`}>
          {/* Radio button visual */}
          <div className="flex items-center">
            <div className={`w-5 h-5 rounded-full border-2 flex items-center justify-center transition-all ${
              currentPaymentMethod === 'bank' && disclaimerAccepted ? 'border-primario' : 'border-gray-300'
            }`}>
              {currentPaymentMethod === 'bank' && disclaimerAccepted && (
                <div className="w-3 h-3 rounded-full bg-primario" />
              )}
            </div>
          </div>
          
          <input
            type="radio"
            name="paymentMethod"
            value="bank"
            checked={currentPaymentMethod === 'bank'}
            onChange={onInputChange}
            disabled={!disclaimerAccepted}
            className="sr-only"
          />
          
          <span className="ml-2">{t('payment.bankTransfer')}</span>
        </label>
      </div>

      {currentPaymentMethod === 'bank' && disclaimerAccepted && (
        <CollapsibleSection
          title={t('payment.bankTransferTitle')}
          variant="soft"
          collapsible={false}
          showCollapseButton={false}
          className="mt-4"
        >
          <p className="text-xs md:text-sm text-gray-600 mb-4">
            <span dangerouslySetInnerHTML={{ __html: sanitizeInlineHtml(t('payment.bankTransferDesc')) }} />
          </p>
          
          <p className="text-xs text-gray-500 mb-3 text-center">{t('payment.bankTransferChoose')}</p>
          
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 md:gap-4">
            {/* Opción 1: Copiar llave */}
            <div className="bg-white border border-gray-200 rounded-lg p-3 md:p-4 flex flex-col items-center text-center hover:border-primario/50 transition-colors">
              <div className="w-14 h-14 md:w-20 md:h-20 bg-primario/10 rounded-full flex items-center justify-center mb-2 md:mb-3">
                <svg xmlns="http://www.w3.org/2000/svg" className="h-7 w-7 md:h-10 md:w-10 text-primario" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                </svg>
              </div>
              <p className="text-xs text-gray-500 mb-0.5 md:mb-1">{t('payment.brebKey')}</p>
              <p className="text-base md:text-lg font-bold text-primario tracking-wider mb-2 md:mb-3">0091528949</p>
              <button
                type="button"
                onClick={() => {
                  navigator.clipboard.writeText('0091528949');
                  const btn = document.getElementById('copy-breb-btn');
                  if (btn) {
                    btn.textContent = t('payment.copied');
                    setTimeout(() => { btn.textContent = t('payment.copyKey'); }, 2000);
                  }
                }}
                id="copy-breb-btn"
                className="bg-primario text-white px-3 md:px-4 py-1.5 md:py-2 rounded-md text-xs md:text-sm font-medium hover:bg-hover transition-colors flex items-center justify-center gap-1.5 md:gap-2 w-full"
              >
                <svg xmlns="http://www.w3.org/2000/svg" className="h-3.5 w-3.5 md:h-4 md:w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                </svg>
                <span className="leading-tight">{t('payment.copyKey')}</span>
              </button>
            </div>
            
            {/* Opción 2: Escanear QR */}
            <div className="bg-white border border-gray-200 rounded-lg p-3 md:p-4 flex flex-col items-center text-center hover:border-primario/50 transition-colors">
              <img 
                src="/assets/images/Club/QR-BRE-V-Club-Flores.webp" 
                alt="QR Bre-B Club Flores" 
                className="w-28 h-28 md:w-36 md:h-36 object-contain mb-2 md:mb-3"
              />
              <button
                type="button"
                onClick={() => {
                  const link = document.createElement('a');
                  link.href = '/assets/images/Club/QR-BRE-V-Club-Flores.webp';
                  link.download = 'QR-Bre-B-Club-Flores.webp';
                  link.click();
                }}
                className="bg-primario text-white px-3 md:px-4 py-1.5 md:py-2 rounded-md text-xs md:text-sm font-medium hover:bg-hover transition-colors flex items-center justify-center gap-1.5 md:gap-2 w-full"
              >
                <svg xmlns="http://www.w3.org/2000/svg" className="h-3.5 w-3.5 md:h-4 md:w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                </svg>
                <span className="leading-tight">{t('payment.scanAndPay')}</span>
              </button>
            </div>
          </div>
          
          <p className="text-xs text-gray-500 mt-4 flex items-center gap-1">
            <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4 text-primario flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            {t('payment.bankAvailability')}
          </p>
        </CollapsibleSection>
      )}

      <div className="mt-4">
        <label className={`flex items-center ${
          isPremiumShipping
            ? 'opacity-40 cursor-not-allowed'
            : disclaimerAccepted ? 'cursor-pointer' : 'opacity-40 cursor-not-allowed'
        }`}>
          {/* Radio button visual */}
          <div className="flex items-center">
            <div className={`w-5 h-5 rounded-full border-2 flex items-center justify-center transition-all ${
              currentPaymentMethod === 'cash' && disclaimerAccepted && !isPremiumShipping ? 'border-primario' : 'border-gray-300'
            }`}>
              {currentPaymentMethod === 'cash' && disclaimerAccepted && !isPremiumShipping && (
                <div className="w-3 h-3 rounded-full bg-primario" />
              )}
            </div>
          </div>
          
          <input
            type="radio"
            name="paymentMethod"
            value="cash"
            checked={currentPaymentMethod === 'cash'}
            onChange={onInputChange}
            disabled={!disclaimerAccepted || isPremiumShipping}
            className="sr-only"
          />
          
          <span className="ml-2">{t('payment.cash')}</span>
        </label>
      </div>

      {currentPaymentMethod === 'cash' && disclaimerAccepted && !isPremiumShipping && (
        <CollapsibleSection
          title={t('payment.cashTitle')}
          variant="soft"
          collapsible={false}
          showCollapseButton={false}
          className="mt-4"
        >
          <p className="text-xs md:text-sm text-gray-600 mb-3">
            {t('payment.cashDesc')}
          </p>
          
          <div className="bg-primario/5 border border-primario/20 rounded-lg p-3 md:p-4">
            <div className="flex items-start gap-2">
              <FiAlertCircle className="text-primario flex-shrink-0 mt-0.5" size={14} />
              <p className="text-xs text-gray-600 leading-relaxed">
                <span dangerouslySetInnerHTML={{ __html: sanitizeInlineHtml(t('payment.cashNotice')) }} />
              </p>
            </div>
          </div>
        </CollapsibleSection>
      )}
    </div>
  );
};

export default PaymentMethod;
