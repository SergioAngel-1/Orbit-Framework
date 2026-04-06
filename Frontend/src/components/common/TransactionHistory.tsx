/**
 * TransactionHistory - Componente reutilizable para historial de transacciones
 * Usa CollapsibleSection con header gradiente
 * Incluye botón para abrir modal con historial completo
 */

import { FC, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FiClock, FiExternalLink, FiGift, FiUsers, FiChevronLeft, FiChevronRight, FiStar } from 'react-icons/fi';
import CollapsibleSection from './CollapsibleSection';
import VirtualCoinPrice from './VirtualCoinPrice';
import AnimatedModal from '../ui/AnimatedModal';
import Loader from '../ui/Loader';
import { fluidSizing } from '../../utils/fluidSizing';
import { pointsService } from '../../services/api';
import logger from '../../utils/logger';

export interface Transaction {
  id: number;
  date: string;
  type: string;
  points: number;
  description: string;
  expires_at?: string | null;
}

interface TransactionHistoryProps {
  /** Transacciones a mostrar (vista previa) */
  transactions: Transaction[];
  /** Si el usuario puede usar el sistema de puntos */
  canUsePoints?: boolean;
  /** Número máximo de transacciones a mostrar en la vista previa */
  previewLimit?: number;
  /** Título de la sección */
  title?: string;
  /** Si está cargando */
  loading?: boolean;
  /** Clase CSS adicional */
  className?: string;
}

/**
 * Este componente se usa en ReferidosPage para mostrar transacciones de referidos y reseñas.
 * Las transacciones de wallet (compras FC, transferencias, pagos) van en WalletHistory.
 */

// Tipos de transacciones que se muestran en este historial
const SUPPORTED_TYPES = ['referral', 'referral_signup', 'referral_purchase', 'referral_product_bonus', 'review', 'membership_activation'];

/**
 * Filtra transacciones soportadas (referidos + reseñas)
 */
const isSupportedTransaction = (tx: Transaction): boolean => {
  return SUPPORTED_TYPES.includes(tx.type);
};

// Mapeo de tipos de transacción de referidos - las labels se resuelven dinámicamente con t()
const TRANSACTION_TYPE_KEYS: Record<string, { labelKey: string; icon: FC<{ className?: string; style?: React.CSSProperties }>; colorClass: string }> = {
  referral: { labelKey: 'transactionHistory.referralCommission', icon: FiUsers, colorClass: 'text-acento' },
  referral_signup: { labelKey: 'transactionHistory.referralSignup', icon: FiUsers, colorClass: 'text-acento' },
  referral_purchase: { labelKey: 'transactionHistory.referralPurchase', icon: FiGift, colorClass: 'text-acento' },
  referral_product_bonus: { labelKey: 'transactionHistory.referralBonus', icon: FiGift, colorClass: 'text-acento' },
  review: { labelKey: 'transactionHistory.reviewReward', icon: FiStar, colorClass: 'text-yellow-500' },
  membership_activation: { labelKey: 'transactionHistory.membershipActivation', icon: FiGift, colorClass: 'text-primario' },
};

const getTransactionInfo = (type: string, tFn: (key: string) => string) => {
  const config = TRANSACTION_TYPE_KEYS[type];
  if (config) {
    return { label: tFn(config.labelKey), icon: config.icon, colorClass: config.colorClass };
  }
  return { label: type, icon: FiGift, colorClass: 'text-texto' };
};

const formatDate = (dateString: string) => {
  const date = new Date(dateString);
  return date.toLocaleDateString('es-CO', {
    day: '2-digit',
    month: 'short',
    year: 'numeric'
  });
};

