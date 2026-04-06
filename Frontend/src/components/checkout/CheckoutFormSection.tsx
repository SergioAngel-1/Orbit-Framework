import React from 'react';
import { ContactInformation, ShippingAddress } from './index';

interface CheckoutFormSectionProps {
  isAuthenticated: boolean;
  user: any;
  formData: any;
  isGift: boolean;
  selectedAddressId: number | null;
  emptyFieldsOnLoad?: { firstName: boolean; lastName: boolean; phone: boolean; documentId: boolean } | undefined;
  onGiftToggle: (e: React.ChangeEvent<HTMLInputElement>) => void;
  onInputChange: (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => void;
  onPhoneChange: (value: string) => void;
  onDocumentIdChange: (value: string) => void;
  onDocumentIdValidChange: (isValid: boolean | null, isUnique: boolean | null) => void;
  onRecipientPhoneChange: (value: string) => void;
  onAddressSelect: (addressId: number) => void;
  onOpenProfileModal: () => void;
  onOpenAddressModal: () => void;
  onSubmit: (e: React.FormEvent) => void;
}

/**
 * Componente que agrupa el formulario de contacto y dirección de envío
 */
const CheckoutFormSection: React.FC<CheckoutFormSectionProps> = ({
  isAuthenticated,
  user,
  formData,
  isGift,
  selectedAddressId,
  emptyFieldsOnLoad,
  onGiftToggle,
  onInputChange,
  onPhoneChange,
  onDocumentIdChange,
  onDocumentIdValidChange,
  onRecipientPhoneChange,
  onAddressSelect,
  onOpenProfileModal,
  onOpenAddressModal,
  onSubmit
}) => {
  return (
    <form id="checkout-form" onSubmit={onSubmit} className="checkout-animate flex flex-col gap-4">
      <ContactInformation
          isAuthenticated={isAuthenticated}
          user={user}
          formData={formData}
          isGift={isGift}
          onGiftToggle={onGiftToggle}
          onInputChange={onInputChange}
          initialEmptyFields={emptyFieldsOnLoad}
          onPhoneChange={onPhoneChange}
          onDocumentIdChange={onDocumentIdChange}
          onDocumentIdValidChange={onDocumentIdValidChange}
          onRecipientPhoneChange={onRecipientPhoneChange}
          onOpenProfileModal={onOpenProfileModal}
        />

        <ShippingAddress
          isAuthenticated={isAuthenticated}
          user={user}
          selectedAddressId={selectedAddressId}
          onAddressSelect={onAddressSelect}
          onOpenProfileModal={onOpenAddressModal}
        />
    </form>
  );
};

export default CheckoutFormSection;
