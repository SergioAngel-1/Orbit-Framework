import { useState } from 'react';
import i18n from '../config/i18n';
import { useAuth } from '../contexts/AuthContext';
import { useCart } from '../contexts/CartContext';
import { useMembership } from '../contexts/MembershipContext';
import { orderService } from '../services/api';
import wompiService from '../services/wompiService';
import { logger } from '../utils/logger';
import alertService from '../services/alertService';
import { cacheManager } from '../services/query/cacheManager';
import { CheckoutFormData, EmptyFieldsOnLoad } from './useCheckoutForm';
import { 
  buildOrderData, 
  validateCheckoutForm, 
  getFieldsToUpdate,
  translateErrorMessage,
  MembershipDiscountData,
  FreeSamplesData
} from '../utils/checkoutHelpers';

interface UseCheckoutSubmitProps {
  formData: CheckoutFormData;
  isGift: boolean;
  selectedAddressId: number | null;
  emptyFieldsOnLoad: EmptyFieldsOnLoad | null;
  appliedPointsDiscount: number;
  appliedPointsAmount: number;
  shippingMethodId: string;
  shippingMethodTitle: string;
  shippingPrice: number;
  disclaimerAccepted: boolean;
  membershipDiscount?: MembershipDiscountData;
  useFreeDelivery?: boolean;
  /** Referencia del pago con tarjeta (para vincular con la orden) */
  cardPaymentReference?: string;
  /** Si el pago con tarjeta quedó PENDING (PSE, Nequi) — la orden se crea con estado wompi-verifying */
  cardPaymentPending?: boolean;
  /** Datos de muestras gratis del usuario (para trazabilidad) */
  freeSamples?: FreeSamplesData | null;
}

interface UseCheckoutSubmitReturn {
  submitting: boolean;
  error: string | null;
  success: boolean;
  orderId: number | null;
  orderCreationFailed: boolean;
  handleSubmit: (e: React.FormEvent) => Promise<void>;
}