const TransactionHistory: FC<TransactionHistoryProps> = ({
  transactions,
  canUsePoints = true,
  previewLimit = 5,
  title,
  loading = false,
  className = ''
}) => {
  const { t } = useTranslation('commonComponents');
  const resolvedTitle = title || t('transactionHistory.defaultTitle');
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [modalTransactions, setModalTransactions] = useState<Transaction[]>([]);
  const [modalLoading, setModalLoading] = useState(false);
  const [currentPage, setCurrentPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const itemsPerPage = 10;

  // Filtrar transacciones soportadas para la vista previa
  const supportedTransactions = transactions.filter(isSupportedTransaction);
  const previewTransactions = supportedTransactions.slice(0, previewLimit);

  // Cargar historial completo para el modal
  const loadFullHistory = async (page: number) => {
    try {
      setModalLoading(true);
      const response = await pointsService.getPointsTransactions(page, itemsPerPage);
      
      if (response && response.data) {
        // Filtrar transacciones soportadas
        const allTransactions = response.data.transactions || [];
        const filteredTransactions = allTransactions.filter(isSupportedTransaction);
        setModalTransactions(filteredTransactions);
        const pages = response.data.pages || response.data.total_pages || 1;
        setTotalPages(pages);
        logger.info('TransactionHistory', `Loaded page ${page} of ${pages} (${filteredTransactions.length} supported transactions)`);
      }
    } catch (error) {
      logger.error('TransactionHistory', 'Error loading history:', error);
      setModalTransactions([]);
    } finally {
      setModalLoading(false);
    }
  };

  const handleOpenModal = () => {
    setIsModalOpen(true);
    setCurrentPage(1);
    loadFullHistory(1);
  };

  const handlePageChange = (page: number) => {
    setCurrentPage(page);
    loadFullHistory(page);
  };

  // Renderizar una fila de transacción
  const renderTransactionRow = (tx: Transaction, isModal: boolean = false) => {
    const info = getTransactionInfo(tx.type, t);
    const IconComponent = info.icon;
    const isPositive = tx.points > 0;

    return (
      <div
        key={tx.id}
        className="flex items-center justify-between hover:bg-secundario/10 transition-colors rounded-lg"
        style={{ padding: fluidSizing.space.sm }}
      >
        <div className="flex items-center" style={{ gap: fluidSizing.space.sm }}>
          <div 
            className={`rounded-full bg-secundario/30 flex items-center justify-center flex-shrink-0 ${info.colorClass}`}
            style={{ width: fluidSizing.size.buttonSm, height: fluidSizing.size.buttonSm }}
          >
            <IconComponent style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
          </div>
          <div>
            <p className="font-medium text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>
              {info.label}
            </p>
            <p className="text-texto/70" style={{ fontSize: fluidSizing.text.xs }}>
              {formatDate(tx.date)}
              {isModal && tx.expires_at && (
                <span className="ml-2">• {t('transactionHistory.expires', { date: formatDate(tx.expires_at) })}</span>
              )}
            </p>
          </div>
        </div>
        <div className={`font-semibold flex items-center ${isPositive ? 'text-green-600' : 'text-red-600'}`}>
          <span style={{ marginRight: '2px' }}>{isPositive ? '+' : '-'}</span>
          <VirtualCoinPrice 
            amount={Math.abs(tx.points)} 
            size="sm" 
            showLabel={false}
          />
        </div>
      </div>
    );
  };

  if (!canUsePoints) {
    return (
      <CollapsibleSection
        title={resolvedTitle}
        icon={FiClock}
        collapsible={false}
        showCollapseButton={false}
        className={className}
      >
        <p className="text-texto text-center" style={{ paddingTop: fluidSizing.space.lg, paddingBottom: fluidSizing.space.lg }}>
          {t('transactionHistory.systemUnavailable')}
        </p>
      </CollapsibleSection>
    );
  }

  return (
    <>
      <CollapsibleSection
        title={resolvedTitle}
        icon={FiClock}
        collapsible={false}
        showCollapseButton={false}
        className={className}
        headerExtra={
          supportedTransactions.length > 0 && (
            <button
              onClick={(e) => {
                e.stopPropagation();
                handleOpenModal();
              }}
              className="flex items-center bg-white/20 hover:bg-white/30 text-white rounded-md transition-colors font-medium"
              style={{ 
                padding: `${fluidSizing.space.xs} ${fluidSizing.space.sm}`,
                fontSize: fluidSizing.text.xs,
                gap: fluidSizing.space.xs
              }}
            >
              <FiExternalLink style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
              <span className="hidden sm:inline">{t('transactionHistory.viewFullHistory')}</span>
              <span className="sm:hidden">{t('transactionHistory.viewAll')}</span>
            </button>
          )
        }
      >
        {loading ? (
          <div className="flex justify-center" style={{ padding: fluidSizing.space.xl }}>
            <Loader text={t('transactionHistory.loadingMovements')} size="medium" />
          </div>
        ) : previewTransactions.length === 0 ? (
          <div 
            className="bg-secundario/10 rounded-lg text-center"
            style={{ padding: fluidSizing.space.xl }}
          >
            <FiClock 
              className="mx-auto text-texto/30" 
              style={{ width: fluidSizing.size.iconXl, height: fluidSizing.size.iconXl, marginBottom: fluidSizing.space.sm }} 
            />
            <p className="text-texto" style={{ fontSize: fluidSizing.text.sm }}>
              {t('transactionHistory.noReferralEarnings')}
            </p>
          </div>
        ) : (
          <div className="divide-y divide-secundario/20">
            {previewTransactions.map(tx => renderTransactionRow(tx))}
          </div>
        )}
      </CollapsibleSection>

      {/* Modal de historial completo */}
      <AnimatedModal
        isOpen={isModalOpen}
        onClose={() => setIsModalOpen(false)}
        title={t('transactionHistory.modalTitle')}
        maxWidth="max-w-3xl"
      >
        <div style={{ padding: fluidSizing.space.lg }}>
          {modalLoading ? (
            <div className="flex justify-center" style={{ padding: fluidSizing.space.xl }}>
              <Loader text={t('transactionHistory.loadingHistory')} size="medium" />
            </div>
          ) : modalTransactions.length === 0 ? (
            <div 
              className="bg-secundario/10 rounded-lg text-center"
              style={{ padding: fluidSizing.space.xl }}
            >
              <p className="text-texto" style={{ fontSize: fluidSizing.text.sm }}>
                {t('transactionHistory.noTransactions')}
              </p>
            </div>
          ) : (
            <>
              <div 
                className="bg-secundario/10 rounded-lg divide-y divide-secundario/20"
                style={{ padding: fluidSizing.space.sm }}
              >
                {modalTransactions.map(tx => renderTransactionRow(tx, true))}
              </div>

              {/* Paginación */}
              {totalPages > 1 && (
                <div 
                  className="flex items-center justify-between border-t border-secundario/30"
                  style={{ paddingTop: fluidSizing.space.md, marginTop: fluidSizing.space.md }}
                >
                  <p className="text-texto" style={{ fontSize: fluidSizing.text.sm }}>
                    {t('transactionHistory.pageOf', { current: currentPage, total: totalPages })}
                  </p>
                  <div className="flex" style={{ gap: fluidSizing.space.sm }}>
                    <button
                      onClick={() => handlePageChange(currentPage - 1)}
                      disabled={currentPage === 1}
                      className={`flex items-center rounded-md transition-colors ${
                        currentPage === 1
                          ? 'bg-gray-100 text-gray-400 cursor-not-allowed'
                          : 'bg-primario text-white hover:bg-hover'
                      }`}
                      style={{ 
                        paddingLeft: fluidSizing.space.md, 
                        paddingRight: fluidSizing.space.md,
                        paddingTop: fluidSizing.space.sm,
                        paddingBottom: fluidSizing.space.sm,
                        fontSize: fluidSizing.text.sm,
                        gap: fluidSizing.space.xs
                      }}
                    >
                      <FiChevronLeft style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                      <span className="hidden sm:inline">{t('transactionHistory.previous')}</span>
                    </button>
                    <button
                      onClick={() => handlePageChange(currentPage + 1)}
                      disabled={currentPage === totalPages}
                      className={`flex items-center rounded-md transition-colors ${
                        currentPage === totalPages
                          ? 'bg-gray-100 text-gray-400 cursor-not-allowed'
                          : 'bg-primario text-white hover:bg-hover'
                      }`}
                      style={{ 
                        paddingLeft: fluidSizing.space.md, 
                        paddingRight: fluidSizing.space.md,
                        paddingTop: fluidSizing.space.sm,
                        paddingBottom: fluidSizing.space.sm,
                        fontSize: fluidSizing.text.sm,
                        gap: fluidSizing.space.xs
                      }}
                    >
                      <span className="hidden sm:inline">{t('transactionHistory.next')}</span>
                      <FiChevronRight style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                    </button>
                  </div>
                </div>
              )}
            </>
          )}
        </div>
      </AnimatedModal>
    </>
  );
};

export default TransactionHistory;
