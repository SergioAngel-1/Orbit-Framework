import React from 'react';
import { OrderSummary, ShippingOptions } from './index';
import { CartItem } from '../../types/woocommerce';
import { ShippingOptionType, ShippingOption } from '../../hooks/useShippingOptions';
import { MembershipDiscountSummary } from '../../hooks/useMembershipDiscount';
import { FreeDeliveriesData } from '../../services/membership/membershipTypes';

interface CheckoutSidebarProps {
  // Props para OrderSummary
  cartItems: CartItem[];
  subtotal: number;
  discount: number;
  total: number;
  isAuthenticated: boolean;
  minimumAmount: number;
  meetsMinimum: boolean;
  missingAmount: number;
  minimumProgress: number;
  // Props para descuento de membresía
  membershipDiscount?: MembershipDiscountSummary;
  membershipDiscountLoading?: boolean;
  // Props para ShippingOptions
  selectedShipping: ShippingOptionType;
  shippingOptions: ShippingOption[];
  onShippingChange: (optionId: ShippingOptionType) => void;
  freeDeliveries?: FreeDeliveriesData | null;
  useFreeDelivery?: boolean;
  onToggleFreeDelivery?: (use: boolean) => void;
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

/**
 * Componente sidebar que agrupa el resumen del retiro y opciones de envío
 * Sticky en desktop para mejor UX
 */
const CheckoutSidebar: React.FC<CheckoutSidebarProps> = ({
  cartItems,
  subtotal,
  discount,
  total,
  isAuthenticated,
  minimumAmount,
  meetsMinimum,
  missingAmount,
  minimumProgress,
  membershipDiscount,
  membershipDiscountLoading = false,
  selectedShipping,
  shippingOptions,
  onShippingChange,
  freeDeliveries,
  useFreeDelivery = false,
  onToggleFreeDelivery,
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
  return (
    <div className="flex flex-col gap-4 md:gap-6">
        {/* Opciones de envío - Arriba en desktop */}
        <div className="checkout-animate">
          <ShippingOptions
            selectedShipping={selectedShipping}
            shippingOptions={shippingOptions}
            onShippingChange={onShippingChange}
            freeDeliveries={freeDeliveries}
            useFreeDelivery={useFreeDelivery}
            onToggleFreeDelivery={onToggleFreeDelivery}
          />
        </div>
        
        {/* Resumen del retiro */}
        <div className="checkout-animate">
          <OrderSummary 
            cartItems={cartItems}
            subtotal={subtotal}
            discount={discount}
            total={total}
            selectedShipping={selectedShipping}
            isAuthenticated={isAuthenticated}
            minimumAmount={minimumAmount}
            meetsMinimum={meetsMinimum}
            missingAmount={missingAmount}
            minimumProgress={minimumProgress}
            membershipDiscount={membershipDiscount}
            membershipDiscountLoading={membershipDiscountLoading}
            useFreeDelivery={useFreeDelivery}
            // Props de Virtual Coins
            VirtualCoinsEnabled={VirtualCoinsEnabled}
            userPoints={userPoints}
            systemConfig={systemConfig}
            pointsLoading={pointsLoading}
            pointsToUse={pointsToUse}
            appliedPointsDiscount={appliedPointsDiscount}
            hasAppliedPointsDiscount={hasAppliedPointsDiscount}
            onPointsChange={onPointsChange}
            onApplyPointsDiscount={onApplyPointsDiscount}
            onRemovePointsDiscount={onRemovePointsDiscount}
            onOpenHelpModal={onOpenHelpModal}
            getMaxPointsToUse={getMaxPointsToUse}
            canApplyPointsDiscount={canApplyPointsDiscount}
            getPointsHelpMessage={getPointsHelpMessage}
          />
        </div>
    </div>
  );
};

export default CheckoutSidebar;
