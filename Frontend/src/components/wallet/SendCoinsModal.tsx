/**
 * SendCoinsModal - Modal para enviar Virtual Coins
 * Utiliza AnimatedModal y contiene el formulario de transferencia
 */

import { FC } from 'react';
import { useTranslation } from 'react-i18next';
import { FiSend } from 'react-icons/fi';
import AnimatedModal from '../ui/AnimatedModal';
import WalletTransfer from './WalletTransfer';
import type { UseTransferReturn } from '../../hooks/useTransfer';

interface SendCoinsModalProps {
  isOpen: boolean;
  onClose: () => void;
  transfer: UseTransferReturn;
  userBalance: number;
  onTransferSuccess?: (newBalance: number) => void;
}

const SendCoinsModal: FC<SendCoinsModalProps> = ({
  isOpen,
  onClose,
  transfer,
  userBalance,
  onTransferSuccess
}) => {
  const { t } = useTranslation('walletComponents');
  const handleTransferSuccess = (newBalance: number) => {
    if (onTransferSuccess) {
      onTransferSuccess(newBalance);
    }
    // Cerrar el modal después de una transferencia exitosa
    onClose();
  };

  const handleClose = () => {
    // Resetear el estado de transferencia al cerrar
    transfer.resetTransfer();
    onClose();
  };

  return (
    <AnimatedModal
      isOpen={isOpen}
      onClose={handleClose}
      title={
        <span className="flex items-center gap-2">
          <FiSend className="text-primario" />
          {t('sendCoinsModal.title')}
        </span>
      }
      maxWidth="max-w-lg"
    >
      <div className="p-1">
        <WalletTransfer
          transfer={transfer}
          userBalance={userBalance}
          onTransferSuccess={handleTransferSuccess}
        />
      </div>
    </AnimatedModal>
  );
};

export default SendCoinsModal;
