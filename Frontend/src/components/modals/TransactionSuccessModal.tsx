import React from 'react';
import { useTranslation } from 'react-i18next';
import { FiCheck } from 'react-icons/fi';
import AnimatedModal from '../ui/AnimatedModal';
import { formatDate } from '../../utils/dateUtils';

interface TransactionSuccessModalProps {
  isOpen: boolean;
  onClose: () => void;
  transaction: {
    points: number;
    recipient: {
      id: number;
      name: string;
    };
    points_sent: number;
    points_received: number;
    commission: number;
    date?: string;
  };
}

const TransactionSuccessModal: React.FC<TransactionSuccessModalProps> = ({
  isOpen,
  onClose,
  transaction
}) => {
  const { t } = useTranslation('transactionSuccessModal');
  const currentDate = transaction.date || formatDate(new Date());
  
  return (
    <AnimatedModal
      isOpen={isOpen}
      onClose={onClose}
      title={t('title')}
      maxWidth="md"
    >
      <div className="p-6 sm:p-6 p-4">
        <div className="flex justify-center mb-4 sm:mb-6">
          <div className="bg-green-100 rounded-full p-4">
            <FiCheck className="text-green-500 text-4xl" />
          </div>
        </div>
        
        <h2 className="text-xl sm:text-2xl font-bold text-center text-gray-800 mb-4 sm:mb-6">
          {t('transferCompleted')}
        </h2>
        
        {/* Factura de transacción */}
        <div className="bg-white border border-gray-200 rounded-lg p-4 sm:p-6 shadow-sm mb-4 sm:mb-6">
          <div className="flex justify-between items-center border-b border-gray-100 pb-3 sm:pb-4 mb-3 sm:mb-4">
            <div>
              <h3 className="text-base sm:text-lg font-semibold text-gray-800">{t('details')}</h3>
              <p className="text-sm text-gray-500">{currentDate}</p>
            </div>
            <div className="text-primario font-bold text-lg sm:text-xl">
              {transaction.points_sent} FC
            </div>
          </div>
          
          <div className="space-y-3 sm:space-y-4">
            {/* Solo mostramos la comisión si existe, de lo contrario mostramos un mensaje de transferencia directa */}
            {transaction.commission > 0 ? (
              <>
                <div className="flex justify-between">
                  <span className="text-gray-600">{t('amountSent')}</span>
                  <span className="font-medium">{transaction.points_sent} FC</span>
                </div>
                
                <div className="flex justify-between">
                  <span className="text-gray-600">{t('commission')}</span>
                  <span className="font-medium text-amber-600">{transaction.commission} FC</span>
                </div>
                
                <div className="flex justify-between">
                  <span className="text-gray-600">{t('receivedByRecipient')}</span>
                  <span className="font-medium text-green-600">{transaction.points_received} FC</span>
                </div>
              </>
            ) : (
              <div className="flex justify-between">
                <span className="text-gray-600">{t('directTransfer')}</span>
                <span className="font-medium text-green-600">{transaction.points_sent} FC</span>
              </div>
            )}
            
            
            <div className="flex flex-col sm:flex-row sm:items-center justify-between pt-3 sm:pt-4 border-t border-gray-100">
              <div className="flex items-center mb-2 sm:mb-0">
                <span className="text-gray-600 mr-2">{t('recipient')}</span>
                <span className="font-semibold text-primario truncate max-w-[150px] sm:max-w-none">{transaction.recipient.name}</span>
              </div>
              <div className="text-xs bg-gray-100 px-2 py-1 rounded text-gray-600">
                ID: {transaction.recipient.id}
              </div>
            </div>
          </div>
        </div>
        
        <div className="flex justify-center">
          <button
            onClick={onClose}
            className="bg-primario text-white py-2 px-6 w-full sm:w-auto rounded-lg hover:bg-primario-dark transition-colors"
          >
            {t('close')}
          </button>
        </div>
      </div>
    </AnimatedModal>
  );
};

export default TransactionSuccessModal;