export const useCheckoutSubmit = ({
  formData,
  isGift,
  selectedAddressId,
  emptyFieldsOnLoad,
  appliedPointsDiscount,
  appliedPointsAmount,
  shippingMethodId,
  shippingMethodTitle,
  shippingPrice,
  disclaimerAccepted,
  membershipDiscount,
  useFreeDelivery = false,
  cardPaymentReference,
  cardPaymentPending = false,
  freeSamples = null,
}: UseCheckoutSubmitProps): UseCheckoutSubmitReturn => {
  // Si usa entrega gratis, modificar título y precio
  const finalShippingTitle = useFreeDelivery ? i18n.t('checkoutComponents:shipping.freeByMembership') : shippingMethodTitle;
  const finalShippingPrice = useFreeDelivery ? 0 : shippingPrice;
  const { user, isAuthenticated, updateProfile } = useAuth();
  const { items: cartItems, clearCart } = useCart();
  const { refreshMembership } = useMembership();
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);
  const [orderId, setOrderId] = useState<number | null>(null);
  const [orderCreationFailed, setOrderCreationFailed] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (submitting) {
      logger.warn('useCheckoutSubmit', 'Se ignoró envío duplicado');
      return;
    }

    // Calcular el total del carrito
    const cartSubtotal = cartItems.reduce((sum, item) => {
      const price = parseFloat((item.variation_id && (item as any).variation?.price) 
        ? (item as any).variation.price 
        : (item.product?.price || '0'));
      return sum + (price * item.quantity);
    }, 0);
    const membershipDiscountAmount = membershipDiscount?.totalDiscount || 0;
    const cartTotal = cartSubtotal - membershipDiscountAmount;
    
    // Detectar si está pagado completamente con Virtual Coins
    const isPaidWithCoins = appliedPointsDiscount > 0 && cartTotal <= appliedPointsDiscount;

    // Validar formulario
    const validation = validateCheckoutForm(formData, isAuthenticated, selectedAddressId, isGift, isPaidWithCoins);
    if (!validation.isValid) {
      setError(validation.error);
      alertService.error(validation.error!);
      return;
    }

    setSubmitting(true);
    setError(null);

    try {
      // Construir datos del pedido
      const orderData = buildOrderData(
        formData,
        isGift,
        cartItems,
        user,
        isAuthenticated,
        selectedAddressId,
        appliedPointsDiscount,
        appliedPointsAmount,
        shippingMethodId,
        finalShippingTitle,
        finalShippingPrice,
        disclaimerAccepted,
        membershipDiscount,
        cartTotal,
        useFreeDelivery,
        freeSamples,
        cardPaymentPending,
        cardPaymentReference
      );

      logger.info('useCheckoutSubmit', 'Enviando pedido a WooCommerce:', orderData);

      // Si hay pago con tarjeta, verificar si el processor ya creó una orden backup
      // (esto ocurre cuando el backend procesó el pago y creó la orden server-side).
      // Si existe, saltar la creación de orden para evitar duplicados.
      let newOrderId: number = 0;
      let isBackupOrder = false;

      if (cardPaymentReference && formData.paymentMethod === 'card') {
        try {
          const statusResult = await wompiService.getCheckoutCardPaymentStatus(cardPaymentReference);
          if (statusResult.success && statusResult.data?.order_id) {
            newOrderId = statusResult.data.order_id;
            isBackupOrder = true;
            logger.info('useCheckoutSubmit', 'Orden backup detectada del servidor, saltando createOrder y linkOrder', {
              reference: cardPaymentReference,
              orderId: newOrderId,
            });
          }
        } catch (statusError) {
          logger.warn('useCheckoutSubmit', 'No se pudo verificar orden backup, se creará normalmente:', statusError);
        }
      }

      if (!newOrderId) {
        // Enviar el pedido a WooCommerce.
        // Si hay pago con tarjeta ya cobrado, reintentar hasta 3 veces para evitar
        // el escenario de "cobrado sin orden" por fallos transitorios de red.
        const maxOrderAttempts = cardPaymentReference ? 3 : 1;
        let response: any = null;
        for (let orderAttempt = 1; orderAttempt <= maxOrderAttempts; orderAttempt++) {
          try {
            response = await orderService.createOrder(orderData);
            break;
          } catch (orderError: any) {
            logger.error('useCheckoutSubmit', `Error al crear orden (intento ${orderAttempt}/${maxOrderAttempts}):`, orderError);
            if (orderAttempt >= maxOrderAttempts) {
              throw orderError;
            }
            logger.info('useCheckoutSubmit', `Reintentando creación de orden en 2s (pago con tarjeta ya cobrado)...`);
            await new Promise(resolve => setTimeout(resolve, 2000));
          }
        }
        logger.info('useCheckoutSubmit', 'Respuesta de WooCommerce:', response.data);
        newOrderId = response.data.id;
      }

      setOrderId(newOrderId);
      
      // Si fue pago con tarjeta y la orden NO viene del backup server-side,
      // vincular la orden creada por el frontend con el registro de pago.
      // Si es backup, el processor ya vinculó el order_id — no hace falta.
      if (cardPaymentReference && formData.paymentMethod === 'card' && !isBackupOrder) {
        const maxLinkAttempts = 3;
        let linkSuccess = false;
        for (let attempt = 1; attempt <= maxLinkAttempts; attempt++) {
          try {
            logger.info('useCheckoutSubmit', `Vinculando orden con pago de tarjeta (intento ${attempt}/${maxLinkAttempts})`, {
              reference: cardPaymentReference,
              orderId: newOrderId,
            });
            await wompiService.linkOrderToCardPayment(cardPaymentReference, newOrderId);
            linkSuccess = true;
            break;
          } catch (linkError) {
            logger.warn('useCheckoutSubmit', `Error al vincular orden (intento ${attempt}/${maxLinkAttempts}):`, linkError);
            if (attempt < maxLinkAttempts) {
              await new Promise(resolve => setTimeout(resolve, 1000));
            }
          }
        }
        if (!linkSuccess) {
          logger.error('useCheckoutSubmit', `ALERTA: No se pudo vincular la orden con el pago después de ${maxLinkAttempts} intentos. Ref: ${cardPaymentReference}, OrderID: ${newOrderId}`);
        }
      }
      
      // Mostrar mensaje de éxito ANTES de cualquier acción que cause re-renders
      // (updateProfile llama setLoading(true) en AuthContext, lo cual re-renderiza
      // CheckoutPage y puede mostrar el carrito vacío antes de que success sea true)
      setSuccess(true);
      
      // Invalidar caché de órdenes para que OrdersSection muestre el nuevo pedido
      cacheManager.invalidateByType('order');

      // Refrescar datos de membresía para actualizar beneficios (free_samples, etc.)
      refreshMembership().catch(() => {});
      
      // Limpiar el carrito después de establecer success
      clearCart().catch(() => {});

      // Actualizar perfil del usuario si se completaron campos vacíos
      // (fire-and-forget para no bloquear ni causar re-renders antes del success)
      if (isAuthenticated && user) {
        const { fieldsToUpdate, hasFieldsToUpdate } = getFieldsToUpdate(emptyFieldsOnLoad, formData);
        
        if (hasFieldsToUpdate) {
          updateProfile(fieldsToUpdate)
            .then(() => logger.info('useCheckoutSubmit', 'Perfil actualizado exitosamente'))
            .catch((profileError) => logger.error('useCheckoutSubmit', 'Error al actualizar el perfil:', profileError));
        }
      }

    } catch (error: any) {
      logger.error('useCheckoutSubmit', 'Error al crear el pedido:', error);
      
      // Extraer mensaje de error específico
      let errorMessage = i18n.t('checkoutComponents:errors.orderProcessingError');
      
      if (error?.response?.data?.message) {
        errorMessage = error.response.data.message;
      } else if (error?.response?.data?.data?.message) {
        errorMessage = error.response.data.data.message;
      } else if (error?.message) {
        errorMessage = error.message;
      }
      
      // Traducir errores comunes
      errorMessage = translateErrorMessage(errorMessage);
      
      // Si el pago con tarjeta ya fue cobrado pero la orden no se pudo crear,
      // mostrar CheckoutSuccess con estado de error en vez del formulario.
      if (cardPaymentReference) {
        setOrderCreationFailed(true);
        setSuccess(true);
      }

      setError(errorMessage);
      alertService.error(errorMessage);
    } finally {
      setSubmitting(false);
    }
  };

  return {
    submitting,
    error,
    success,
    orderId,
    orderCreationFailed,
    handleSubmit,
  };
};
