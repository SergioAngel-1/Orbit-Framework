import React from 'react';
import { ShippingOption, ShippingOptionType } from '../../hooks/useShippingOptions';
import { CartItem } from '../../types/woocommerce';
import { CheckoutFormSection, CheckoutPaymentSection, OrderSummary, ShippingOptions } from './index';
import { MembershipDiscountSummary } from '../../hooks/useMembershipDiscount';
import { FreeDeliveriesData } from '../../services/membership/membershipTypes';

interface CheckoutMobileProps {
  // Form props
  isAuthenticated: boolean;
  user: any;
  formData: any;
  isGift: boolean;
  selectedAddressId: number | null;
  emptyFieldsOnLoad?: { firstName: boolean; lastName: boolean; phone: boolean; documentId: boolean } | undefined;
  submitting: boolean;
  disclaimerAccepted: boolean;
  onDisclaimerChange: (accepted: boolean) => void;
  
  // Cart props
  cartItems: CartItem[];
  subtotal: number;
  discount: number;
  total: number;
  
  // Minimum order props
  minimumAmount: number;
  meetsMinimum: boolean;
  missingAmount: number;
  minimumProgress: number;
  
  // Membership discount props
  membershipDiscount?: MembershipDiscountSummary;
  membershipDiscountLoading?: boolean;
  
  // Card payment props
  orderReference?: string;
  onSubmitCheckout?: () => void;
  onCardPaymentReferenceChange?: (reference: string) => void;
  onCardPaymentPendingChange?: (pending: boolean) => void;
  buildOrderDataForBackup?: () => Record<string, any> | null;
  /** Callback de validación pre-pago: retorna null si válido, o string con mensaje de error */
  validateBeforePayment?: () => string | null;
  
  // Handlers
  onGiftToggle: (e: React.ChangeEvent<HTMLInputElement>) => void;
  onInputChange: (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => void;
  onPhoneChange: (value: string) => void;
  onDocumentIdChange: (value: string) => void;
  onDocumentIdValidChange: (isValid: boolean | null, isUnique: boolean | null) => void;
  documentIdValid: boolean;
  onRecipientPhoneChange: (value: string) => void;
  onAddressSelect: (addressId: number) => void;
  onOpenProfileModal: () => void;
  onOpenAddressModal: () => void;
  onSubmit: (e: React.FormEvent) => void;
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
 * Layout de checkout para mobile
 * Orden específico: Resumen → Opciones de envío → Info contacto → Método de pago
 */
const CheckoutMobile: React.FC<CheckoutMobileProps> = ({
  isAuthenticated,
  user,
  formData,
  isGift,
  selectedAddressId,
  emptyFieldsOnLoad,
  submitting,
  disclaimerAccepted,
  onDisclaimerChange,
  cartItems,
  subtotal,
  discount,
  total,
  minimumAmount,
  meetsMinimum,
  missingAmount,
  minimumProgress,
  membershipDiscount,
  membershipDiscountLoading = false,
  orderReference,
  onSubmitCheckout,
  onCardPaymentReferenceChange,
  onCardPaymentPendingChange,
  buildOrderDataForBackup,
  validateBeforePayment,
  onGiftToggle,
  onInputChange,
  onPhoneChange,
  onDocumentIdChange,
  onDocumentIdValidChange,
  documentIdValid,
  onRecipientPhoneChange,
  onAddressSelect,
  onOpenProfileModal,
  onOpenAddressModal,
  onSubmit,
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
  // Calcular precio de envío efectivo para incluirlo en el monto total del pago
  const selectedOption = shippingOptions.find(opt => opt.id === selectedShipping);
  const effectiveShippingPrice = useFreeDelivery ? 0 : (selectedOption?.price || 0);
  const isPremiumShipping = selectedShipping === 'express' || selectedShipping === 'fast';

  return (
    <div className="flex flex-col gap-4 md:gap-8">
      {/* 1. Información de contacto y dirección */}
      <CheckoutFormSection
        isAuthenticated={isAuthenticated}
        user={user}
        formData={formData}
        isGift={isGift}
        selectedAddressId={selectedAddressId}
        emptyFieldsOnLoad={emptyFieldsOnLoad}
        onGiftToggle={onGiftToggle}
        onInputChange={onInputChange}
        onPhoneChange={onPhoneChange}
        onDocumentIdChange={onDocumentIdChange}
        onDocumentIdValidChange={onDocumentIdValidChange}
        onRecipientPhoneChange={onRecipientPhoneChange}
        onAddressSelect={onAddressSelect}
        onOpenProfileModal={onOpenProfileModal}
        onOpenAddressModal={onOpenAddressModal}
        onSubmit={onSubmit}
      />
      
      {/* 2. Opciones de envío */}
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
      
      {/* 3. Resumen del pedido */}
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
      
      {/* 4. Método de pago */}
      <CheckoutPaymentSection
        paymentMethod={formData.paymentMethod}
        submitting={submitting}
        disclaimerAccepted={disclaimerAccepted}
        onDisclaimerChange={onDisclaimerChange}
        onInputChange={onInputChange}
        totalAmount={total + effectiveShippingPrice}
        appliedPointsDiscount={appliedPointsDiscount}
        orderReference={orderReference}
        onSubmitCheckout={onSubmitCheckout}
        onCardPaymentReferenceChange={onCardPaymentReferenceChange}
        onCardPaymentPendingChange={onCardPaymentPendingChange}
        documentIdMissing={!documentIdValid}
        buildOrderDataForBackup={buildOrderDataForBackup}
        validateBeforePayment={validateBeforePayment}
        isPremiumShipping={isPremiumShipping}
      />
    </div>
  );
};

export default CheckoutMobile;
