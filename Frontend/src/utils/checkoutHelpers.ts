import { CartItem } from '../types/woocommerce';
import { User, Address } from '../contexts/types/auth.types';
import { CheckoutFormData, EmptyFieldsOnLoad } from '../hooks/useCheckoutForm';
import type { FreeSamplesData } from '../services/membership/membershipTypes';
import { ceilTo50COP } from './formatters';
import i18n from 'i18next';

export interface OrderData {
  payment_method: string;
  payment_method_title: string;
  set_paid: boolean;
  billing: {
    first_name: string;
    last_name: string;
    address_1: string;
    city: string;
    state: string;
    postcode: string;
    country: string;
    email: string;
    phone: string;
  };
  shipping: {
    first_name: string;
    last_name: string;
    address_1: string;
    city: string;
    state: string;
    postcode: string;
    country: string;
  };
  line_items: Array<{
    product_id: number;
    quantity: number;
    variation_id?: number;
  }>;
  customer_id: number;
  customer_note: string;
  fee_lines?: Array<{
    name: string;
    total: string;
    tax_status: string;
    tax_class: string;
  }>;
  shipping_lines: Array<{
    method_id: string;
    method_title: string;
    total: string;
  }>;
  meta_data: Array<{
    key: string;
    value: string;
  }>;
}

export const buildLineItems = (cartItems: CartItem[]) => {
  return cartItems.map(item => ({
    product_id: item.id,
    quantity: item.quantity,
    ...(item.variation_id && item.variation_id > 0 ? { variation_id: item.variation_id } : {})
  }));
};

export const buildAddressData = (
  formData: CheckoutFormData,
  isAuthenticated: boolean,
  selectedAddressId: number | null,
  user: User | null
) => {
  let addressData = {
    address_1: formData.address,
    city: formData.city,
    state: formData.state,
    postcode: formData.postalCode,
    country: 'CO'
  };
  
  if (isAuthenticated && selectedAddressId && user?.addresses) {
    const selectedAddress = user.addresses.find((addr: Address) => addr.id === selectedAddressId);
    if (selectedAddress) {
      addressData = {
        address_1: selectedAddress.address,
        city: selectedAddress.city,
        state: selectedAddress.state,
        postcode: selectedAddress.postalCode || '',
        country: 'CO'
      };
    }
  }

  return addressData;
};

export interface MembershipDiscountData {
  totalDiscount: number;
  discountPercentage: number;
  membershipName: string;
  membershipLevel: number;
  itemsWithDiscount: number;
  discountedItems: Array<{
    productId: number;
    productName: string;
    originalPrice: number;
    finalPrice: number;
    discountAmount: number;
    quantity: number;
  }>;
}

// Constante para el porcentaje de incremento por pago con tarjeta
export const CARD_PAYMENT_FEE_PERCENTAGE = 5;

// Re-exportar FreeSamplesData para mantener compatibilidad con imports existentes
export type { FreeSamplesData } from '../services/membership/membershipTypes';

