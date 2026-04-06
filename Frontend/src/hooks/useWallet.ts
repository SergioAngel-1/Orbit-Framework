/**
 * useWallet - Hook personalizado para gestión de wallet/Virtual Coins
 * Maneja el estado de puntos, carga de datos y operaciones relacionadas
 */

import { useState, useEffect, useCallback } from 'react';
import { pointsService, systemService } from '../services/api';
import type { SystemStatus } from '../services/system';
import logger from '../utils/logger';
import i18n from '../config/i18n';

export interface PointsInfo {
  balance: number;
  total_earned: number;
  used: number;
  monetary_value: number;
  conversion_rate: number;
}

export interface Transaction {
  id: number;
  date: string;
  type: string;
  points: number;
  description: string;
  expires_at: string | null;
}

export interface UseWalletReturn {
  points: PointsInfo | null;
  transactions: Transaction[];
  systemStatus: SystemStatus | null;
  loading: boolean;
  error: string | null;
  refreshWallet: () => Promise<void>;
  canUsePoints: boolean;
}

export const useWallet = (): UseWalletReturn => {
  const [points, setPoints] = useState<PointsInfo | null>(null);
  const [transactions, setTransactions] = useState<Transaction[]>([]);
  const [systemStatus, setSystemStatus] = useState<SystemStatus | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const fetchWalletData = useCallback(async () => {
    try {
      setLoading(true);
      setError(null);

      // Obtener estado del sistema
      const systemStatusResponse = await systemService.getSystemStatus();
      setSystemStatus(systemStatusResponse);

      // Obtener puntos solo si el sistema está habilitado y el usuario tiene permisos
      if (systemStatusResponse.systems.points_enabled && systemStatusResponse.user_permissions.can_use_points) {
        const [pointsResponse, transactionsResponse] = await Promise.all([
          pointsService.getUserPoints(),
          pointsService.getPointsTransactions(1, 20)
        ]);

        setPoints(pointsResponse.data);
        setTransactions(transactionsResponse?.data?.transactions || []);
      }
    } catch (err) {
      logger.error('useWallet', 'Error al cargar datos de wallet:', err);
      setError(i18n.t('errors:wallet.loadError'));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchWalletData();
  }, [fetchWalletData]);

  const canUsePoints = (systemStatus?.systems.points_enabled && 
                       systemStatus?.user_permissions.can_use_points) || false;

  return {
    points,
    transactions,
    systemStatus,
    loading,
    error,
    refreshWallet: fetchWalletData,
    canUsePoints
  };
};

export default useWallet;
