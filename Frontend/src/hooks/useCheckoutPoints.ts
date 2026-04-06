import { useState, useEffect } from 'react';
import { pointsService, systemService } from '../services/api';
import { logger } from '../utils/logger';
import alertService from '../services/alertService';
import i18n from '../config/i18n';

interface UseCheckoutPointsReturn {
  userPoints: {balance: number; monetary_value: number} | null;
  systemConfig: any;
  pointsToUse: number;
  pointsLoading: boolean;
  appliedPointsDiscount: number;
  appliedPointsAmount: number;
  setPointsToUse: React.Dispatch<React.SetStateAction<number>>;
  handleApplyPointsDiscount: (discount: number, points: number) => void;
  handleRemovePointsDiscount: () => void;
  formatPrice: (price: number) => string;
}

export const useCheckoutPoints = (isAuthenticated: boolean): UseCheckoutPointsReturn => {
  const [userPoints, setUserPoints] = useState<{balance: number; monetary_value: number} | null>(null);
  const [pointsToUse, setPointsToUse] = useState(0);
  const [systemConfig, setSystemConfig] = useState<any>(null);
  const [pointsLoading, setPointsLoading] = useState(false);
  const [appliedPointsDiscount, setAppliedPointsDiscount] = useState(0);
  const [appliedPointsAmount, setAppliedPointsAmount] = useState(0);

  useEffect(() => {
    const loadPointsData = async () => {
      if (!isAuthenticated) return;
      
      setPointsLoading(true);
      try {
        const [pointsResponse, configResponse] = await Promise.all([
          pointsService.getUserPoints(),
          systemService.getPublicConfig()
        ]);
        
        setUserPoints(pointsResponse.data);
        setSystemConfig(configResponse);
        
        logger.info('useCheckoutPoints', 'Puntos del usuario cargados:', pointsResponse.data);
        logger.info('useCheckoutPoints', 'Configuración del sistema cargada:', configResponse);
      } catch (error) {
        logger.error('useCheckoutPoints', 'Error al cargar datos de puntos:', error);
      } finally {
        setPointsLoading(false);
      }
    };

    loadPointsData();
  }, [isAuthenticated]);

  const handleApplyPointsDiscount = (discount: number, points: number) => {
    setAppliedPointsDiscount(discount);
    setAppliedPointsAmount(points);
    alertService.success(i18n.t('alerts:checkout.pointsApplied'));
  };

  const handleRemovePointsDiscount = () => {
    setAppliedPointsDiscount(0);
    setAppliedPointsAmount(0);
    alertService.info(i18n.t('alerts:checkout.pointsRemoved'));
  };

  const formatPrice = (price: number): string => {
    return new Intl.NumberFormat('es-CO', {
      style: 'currency',
      currency: 'COP',
      minimumFractionDigits: 0,
      maximumFractionDigits: 0
    }).format(price);
  };

  return {
    userPoints,
    systemConfig,
    pointsToUse,
    pointsLoading,
    appliedPointsDiscount,
    appliedPointsAmount,
    setPointsToUse,
    handleApplyPointsDiscount,
    handleRemovePointsDiscount,
    formatPrice,
  };
};
