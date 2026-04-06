import { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { gsap } from 'gsap';
import { useAuth } from '../contexts/AuthContext';
import { useCart } from '../contexts/CartContext';
import { useMembership } from '../contexts/MembershipContext';
import ProfileModal from '../components/profile/ProfileModal';
import HelpModal from '../components/help/HelpModal';
import { CheckoutDesktop, CheckoutMobile, CheckoutSuccess, CheckoutLoader } from '../components/checkout';
import { useCheckoutForm } from '../hooks/useCheckoutForm';
import { useCheckoutSubmit } from '../hooks/useCheckoutSubmit';
import { useShippingOptions } from '../hooks/useShippingOptions';
import { useMembershipDiscount } from '../hooks/useMembershipDiscount';
import { useCartAccessValidation } from '../hooks/useCartAccessValidation';
import { useCheckoutPoints } from '../hooks/useCheckoutPoints';
import AccessDeniedMessage from '../components/membership/AccessDeniedMessage';
import { useSEO } from '../hooks/useSEO';
import { getBaseUrl } from '../utils/seo';
import { useLanguage } from '../contexts/LanguageContext';
import { buildOrderData, validateCheckoutForm } from '../utils/checkoutHelpers';

const CheckoutPage = () => {
  const { t } = useTranslation('checkoutPage');
  const navigate = useNavigate();
  const { localizedPath } = useLanguage();

  // SEO: Página privada - noindex para evitar indexación
  useSEO({
    title: t('seo.title'),
    description: t('seo.description'),
    url: `${getBaseUrl()}/finalizar-retiro`,
    noIndex: true
  });

  const { user, isAuthenticated } = useAuth();
  const { items: cartItems, total, isLoading: cartLoading, minimumAmount, meetsMinimum, missingAmount, minimumProgress } = useCart();
  const { membershipName, currentLevel, freeDeliveries, freeSamples } = useMembership();
  
  // Hook para descuento de membresía
  const membershipDiscount = useMembershipDiscount(cartItems);

  // Hook para validar acceso a productos del carrito según membresía
  const cartAccessValidation = useCartAccessValidation(cartItems);

  // Hook para Virtual Coins
  const {
    userPoints,
    systemConfig,
    pointsToUse,
    pointsLoading,
    appliedPointsDiscount,
    appliedPointsAmount,
    setPointsToUse,
    handleApplyPointsDiscount,
    handleRemovePointsDiscount,
  } = useCheckoutPoints(isAuthenticated);

  // Determinar si el canje de Virtual Coins está habilitado desde la configuración del backend
  const VirtualCoinsEnabled = systemConfig?.configuration?.display_points_checkout ?? false;

  // El descuento base (cupón) + descuento de membresía
  const couponDiscount = 0;
  const subtotal = total;
  
  // Total con descuento de membresía aplicado
  const totalWithMembershipDiscount = total - membershipDiscount.totalDiscount;

  // Modales
  const [isProfileModalOpen, setIsProfileModalOpen] = useState(false);
  const [activeProfileSection, setActiveProfileSection] = useState<'profile' | 'addresses' | 'orders' | 'referrals'>('addresses');
  const [addressesInitialShowAddForm, setAddressesInitialShowAddForm] = useState(false);
  const [isHelpModalOpen, setIsHelpModalOpen] = useState(false);
  const [helpModalInitialTab, _setHelpModalInitialTab] = useState<'help' | 'howToRequest' | 'coinsSystem'>('help');
  
  // Estado del disclaimer obligatorio
  const [disclaimerAccepted, setDisclaimerAccepted] = useState(false);
  
  // Estado para usar entrega gratis
  const [useFreeDelivery, setUseFreeDelivery] = useState(false);

  // Estado de validación de cédula (true si ya tiene o si el input validó con éxito)
  const [documentIdValid, setDocumentIdValid] = useState(!!user?.documentId);

  // Callback para recibir el estado de validación del CedulaInput
  const handleDocumentIdValidChange = useCallback((isValid: boolean | null, isUnique: boolean | null) => {
    setDocumentIdValid(isValid === true && isUnique !== false);
  }, []);

  // Estado para la referencia del pago con tarjeta (para vincular con la orden)
  const [cardPaymentReference, setCardPaymentReference] = useState<string | undefined>(undefined);

  // Estado para indicar que el pago con tarjeta quedó PENDING (PSE, Nequi)
  const [cardPaymentPending, setCardPaymentPending] = useState(false);

  // Hooks personalizados
  const {
    formData,
    emptyFieldsOnLoad,
    selectedAddressId,
    isGift,
    handleInputChange,
    handlePhoneChange,
    handleDocumentIdChange,
    handleRecipientPhoneChange,
    handleAddressSelect,
    handleGiftToggle,
  } = useCheckoutForm();

  const {
    selectedShipping,
    shippingOptions,
    handleShippingChange,
    getShippingPrice,
    getShippingMethodId,
    getShippingMethodTitle,
  } = useShippingOptions();

  const {
    submitting,
    success,
    orderId,
    orderCreationFailed,
    handleSubmit,
  } = useCheckoutSubmit({
    formData,
    isGift,
    selectedAddressId,
    emptyFieldsOnLoad,
    appliedPointsDiscount,
    appliedPointsAmount,
    shippingMethodId: getShippingMethodId(),
    shippingMethodTitle: getShippingMethodTitle(),
    shippingPrice: getShippingPrice(),
    disclaimerAccepted,
    membershipDiscount: membershipDiscount.totalDiscount > 0 ? {
      totalDiscount: membershipDiscount.totalDiscount,
      discountPercentage: membershipDiscount.discountPercentage,
      membershipName: membershipName,
      membershipLevel: currentLevel,
      itemsWithDiscount: membershipDiscount.itemsWithDiscount,
      discountedItems: membershipDiscount.discountedItems.map(item => ({
        productId: item.productId,
        productName: item.productName,
        originalPrice: item.originalPrice,
        finalPrice: item.finalPrice,
        discountAmount: item.discountAmount,
        quantity: item.quantity
      }))
    } : undefined,
    useFreeDelivery,
    cardPaymentReference,
    cardPaymentPending,
    freeSamples,
  });

  // Funciones helper para Virtual Coins - DEBEN estar antes de cualquier early return
  const openHelpModal = useCallback(() => {
    setIsHelpModalOpen(true);
  }, []);

  const getMaxPointsToUse = useCallback(() => {
    if (!userPoints || !systemConfig?.configuration) return 0;
    const config = systemConfig.configuration;
    const availablePoints = userPoints.balance;
    const maxPerOrder = config.max_points_per_order || Infinity;
    
    // Calcular el máximo de puntos que equivalen al valor del pedido
    // para no dar un descuento mayor al total del pedido
    const conversionRate = config.points_conversion_rate || 1;
    const maxPointsForOrderValue = Math.floor(totalWithMembershipDiscount / conversionRate);
    
    return Math.min(availablePoints, maxPerOrder, maxPointsForOrderValue);
  }, [userPoints, systemConfig, totalWithMembershipDiscount]);

  const canApplyPointsDiscount = useCallback(() => {
    if (!userPoints || !systemConfig?.configuration) return false;
    const config = systemConfig.configuration;
    return pointsToUse >= config.min_points_redemption && pointsToUse <= getMaxPointsToUse();
  }, [userPoints, systemConfig, pointsToUse, getMaxPointsToUse]);

  const getPointsHelpMessage = useCallback(() => {
    if (!systemConfig?.configuration) return null;
    const config = systemConfig.configuration;
    if (pointsToUse < config.min_points_redemption) {
      return t('page.pointsMinHelp', { min: config.min_points_redemption });
    }
    if (pointsToUse > getMaxPointsToUse()) {
      return t('page.pointsMaxHelp', { max: getMaxPointsToUse() });
    }
    return null;
  }, [systemConfig, pointsToUse, getMaxPointsToUse, t]);

  const handlePointsChange = useCallback((points: number) => {
    setPointsToUse(points);
  }, [setPointsToUse]);

  const handleApplyDiscount = useCallback(() => {
    if (!systemConfig?.configuration) return;
    const config = systemConfig.configuration;
    const discount = pointsToUse * config.points_conversion_rate;
    handleApplyPointsDiscount(discount, pointsToUse);
  }, [systemConfig, pointsToUse, handleApplyPointsDiscount]);

  const handleRemoveDiscount = useCallback(() => {
    handleRemovePointsDiscount();
  }, [handleRemovePointsDiscount]);

  // Callback para construir los datos del pedido WC como backup server-side.
  // Se invoca desde CheckoutPaymentSection ANTES de abrir el widget de Wompi,
  // para que el backend pueda crear la orden si el frontend falla después del pago.
  const handleBuildOrderDataForBackup = useCallback((): Record<string, any> | null => {
    if (!cartItems.length || !user) return null;

    const shippingPrice = useFreeDelivery ? 0 : getShippingPrice();
    const shippingMethodTitle = useFreeDelivery ? 'Envío gratis por membresía' : getShippingMethodTitle();
    const cartSubtotal = cartItems.reduce((sum, item) => {
      const price = parseFloat((item.variation_id && (item as any).variation?.price)
        ? (item as any).variation.price
        : (item.product?.price || '0'));
      return sum + (price * item.quantity);
    }, 0);
    const membershipDiscountAmount = membershipDiscount.totalDiscount;
    const cartTotal = cartSubtotal - membershipDiscountAmount;

    const mdData = membershipDiscount.totalDiscount > 0 ? {
      totalDiscount: membershipDiscount.totalDiscount,
      discountPercentage: membershipDiscount.discountPercentage,
      membershipName: membershipName,
      membershipLevel: currentLevel,
      itemsWithDiscount: membershipDiscount.itemsWithDiscount,
      discountedItems: membershipDiscount.discountedItems.map(item => ({
        productId: item.productId,
        productName: item.productName,
        originalPrice: item.originalPrice,
        finalPrice: item.finalPrice,
        discountAmount: item.discountAmount,
        quantity: item.quantity
      }))
    } : undefined;

    return buildOrderData(
      formData,
      isGift,
      cartItems,
      user,
      isAuthenticated,
      selectedAddressId,
      appliedPointsDiscount,
      appliedPointsAmount,
      getShippingMethodId(),
      shippingMethodTitle,
      shippingPrice,
      disclaimerAccepted,
      mdData,
      cartTotal,
      useFreeDelivery,
      freeSamples,
      false,
      undefined
    ) as Record<string, any>;
  }, [
    cartItems, user, isAuthenticated, formData, isGift, selectedAddressId,
    appliedPointsDiscount, appliedPointsAmount, disclaimerAccepted,
    membershipDiscount, membershipName, currentLevel, useFreeDelivery,
    freeSamples, getShippingPrice, getShippingMethodId, getShippingMethodTitle,
  ]);

  // Callback de validación pre-pago: valida formulario y dirección ANTES de cobrar.
  // Retorna null si todo está correcto, o un string con el mensaje de error.
  // Esto previene el escenario donde el usuario paga con tarjeta pero la orden
  // se crea sin dirección (bug de órdenes sin dirección en pagos con tarjeta).
  const handleValidateBeforePayment = useCallback((): string | null => {
    const cartSubtotal = cartItems.reduce((sum, item) => {
      const price = parseFloat((item.variation_id && (item as any).variation?.price)
        ? (item as any).variation.price
        : (item.product?.price || '0'));
      return sum + (price * item.quantity);
    }, 0);
    const membershipDiscountAmount = membershipDiscount.totalDiscount;
    const cartTotal = cartSubtotal - membershipDiscountAmount;
    const isPaidWithCoins = appliedPointsDiscount > 0 && cartTotal <= appliedPointsDiscount;

    const validation = validateCheckoutForm(formData, isAuthenticated, selectedAddressId, isGift, isPaidWithCoins);
    return validation.isValid ? null : validation.error;
  }, [formData, isAuthenticated, selectedAddressId, isGift, cartItems, membershipDiscount.totalDiscount, appliedPointsDiscount]);

  // Animaciones con GSAP
  useEffect(() => {
    if (!cartLoading) {
      // Pequeño delay para asegurar que el DOM esté listo
      const timer = setTimeout(() => {
        const checkoutElements = document.querySelectorAll('.checkout-animate');
        if (checkoutElements.length > 0) {
          gsap.fromTo(
            checkoutElements,
            { opacity: 0, y: 20 },
            {
              opacity: 1,
              y: 0,
              duration: 0.5,
              stagger: 0.1,
              ease: 'power2.out'
            }
          );
        }
      }, 50);
      return () => clearTimeout(timer);
    }
  }, [cartLoading]);

  // ============ EARLY RETURNS (después de todos los hooks) ============

  // Si no hay sesión, mostrar mensaje de acceso denegado
  if (!isAuthenticated) {
    return (
      <div className="container mx-auto px-4 py-8">
        <AccessDeniedMessage
          title={t('accessDenied.title')}
          reason={t('accessDenied.reason')}
          description={t('accessDenied.description')}
          showCatalogButton={true}
          catalogButtonText={t('accessDenied.catalogButton')}
          catalogButtonPath="/reserva"
          showMembershipButton={true}
          membershipButtonText={t('accessDenied.membershipButton')}
          membershipButtonPath="/iniciar-sesion"
        />
      </div>
    );
  }

  if (cartLoading) {
    return <CheckoutLoader />;
  }

  if (success) {
    return (
      <CheckoutSuccess
        orderId={orderId}
        cardPaymentPending={cardPaymentPending}
        cardPaymentReference={cardPaymentReference}
        orderCreationFailed={orderCreationFailed}
      />
    );
  }

  // Si el carrito está vacío y no es una compra exitosa, mostrar mensaje
  if (cartItems.length === 0) {
    return (
      <div className="container mx-auto px-3 md:px-4 py-6 md:py-16">
        <div className="max-w-2xl mx-auto">
          <div className="bg-white rounded-lg shadow-md p-8 text-center">
            <div className="inline-flex items-center justify-center w-20 h-20 bg-yellow-100 rounded-full mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" className="h-10 w-10 text-yellow-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
              </svg>
            </div>
            <h2 className="text-2xl font-bold text-gray-900 mb-2">{t('emptyCart.title')}</h2>
            <p className="text-gray-600 mb-6">{t('emptyCart.description')}</p>
            <button
              onClick={() => navigate(localizedPath('/catalogo'))}
              className="bg-primario text-white py-3 px-8 rounded-md hover:bg-hover transition-colors font-medium inline-flex items-center"
            >
              <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
              </svg>
              {t('emptyCart.goToCatalog')}
            </button>
          </div>
        </div>
      </div>
    );
  }

    // Si hay productos restringidos por membresía, mostrar mensaje de error
  if (!cartAccessValidation.isValid && cartAccessValidation.restrictedProducts.length > 0) {
    return (
      <div className="container mx-auto px-4 py-8">
        <div className="max-w-2xl mx-auto">
          <div className="bg-white rounded-lg shadow-md p-8">
            <div className="flex items-center justify-center w-16 h-16 bg-red-100 rounded-full mx-auto mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" className="h-8 w-8 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
              </svg>
            </div>
            <h2 className="text-xl font-bold text-gray-900 text-center mb-2">{t('restrictedProducts.title')}</h2>
            <p className="text-gray-600 text-center mb-6">
              {t('restrictedProducts.description', { icon: cartAccessValidation.currentLevelIcon, name: cartAccessValidation.currentLevelName })}
            </p>
            <div className="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
              <h3 className="font-semibold text-red-800 mb-3">{t('restrictedProducts.listTitle')}</h3>
              <ul className="space-y-2">
                {cartAccessValidation.restrictedProducts.map((item, index) => (
                  <li key={index} className="flex items-start text-sm text-red-700">
                    <span className="mr-2">•</span>
                    <span>
                      <strong>{item.productName}</strong>
                      <span className="text-red-600 ml-1">({t('restrictedProducts.requires', { icon: item.requiredLevelIcon, name: item.requiredLevelName })})</span>
                    </span>
                  </li>
                ))}
              </ul>
            </div>
            <div className="flex flex-col sm:flex-row gap-3 justify-center">
              <button
                onClick={() => navigate(localizedPath('/reserva'))}
                className="px-6 py-3 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition-colors font-medium"
              >
                {t('restrictedProducts.editSelection')}
              </button>
              <button
                onClick={() => navigate(localizedPath('/membresias'))}
                className="px-6 py-3 bg-primario text-white rounded-md hover:bg-hover transition-colors font-medium"
              >
                {t('restrictedProducts.upgradeMembership')}
              </button>
            </div>
          </div>
        </div>
      </div>
    );
  }

  // ============ FUNCIONES HELPER (después de early returns) ============

  const closeProfileModal = () => {
    setIsProfileModalOpen(false);
    setAddressesInitialShowAddForm(false);
  };

  const closeHelpModal = () => {
    setIsHelpModalOpen(false);
  };

  const openProfileModalProfile = () => {
    setActiveProfileSection('profile');
    setAddressesInitialShowAddForm(false);
    setIsProfileModalOpen(true);
  };

  // Wrapper para llamar handleSubmit sin evento (usado por CheckoutPaymentSection)
  const submitCheckout = () => {
    handleSubmit({ preventDefault: () => {} } as React.FormEvent);
  };

  return (
    <div className="container mx-auto px-3 md:px-4 py-6 md:py-16">
      <h1 className="text-2xl md:text-3xl font-bold text-primario mb-4 md:mb-8">{t('page.title')}</h1>

      {/* Desktop: Layout masonry */}
      <div className="hidden lg:block">
        <CheckoutDesktop
          isAuthenticated={isAuthenticated}
          user={user}
          formData={formData}
          isGift={isGift}
          selectedAddressId={selectedAddressId}
          emptyFieldsOnLoad={emptyFieldsOnLoad || undefined}
          submitting={submitting}
          disclaimerAccepted={disclaimerAccepted}
          onDisclaimerChange={setDisclaimerAccepted}
          cartItems={cartItems}
          subtotal={subtotal}
          discount={couponDiscount}
          total={totalWithMembershipDiscount}
          membershipDiscount={membershipDiscount}
          membershipDiscountLoading={membershipDiscount.loading}
          minimumAmount={minimumAmount}
          meetsMinimum={meetsMinimum}
          missingAmount={missingAmount}
          minimumProgress={minimumProgress}
          onGiftToggle={handleGiftToggle}
          onInputChange={handleInputChange}
          onPhoneChange={handlePhoneChange}
          onDocumentIdChange={handleDocumentIdChange}
          onDocumentIdValidChange={handleDocumentIdValidChange}
          documentIdValid={documentIdValid}
          onRecipientPhoneChange={handleRecipientPhoneChange}
          onAddressSelect={handleAddressSelect}
          onOpenProfileModal={openProfileModalProfile}
          onOpenAddressModal={() => {
            setActiveProfileSection('addresses');
            setAddressesInitialShowAddForm(true);
            setIsProfileModalOpen(true);
          }}
          onSubmit={handleSubmit}
          onSubmitCheckout={submitCheckout}
          onCardPaymentReferenceChange={setCardPaymentReference}
          onCardPaymentPendingChange={setCardPaymentPending}
          buildOrderDataForBackup={handleBuildOrderDataForBackup}
          validateBeforePayment={handleValidateBeforePayment}
          selectedShipping={selectedShipping}
          shippingOptions={shippingOptions}
          onShippingChange={handleShippingChange}
          freeDeliveries={freeDeliveries}
          useFreeDelivery={useFreeDelivery}
          onToggleFreeDelivery={setUseFreeDelivery}
          // Props de Virtual Coins
          VirtualCoinsEnabled={VirtualCoinsEnabled}
          userPoints={userPoints}
          systemConfig={systemConfig}
          pointsLoading={pointsLoading}
          pointsToUse={pointsToUse}
          appliedPointsDiscount={appliedPointsDiscount}
          hasAppliedPointsDiscount={appliedPointsAmount > 0}
          onPointsChange={handlePointsChange}
          onApplyPointsDiscount={handleApplyDiscount}
          onRemovePointsDiscount={handleRemoveDiscount}
          onOpenHelpModal={openHelpModal}
          getMaxPointsToUse={getMaxPointsToUse}
          canApplyPointsDiscount={canApplyPointsDiscount}
          getPointsHelpMessage={getPointsHelpMessage}
        />
      </div>

      {/* Mobile: Orden específico (Resumen → Opciones → Info → Pago) */}
      <div className="block lg:hidden">
        <CheckoutMobile
          isAuthenticated={isAuthenticated}
          user={user}
          formData={formData}
          isGift={isGift}
          selectedAddressId={selectedAddressId}
          emptyFieldsOnLoad={emptyFieldsOnLoad || undefined}
          submitting={submitting}
          disclaimerAccepted={disclaimerAccepted}
          onDisclaimerChange={setDisclaimerAccepted}
          cartItems={cartItems}
          subtotal={subtotal}
          discount={couponDiscount}
          total={totalWithMembershipDiscount}
          membershipDiscount={membershipDiscount}
          membershipDiscountLoading={membershipDiscount.loading}
          minimumAmount={minimumAmount}
          meetsMinimum={meetsMinimum}
          missingAmount={missingAmount}
          minimumProgress={minimumProgress}
          onGiftToggle={handleGiftToggle}
          onInputChange={handleInputChange}
          onPhoneChange={handlePhoneChange}
          onDocumentIdChange={handleDocumentIdChange}
          onDocumentIdValidChange={handleDocumentIdValidChange}
          documentIdValid={documentIdValid}
          onRecipientPhoneChange={handleRecipientPhoneChange}
          onAddressSelect={handleAddressSelect}
          onOpenProfileModal={openProfileModalProfile}
          onOpenAddressModal={() => {
            setActiveProfileSection('addresses');
            setAddressesInitialShowAddForm(true);
            setIsProfileModalOpen(true);
          }}
          onSubmit={handleSubmit}
          onSubmitCheckout={submitCheckout}
          onCardPaymentReferenceChange={setCardPaymentReference}
          onCardPaymentPendingChange={setCardPaymentPending}
          buildOrderDataForBackup={handleBuildOrderDataForBackup}
          validateBeforePayment={handleValidateBeforePayment}
          onShippingChange={handleShippingChange}
          selectedShipping={selectedShipping}
          shippingOptions={shippingOptions}
          freeDeliveries={freeDeliveries}
          useFreeDelivery={useFreeDelivery}
          onToggleFreeDelivery={setUseFreeDelivery}
          // Props de Virtual Coins
          VirtualCoinsEnabled={VirtualCoinsEnabled}
          userPoints={userPoints}
          systemConfig={systemConfig}
          pointsLoading={pointsLoading}
          pointsToUse={pointsToUse}
          appliedPointsDiscount={appliedPointsDiscount}
          hasAppliedPointsDiscount={appliedPointsAmount > 0}
          onPointsChange={handlePointsChange}
          onApplyPointsDiscount={handleApplyDiscount}
          onRemovePointsDiscount={handleRemoveDiscount}
          onOpenHelpModal={openHelpModal}
          getMaxPointsToUse={getMaxPointsToUse}
          canApplyPointsDiscount={canApplyPointsDiscount}
          getPointsHelpMessage={getPointsHelpMessage}
        />
      </div>

      {/* Modal de perfil */}
      {isAuthenticated && (
        <ProfileModal
          isOpen={isProfileModalOpen}
          onClose={closeProfileModal}
          activeSection={activeProfileSection}
          addressesInitialShowAddForm={addressesInitialShowAddForm}
        />
      )}

      {/* Modal de ayuda */}
      {isHelpModalOpen && (
        <HelpModal
          isOpen={isHelpModalOpen}
          onClose={closeHelpModal}
          initialTab={helpModalInitialTab}
        />
      )}
    </div>
  );
};

export default CheckoutPage;