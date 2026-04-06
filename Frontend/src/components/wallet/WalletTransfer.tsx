/**
 * WalletTransfer - Componente para transferir Virtual Coins
 * Formulario completo de transferencia con validación
 */

import { FC, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FiSend, FiCheck, FiX, FiArrowLeft } from 'react-icons/fi';
import ReferralCodeInput from '../auth/form-inputs/ReferralCodeInput';
import VirtualCoinPrice from '../common/VirtualCoinPrice';
import Loader from '../ui/Loader';
import { pointsService } from '../../services/api';
import logger from '../../utils/logger';
import { fluidSizing } from '../../utils/fluidSizing';
import type { UseTransferReturn } from '../../hooks/useTransfer';

interface WalletTransferProps {
  transfer: UseTransferReturn;
  userBalance: number;
  onTransferSuccess?: (newBalance: number) => void;
  onBack?: () => void;
  className?: string;
}

const WalletTransfer: FC<WalletTransferProps> = ({
  transfer,
  userBalance,
  onTransferSuccess,
  onBack,
  className = ''
}) => {
  const { t } = useTranslation('walletComponents');
  const [currentUserCode, setCurrentUserCode] = useState('');

  // Cargar código del usuario actual para evitar auto-transferencias
  useEffect(() => {
    const loadCurrentUserCode = async () => {
      try {
        const response = await pointsService.getReferralCode();
        if (response?.data?.code) {
          setCurrentUserCode(response.data.code);
        }
      } catch (error) {
        logger.error('WalletTransfer', 'Error al obtener código de usuario:', error);
      }
    };
    loadCurrentUserCode();
  }, []);

  const handleValidate = async () => {
    await transfer.validateRecipient(currentUserCode);
  };

  const handleTransfer = async () => {
    const result = await transfer.executeTransfer(userBalance);
    if (result && result.new_balance !== undefined && onTransferSuccess) {
      onTransferSuccess(result.new_balance);
      transfer.resetTransfer();
    }
  };

  const isTransferDisabled = 
    transfer.recipientValid !== true ||
    transfer.transferAmount < 100 ||
    transfer.transferring ||
    userBalance < transfer.transferAmount;

  return (
    <div className={className}>
      {/* Botón de volver si está disponible */}
      {onBack && (
        <button
          onClick={onBack}
          type="button"
          className="flex items-center text-texto hover:text-primario transition-colors mb-4"
          style={{ fontSize: fluidSizing.text.sm, gap: fluidSizing.space.xs }}
        >
          <FiArrowLeft style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
          {t('transfer.back')}
        </button>
      )}
      
      {/* Código de referido del destinatario */}
      <ReferralCodeInput
        referralCode={transfer.recipientCode}
        setReferralCode={transfer.setRecipientCode}
        disabled={transfer.recipientValid === true}
        onValidateReferralCode={handleValidate}
        isValidatingCode={transfer.validatingCode}
        isValid={transfer.recipientValid}
      />

      {/* Información del destinatario validado */}
      {transfer.recipientValid === true && transfer.recipientInfo && (
        <div 
          className="bg-acento/20 border border-acento/30 rounded-lg flex items-center justify-between"
          style={{ marginTop: fluidSizing.space.md, padding: fluidSizing.space.md }}
        >
          <div className="flex items-center" style={{ fontSize: fluidSizing.text.sm, gap: fluidSizing.space.sm }}>
            <div 
              className="bg-acento text-white rounded-full flex items-center justify-center"
              style={{ padding: fluidSizing.space.xs }}
            >
              <FiCheck style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
            </div>
            <span className="text-texto">
              {t('transfer.sendTo')}{' '}
              <span className="font-semibold text-primario">
                {transfer.recipientInfo.name}
              </span>
            </span>
          </div>
          <button
            onClick={transfer.clearRecipient}
            type="button"
            className="text-texto/50 hover:text-red-500 bg-white rounded-full hover:bg-red-50 transition-colors"
            style={{ padding: fluidSizing.space.xs }}
            aria-label={t('transfer.removeRecipient')}
          >
            <FiX style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
          </button>
        </div>
      )}

      {/* Cantidad a transferir */}
      <div style={{ marginTop: fluidSizing.space.lg }}>
        <label
          htmlFor="transferAmount"
          className="block font-medium text-oscuro"
          style={{ fontSize: fluidSizing.text.sm, marginBottom: fluidSizing.space.xs }}
        >
          {t('transfer.amountLabel')}
        </label>
        <div className="relative">
          <input
            type="number"
            id="transferAmount"
            min="100"
            step="1"
            value={transfer.transferAmount || ''}
            onChange={(e) => transfer.setTransferAmount(parseInt(e.target.value) || 0)}
            className="w-full border border-secundario/50 rounded-lg focus:ring-primario focus:border-primario"
            style={{ padding: fluidSizing.space.sm, fontSize: fluidSizing.text.sm }}
            placeholder={t('transfer.amountPlaceholder')}
            disabled={transfer.recipientValid !== true || transfer.transferring}
          />
          <div className="absolute inset-y-0 right-0 flex items-center pointer-events-none" style={{ paddingRight: fluidSizing.space.md }}>
            <span className="text-texto/50" style={{ fontSize: fluidSizing.text.sm }}>FC</span>
          </div>
        </div>
        <div className="flex items-center text-texto/70" style={{ gap: fluidSizing.space.xs, marginTop: fluidSizing.space.xs, fontSize: fluidSizing.text.xs }}>
          <span>{t('transfer.availableBalance')}</span>
          <VirtualCoinPrice amount={userBalance} size="xs" showLabel={true} />
        </div>
      </div>

      {/* Notas opcionales */}
      <div style={{ marginTop: fluidSizing.space.lg }}>
        <label
          htmlFor="notes"
          className="block font-medium text-oscuro"
          style={{ fontSize: fluidSizing.text.sm, marginBottom: fluidSizing.space.xs }}
        >
          {t('transfer.notesLabel')}
        </label>
        <textarea
          id="notes"
          value={transfer.notes}
          onChange={(e) => transfer.setNotes(e.target.value)}
          className="w-full border border-secundario/50 rounded-lg focus:ring-primario focus:border-primario"
          style={{ padding: fluidSizing.space.sm, fontSize: fluidSizing.text.sm }}
          placeholder={t('transfer.notesPlaceholder')}
          rows={2}
          disabled={transfer.recipientValid !== true || transfer.transferring}
        />
      </div>

      {/* Botón de transferencia */}
      <div style={{ marginTop: fluidSizing.space.xl }}>
        <button
          onClick={handleTransfer}
          disabled={isTransferDisabled}
          className={`w-full flex items-center justify-center rounded-lg shadow-md transition-colors ${
            isTransferDisabled
              ? 'bg-gray-300 text-gray-500 cursor-not-allowed'
              : 'bg-primario text-white hover:bg-hover'
          }`}
          style={{ padding: `${fluidSizing.space.md} ${fluidSizing.space.lg}`, fontSize: fluidSizing.text.sm }}
        >
          {transfer.transferring ? (
            <div className="flex items-center justify-center">
              <Loader text="" size="small" />
              <span style={{ marginLeft: fluidSizing.space.sm }}>{t('transfer.processing')}</span>
            </div>
          ) : (
            <>
              <FiSend style={{ marginRight: fluidSizing.space.sm, width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
              {transfer.transferAmount > 0 ? t('transfer.shareAmount', { amount: transfer.transferAmount }) : t('transfer.shareCoins')}
            </>
          )}
        </button>
      </div>
    </div>
  );
};

export default WalletTransfer;
