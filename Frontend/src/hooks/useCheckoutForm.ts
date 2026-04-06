import { useState, useEffect, useRef } from 'react';
import { useAuth } from '../contexts/AuthContext';
import { logger } from '../utils/logger';

export interface CheckoutFormData {
  firstName: string;
  lastName: string;
  email: string;
  phone: string;
  documentId: string;
  address: string;
  city: string;
  state: string;
  postalCode: string;
  paymentMethod: string;
  recipientFirstName: string;
  recipientLastName: string;
  recipientPhone: string;
}

export interface EmptyFieldsOnLoad {
  firstName: boolean;
  lastName: boolean;
  phone: boolean;
  documentId: boolean;
}

interface UseCheckoutFormReturn {
  formData: CheckoutFormData;
  setFormData: React.Dispatch<React.SetStateAction<CheckoutFormData>>;
  emptyFieldsOnLoad: EmptyFieldsOnLoad | null;
  selectedAddressId: number | null;
  setSelectedAddressId: React.Dispatch<React.SetStateAction<number | null>>;
  isGift: boolean;
  setIsGift: React.Dispatch<React.SetStateAction<boolean>>;
  handleInputChange: (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => void;
  handlePhoneChange: (value: string) => void;
  handleDocumentIdChange: (value: string) => void;
  handleRecipientPhoneChange: (value: string) => void;
  handleAddressSelect: (addressId: number) => void;
  handleGiftToggle: (e: React.ChangeEvent<HTMLInputElement>) => void;
}

const initialFormData: CheckoutFormData = {
  firstName: '',
  lastName: '',
  email: '',
  phone: '',
  documentId: '',
  address: '',
  city: '',
  state: '',
  postalCode: '',
  paymentMethod: 'bank',
  recipientFirstName: '',
  recipientLastName: '',
  recipientPhone: '',
};

export const useCheckoutForm = (): UseCheckoutFormReturn => {
  const { user, isAuthenticated, getCurrentUser } = useAuth();
  const [formData, setFormData] = useState<CheckoutFormData>(initialFormData);
  const [selectedAddressId, setSelectedAddressId] = useState<number | null>(null);
  const [isGift, setIsGift] = useState(false);
  const [emptyFieldsOnLoad, setEmptyFieldsOnLoad] = useState<EmptyFieldsOnLoad | null>(null);
  const hasInitializedForm = useRef(false);

  // Asegurar que paymentMethod siempre tenga un valor por defecto
  useEffect(() => {
    if (!formData.paymentMethod) {
      logger.warn('useCheckoutForm', 'paymentMethod está vacío, estableciendo valor por defecto');
      setFormData(prev => ({
        ...prev,
        paymentMethod: 'bank'
      }));
    }
  }, [formData.paymentMethod]);

  // Cargar datos del usuario si está autenticado
  useEffect(() => {
    const hydrateFromUser = async () => {
      try {
        if (!isAuthenticated) {
          return;
        }

        // Solo hacer fetch si no tenemos el usuario o no tiene email (dato mínimo requerido)
        const needsFetch = !user || !user.email;
        if (needsFetch && typeof getCurrentUser === 'function') {
          await getCurrentUser();
          return; // Esperar a que user se actualice antes de hidratar el formulario
        }

        if (!user || hasInitializedForm.current) {
          return;
        }

        logger.info('useCheckoutForm', 'Email del usuario:', user.email);

        const userEmail = user.email || '';
        const userFirstName = user.firstName || '';
        const userLastName = user.lastName || '';
        const userPhone = user.phone || '';
        const userDocumentId = user.documentId || '';

        setEmptyFieldsOnLoad({
          firstName: !userFirstName,
          lastName: !userLastName,
          phone: !userPhone,
          documentId: !userDocumentId,
        });

        setFormData(prev => ({
          ...prev,
          firstName: userFirstName,
          lastName: userLastName,
          email: userEmail,
          phone: userPhone,
          documentId: userDocumentId,
          paymentMethod: prev.paymentMethod || 'bank',
        }));

        if (user.defaultAddress) {
          setSelectedAddressId(user.defaultAddress.id);
          setFormData(prev => ({
            ...prev,
            address: user.defaultAddress?.address || '',
            city: user.defaultAddress?.city || '',
            state: user.defaultAddress?.state || '',
            postalCode: user.defaultAddress?.postalCode || '',
            paymentMethod: prev.paymentMethod || 'bank',
          }));
        } else if (user.addresses && user.addresses.length > 0) {
          setSelectedAddressId(user.addresses[0].id);
          setFormData(prev => ({
            ...prev,
            address: user.addresses[0].address || '',
            city: user.addresses[0].city || '',
            state: user.addresses[0].state || '',
            postalCode: user.addresses[0].postalCode || '',
            paymentMethod: prev.paymentMethod || 'bank',
          }));
        }

        hasInitializedForm.current = true;
      } catch (e) {
        logger.error('useCheckoutForm', 'Error al hidratar formulario', e);
      }
    };

    hydrateFromUser();
  }, [isAuthenticated, user, getCurrentUser]);

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => {
    const { name, value } = e.target;
    setFormData(prev => ({
      ...prev,
      [name]: value
    }));
  };

  const handlePhoneChange = (value: string) => {
    setFormData(prev => ({ ...prev, phone: value }));
  };

  const handleDocumentIdChange = (value: string) => {
    setFormData(prev => ({ ...prev, documentId: value }));
  };

  const handleRecipientPhoneChange = (value: string) => {
    setFormData(prev => ({ ...prev, recipientPhone: value }));
  };

  const handleAddressSelect = (addressId: number) => {
    setSelectedAddressId(addressId);
    const selectedAddress = user?.addresses.find(addr => addr.id === addressId);

    if (selectedAddress) {
      setFormData(prev => ({
        ...prev,
        address: selectedAddress.address,
        city: selectedAddress.city,
        state: selectedAddress.state,
        postalCode: selectedAddress.postalCode,
        paymentMethod: prev.paymentMethod || 'bank',
      }));
    }
  };

  const handleGiftToggle = (e: React.ChangeEvent<HTMLInputElement>) => {
    setIsGift(e.target.checked);

    if (!e.target.checked) {
      setFormData(prev => ({
        ...prev,
        recipientFirstName: '',
        recipientLastName: '',
        recipientPhone: '',
      }));
    }
  };

  return {
    formData,
    setFormData,
    emptyFieldsOnLoad,
    selectedAddressId,
    setSelectedAddressId,
    isGift,
    setIsGift,
    handleInputChange,
    handlePhoneChange,
    handleDocumentIdChange,
    handleRecipientPhoneChange,
    handleAddressSelect,
    handleGiftToggle,
  };
};
