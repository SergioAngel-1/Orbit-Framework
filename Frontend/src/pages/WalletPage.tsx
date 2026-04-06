/**
 * WalletPage - Página dedicada para gestión de Virtual Coins
 * Reemplaza el modal anterior con una experiencia completa
 * Diseño alineado con ReferidosPage y MembershipsPage
 */

import { useTranslation } from 'react-i18next';
import { FiDollarSign, FiHelpCircle, FiStar, FiGift, FiUsers } from 'react-icons/fi';
import { useAuth } from '../contexts/AuthContext';
import { useMembership } from '../contexts/MembershipContext';
import { useWallet } from '../hooks/useWallet';
import { useTransfer } from '../hooks/useTransfer';
import {
  WalletBalance,
  WalletDisclaimer,
  WalletEmptyState,
  WalletHistory,
  WalletActionSelector,
  SendCoinsModal,
  BuyCoinsModal
} from '../components/wallet';
import AccessDeniedMessage from '../components/membership/AccessDeniedMessage';
import FallbackBanner from '../components/common/FallbackBanner';
import Loader from '../components/ui/Loader';
import HelpModal from '../components/help/HelpModal';
import { useState, useEffect } from 'react';
import { useLocation } from 'react-router-dom';
import { useSEO } from '../hooks/useSEO';
import { OG_IMAGES, getBaseUrl } from '../utils/seo';

