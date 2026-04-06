import { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { FiGift, FiClock, FiBarChart, FiUsers, FiChevronLeft, FiChevronRight } from 'react-icons/fi';
import AnimatedModal from '../ui/AnimatedModal';
import { pointsService } from '../../services/api';
import Loader from '../ui/Loader';
import logger from '../../utils/logger';
import VirtualCoinPrice from '../common/VirtualCoinPrice';

interface Transaction {
  id: number;
  date: string;
  type: string;
  points: number;
  description: string;
  expires_at: string | null;
}

interface VirtualCoinsHistoryModalProps {
  isOpen: boolean;
  onClose: () => void;
}

/**
 * Modal para mostrar el historial completo de transacciones de Virtual Coins con paginación
 */
const VirtualCoinsHistoryModal: React.FC<VirtualCoinsHistoryModalProps> = ({
  isOpen,
  onClose
}) => {
  const { t } = useTranslation('referralComponents');
  const [transactions, setTransactions] = useState<Transaction[]>([]);
  const [loading, setLoading] = useState(false);
  const [currentPage, setCurrentPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const itemsPerPage = 10;

  // Cargar transacciones cuando se abre el modal o cambia la página
  useEffect(() => {
    if (isOpen) {
      loadTransactions(currentPage);
    }
  }, [isOpen, currentPage]);

  const loadTransactions = async (page: number) => {
    try {
      setLoading(true);
      const response = await pointsService.getPointsTransactions(page, itemsPerPage);
      
      if (response && response.data) {
        setTransactions(response.data.transactions || []);
        // El backend devuelve 'pages' no 'total_pages'
        const pages = response.data.pages || response.data.total_pages || 1;
        logger.info('VirtualCoinsHistoryModal', `Total pages: ${pages}, Current page: ${page}`);
        setTotalPages(pages);
      }
    } catch (error) {
      logger.error('VirtualCoinsHistoryModal', 'Error al cargar historial:', error);
      setTransactions([]);
    } finally {
      setLoading(false);
    }
  };

  // Renderizar tipo de transacción con nueva narrativa
  const renderTransactionType = (type: string) => {
    const types: Record<string, { label: string, icon: React.ReactNode, color: string }> = {
      earned: {
        label: t('coinsHistory.types.earned'),
        icon: <FiGift className="mr-1" />,
        color: 'text-primario'
      },
      used: {
        label: t('coinsHistory.types.used'),
        icon: <FiClock className="mr-1" />,
        color: 'text-primario'
      },
      expired: {
        label: t('coinsHistory.types.expired'),
        icon: <FiClock className="mr-1" />,
        color: 'text-primario'
      },
      admin_add: {
        label: t('coinsHistory.types.admin_add'),
        icon: <FiBarChart className="mr-1" />,
        color: 'text-primario'
      },
      admin_deduct: {
        label: t('coinsHistory.types.admin_deduct'),
        icon: <FiBarChart className="mr-1" />,
        color: 'text-primario'
      },
      referral: {
        label: t('coinsHistory.types.referral'),
        icon: <FiUsers className="mr-1" />,
        color: 'text-primario'
      },
      referral_signup: {
        label: t('coinsHistory.types.referral_signup'),
        icon: <FiUsers className="mr-1" />,
        color: 'text-primario'
      },
      referral_purchase: {
        label: t('coinsHistory.types.referral_purchase'),
        icon: <FiGift className="mr-1" />,
        color: 'text-primario'
      },
      referral_product_bonus: {
        label: t('coinsHistory.types.referral_product_bonus'),
        icon: <FiGift className="mr-1" />,
        color: 'text-primario'
      },
      registration: {
        label: t('coinsHistory.types.registration'),
        icon: <FiUsers className="mr-1" />,
        color: 'text-primario'
      },
      review: {
        label: t('coinsHistory.types.review'),
        icon: <FiBarChart className="mr-1" />,
        color: 'text-primario'
      },
      birthday: {
        label: t('coinsHistory.types.birthday'),
        icon: <FiGift className="mr-1" />,
        color: 'text-primario'
      }
    };

    const defaultType = { label: type, icon: <FiGift className="mr-1" />, color: 'text-gray-600' };
    const typeInfo = types[type] || defaultType;

    return (
      <span className={`flex items-center ${typeInfo.color}`}>
        {typeInfo.icon} {typeInfo.label}
      </span>
    );
  };

  const handlePreviousPage = () => {
    if (currentPage > 1) {
      setCurrentPage(currentPage - 1);
    }
  };

  const handleNextPage = () => {
    if (currentPage < totalPages) {
      setCurrentPage(currentPage + 1);
    }
  };

  return (
    <AnimatedModal
      isOpen={isOpen}
      onClose={onClose}
      title={t('coinsHistoryModal.title')}
      maxWidth="max-w-5xl"
    >
      <div className="p-4">
        {loading ? (
          <div className="flex justify-center py-8">
            <Loader text={t('coinsHistoryModal.loading')} size="medium" />
          </div>
        ) : transactions.length === 0 ? (
          <div className="bg-gray-50 p-8 text-center rounded-md">
            <p className="text-gray-600">{t('coinsHistoryModal.noTransactions')}</p>
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200 text-sm">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider whitespace-nowrap">{t('coinsHistory.tableDate')}</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider whitespace-nowrap">{t('coinsHistory.tableType')}</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider whitespace-nowrap">{t('coinsHistory.tableCoins')}</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider whitespace-nowrap">{t('coinsHistory.tableDescription')}</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider whitespace-nowrap">{t('coinsHistory.tableExpiry')}</th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                  {transactions.map(transaction => (
                    <tr key={transaction.id} className="hover:bg-gray-50">
                      <td className="px-4 py-3 whitespace-nowrap text-xs text-gray-500">
                        {new Date(transaction.date).toLocaleDateString()}
                      </td>
                      <td className="px-4 py-3 whitespace-nowrap text-xs">
                        {renderTransactionType(transaction.type)}
                      </td>
                      <td className="px-4 py-3 whitespace-nowrap text-xs font-medium">
                        <div className="flex items-center">
                          {transaction.points > 0 ? (
                            <span className="text-green-600 mr-1">+</span>
                          ) : (
                            <span className="text-red-600 mr-1">-</span>
                          )}
                          <VirtualCoinPrice 
                            amount={Math.abs(transaction.points)} 
                            size="xs" 
                            showLabel={false}
                            className={transaction.points > 0 ? 'text-green-600' : 'text-red-600'}
                          />
                        </div>
                      </td>
                      <td className="px-4 py-3 text-xs text-gray-500 min-w-[250px] max-w-sm">
                        <div>{transaction.description}</div>
                      </td>
                      <td className="px-4 py-3 whitespace-nowrap text-xs text-gray-500">
                        {transaction.expires_at ? new Date(transaction.expires_at).toLocaleDateString() : t('coinsHistoryModal.noExpiry')}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {/* Paginación */}
            {totalPages > 1 && (
              <div className="flex items-center justify-between mt-6 px-2 sm:px-4">
                <div className="text-xs sm:text-sm text-gray-600">
                  {t('coinsHistoryModal.pageInfo', { current: currentPage, total: totalPages })}
                </div>
                <div className="flex gap-2">
                  <button
                    onClick={handlePreviousPage}
                    disabled={currentPage === 1}
                    className={`flex items-center px-3 sm:px-4 py-2 text-sm font-medium rounded-md transition-colors ${
                      currentPage === 1
                        ? 'bg-gray-100 text-gray-400 cursor-not-allowed'
                        : 'bg-primario text-white hover:bg-primario-dark'
                    }`}
                  >
                    <FiChevronLeft className="sm:mr-1" />
                    <span className="hidden sm:inline">{t('coinsHistoryModal.previous')}</span>
                  </button>
                  <button
                    onClick={handleNextPage}
                    disabled={currentPage === totalPages}
                    className={`flex items-center px-3 sm:px-4 py-2 text-sm font-medium rounded-md transition-colors ${
                      currentPage === totalPages
                        ? 'bg-gray-100 text-gray-400 cursor-not-allowed'
                        : 'bg-primario text-white hover:bg-primario-dark'
                    }`}
                  >
                    <span className="hidden sm:inline">{t('coinsHistoryModal.next')}</span>
                    <FiChevronRight className="sm:ml-1" />
                  </button>
                </div>
              </div>
            )}
          </>
        )}
      </div>
    </AnimatedModal>
  );
};

export default VirtualCoinsHistoryModal;
