/**
 * WalletHistory - Componente para mostrar historial de transacciones wallet
 * Muestra transferencias entre usuarios y compras de Virtual Coins
 */

import { FC, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FiArrowUpRight, FiArrowDownLeft, FiRepeat, FiExternalLink, FiChevronLeft, FiChevronRight, FiShoppingCart } from 'react-icons/fi';
import VirtualCoinPrice from '../common/VirtualCoinPrice';
import CollapsibleSection from '../common/CollapsibleSection';
import AnimatedModal from '../ui/AnimatedModal';
import { fluidSizing } from '../../utils/fluidSizing';
import type { Transaction } from '../../hooks/useWallet';

interface WalletHistoryProps {
  transactions: Transaction[];
  loading?: boolean;
  className?: string;
}


/**
 * Tipos de transacciones que pertenecen al contexto de WALLET:
 * - Compras de paquetes de FC (fc_purchase, checkout_purchase)
 * - Transferencias entre usuarios (received, used con "transferencia")
 * - Pagos de aportes/pedidos con FC
 * - Bonos de membresía
 * 
 * NO incluye transacciones de referidos (esas van en ReferidosPage)
 */

/**
 * Determina si es una transacción de REFERIDOS (para excluirla)
 */
const isReferralTransaction = (tx: Transaction): boolean => {
  const referralTypes = ['referral', 'referral_signup', 'referral_purchase', 'referral_product_bonus'];
  return referralTypes.includes(tx.type);
};

/**
 * Determina si es una transferencia wallet
 * - type 'received' = puntos recibidos de otro usuario (NO de referidos)
 * - type 'used' con descripción que contiene 'Transferencia' = puntos enviados a otro usuario
 */
const isWalletTransfer = (tx: Transaction): boolean => {
  const desc = tx.description.toLowerCase();
  // Transferencia recibida (pero no de referidos - verificar descripción)
  if (tx.type === 'received' && !desc.includes('referid') && !desc.includes('referral')) return true;
  // Transferencia enviada
  if (tx.type === 'used' && (desc.includes('transferencia') || desc.includes('transfer'))) return true;
  return false;
};

/**
 * Determina si es una compra de Virtual Coins (paquetes o checkout)
 */
const isFCPurchase = (tx: Transaction): boolean => {
  const desc = tx.description.toLowerCase();
  // Compra de paquetes de FC
  if (tx.type === 'fc_purchase') return true;
  if (desc.includes('compra de paquete') || desc.includes('package purchase')) return true;
  // Compra de FC para checkout (pago con tarjeta)
  if (tx.type === 'checkout_purchase') return true;
  if ((desc.includes('compra de') || desc.includes('purchase of')) && desc.includes('Virtual Coins')) return true;
  // Bono de membresía (FC recibidos al comprar membresía)
  if (tx.type === 'membership_bonus') return true;
  if (desc.includes('bono de membresía') || desc.includes('membership bonus')) return true;
  return false;
};

/**
 * Determina si es un pago de aporte/pedido con FC
 */
const isOrderPayment = (tx: Transaction): boolean => {
  const desc = tx.description.toLowerCase();
  if (tx.type === 'used' && (desc.includes('aporte') || desc.includes('contribution') || desc.includes('order'))) return true;
  if (tx.type === 'used' && (desc.includes('pagado con') || desc.includes('paid with'))) return true;
  if (tx.type === 'purchase') return true;
  if (tx.type === 'order_discount') return true;
  return false;
};

/**
 * Determina si una transacción debe mostrarse en el historial de WALLET
 * Excluye explícitamente las transacciones de referidos
 */
const isWalletTransaction = (tx: Transaction): boolean => {
  // Excluir transacciones de referidos
  if (isReferralTransaction(tx)) return false;
  // Incluir transacciones de wallet
  return isWalletTransfer(tx) || isFCPurchase(tx) || isOrderPayment(tx);
};

/**
 * Determina si es una transferencia enviada
 */
