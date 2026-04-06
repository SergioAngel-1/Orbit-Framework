/**
 * useTransfer - Hook personalizado para transferencia de Virtual Coins
 * Maneja validación de código de referido y proceso de transferencia
 */

import { useState, useCallback } from 'react';
import { pointsService } from '../services/api';
import alertService from '../services/alertService';
import i18n from '../config/i18n';
import logger from '../utils/logger';
import secureStorage from '../utils/secureStorage';

export interface ReferrerInfo {
  id?: number;
  name: string;
  email?: string;
}

export interface TransferResult {
  success: boolean;
  message: string;
  transaction_id?: number;
  new_balance?: number;
  recipient?: string | { id: number; name: string };
  points_sent?: number;
  points_received?: number;
  commission?: number;
}

export interface UseTransferReturn {
  recipientCode: string;
  setRecipientCode: (code: string) => void;
  recipientValid: boolean | null;
  recipientInfo: ReferrerInfo | null;
  validatingCode: boolean;
  transferAmount: number;
  setTransferAmount: (amount: number) => void;
  notes: string;
  setNotes: (notes: string) => void;
  transferring: boolean;
  lastTransferResult: TransferResult | null;
  validateRecipient: (currentUserCode?: string) => Promise<boolean>;
  executeTransfer: (userBalance: number) => Promise<TransferResult | null>;
  resetTransfer: () => void;
  clearRecipient: () => void;
}

export const useTransfer = (): UseTransferReturn => {
  const [recipientCode, setRecipientCode] = useState('');
  const [recipientValid, setRecipientValid] = useState<boolean | null>(null);
  const [recipientInfo, setRecipientInfo] = useState<ReferrerInfo | null>(null);
  const [validatingCode, setValidatingCode] = useState(false);
  const [transferAmount, setTransferAmount] = useState(0);
  const [notes, setNotes] = useState('');
  const [transferring, setTransferring] = useState(false);
  const [lastTransferResult, setLastTransferResult] = useState<TransferResult | null>(null);

  const validateRecipient = useCallback(async (currentUserCode?: string): Promise<boolean> => {
    if (!recipientCode) return false;

    // Verificar auto-transferencia
    if (currentUserCode && recipientCode === currentUserCode) {
      setValidatingCode(true);
      setRecipientValid(false);
      setRecipientInfo(null);
      alertService.error(i18n.t('alerts:wallet.selfTransfer'));
      setValidatingCode(false);
      return false;
    }

    try {
      setValidatingCode(true);
      const response = await pointsService.validateReferralCode(recipientCode);

      if (response.valid && response.referrer) {
        setRecipientValid(true);
        setRecipientInfo(response.referrer);
        secureStorage.setItem('referrerName', response.referrer.name);
        alertService.success(i18n.t('alerts:wallet.recipientValid'));
        return true;
      } else {
        setRecipientValid(false);
        setRecipientInfo(null);
        alertService.error(response.message || i18n.t('alerts:referral.codeInvalid'));
        return false;
      }
    } catch (error: unknown) {
      setRecipientValid(false);
      setRecipientInfo(null);
      const errorMessage = error instanceof Error 
        ? error.message 
        : (error as { response?: { data?: { message?: string } } })?.response?.data?.message || i18n.t('alerts:referral.validationError');
      alertService.error(errorMessage);
      logger.error('useTransfer', 'Error validando código:', error);
      return false;
    } finally {
      setValidatingCode(false);
    }
  }, [recipientCode]);

  const executeTransfer = useCallback(async (userBalance: number): Promise<TransferResult | null> => {
    if (recipientValid !== true || !recipientInfo || transferAmount <= 0) {
      alertService.error(i18n.t('alerts:wallet.invalidRecipient'));
      return null;
    }

    if (userBalance < transferAmount) {
      alertService.error(i18n.t('alerts:wallet.insufficientBalance'));
      return null;
    }

    try {
      setTransferring(true);
      const response = await pointsService.transferPoints(recipientCode, transferAmount, notes);
      const responseData = response.data || response;

      if (responseData && responseData.success) {
        alertService.success(i18n.t('alerts:wallet.transferSuccess'));
        setLastTransferResult(responseData);
        return responseData;
      } else {
        alertService.error(responseData?.message || i18n.t('alerts:wallet.transferError'));
        return null;
      }
    } catch (error: unknown) {
      const errorMessage = error instanceof Error 
        ? error.message 
        : (error as { response?: { data?: { message?: string } } })?.response?.data?.message || i18n.t('alerts:wallet.transferError');
      alertService.error(errorMessage);
      logger.error('useTransfer', 'Error en transferencia:', error);
      return null;
    } finally {
      setTransferring(false);
    }
  }, [recipientCode, recipientValid, recipientInfo, transferAmount, notes]);

  const resetTransfer = useCallback(() => {
    setRecipientCode('');
    setRecipientValid(null);
    setRecipientInfo(null);
    setTransferAmount(0);
    setNotes('');
    setLastTransferResult(null);
    secureStorage.removeItem('referrerName');
  }, []);

  const clearRecipient = useCallback(() => {
    setRecipientValid(null);
    setRecipientInfo(null);
    secureStorage.removeItem('referrerName');
    alertService.info(i18n.t('alerts:wallet.recipientCleared'));
  }, []);

  return {
    recipientCode,
    setRecipientCode,
    recipientValid,
    recipientInfo,
    validatingCode,
    transferAmount,
    setTransferAmount,
    notes,
    setNotes,
    transferring,
    lastTransferResult,
    validateRecipient,
    executeTransfer,
    resetTransfer,
    clearRecipient
  };
};

export default useTransfer;
