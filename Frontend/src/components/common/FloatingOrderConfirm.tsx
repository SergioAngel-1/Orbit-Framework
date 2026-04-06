/**
 * FloatingOrderConfirm - Botón flotante para confirmar/calificar pedidos
 * 
 * Posicionado justo ENCIMA del botón de Instagram (bottom-right).
 * Solo visible si hay pedidos processing sin confirmar O completed sin calificar.
 * Al click → abre OrderConfirmModal (2 secciones) → confirmar recepción o calificar directo.
 */

import { useState, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { FiPackage } from 'react-icons/fi';
import { fluidSizing } from '../../utils/fluidSizing';
import FloatingButton from './FloatingButton';
import useOrderRating from '../../hooks/useOrderRating';
import OrderConfirmModal from '../reviews/OrderConfirmModal';
import OrderRatingModal from '../reviews/OrderRatingModal';
import type { PendingOrder } from '../../services/reviews/reviewTypes';

interface FloatingOrderConfirmProps {
  ready?: boolean;
}

const FloatingOrderConfirm = ({ ready = false }: FloatingOrderConfirmProps) => {
  const { t } = useTranslation('reviews');
  const {
    hasPendingOrders,
    reviewPoints,
    submitting,
    confirming,
    modalOpen,
    openModal,
    closeModal,
    confirmOrder,
    rateOrder,
    fetchPendingOrders,
    loading,
  } = useOrderRating();

  const [confirmModalOpen, setConfirmModalOpen] = useState(false);
  const [orderForRating, setOrderForRating] = useState<PendingOrder | null>(null);

  const handleClick = useCallback(() => {
    setConfirmModalOpen(true);
  }, []);

  const handleCloseConfirm = useCallback(() => {
    setConfirmModalOpen(false);
  }, []);

  // Pedido processing: confirmar recepción → si OK → abrir rating
  const handleConfirmReceived = useCallback(async (order: PendingOrder) => {
    try {
      const confirmedOrder = await confirmOrder(order);
      setOrderForRating(confirmedOrder);
      setConfirmModalOpen(false);
      openModal();
    } catch {
      // Error ya logueado en el hook
    }
  }, [confirmOrder, openModal]);

  // Pedido completed: ir directo al rating
  const handleSelectForRating = useCallback((order: PendingOrder) => {
    setOrderForRating(order);
    setConfirmModalOpen(false);
    openModal();
  }, [openModal]);

  const handleCloseRating = useCallback(() => {
    setOrderForRating(null);
    closeModal();
    // Revalidar desde backend para sincronizar hasPendingOrders con la realidad
    fetchPendingOrders(true);
  }, [closeModal, fetchPendingOrders]);

  if (!hasPendingOrders) {
    return null;
  }

  return (
    <>
      <FloatingButton
        onClick={handleClick}
        icon={<FiPackage style={{ width: '100%', height: '100%' }} />}
        tooltip={t('orderRating.tooltip', 'Confirma tu pedido')}
        badge={
          <span
            className="bg-yellow-400 text-oscuro font-bold rounded-full flex items-center justify-center"
            style={{ width: '1.125rem', height: '1.125rem', fontSize: '0.65rem' }}
          >
            !
          </span>
        }
        ariaLabel={t('orderRating.confirmButton', 'Confirmar pedido')}
        visible={ready && hasPendingOrders && !loading}
        entryDelay={600}
        position={{
          bottom: `calc(max(${fluidSizing.space.lg}, calc(${fluidSizing.space.lg} + env(safe-area-inset-bottom, 0px))) + ${fluidSizing.size.floatingButton} + ${fluidSizing.space.md})`,
          right: `max(${fluidSizing.space.lg}, calc(${fluidSizing.space.lg} + env(safe-area-inset-right, 0px)))`,
        }}
      />

      {/* Modal de confirmación/selección de pedido */}
      <OrderConfirmModal
        isOpen={confirmModalOpen}
        onClose={handleCloseConfirm}
        confirming={confirming}
        onConfirmReceived={handleConfirmReceived}
        onSelectForRating={handleSelectForRating}
      />

      {/* Modal de calificación */}
      {orderForRating && (
        <OrderRatingModal
          isOpen={modalOpen}
          onClose={handleCloseRating}
          order={orderForRating}
          reviewPoints={reviewPoints}
          submitting={submitting}
          onSubmit={rateOrder}
        />
      )}
    </>
  );
};

export default FloatingOrderConfirm;