const isSentTransfer = (tx: Transaction): boolean => {
  return tx.type === 'used' && tx.description.toLowerCase().includes('transferencia');
};

/**
 * Determina si es una transferencia recibida
 */
const isReceivedTransfer = (tx: Transaction): boolean => {
  return tx.type === 'received';
};

const getTransactionIcon = (tx: Transaction) => {
  const iconStyle = { width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm };
  // Compra de FC (paquetes o checkout)
  if (isFCPurchase(tx)) {
    return <FiShoppingCart className="text-primario flex-shrink-0" style={iconStyle} />;
  }
  // Pago de aporte con FC
  if (isOrderPayment(tx)) {
    return <FiArrowUpRight className="text-orange-500 flex-shrink-0" style={iconStyle} />;
  }
  // Transferencia enviada
  if (isSentTransfer(tx)) {
    return <FiArrowUpRight className="text-red-500 flex-shrink-0" style={iconStyle} />;
  }
  // Transferencia recibida
  if (isReceivedTransfer(tx)) {
    return <FiArrowDownLeft className="text-acento flex-shrink-0" style={iconStyle} />;
  }
  return <FiRepeat className="text-texto flex-shrink-0" style={iconStyle} />;
};

const getTransactionColor = (points: number) => {
  return points >= 0 ? 'text-acento' : 'text-red-600';
};

const formatDate = (dateString: string) => {
  const date = new Date(dateString);
  return date.toLocaleDateString('es-CO', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  });
};

