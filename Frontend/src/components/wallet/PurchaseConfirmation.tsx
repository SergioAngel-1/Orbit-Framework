/**
 * PurchaseConfirmation - Vista de confirmación de compra de Virtual Coins
 * Muestra resumen del paquete seleccionado y opciones de pago
 */

import { FC } from 'react';
import { useTranslation } from 'react-i18next';
import { FiCheck, FiShoppingBag, FiCreditCard, FiArrowLeft } from 'react-icons/fi';
import VirtualCoinPrice from '../common/VirtualCoinPrice';
import MembershipBadge from '../common/MembershipBadge';
import { fluidSizing } from '../../utils/fluidSizing';
import { formatCurrency } from '../../utils/formatters';
import type { VirtualCoinsPackage } from '../../types/wompi';

interface PurchaseConfirmationProps {
  /** Paquete seleccionado */
  package: VirtualCoinsPackage;
  /** Si el widget de pago está cargando */
  isLoading?: boolean;
  /** Si el sistema de pagos está configurado */
  isConfigured?: boolean;
  /** Callback para volver a selección */
  onBack: () => void;
  /** Callback para confirmar compra */
  onConfirm: () => void;
}

const PurchaseConfirmation: FC<PurchaseConfirmationProps> = ({
  package: pkg,
  isLoading = false,
  isConfigured = true,
  onBack,
  onConfirm
}) => {
  const { t } = useTranslation('walletComponents');
  const hasBonus = (pkg.bonus ?? 0) > 0;
  const hasDiscount = pkg.is_on_sale && pkg.regular_price;

  return (
    <div className="flex flex-col" style={{ gap: fluidSizing.space.lg }}>
      {/* Header con icono de membresía */}
      <div className="flex flex-col items-center text-center">
        <MembershipBadge 
          level={pkg.min_membership ?? 2} 
          size="lg"
        />
        <h3 
          className="text-oscuro font-semibold"
          style={{ fontSize: fluidSizing.text.lg, marginTop: fluidSizing.space.sm }}
        >
          {t('purchaseConfirmation.confirmTitle')}
        </h3>
      </div>

      {/* Card de resumen */}
      <div 
        className="bg-gradient-to-br from-primario/5 to-primario/10 rounded-2xl border border-primario/20"
        style={{ padding: fluidSizing.space.lg }}
      >
        {/* Virtual Coins */}
        <div className="flex items-center justify-between">
          <div className="flex items-center" style={{ gap: fluidSizing.space.sm }}>
            <FiShoppingBag className="text-primario" style={{ width: 20, height: 20 }} />
            <span className="text-texto font-medium">{t('purchaseConfirmation.VirtualCoins')}</span>
          </div>
          <VirtualCoinPrice amount={pkg.total_coins} size="md" showLabel />
        </div>

        {/* Bonus */}
        {hasBonus && (
          <div 
            className="flex items-center justify-between text-primario"
            style={{ marginTop: fluidSizing.space.sm }}
          >
            <span style={{ fontSize: fluidSizing.text.sm }}>{t('purchaseConfirmation.bonusIncluded')}</span>
            <span className="font-semibold" style={{ fontSize: fluidSizing.text.sm }}>
              +{pkg.bonus?.toLocaleString()} FC
            </span>
          </div>
        )}

        {/* Separador */}
        <div 
          className="border-t border-primario/20"
          style={{ marginTop: fluidSizing.space.md, marginBottom: fluidSizing.space.md }}
        />

        {/* Total */}
        <div className="flex items-center justify-between">
          <span className="text-oscuro font-semibold">{t('purchaseConfirmation.totalToPay')}</span>
          <div className="text-right">
            {hasDiscount && (
              <div 
                className="text-texto/50 line-through"
                style={{ fontSize: fluidSizing.text.xs }}
              >
                {formatCurrency(pkg.regular_price!)}
              </div>
            )}
            <div 
              className="font-bold text-primario"
              style={{ fontSize: fluidSizing.text.xl }}
            >
              {formatCurrency(pkg.price)}
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
            {t('purchaseConfirmation.securePayment')}
          </div>
          <div className="text-texto/60" style={{ fontSize: fluidSizing.text['2xs'] }}>
            {t('purchaseConfirmation.paymentMethods')}
          </div>
        </div>
        <FiCheck className="text-acento" style={{ width: 18, height: 18 }} />
      </div>

      {/* Botones */}
      <div className="flex flex-col" style={{ gap: fluidSizing.space.sm }}>
        <button
          onClick={onConfirm}
          disabled={isLoading || !isConfigured}
          className="w-full py-3 px-4 bg-primario text-white rounded-xl hover:bg-hover transition-colors disabled:opacity-50 disabled:cursor-not-allowed font-semibold flex items-center justify-center"
          style={{ fontSize: fluidSizing.text.base, gap: fluidSizing.space.sm }}
        >
          {isLoading ? t('purchaseConfirmation.processing') : t('purchaseConfirmation.payNow')}
        </button>
        
        <button
          onClick={onBack}
          className="w-full py-2.5 px-4 text-texto/70 hover:text-texto rounded-xl hover:bg-gray-100 transition-colors flex items-center justify-center"
          style={{ fontSize: fluidSizing.text.sm, gap: fluidSizing.space.xs }}
        >
          <FiArrowLeft style={{ width: 16, height: 16 }} />
          {t('purchaseConfirmation.changePackage')}
        </button>
      </div>
    </div>
  );
};

export default PurchaseConfirmation;
