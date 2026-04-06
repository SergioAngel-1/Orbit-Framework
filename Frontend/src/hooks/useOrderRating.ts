/**
 * useOrderRating - Hook global para gestión de confirmación y calificación de pedidos
 * 
 * Maneja 2 listas:
 * - pendingConfirmation: pedidos en processing (pendientes de confirmar recepción)
 * - completedUnrated: pedidos completed (pendientes de calificar)
 * 
 * Expone:
 * - pendingConfirmation, completedUnrated, hasPendingOrders
 * - confirmOrder(), rateOrder(), loading, submitting, confirming
 * - modalOpen, openModal(), closeModal()
 * 
 * Debe usarse en componentes que estén dentro de AuthContext.
 */

import { useState, useCallback, useEffect, useRef } from 'react';
import { useAuth } from '../contexts/AuthContext';
import reviewApiService from '../services/reviews/reviewApiService';
import alertService from '../services/alertService';
import logger from '../utils/logger';
import i18n from '../config/i18n';
import type {
  PendingOrder,
  RateOrderFormData,
  RateOrderResponse,
} from '../services/reviews/reviewTypes';

export const useOrderRating = () => {
  const { isAuthenticated } = useAuth();
  const [pendingConfirmation, setPendingConfirmation] = useState<PendingOrder[]>([]);
  const [completedUnrated, setCompletedUnrated] = useState<PendingOrder[]>([]);
  const [reviewPoints, setReviewPoints] = useState(0);
  const [loading, setLoading] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const [modalOpen, setModalOpen] = useState(false);
  const initialLoadDone = useRef(false);

  const hasPendingOrders = pendingConfirmation.length > 0 || completedUnrated.length > 0;

  /**
   * Cargar pedidos pendientes (ambas listas)
   */
  const fetchPendingOrders = useCallback(async (skipCache = false) => {
    if (!isAuthenticated) {
      setPendingConfirmation([]);
      setCompletedUnrated([]);
      return;
    }

    try {
      setLoading(true);
      const data = await reviewApiService.getPendingOrders(skipCache);
      setPendingConfirmation(data.pending_confirmation);
      setCompletedUnrated(data.completed_unrated);
      setReviewPoints(data.review_points);
    } catch (err: any) {
      logger.error('useOrderRating', 'Error al obtener pedidos pendientes:', err);
      setPendingConfirmation([]);
      setCompletedUnrated([]);
    } finally {
      setLoading(false);
    }
  }, [isAuthenticated]);

  /**
   * Confirmar recepción de un pedido (processing → completed)
   * Recibe el objeto PendingOrder completo desde el caller (modal con datos frescos)
   * para evitar depender del estado local potencialmente stale.
   */
  const confirmOrder = useCallback(async (order: PendingOrder): Promise<PendingOrder> => {
    try {
      setConfirming(true);
      await reviewApiService.confirmOrder(order.order_id);

      // Mover de pendingConfirmation a completedUnrated
      const confirmedOrder: PendingOrder = { ...order, status: 'completed' };
      setPendingConfirmation(prev => prev.filter(o => o.order_id !== order.order_id));
      setCompletedUnrated(prev => [confirmedOrder, ...prev]);

      alertService.success(i18n.t('reviews:alerts.orderConfirmed'));
      return confirmedOrder;
    } catch (err: any) {
      logger.error('useOrderRating', 'Error al confirmar pedido:', err);
      alertService.error(i18n.t('reviews:alerts.confirmError'));
      throw err;
    } finally {
      setConfirming(false);
    }
  }, []);

  /**
   * Calificar un pedido (crea reviews en todos sus productos)
   */
  const rateOrder = useCallback(async (formData: RateOrderFormData): Promise<RateOrderResponse> => {
    try {
      setSubmitting(true);
      const result = await reviewApiService.rateOrder(formData);

      // Remover el pedido calificado de completedUnrated
      setCompletedUnrated(prev => prev.filter(o => o.order_id !== formData.order_id));

      alertService.success(i18n.t('reviews:alerts.orderRated'));
      return result;
    } catch (err: any) {
      logger.error('useOrderRating', 'Error al calificar pedido:', err);
      alertService.error(i18n.t('reviews:alerts.rateError'));
      throw err;
    } finally {
      setSubmitting(false);
    }
  }, []);

  const openModal = useCallback(() => setModalOpen(true), []);
  const closeModal = useCallback(() => setModalOpen(false), []);

  // Carga inicial automática
  useEffect(() => {
    if (!isAuthenticated || initialLoadDone.current) return;
    initialLoadDone.current = true;
    fetchPendingOrders();
  }, [isAuthenticated, fetchPendingOrders]);

  // Revalidar cuando la pestaña vuelve a ser visible (sincroniza instancias independientes del hook)
  useEffect(() => {
    if (!isAuthenticated) return;

    const handleVisibility = () => {
      if (document.visibilityState === 'visible' && initialLoadDone.current) {
        fetchPendingOrders(); // usa caché; solo pide al backend si expiró
      }
    };

    document.addEventListener('visibilitychange', handleVisibility);
    return () => document.removeEventListener('visibilitychange', handleVisibility);
  }, [isAuthenticated, fetchPendingOrders]);

  // Reset cuando se desautentica
  useEffect(() => {
    if (!isAuthenticated) {
      setPendingConfirmation([]);
      setCompletedUnrated([]);
      initialLoadDone.current = false;
    }
  }, [isAuthenticated]);

  return {
    pendingConfirmation,
    completedUnrated,
    hasPendingOrders,
    reviewPoints,
    loading,
    submitting,
    confirming,
    modalOpen,
    openModal,
    closeModal,
    confirmOrder,
    rateOrder,
    fetchPendingOrders,
  };
};

export default useOrderRating;