const WalletPage = () => {
  const { t } = useTranslation('walletPage');

  // SEO: Página privada (requiere auth) - noIndex para evitar indexación de contenido vacío
  useSEO({
    title: t('seo.title'),
    description: t('seo.description'),
    keywords: t('seo.keywords'),
    url: `${getBaseUrl()}/fondo-de-aportes`,
    type: 'website',
    image: OG_IMAGES.wallet,
    noIndex: true,
    schema: {
      '@context': 'https://schema.org',
      '@type': 'WebPage',
      'name': t('seo.schemaName'),
      'description': t('seo.schemaDescription'),
      'url': `${getBaseUrl()}/fondo-de-aportes`,
      'isPartOf': {
        '@type': 'WebSite',
        'name': 'My Store',
        'url': getBaseUrl()
      },
      'breadcrumb': {
        '@type': 'BreadcrumbList',
        'itemListElement': [
          {
            '@type': 'ListItem',
            'position': 1,
            'name': t('seo.breadcrumbHome'),
            'item': getBaseUrl()
          },
          {
            '@type': 'ListItem',
            'position': 2,
            'name': t('seo.breadcrumbWallet'),
            'item': `${getBaseUrl()}/fondo-de-aportes`
          }
        ]
      },
      'mainEntity': {
        '@type': 'Service',
        'name': t('seo.schemaServiceName'),
        'description': t('seo.schemaServiceDescription'),
        'provider': {
          '@type': 'Organization',
          'name': 'My Store'
        }
      }
    }
  });
  
  const { isAuthenticated } = useAuth();
  const { currentLevel } = useMembership();
  const wallet = useWallet();
  const transfer = useTransfer();
  const location = useLocation();
  const [isHelpModalOpen, setIsHelpModalOpen] = useState(false);
  const [isSendCoinsModalOpen, setIsSendCoinsModalOpen] = useState(false);
  const [isBuyCoinsModalOpen, setIsBuyCoinsModalOpen] = useState(false);

  // Nivel mínimo para comprar paquetes de FC: Plata (nivel 2)
  const canBuyPackages = currentLevel >= 2;
  
  // Abrir modal de compra automáticamente si viene de ProductCard con estado openBuyModal
  useEffect(() => {
    const state = location.state as { openBuyModal?: boolean } | null;
    if (state?.openBuyModal && isAuthenticated && !wallet.loading) {
      setIsBuyCoinsModalOpen(true);
      // Limpiar el estado para evitar que se abra de nuevo al navegar
      window.history.replaceState({}, document.title);
    }
  }, [location.state, isAuthenticated, wallet.loading]);

  // Vista para usuarios no autenticados
  if (!isAuthenticated) {
    return (
      <div className="container mx-auto px-4 py-8">
        {/* Banner introductorio para no autenticados */}
        <FallbackBanner
          title={t('unauthenticated.bannerTitle')}
          description={t('unauthenticated.bannerDescription')}
          tags={[
            { icon: <FiStar className="w-full h-full" />, text: t('unauthenticated.tagExclusive') },
            { icon: <FiGift className="w-full h-full" />, text: t('unauthenticated.tagMembership') },
            { icon: <FiUsers className="w-full h-full" />, text: t('unauthenticated.tagReferrals') }
          ]}
          primaryButtonPath="/registrarse"
          secondaryButtonPath="/iniciar-sesion"
        />

        {/* Mensaje de acceso denegado */}
        <AccessDeniedMessage
          title={t('unauthenticated.accessTitle')}
          reason={t('unauthenticated.accessReason')}
          description={t('unauthenticated.accessDescription')}
          showCatalogButton={false}
          showMembershipButton={true}
          membershipButtonText={t('unauthenticated.accessButton')}
          membershipButtonPath="/registrarse"
        />

        {/* Disclaimer informativo */}
        <div className="mt-6">
          <WalletDisclaimer variant="full" />
        </div>
      </div>
    );
  }

  // Estado de carga
  if (wallet.loading) {
    return (
      <div className="container mx-auto px-4 py-8">
        <div className="flex justify-center items-center h-64">
          <Loader text={t('loading')} size="large" />
        </div>
      </div>
    );
  }

  // Estado de error
  if (wallet.error) {
    return (
      <div className="container mx-auto px-4 py-8">
        <div className="bg-red-50 p-4 rounded-md border border-red-200 text-center">
          <p className="text-red-600">{wallet.error}</p>
          <button
            onClick={() => wallet.refreshWallet()}
            className="mt-4 bg-primario text-white py-2 px-4 rounded-md hover:bg-hover transition-colors"
          >
            {t('retryButton')}
          </button>
        </div>
      </div>
    );
  }

  // Handler para actualizar balance después de transferencia o compra
  const handleTransferSuccess = () => {
    wallet.refreshWallet();
  };

  const handlePurchaseSuccess = () => {
    wallet.refreshWallet();
  };

  const hasBalance = wallet.points && wallet.points.balance > 0;

  return (
    <div className="container mx-auto px-4 py-6">
      {/* Header - Estilo consistente con ReferidosPage */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <h1 className="text-xl sm:text-2xl font-bold text-oscuro flex items-center">
          <FiDollarSign className="mr-2 text-primario flex-shrink-0" />
          <span className="break-words">{t('pageTitle')}</span>
        </h1>
        <button
          onClick={() => setIsHelpModalOpen(true)}
          className="flex items-center justify-center bg-primario hover:bg-hover text-white px-3 sm:px-4 py-2 rounded-md text-sm transition-colors w-full sm:w-auto"
        >
          <FiHelpCircle className="mr-1 sm:mr-2 flex-shrink-0" />
          <span className="whitespace-nowrap">{t('helpButton')}</span>
        </button>
      </div>

      {/* Mensajes del sistema */}
      {wallet.systemStatus && wallet.systemStatus.messages && wallet.systemStatus.messages.length > 0 && (
        <div className="mb-6 space-y-3">
          {wallet.systemStatus.messages.map((message, index) => (
            <div
              key={index}
              className={`p-4 rounded-md border ${
                message.type === 'error'
                  ? 'bg-red-50 border-red-200 text-red-700'
                  : message.type === 'warning'
                  ? 'bg-yellow-50 border-yellow-200 text-yellow-700'
                  : 'bg-blue-50 border-blue-200 text-blue-700'
              }`}
            >
              <p className="font-medium">{message.message}</p>
            </div>
          ))}
        </div>
      )}

      {/* Sección de Balance */}
      {wallet.canUsePoints ? (
        <WalletBalance 
          points={wallet.points} 
          conversionRate={wallet.systemStatus?.configuration?.points_conversion_rate}
          className="mb-6"
        />
      ) : (
        <div className="bg-gray-50 rounded-lg shadow-md p-4 mb-6">
          <h2 className="text-lg font-semibold text-gray-500 mb-3 border-b pb-2">{t('balance.title')}</h2>
          <p className="text-gray-600 text-center py-8">
            {t('balance.unavailable')}
          </p>
        </div>
      )}

      {/* Grid principal */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Columna izquierda: Selector de acción o Empty State */}
        <div className="lg:col-span-2">
          {!hasBalance ? (
            <WalletEmptyState 
              onBuyCoins={() => setIsBuyCoinsModalOpen(true)} 
              canBuyPackages={canBuyPackages}
            />
          ) : (
            <WalletActionSelector 
              onSelectSend={() => setIsSendCoinsModalOpen(true)}
              onSelectBuy={() => setIsBuyCoinsModalOpen(true)}
              canBuyPackages={canBuyPackages}
            />
          )}
        </div>

        {/* Columna derecha: Disclaimer */}
        <div className="lg:col-span-1">
          <WalletDisclaimer variant="full" />
        </div>
      </div>

      {/* Historial de transferencias wallet */}
      <WalletHistory
        transactions={wallet.transactions}
        loading={wallet.loading}
        className="mt-6"
      />

      {/* Modal de ayuda */}
      <HelpModal
        isOpen={isHelpModalOpen}
        onClose={() => setIsHelpModalOpen(false)}
        initialTab="coinsSystem"
      />

      {/* Modal de envío de Virtual Coins */}
      <SendCoinsModal
        isOpen={isSendCoinsModalOpen}
        onClose={() => setIsSendCoinsModalOpen(false)}
        transfer={transfer}
        userBalance={wallet.points?.balance || 0}
        onTransferSuccess={handleTransferSuccess}
      />

      {/* Modal de compra de Virtual Coins */}
      <BuyCoinsModal
        isOpen={isBuyCoinsModalOpen}
        onClose={() => setIsBuyCoinsModalOpen(false)}
        onPurchaseSuccess={handlePurchaseSuccess}
      />
    </div>
  );
};

export default WalletPage;
