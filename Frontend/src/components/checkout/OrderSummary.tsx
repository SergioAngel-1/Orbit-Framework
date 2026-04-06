import React, { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { CartItem } from '../../types/woocommerce';
import MinimumOrderAlert from '../cart/MinimumOrderAlert';
import { ShippingOptionType, SHIPPING_OPTIONS } from '../../hooks/useShippingOptions';
import VirtualCoinPrice from '../common/VirtualCoinPrice';
import CollapsibleSection from '../common/CollapsibleSection';
import MembershipBadge from '../common/MembershipBadge';
import MembershipDiscountSection from './MembershipDiscountSection';
import VirtualCoinsCollapsible from './VirtualCoinsCollapsible';
import FreeSamplesProgress from './FreeSamplesProgress';
import { useMembership } from '../../contexts/MembershipContext';
import { MembershipDiscountSummary } from '../../hooks/useMembershipDiscount';
import { ceilTo50COP } from '../../utils/formatters';

interface OrderSummaryProps {
  cartItems: CartItem[];
  subtotal: number;
  discount: number;
  total: number;
  selectedShipping: ShippingOptionType;
  isAuthenticated?: boolean;
  // Props para aporte mínimo
  minimumAmount?: number;
  meetsMinimum?: boolean;
  missingAmount?: number;
  minimumProgress?: number;
  // Props para descuento de membresía
  membershipDiscount?: MembershipDiscountSummary;
  membershipDiscountLoading?: boolean;
  // Props para entrega gratis
  useFreeDelivery?: boolean;
  // Props para Virtual Coins
  VirtualCoinsEnabled?: boolean;
  userPoints?: { balance: number } | null;
  systemConfig?: { configuration: any } | null;
  pointsLoading?: boolean;
  pointsToUse?: number;
  appliedPointsDiscount?: number;
  hasAppliedPointsDiscount?: boolean;
  onPointsChange?: (points: number) => void;
  onApplyPointsDiscount?: () => void;
  onRemovePointsDiscount?: () => void;
  onOpenHelpModal?: () => void;
  getMaxPointsToUse?: () => number;
  canApplyPointsDiscount?: () => boolean;
  getPointsHelpMessage?: () => string | null;
}

const OrderSummary: React.FC<OrderSummaryProps> = ({ 
  cartItems, 
  subtotal, 
  discount, 
  total,
  selectedShipping,
  isAuthenticated = false,
  // Props de aporte mínimo
  minimumAmount = 0,
  meetsMinimum = true,
  missingAmount = 0,
  minimumProgress = 0,
  // Props de descuento de membresía
  membershipDiscount,
  membershipDiscountLoading = false,
  // Props de entrega gratis
  useFreeDelivery = false,
  // Props de Virtual Coins
  VirtualCoinsEnabled = false,
  userPoints = null,
  systemConfig = null,
  pointsLoading = false,
  pointsToUse = 0,
  appliedPointsDiscount = 0,
  hasAppliedPointsDiscount = false,
  onPointsChange,
  onApplyPointsDiscount,
  onRemovePointsDiscount,
  onOpenHelpModal,
  getMaxPointsToUse,
  canApplyPointsDiscount,
  getPointsHelpMessage
}) => {
  // Usar el contexto de membresía para obtener niveles y datos del usuario
  const { t } = useTranslation('checkoutPage');
  const { getCategoryMembershipLevel, membershipName, membershipColor, freeSamples } = useMembership();
  
  // Estado para almacenar los niveles de membresía requeridos por producto
  const [productMembershipLevels, setProductMembershipLevels] = useState<Record<string, number>>({});

  // Obtener niveles de membresía para cada producto del carrito
  useEffect(() => {
    const fetchMembershipLevels = async () => {
      const levels: Record<string, number> = {};
      
      for (const item of cartItems) {
        const itemKey = `${item.id}-${item.variation_id || 'none'}`;
        const categories = item.product?.categories || [];
        let highestLevel = 0;
        
        for (const category of categories) {
          const level = await getCategoryMembershipLevel(category.id);
          if (level > highestLevel) {
            highestLevel = level;
          }
        }
        
        levels[itemKey] = highestLevel;
      }
      
      setProductMembershipLevels(levels);
    };
    
    if (cartItems.length > 0) {
      fetchMembershipLevels();
    }
  }, [cartItems, getCategoryMembershipLevel]);
  
  // Obtener el precio de envío basado en la opción seleccionada
  const shippingOption = SHIPPING_OPTIONS.find(opt => opt.id === selectedShipping);
  const shippingPrice = shippingOption?.price || 0;
  
  return (
    <CollapsibleSection
      title={t('orderSummary.title')}
      variant="soft"
      collapsible={false}
      showCollapseButton={false}
    >
      
      {/* Alerta de aporte mínimo: mostrar solo si falta aporte */}
      {!meetsMinimum && (
        <MinimumOrderAlert
          minimumAmount={minimumAmount}
          currentTotal={subtotal}
          meetsMinimum={meetsMinimum}
          missingAmount={missingAmount}
          progress={minimumProgress}
        />
      )}
      
      {/* Barra de progreso de muestras gratis */}
      <FreeSamplesProgress 
        freeSamples={freeSamples} 
        isAuthenticated={isAuthenticated} 
      />
      
      <div className="max-h-60 md:max-h-60 overflow-y-auto mb-3 md:mb-4 pr-3 pb-1 mt-3" style={{ scrollbarWidth: 'thin', scrollbarColor: '#d1d5db transparent', overflowX: 'hidden' }}>
        {cartItems.map(item => (
          <div key={`${item.id}-${item.variation_id || 'none'}`} className="flex items-center py-2 md:py-3 px-1 border-b border-gray-200 last:border-0 rounded-md hover:bg-gray-50">
            <div className="h-14 w-14 md:h-16 md:w-16 flex-shrink-0 bg-gray-100 rounded overflow-hidden relative">
              <img
                src={(item.product?.images && item.product.images.length > 0)
                  ? item.product.images[0].src
                  : '/placeholder.jpg'}
                alt={item.product?.name || t('orderSummary.benefitAlt')}
                className="h-full w-full object-cover"
              />
              {productMembershipLevels[`${item.id}-${item.variation_id || 'none'}`] > 0 && (
                <div className="absolute top-1 left-1">
                  <MembershipBadge 
                    level={productMembershipLevels[`${item.id}-${item.variation_id || 'none'}`]} 
                    size="xs" 
                  />
                </div>
              )}
            </div>
            <div className="ml-4 flex-1">
              <h3 className="text-sm font-medium text-gray-800">{item.product?.name || t('orderSummary.benefitAlt')}</h3>
              {item.variation_id && (item as any).variation?.attributes && (item as any).variation.attributes.length > 0 && (
                <div className="mt-0.5">
                  <span className="inline-block text-2xs text-gray-700 bg-gray-100 border border-gray-200 rounded-full px-2 py-0.5">
                    {(() => {
                      try {
                        const values = ((item as any).variation.attributes as any[])
                          .map((a: any) => a?.option)
                          .filter((v: any) => !!v)
                          .join(' · ');
                        return t('orderSummary.variation', { values });
                      } catch {
                        return '';
                      }
                    })()}
                  </span>
                </div>
              )}
              <div className="flex items-center gap-1 text-xs text-gray-500 mt-1">
                {(() => {
                  const unitPrice = parseFloat((item.variation_id && (item as any).variation?.price) ? (item as any).variation.price : (item.product?.price || '0'));
                  return (
                    <>
                      <span>{t('orderSummary.quantity', { qty: item.quantity })} </span>
                      <VirtualCoinPrice amount={unitPrice} size="xs" showLabel={false} />
                    </>
                  );
                })()}
              </div>
            </div>
            <div className="text-right">
              <div className="text-sm font-medium text-gray-800">
                {(() => {
                  const unitPrice = parseFloat((item.variation_id && (item as any).variation?.price) ? (item as any).variation.price : (item.product?.price || '0'));
                  return (
                    <VirtualCoinPrice amount={unitPrice * item.quantity} size="sm" showLabel={false} />
                  );
                })()}
              </div>
            </div>
          </div>
        ))}
      </div>

      <div className="border-t border-gray-200 pt-4">
        <div className="flex justify-between mb-2">
          <span className="text-sm text-gray-600">{t('orderSummary.subtotal')}</span>
          <VirtualCoinPrice amount={subtotal} size="sm" showLabel={false} className="text-gray-800 font-medium" />
        </div>

        {discount > 0 && (
          <div className="flex justify-between mb-2 text-green-600">
            <span className="text-sm">{t('orderSummary.discount')}</span>
            <div className="flex items-center">
              <span className="text-sm font-medium">-</span>
              <VirtualCoinPrice amount={discount} size="sm" showLabel={false} className="text-green-600 font-medium" />
            </div>
          </div>
        )}

        {/* Sección de Descuento por Membresía */}
        {isAuthenticated && membershipDiscount && (
          <div className="mb-3">
            <MembershipDiscountSection
              discount={membershipDiscount}
              loading={membershipDiscountLoading}
              membershipName={membershipName}
              membershipColor={membershipColor}
              categoryNames={membershipDiscount.eligibleCategoryNames}
            />
          </div>
        )}

        {/* Sección de Canje de Virtual Coins */}
        {isAuthenticated && VirtualCoinsEnabled && onPointsChange && onApplyPointsDiscount && onRemovePointsDiscount && onOpenHelpModal && getMaxPointsToUse && canApplyPointsDiscount && getPointsHelpMessage && (
          <div className="mb-3">
            <VirtualCoinsCollapsible
              userPoints={userPoints}
              systemConfig={systemConfig}
              pointsLoading={pointsLoading}
              pointsToUse={pointsToUse}
              appliedPointsDiscount={appliedPointsDiscount}
              hasAppliedDiscount={hasAppliedPointsDiscount}
              onPointsChange={onPointsChange}
              onApplyDiscount={onApplyPointsDiscount}
              onRemoveDiscount={onRemovePointsDiscount}
              onOpenHelpModal={onOpenHelpModal}
              getMaxPointsToUse={getMaxPointsToUse}
              canApplyDiscount={canApplyPointsDiscount}
              getHelpMessage={getPointsHelpMessage}
            />
          </div>
        )}

        <div className="flex justify-between mb-2">
          <span className="text-sm text-gray-600">{t('orderSummary.shipping')}</span>
          {useFreeDelivery ? (
            <span className="text-sm font-bold text-primario flex items-center gap-1">
              <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v13m0-13V6a2 2 0 112 2h-2zm0 0V5.5A2.5 2.5 0 109.5 8H12zm-7 4h14M5 12a2 2 0 110-4h14a2 2 0 110 4M5 12v7a2 2 0 002 2h10a2 2 0 002-2v-7" />
              </svg>
              {t('orderSummary.freeByMembership')}
            </span>
          ) : shippingPrice > 0 ? (
            <VirtualCoinPrice amount={shippingPrice} size="sm" showLabel={false} className="text-gray-800 font-medium" />
          ) : (
            <span className="text-sm font-medium text-gray-800">{t('orderSummary.toBeDefinied')}</span>
          )}
        </div>

        <div className="flex justify-between pt-2 border-t border-gray-200 mt-2">
          <span className="text-base font-medium text-gray-900">{t('orderSummary.total')}</span>
          <VirtualCoinPrice amount={ceilTo50COP(total - (appliedPointsDiscount || 0) + (useFreeDelivery ? 0 : shippingPrice))} size="md" showLabel={false} className="text-primario font-bold" />
        </div>
      </div>
    </CollapsibleSection>
  );
};

export default OrderSummary;
