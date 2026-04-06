import { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { FiUsers, FiHelpCircle, FiGift, FiDollarSign, FiShare2 } from 'react-icons/fi';
import { pointsService, systemService } from '../services/api';
import type { SystemStatus } from '../services/system';
import logger from '../utils/logger';
import Loader from '../components/ui/Loader';
import HelpModal from '../components/help/HelpModal';
import TransactionHistory from '../components/common/TransactionHistory';
import ReferralStats from '../components/referrals/ReferralStats';
import ReferralCode from '../components/referrals/ReferralCode';
import VirtualCoinPrice from '../components/common/VirtualCoinPrice';
import FallbackBanner from '../components/common/FallbackBanner';
import CollapsibleSection from '../components/common/CollapsibleSection';
import { useAuth } from '../contexts/AuthContext';
import { useLanguage } from '../contexts/LanguageContext';
import AccessDeniedMessage from '../components/membership/AccessDeniedMessage';
import { fluidSizing } from '../utils/fluidSizing';
import { useSEO } from '../hooks/useSEO';
import { OG_IMAGES, getBaseUrl } from '../utils/seo';

interface UserPoints {
  balance: number;
  total_earned: number;
  used: number;
  monetary_value: number;
  conversion_rate: number;
}

interface Transaction {
  id: number;
  date: string;
  type: string;
  points: number;
  description: string;
  expires_at: string | null;
}

interface ReferralStatsData {
  total_referrals: number;
  direct_referrals: number;
  indirect_referrals: number;
  total_earnings: number; // API retorna total_earnings
  pending_referrals?: number;
}

interface ReferralInfo {
  code: string;
  url: string;
}

const ReferidosPage = () => {
  const { t } = useTranslation('referidosPage');

  // SEO: Página privada (requiere auth) - noIndex para evitar indexación de contenido vacío
  useSEO({
    title: t('seo.title'),
    description: t('seo.description'),
    keywords: t('seo.keywords'),
    url: `${getBaseUrl()}/invitados`,
    type: 'website',
    image: OG_IMAGES.referidos,
    noIndex: true,
    schema: {
      '@context': 'https://schema.org',
      '@type': 'WebPage',
      'name': t('seo.schemaName'),
      'description': t('seo.schemaDescription'),
      'url': `${getBaseUrl()}/invitados`,
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
            'name': t('seo.breadcrumbReferrals'),
            'item': `${getBaseUrl()}/invitados`
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
  const { localizedPath } = useLanguage();
  
  // Estados
  const [points, setPoints] = useState<UserPoints | null>(null);
  const [transactions, setTransactions] = useState<Transaction[]>([]);
  const [referralStats, setReferralStats] = useState<ReferralStatsData | null>(null);
  const [referralInfo, setReferralInfo] = useState<ReferralInfo | null>(null);
  const [systemStatus, setSystemStatus] = useState<SystemStatus | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [isHelpModalOpen, setIsHelpModalOpen] = useState(false);

  // Debug estado del modal
  useEffect(() => {
    logger.info('ReferidosPage', 'Help modal state:', isHelpModalOpen);
  }, [isHelpModalOpen]);

  // Obtener datos iniciales (solo si está autenticado)
  useEffect(() => {
    if (!isAuthenticated) return;
    
    const fetchData = async () => {
      try {
        setLoading(true);

        // Obtener estado del sistema primero
        const systemStatusResponse = await systemService.getSystemStatus();
        setSystemStatus(systemStatusResponse);

        // Obtener puntos del usuario solo si el sistema de puntos está habilitado y el usuario tiene permisos
        let pointsResponse = null;
        let transactionsResponse = null;
        if (systemStatusResponse.systems.points_enabled && systemStatusResponse.user_permissions.can_use_points) {
          pointsResponse = await pointsService.getUserPoints();
          transactionsResponse = await pointsService.getPointsTransactions(1, 10); // Solo página 1, máximo 10 elementos
        }

        // Obtener estadísticas de invitados solo si el sistema está habilitado y el usuario tiene permisos
        let referralStatsResponse = null;
        let referralCodeResponse = null;
        if (systemStatusResponse.systems.referrals_enabled && systemStatusResponse.user_permissions.can_use_referrals) {
          logger.info('ReferidosPage', 'Obteniendo estadísticas de invitados...');
          referralStatsResponse = await pointsService.getReferralStats();
          logger.info('ReferidosPage', `Respuesta completa: ${JSON.stringify(referralStatsResponse)}`);
          logger.info('ReferidosPage', `Datos de estadísticas: ${JSON.stringify(referralStatsResponse?.data)}`);
          referralCodeResponse = await pointsService.getReferralCode();
        } else {
          logger.warn('ReferidosPage', 'Sistema de invitados deshabilitado o sin permisos');
        }

        // Actualizar estados
        setPoints(pointsResponse?.data || null);
        setTransactions(transactionsResponse?.data.transactions || []);
        const statsData = referralStatsResponse?.data || null;
        logger.info('ReferidosPage', `Estableciendo stats: ${JSON.stringify(statsData)}`);
        setReferralStats(statsData);

        // Modificar la URL para usar el dominio del frontend en lugar del backend
        if (referralCodeResponse?.data) {
          const frontendUrl = window.location.origin; // Obtiene el dominio actual del frontend
          const code = referralCodeResponse.data.code;

          // Crear la URL de invitación con el dominio del frontend apuntando a la página de registro
          const referralUrl = `${frontendUrl}${localizedPath('/registrarse')}?ref=${code}`;

          setReferralInfo({
            code: code,
            url: referralUrl
          });
        } else {
          setReferralInfo(null);
        }

        setError('');
      } catch (err) {
        logger.error('ReferidosPage', 'Error al cargar datos de invitados y puntos:', err);
        setError(t('error'));
      } finally {
        setLoading(false);
      }
    };

    fetchData();
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isAuthenticated, localizedPath]);



  // Si no hay sesión, mostrar banner informativo + acceso denegado
  if (!isAuthenticated) {
    return (
      <div className="container mx-auto px-4 py-8">
        {/* Banner introductorio para no autenticados */}
        <FallbackBanner
          title={t('unauthenticated.bannerTitle')}
          description={t('unauthenticated.bannerDescription')}
          tags={[
            { icon: <FiShare2 className="w-full h-full" />, text: t('unauthenticated.tagShare') },
            { icon: <FiGift className="w-full h-full" />, text: t('unauthenticated.tagEarn') },
            { icon: <FiDollarSign className="w-full h-full" />, text: t('unauthenticated.tagUse') }
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
      </div>
    );
  }

  // Si está cargando, mostrar spinner
  if (loading && !points && !referralInfo) {
    return (
      <div className="container mx-auto px-4 py-8">
        <div className="flex justify-center items-center h-64">
          <Loader text={t('loading')} size="large" />
        </div>
      </div>
    );
  }

  // Si hay error, mostrar mensaje
  if (error) {
    return (
      <div className="container mx-auto px-4 py-8">
        <div className="bg-red-50 p-4 rounded-md border border-red-200 text-center">
          <p className="text-red-600">{error}</p>
          <button
            onClick={() => window.location.reload()}
            className="mt-4 bg-primario text-white py-2 px-4 rounded-md hover:bg-hover transition-colors"
          >
            {t('retryButton')}
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="container mx-auto px-4 py-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <h1 className="text-xl sm:text-2xl font-bold text-oscuro flex items-center">
          <FiUsers className="mr-2 text-primario flex-shrink-0" />
          <span className="break-words">{t('pageTitle')}</span>
      </h1>
        <button
          onClick={() => {
            logger.info('ReferidosPage', 'Opening help modal');
            setIsHelpModalOpen(true);
          }}
          className="flex items-center justify-center bg-primario hover:bg-hover text-white px-3 sm:px-4 py-2 rounded-md text-sm transition-colors w-full sm:w-auto"
        >
          <FiHelpCircle className="mr-1 sm:mr-2 flex-shrink-0" />
          <span className="whitespace-nowrap">{t('helpButton')}</span>
        </button>
      </div>

      {/* Mensajes del sistema */}
      {systemStatus && systemStatus.messages.length > 0 && (
        <div className="mb-6 space-y-3">
          {systemStatus.messages.map((message, index) => (
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

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {/* Sección de Virtual Coins y Estadísticas (Izquierda) */}
        <div className="lg:col-span-2 flex flex-col">
          {/* Sección de Virtual Coins - Solo si el usuario puede usarlos */}
          {systemStatus?.user_permissions.can_use_points ? (
            <CollapsibleSection
              title={t('balance.title')}
              icon={FiDollarSign}
              collapsible={false}
              showCollapseButton={false}
              className="mb-4"
            >
              {points && (
                <div 
                  className="grid grid-cols-2 md:grid-cols-4" 
                  style={{ gap: fluidSizing.space.md }}
                >
                  <div 
                    className="bg-secundario/20 rounded-lg border border-secundario/30 text-center"
                    style={{ padding: fluidSizing.space.md }}
                  >
                    <p className="text-texto" style={{ fontSize: fluidSizing.text.xs, marginBottom: fluidSizing.space.xs }}>{t('balance.available')}</p>
                    <div className="flex justify-center" style={{ marginTop: fluidSizing.space.xs, marginBottom: fluidSizing.space.xs }}>
                      <VirtualCoinPrice amount={points.balance} size="md" showLabel={false} />
                    </div>
                    <p className="text-texto/70" style={{ fontSize: fluidSizing.text.xs }}>
                      {t('balance.availableHint')}
                    </p>
                  </div>

                  <div 
                    className="bg-secundario/20 rounded-lg border border-secundario/30 text-center"
                    style={{ padding: fluidSizing.space.md }}
                  >
                    <p className="text-texto" style={{ fontSize: fluidSizing.text.xs, marginBottom: fluidSizing.space.xs }}>{t('balance.totalEarned')}</p>
                    <div className="flex justify-center" style={{ marginTop: fluidSizing.space.xs, marginBottom: fluidSizing.space.xs }}>
                      <VirtualCoinPrice amount={points.total_earned} size="md" showLabel={false} />
                    </div>
                    <p className="text-texto/70" style={{ fontSize: fluidSizing.text.xs }}>
                      {t('balance.totalEarnedHint')}
                    </p>
                  </div>

                  <div 
                    className="bg-secundario/20 rounded-lg border border-secundario/30 text-center"
                    style={{ padding: fluidSizing.space.md }}
                  >
                    <p className="text-texto" style={{ fontSize: fluidSizing.text.xs, marginBottom: fluidSizing.space.xs }}>{t('balance.used')}</p>
                    <div className="flex justify-center" style={{ marginTop: fluidSizing.space.xs, marginBottom: fluidSizing.space.xs }}>
                      <VirtualCoinPrice amount={points.used} size="md" showLabel={false} />
                    </div>
                    <p className="text-texto/70" style={{ fontSize: fluidSizing.text.xs }}>
                      {t('balance.usedHint')}
                    </p>
                  </div>

                  <div 
                    className="bg-secundario/20 rounded-lg border border-secundario/30 text-center"
                    style={{ padding: fluidSizing.space.md }}
                  >
                    <p className="text-texto" style={{ fontSize: fluidSizing.text.xs, marginBottom: fluidSizing.space.xs }}>{t('balance.conversionRate')}</p>
                    <p className="font-bold text-primario" style={{ fontSize: fluidSizing.text.xl, marginTop: fluidSizing.space.xs, marginBottom: fluidSizing.space.xs }}>
                      {systemStatus?.configuration.points_conversion_rate ? 
                        `$${systemStatus.configuration.points_conversion_rate}` : 
                        'N/A'
                      }
                    </p>
                    <p className="text-texto/70" style={{ fontSize: fluidSizing.text.xs }}>
                      {t('balance.conversionRateHint')}
                    </p>
                  </div>
                </div>
              )}
            </CollapsibleSection>
          ) : (
            <CollapsibleSection
              title={t('balance.title')}
              icon={FiDollarSign}
              collapsible={false}
              showCollapseButton={false}
              className="mb-4"
            >
              <p className="text-texto text-center" style={{ paddingTop: fluidSizing.space.lg, paddingBottom: fluidSizing.space.lg }}>
                {t('balance.unavailable')}
              </p>
            </CollapsibleSection>
          )}

          {/* Sección de Estadísticas de Invitados - flex-grow para ocupar espacio restante */}
          <div className="flex-grow flex flex-col">
            <ReferralStats 
              stats={referralStats}
              canUseReferrals={systemStatus?.user_permissions.can_use_referrals || false}
            />
          </div>
        </div>

        {/* Sección de Código de Invitación */}
        <div className="lg:col-span-1">
          <ReferralCode 
            referralInfo={referralInfo}
            canUseReferrals={systemStatus?.user_permissions.can_use_referrals || false}
            referralsEnabled={systemStatus?.systems.referrals_enabled || false}
          />
        </div>
      </div>

      {/* Sección de Historial de Virtual Coins */}
      <TransactionHistory
        transactions={transactions}
        canUsePoints={systemStatus?.user_permissions.can_use_points || false}
        title={t('history.title')}
        className="mt-6"
      />

      {/* Modal de ayuda */}
      <HelpModal
        isOpen={isHelpModalOpen}
        onClose={() => setIsHelpModalOpen(false)}
        initialTab="coinsSystem"
      />
    </div>
  );
};

export default ReferidosPage;