export const buildOrderData = (
  formData: CheckoutFormData,
  isGift: boolean,
  cartItems: CartItem[],
  user: User | null,
  isAuthenticated: boolean,
  selectedAddressId: number | null,
  appliedPointsDiscount: number,
  appliedPointsAmount: number,
  shippingMethodId: string,
  shippingMethodTitle: string,
  shippingPrice: number,
  disclaimerAccepted: boolean = false,
  membershipDiscount?: MembershipDiscountData,
  /** Total del carrito (para calcular fee de tarjeta) */
  cartTotal?: number,
  /** Si el usuario eligió usar entrega gratis por membresía */
  useFreeDelivery: boolean = false,
  /** Datos de muestras gratis del usuario (para trazabilidad) */
  freeSamples?: FreeSamplesData | null,
  /** Si el pago con tarjeta quedó en PENDING (PSE, Nequi) — la orden se crea con estado wompi-verifying */
  cardPaymentPending: boolean = false,
  /** Referencia del pago con tarjeta (para vincular atómicamente con la orden al crearla) */
  cardPaymentReference?: string
): OrderData => {
  const line_items = buildLineItems(cartItems);
  const addressData = buildAddressData(formData, isAuthenticated, selectedAddressId, user);

  // Detectar si está pagado completamente con Virtual Coins
  const isPaidWithCoins = appliedPointsDiscount > 0 && 
    (cartItems.reduce((sum, item) => {
      const price = parseFloat((item.variation_id && (item as any).variation?.price) 
        ? (item as any).variation.price 
        : (item.product?.price || '0'));
      return sum + (price * item.quantity);
    }, 0) - (membershipDiscount?.totalDiscount || 0)) <= appliedPointsDiscount;
  
  // Mapear método de pago
  let paymentMethodId = formData.paymentMethod;
  let paymentMethodTitle = i18n.t('checkoutComponents:orderData.paymentCash');
  
  if (isPaidWithCoins) {
    paymentMethodId = 'virtual_coins';
    paymentMethodTitle = i18n.t('checkoutComponents:orderData.paymentVirtualCoins');
  } else if (formData.paymentMethod === 'card') {
    paymentMethodId = 'wompi';
    paymentMethodTitle = 'Wompi';
  } else if (formData.paymentMethod === 'bank') {
    paymentMethodTitle = i18n.t('checkoutComponents:orderData.paymentBankTransfer');
  }

  return {
    payment_method: paymentMethodId,
    payment_method_title: paymentMethodTitle,
    set_paid: (formData.paymentMethod === 'card' && !cardPaymentPending) || isPaidWithCoins,
    ...(cardPaymentPending ? { status: 'wompi-verifying' } : {}),
    billing: {
      first_name: formData.firstName,
      last_name: formData.lastName,
      ...addressData,
      email: formData.email,
      phone: formData.phone,
    },
    shipping: {
      first_name: isGift ? (formData.recipientFirstName || formData.firstName) : formData.firstName,
      last_name: isGift ? (formData.recipientLastName || formData.lastName) : formData.lastName,
      ...addressData,
    },
    line_items,
    customer_id: user?.id || 0,
    customer_note: isGift ? i18n.t('checkoutComponents:orderData.giftNote') : "",
    fee_lines: [
      // Descuento por Virtual Coins (si aplica)
      ...(appliedPointsDiscount > 0 ? [{
        name: i18n.t('checkoutComponents:orderData.feeVirtualCoinsDiscount', { amount: appliedPointsAmount }),
        total: (-appliedPointsDiscount).toString(),
        tax_status: 'none',
        tax_class: ''
      }] : []),
      // Descuento por Membresía (si aplica)
      ...(membershipDiscount && membershipDiscount.totalDiscount > 0 ? [{
        name: i18n.t('checkoutComponents:orderData.feeMembershipDiscount', { percentage: membershipDiscount.discountPercentage, name: membershipDiscount.membershipName }),
        total: (-membershipDiscount.totalDiscount).toString(),
        tax_status: 'none',
        tax_class: ''
      }] : []),
      // Cargo por pago con tarjeta (5%) - solo si es pago con tarjeta Y NO está pagado con Virtual Coins
      // Fórmula alineada con CheckoutPaymentSection y backend (ceilTo50COP, incluye shipping, resta points)
      ...(formData.paymentMethod === 'card' && !isPaidWithCoins && cartTotal && cartTotal > 0 ? [{
        name: i18n.t('checkoutComponents:orderData.feeCardPayment', { percentage: CARD_PAYMENT_FEE_PERCENTAGE }),
        total: ceilTo50COP(ceilTo50COP(Math.max(0, cartTotal + shippingPrice - appliedPointsDiscount)) * (CARD_PAYMENT_FEE_PERCENTAGE / 100)).toString(),
        tax_status: 'none',
        tax_class: ''
      }] : [])
    ],
    shipping_lines: [
      {
        method_id: shippingMethodId,
        method_title: shippingMethodTitle,
        total: shippingPrice.toString(),
      }
    ],
    meta_data: [
      {
        key: '_is_gift',
        value: isGift ? 'yes' : 'no'
      },
      {
        key: '_recipient_phone',
        value: isGift ? formData.recipientPhone : ''
      },
      {
        key: '_shipping_method_id',
        value: shippingMethodId
      },
      {
        key: '_shipping_price',
        value: shippingPrice.toString()
      },
      {
        key: '_virtual_coins_used',
        value: appliedPointsAmount.toString()
      },
      {
        key: '_virtual_coins_discount',
        value: appliedPointsDiscount.toString()
      },
      {
        key: '_club_disclaimer_accepted',
        value: disclaimerAccepted ? 'yes' : 'no'
      },
      {
        key: '_club_disclaimer_date',
        value: disclaimerAccepted ? new Date().toISOString() : ''
      },
      // Descuento de membresía
      ...(membershipDiscount && membershipDiscount.totalDiscount > 0 ? [
        {
          key: '_membership_discount_total',
          value: membershipDiscount.totalDiscount.toString()
        },
        {
          key: '_membership_discount_percentage',
          value: membershipDiscount.discountPercentage.toString()
        },
        {
          key: '_membership_name',
          value: membershipDiscount.membershipName
        },
        {
          key: '_membership_level',
          value: membershipDiscount.membershipLevel.toString()
        },
        {
          key: '_membership_items_with_discount',
          value: membershipDiscount.itemsWithDiscount.toString()
        },
        {
          key: '_membership_discounted_items',
          value: JSON.stringify(membershipDiscount.discountedItems)
        }
      ] : []),
      // Pago con tarjeta: incluir referencia y flag en meta_data para que WooCommerce
      // los persista atómicamente al crear la orden, ANTES de que cualquier hook de
      // status (woocommerce_order_status_completed) dispare order-fc-transactions.php.
      // Esto evita la race condition donde set_paid=true dispara FC transactions duplicadas.
      ...(cardPaymentReference ? [
        {
          key: '_starter_card_payment_reference',
          value: cardPaymentReference
        },
        {
          key: '_starter_card_payment_pending',
          value: 'yes'
        }
      ] : []),
      // Entrega gratis por membresía
      {
        key: '_use_free_delivery_membership',
        value: useFreeDelivery ? 'yes' : 'no'
      },
      // Muestras gratis - elegibilidad al momento del checkout (para trazabilidad)
      // orders_until_next === 1: este pedido completará el ciclo
      // orders_until_next === 0: ya completó ciclo, muestra pendiente de procesar
      ...(freeSamples ? [
        {
          key: '_free_samples_eligible_frontend',
          value: (freeSamples.orders_until_next === 1 || freeSamples.orders_until_next === 0) && freeSamples.can_receive_more ? 'yes' : 'no'
        },
        {
          key: '_free_samples_grams_expected_frontend',
          value: (freeSamples.orders_until_next === 1 || freeSamples.orders_until_next === 0) && freeSamples.can_receive_more 
            ? Math.min(freeSamples.grams_per_delivery, freeSamples.grams_remaining).toString() 
            : '0'
        },
        {
          key: '_free_samples_orders_count_frontend',
          value: (freeSamples.orders_count ?? 0).toString()
        },
        {
          key: '_free_samples_orders_until_next_frontend',
          value: freeSamples.orders_until_next?.toString() ?? 'null'
        }
      ] : [])
    ]
  };
};

