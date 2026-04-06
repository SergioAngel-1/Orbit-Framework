import { useState, useEffect } from 'react';
import { systemService } from '../services/api';
import logger from '../utils/logger';
import i18n from '../config/i18n';

interface MinimumOrderState {
  minimumAmount: number;
  currency: string;
  currencySymbol: string;
  formatted: string;
  loading: boolean;
  error: string | null;
}

/**
 * Hook para obtener y gestionar la configuración de pedido mínimo
 * @returns Estado del pedido mínimo
 */
export const useMinimumOrder = () => {
  const [state, setState] = useState<MinimumOrderState>({
    minimumAmount: 0,
    currency: 'COP',
    currencySymbol: '$',
    formatted: '$0',
    loading: true,
    error: null
  });

  useEffect(() => {
    const fetchMinimumOrder = async () => {
      try {
        setState(prev => ({ ...prev, loading: true, error: null }));
        
        logger.info('useMinimumOrder', 'Obteniendo configuración de pedido mínimo...');
        
        const config = await systemService.getMinimumOrder();
        
        setState({
          minimumAmount: config.minimum_amount,
          currency: config.currency,
          currencySymbol: config.currency_symbol,
          formatted: config.formatted,
          loading: false,
          error: null
        });
        
        logger.info('useMinimumOrder', `Pedido mínimo configurado: ${config.formatted}`);
      } catch (error) {
        logger.error('useMinimumOrder', 'Error al obtener pedido mínimo:', error);
        
        setState(prev => ({
          ...prev,
          loading: false,
          error: error instanceof Error ? error.message : i18n.t('errors:cart.loadMinimumOrder')
        }));
      }
    };

    fetchMinimumOrder();
  }, []);

  /**
   * Verificar si un monto cumple con el pedido mínimo
   */
  const meetsMinimum = (amount: number): boolean => {
    // Si el mínimo es 0, siempre cumple
    if (state.minimumAmount === 0) return true;
    
    return amount >= state.minimumAmount;
  };

  /**
   * Calcular cuánto falta para alcanzar el pedido mínimo
   */
  const getMissingAmount = (currentAmount: number): number => {
    if (state.minimumAmount === 0) return 0;
    
    const missing = state.minimumAmount - currentAmount;
    return missing > 0 ? missing : 0;
  };

  /**
   * Calcular el porcentaje de progreso hacia el pedido mínimo
   */
  const getProgress = (currentAmount: number): number => {
    if (state.minimumAmount === 0) return 100;
    
    const progress = (currentAmount / state.minimumAmount) * 100;
    return Math.min(progress, 100);
  };

  return {
    ...state,
    meetsMinimum,
    getMissingAmount,
    getProgress
  };
};

export default useMinimumOrder;