const WalletHistory: FC<WalletHistoryProps> = ({
  transactions,
  loading = false,
  className = ''
}) => {
  const { t } = useTranslation('walletComponents');
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [currentPage, setCurrentPage] = useState(1);
  const itemsPerPage = 10;
  const previewLimit = 5;

  // Filtrar transacciones de wallet (compras FC, transferencias, pagos - NO referidos)
  const walletTransfers = transactions.filter(isWalletTransaction);

  // Transacciones a mostrar en la vista previa
  const previewTransactions = walletTransfers.slice(0, previewLimit);

  // Calcular paginación para el modal usando datos locales
  const totalPages = Math.ceil(walletTransfers.length / itemsPerPage);
  const modalTransactions = walletTransfers.slice(
    (currentPage - 1) * itemsPerPage,
    currentPage * itemsPerPage
  );

  const handleOpenModal = () => {
    setIsModalOpen(true);
    setCurrentPage(1);
  };

  const handlePageChange = (newPage: number) => {
    setCurrentPage(newPage);
  };

  // Renderizar una fila de transacción
  const renderTransactionRow = (tx: Transaction, isModal: boolean = false) => (
    <div
      key={tx.id}
      className="flex items-center justify-between hover:bg-secundario/5 transition-colors"
      style={{ padding: fluidSizing.space.md }}
    >
      <div className="flex items-center" style={{ gap: fluidSizing.space.md }}>
        {getTransactionIcon(tx)}
        <div>
          <p className="font-medium text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>
            {tx.description}
          </p>
          <p className="text-texto/70" style={{ fontSize: fluidSizing.text.xs }}>
            {formatDate(tx.date)}
            {isModal && tx.expires_at && (
              <span className="ml-2">• {t('history.expires', { date: formatDate(tx.expires_at) })}</span>
            )}
          </p>
        </div>
      </div>
      <div className={`font-semibold flex items-center ${getTransactionColor(tx.points)}`}>
        <span style={{ marginRight: fluidSizing.space.xs }}>{tx.points >= 0 ? '+' : ''}</span>
        <VirtualCoinPrice 
          amount={Math.abs(tx.points)} 
          size="sm" 
          showLabel={true}
        />
      </div>
    </div>
  );

  return (
    <>
      <CollapsibleSection
        title={t('history.title')}
        icon={FiRepeat}
        collapsible={false}
        showCollapseButton={false}
        className={className}
        headerExtra={
          walletTransfers.length > previewLimit ? (
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
              <span className="hidden sm:inline">{t('history.viewFullHistory')}</span>
              <span className="sm:hidden">{t('history.viewAll')}</span>
            </button>
          ) : undefined
        }
      >
        <div className="divide-y divide-secundario/20">
          {loading ? (
            <div className="text-center text-texto" style={{ padding: fluidSizing.space.xl }}>
              {t('history.loading')}
            </div>
          ) : previewTransactions.length === 0 ? (
            <div className="text-center text-texto" style={{ padding: fluidSizing.space.xl }}>
              <FiRepeat className="mx-auto text-secundario" style={{ fontSize: fluidSizing.text['2xl'], marginBottom: fluidSizing.space.sm }} />
              <p style={{ fontSize: fluidSizing.text.sm }}>{t('history.noTransfers')}</p>
            </div>
          ) : (
            previewTransactions.map(tx => renderTransactionRow(tx))
          )}
        </div>

        {previewTransactions.length > 0 && walletTransfers.length > previewLimit && (
          <div className="text-center border-t border-secundario/30" style={{ padding: fluidSizing.space.md }}>
            <p className="text-texto/70" style={{ fontSize: fluidSizing.text.xs }}>
              {t('history.showing', { count: previewTransactions.length, total: walletTransfers.length })}
            </p>
          </div>
        )}
      </CollapsibleSection>

      {/* Modal de historial completo */}
      <AnimatedModal
        isOpen={isModalOpen}
        onClose={() => setIsModalOpen(false)}
        title={t('history.title')}
        maxWidth="max-w-3xl"
      >
        <div style={{ padding: fluidSizing.space.lg }}>
          {modalTransactions.length === 0 ? (
            <div 
              className="bg-secundario/10 rounded-lg text-center"
              style={{ padding: fluidSizing.space.xl }}
            >
              <FiRepeat className="mx-auto text-secundario" style={{ fontSize: fluidSizing.text['2xl'], marginBottom: fluidSizing.space.sm }} />
              <p className="text-texto" style={{ fontSize: fluidSizing.text.sm }}>{t('history.noTransfersRegistered')}</p>
            </div>
          ) : (
            <>
              <div className="divide-y divide-secundario/20">
                {modalTransactions.map(tx => renderTransactionRow(tx, true))}
              </div>

              {/* Paginación */}
              {totalPages > 1 && (
                <div 
                  className="flex items-center justify-center"
                  style={{ marginTop: fluidSizing.space.lg, gap: fluidSizing.space.md }}
                >
                  <button
                    onClick={() => handlePageChange(currentPage - 1)}
                    disabled={currentPage === 1}
                    className={`flex items-center rounded-md transition-colors ${
                      currentPage === 1 
                        ? 'bg-secundario/20 text-texto/50 cursor-not-allowed' 
                        : 'bg-primario text-white hover:bg-hover'
                    }`}
                    style={{ padding: `${fluidSizing.space.sm} ${fluidSizing.space.md}`, fontSize: fluidSizing.text.sm }}
                  >
                    <FiChevronLeft style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                    {t('history.previous')}
                  </button>
                  
                  <span className="text-texto" style={{ fontSize: fluidSizing.text.sm }}>
                    {t('history.pageOf', { current: currentPage, total: totalPages })}
                  </span>
                  
                  <button
                    onClick={() => handlePageChange(currentPage + 1)}
                    disabled={currentPage === totalPages}
                    className={`flex items-center rounded-md transition-colors ${
                      currentPage === totalPages 
                        ? 'bg-secundario/20 text-texto/50 cursor-not-allowed' 
                        : 'bg-primario text-white hover:bg-hover'
                    }`}
                    style={{ padding: `${fluidSizing.space.sm} ${fluidSizing.space.md}`, fontSize: fluidSizing.text.sm }}
                  >
                    {t('history.next')}
                    <FiChevronRight style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                  </button>
                </div>
              )}
            </>
          )}
        </div>
      </AnimatedModal>
    </>
  );
};

export default WalletHistory;