export const validateCheckoutForm = (
  formData: CheckoutFormData,
  isAuthenticated: boolean,
  selectedAddressId: number | null,
  isGift: boolean,
  isPaidWithCoins: boolean = false
): { isValid: boolean; error: string | null } => {
  // Si está pagado con Virtual Coins, no necesita método de pago tradicional
  const validPaymentMethods = ['bank', 'cash', 'card', 'virtual_coins'];
  if (!isPaidWithCoins && (!formData.paymentMethod || !validPaymentMethods.includes(formData.paymentMethod))) {
    return { isValid: false, error: i18n.t('checkoutComponents:validation.selectPaymentMethod') };
  }

  if (isAuthenticated) {
    if (!selectedAddressId) {
      return { isValid: false, error: i18n.t('checkoutComponents:validation.selectShippingAddress') };
    }
    
    if (!formData.firstName || !formData.lastName || !formData.email || !formData.phone || !formData.documentId) {
      return { isValid: false, error: i18n.t('checkoutComponents:validation.completeContactFields') };
    }
  } else {
    if (!formData.firstName || !formData.lastName || !formData.email || !formData.phone || !formData.address || !formData.city || !formData.state) {
      return { isValid: false, error: i18n.t('checkoutComponents:validation.completeRequiredFields') };
    }
  }

  if (isGift && (!formData.recipientFirstName || !formData.recipientLastName || !formData.recipientPhone)) {
    return { isValid: false, error: i18n.t('checkoutComponents:validation.completeRecipientFields') };
  }

  return { isValid: true, error: null };
};

export const getFieldsToUpdate = (
  emptyFieldsOnLoad: EmptyFieldsOnLoad | null,
  formData: CheckoutFormData
): { fieldsToUpdate: any; hasFieldsToUpdate: boolean } => {
  const fieldsToUpdate: any = {};
  let hasFieldsToUpdate = false;
  
  if (emptyFieldsOnLoad?.firstName && formData.firstName) {
    fieldsToUpdate.firstName = formData.firstName;
    hasFieldsToUpdate = true;
  }
  
  if (emptyFieldsOnLoad?.lastName && formData.lastName) {
    fieldsToUpdate.lastName = formData.lastName;
    hasFieldsToUpdate = true;
  }
  
  if (emptyFieldsOnLoad?.phone && formData.phone) {
    fieldsToUpdate.phone = formData.phone;
    hasFieldsToUpdate = true;
  }
  
  if (emptyFieldsOnLoad?.documentId && formData.documentId) {
    fieldsToUpdate.documentId = formData.documentId;
    hasFieldsToUpdate = true;
  }
  
  return { fieldsToUpdate, hasFieldsToUpdate };
};

export const translateErrorMessage = (errorMessage: string): string => {
  if (errorMessage.includes('Coupon') && errorMessage.includes('does not exist')) {
    return i18n.t('checkoutComponents:errors.couponNotExist');
  }
  
  if (errorMessage.includes('invalid_coupon')) {
    return i18n.t('checkoutComponents:errors.invalidCoupon');
  }
  
  return errorMessage;
};
