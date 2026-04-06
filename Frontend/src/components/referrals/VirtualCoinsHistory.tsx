import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FiGift, FiClock, FiBarChart, FiUsers, FiExternalLink } from 'react-icons/fi';
import VirtualCoinsHistoryModal from './VirtualCoinsHistoryModal';
import VirtualCoinPrice from '../common/VirtualCoinPrice';

interface Transaction {
  id: number;
  date: string;
  type: string;
  points: number;
  description: string;
  expires_at: string | null;
}

interface VirtualCoinsHistoryProps {
  transactions: Transaction[];
  canUsePoints: boolean;
}

/**
 * Componente para mostrar el historial de transacciones de Virtual Coins
 * Actualizado con la nueva narrativa del club (compra -> aporte, etc.)
 */
const VirtualCoinsHistory: React.FC<VirtualCoinsHistoryProps> = ({
  transactions,
  canUsePoints
}) => {
  const { t } = useTranslation('referralComponents');
  const [isModalOpen, setIsModalOpen] = useState(false);
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

  if (!canUsePoints) {
    return (
      <div className="bg-gray-50 rounded-lg shadow-md p-4 mt-4">
        <h2 className="text-lg font-semibold text-gray-500 mb-3 border-b pb-2">{t('coinsHistory.recentTitle')}</h2>
        <p className="text-gray-600 text-center py-8">
          {t('coinsHistory.unavailable')}
        </p>
      </div>
    );
  }

  return (
    <>
      <div className="bg-white rounded-lg shadow-md p-4 mt-4">
        <div className="flex justify-between items-center mb-3 border-b pb-2">
          <h2 className="text-lg font-semibold text-oscuro">{t('coinsHistory.recentTitle')}</h2>
          {canUsePoints && transactions.length > 0 && (
            <button
              onClick={() => setIsModalOpen(true)}
              className="flex items-center gap-1 text-sm text-primario hover:text-primario-dark transition-colors font-medium"
            >
              <FiExternalLink className="w-4 h-4" />
              <span className="hidden sm:inline">{t('coinsHistory.viewFullHistory')}</span>
              <span className="sm:hidden">{t('coinsHistory.viewAll')}</span>
            </button>
          )}
        </div>

      {transactions.length === 0 ? (
        <div className="bg-gray-50 p-4 text-center rounded-md">
          <p className="text-gray-600">{t('coinsHistory.noTransactions')}</p>
        </div>
      ) : (
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200 text-sm">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden md:table-cell">{t('coinsHistory.tableDate')}</th>
                <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{t('coinsHistory.tableType')}</th>
                <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{t('coinsHistory.tableCoins')}</th>
                <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden lg:table-cell">{t('coinsHistory.tableDescription')}</th>
                <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden md:table-cell">{t('coinsHistory.tableExpiry')}</th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {transactions.map(transaction => (
                <tr key={transaction.id} className="hover:bg-gray-50">
                  <td className="px-3 py-2 whitespace-nowrap text-xs text-gray-500 hidden md:table-cell">
                    {new Date(transaction.date).toLocaleDateString()}
                  </td>
                  <td className="px-3 py-2 text-xs">
                    <div className="flex flex-col">
                      {renderTransactionType(transaction.type)}
                      <span className="text-2xs text-gray-400 mt-1 md:hidden">
                        {new Date(transaction.date).toLocaleDateString()}
                      </span>
                    </div>
                  </td>
                  <td className="px-3 py-2 whitespace-nowrap text-xs font-medium">
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
                  <td className="px-3 py-2 text-xs text-gray-500 hidden lg:table-cell">
                    <div>{transaction.description}</div>
                  </td>
                  <td className="px-3 py-2 whitespace-nowrap text-xs text-gray-500 hidden md:table-cell">
                    {transaction.expires_at ? new Date(transaction.expires_at).toLocaleDateString() : t('coinsHistory.noExpiry')}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      </div>

      {/* Modal de historial completo */}
      <VirtualCoinsHistoryModal
        isOpen={isModalOpen}
        onClose={() => setIsModalOpen(false)}
      />
    </>
  );
};

export default VirtualCoinsHistory;
