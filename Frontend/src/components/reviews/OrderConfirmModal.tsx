/**
 * OrderConfirmModal - Modal para confirmar recepción y calificar pedidos
 * 
 * Flujo:
 *  SECCIÓN PRINCIPAL: Pedidos en processing (pendientes de confirmar recepción)
 *    → Click pedido → muestra detalle + pregunta "¿Lo recibiste?"
 *    → Sí → POST /confirm-order → pasa al rating modal
 *    → No → vuelve a la lista
 *  
 *  DESPLEGABLE: Pedidos completed sin calificar
 *    → Click pedido → pasa directo al rating modal
 */

import { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { FiPackage, FiChevronRight, FiArrowLeft, FiCalendar, FiCheckCircle, FiStar, FiLoader } from 'react-icons/fi';
import AnimatedModal from '../ui/AnimatedModal';
import Loader from '../ui/Loader';
import CollapsibleSection from '../common/CollapsibleSection';
import VirtualCoinsRewardBadge from './atoms/VirtualCoinsRewardBadge';
import VirtualCoinPrice from '../common/VirtualCoinPrice';
import { fluidSizing } from '../../utils/fluidSizing';
import { useLanguage } from '../../contexts/LanguageContext';
import reviewApiService from '../../services/reviews/reviewApiService';
import logger from '../../utils/logger';
import type { PendingOrder } from '../../services/reviews/reviewTypes';

interface OrderConfirmModalProps {
  isOpen: boolean;
  onClose: () => void;
  confirming: boolean;
  onConfirmReceived: (order: PendingOrder) => void;
  onSelectForRating: (order: PendingOrder) => void;
}

const OrderConfirmModal = ({
  isOpen, onClose, confirming,
  onConfirmReceived, onSelectForRating,
}: OrderConfirmModalProps) => {
  const { t } = useTranslation('reviews');
  const { currentLang } = useLanguage();
  const [selectedOrder, setSelectedOrder] = useState<PendingOrder | null>(null);
  const [completedExpanded, setCompletedExpanded] = useState(false);
  const [pendingConfirmation, setPendingConfirmation] = useState<PendingOrder[]>([]);
  const [completedUnrated, setCompletedUnrated] = useState<PendingOrder[]>([]);
  const [reviewPoints, setReviewPoints] = useState(0);
  const [loadingOrders, setLoadingOrders] = useState(false);
  const fetchRef = useRef(0);

  // Fetch fresh data cada vez que se abre el modal
  useEffect(() => {
    if (!isOpen) return;
    setSelectedOrder(null);

    const fetchId = ++fetchRef.current;
    setLoadingOrders(true);

    reviewApiService.getPendingOrders(true).then(data => {
      if (fetchId !== fetchRef.current) return;
      setPendingConfirmation(data.pending_confirmation);
      setCompletedUnrated(data.completed_unrated);
      setReviewPoints(data.review_points);
      setCompletedExpanded(data.pending_confirmation.length === 0);
      // Si solo hay 1 pedido processing, ir directo al detalle (sin importar completed_unrated)
      if (data.pending_confirmation.length === 1) {
        setSelectedOrder(data.pending_confirmation[0]);
      }
    }).catch(err => {
      if (fetchId !== fetchRef.current) return;
      logger.error('OrderConfirmModal', 'Error al obtener pedidos:', err);
      setPendingConfirmation([]);
      setCompletedUnrated([]);
    }).finally(() => {
      if (fetchId !== fetchRef.current) return;
      setLoadingOrders(false);
    });
  }, [isOpen]);

  const formatDate = useCallback((dateString: string) => {
    if (!dateString) return '';
    const date = new Date(dateString);
    if (Number.isNaN(date.getTime())) return '';
    const locale = currentLang === 'en' ? 'en-US' : 'es-CO';
    return date.toLocaleDateString(locale, {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    });
  }, [currentLang]);

  const formatCurrency = useCallback((value: string) => {
    const num = parseFloat(value);
    if (isNaN(num)) return value;
    return num.toLocaleString('es-CO') + ' FC';
  }, []);

  const handleBack = useCallback(() => {
    setSelectedOrder(null);
  }, []);

  const handleConfirmYes = useCallback(() => {
    if (selectedOrder) {
      onConfirmReceived(selectedOrder);
    }
  }, [selectedOrder, onConfirmReceived]);

  const handleClose = useCallback(() => {
    setSelectedOrder(null);
    onClose();
  }, [onClose]);

  const title = useMemo(() => {
    if (selectedOrder) {
      return t('orderConfirm.detailTitle', { orderId: selectedOrder.order_id, defaultValue: `Retiro #${selectedOrder.order_id}` });
    }
    return t('orderConfirm.selectTitle', { defaultValue: 'Confirmar retiro' });
  }, [selectedOrder, t]);

  // ── Render de una tarjeta de pedido (reutilizable para ambas secciones) ──
  const renderOrderCard = useCallback((order: PendingOrder, onClick: () => void) => {
    const isCompleted = order.status === 'completed';
    const badgeClass = isCompleted
      ? 'bg-green-100 text-green-800'
      : 'bg-blue-100 text-blue-800';
    const badgeText = isCompleted
      ? t('orderConfirm.statusCompleted', 'Completado')
      : t('orderConfirm.statusProcessing', 'En proceso');

    return (
      <div
        key={order.order_id}
        role="button"
        tabIndex={0}
        onClick={onClick}
        className="flex flex-col rounded-lg transition-colors text-left group bg-gray-50 hover:bg-gray-100 cursor-pointer"
        style={{ padding: fluidSizing.space.sm, gap: fluidSizing.space.xs }}
      >
        {/* Fila 1: Título + badge estado */}
        <div className="flex items-center justify-between w-full" style={{ gap: fluidSizing.space.xs }}>
          <div className="flex items-center min-w-0" style={{ gap: fluidSizing.space.xs }}>
            <FiPackage className="text-primario flex-shrink-0" style={{ width: '1rem', height: '1rem' }} />
            <span className="text-oscuro font-medium truncate" style={{ fontSize: fluidSizing.text.sm }}>
              {t('orderConfirm.orderLabel', { id: order.order_id, defaultValue: `Retiro #${order.order_id}` })}
            </span>
          </div>
          <span
            className={`inline-flex items-center rounded-full flex-shrink-0 ${badgeClass}`}
            style={{ padding: `1px ${fluidSizing.space.xs}`, fontSize: '0.6rem', lineHeight: '1.4' }}
          >
            {badgeText}
          </span>
        </div>

        {/* Fila 2: Fecha · productos · total */}
        <div className="flex items-center justify-between w-full">
          <div className="flex items-center flex-wrap text-texto" style={{ gap: fluidSizing.space.xs, fontSize: fluidSizing.text.xs }}>
            <div className="flex items-center" style={{ gap: '3px' }}>
              <FiCalendar className="flex-shrink-0" style={{ width: '0.7rem', height: '0.7rem' }} />
              <span className="whitespace-nowrap">{formatDate(order.date)}</span>
            </div>
            <span className="text-texto/30">•</span>
            <span className="whitespace-nowrap">{order.items.length} {order.items.length === 1 ? t('orderConfirm.product', 'producto') : t('orderConfirm.products', 'productos')}</span>
          </div>
          <div className="flex-shrink-0 flex items-center" style={{ gap: '4px', marginLeft: fluidSizing.space.xs }}>
            <span className="text-primario font-semibold whitespace-nowrap" style={{ fontSize: fluidSizing.text.xs }}>
              {formatCurrency(order.total)}
            </span>
            <FiChevronRight className="text-texto/40 group-hover:text-primario transition-colors" style={{ width: '0.875rem', height: '0.875rem' }} />
          </div>
        </div>

        {/* Fila 3: Mini thumbnails */}
        <div className="flex items-center" style={{ gap: '2px' }}>
          {order.items.slice(0, 4).map((item, idx) => (
            item.image ? (
              <img
                key={`${item.product_id}-${idx}`}
                src={item.image}
                alt={item.name}
                className="rounded flex-shrink-0 object-cover border border-gray-200"
                style={{ width: '1.5rem', height: '1.5rem' }}
                loading="lazy"
              />
            ) : (
              <div
                key={`${item.product_id}-${idx}`}
                className="rounded flex-shrink-0 bg-gray-200 border border-gray-200"
                style={{ width: '1.5rem', height: '1.5rem' }}
              />
            )
          ))}
          {order.items.length > 4 && (
            <span className="text-texto/50" style={{ fontSize: fluidSizing.text['2xs'], marginLeft: '2px' }}>
              +{order.items.length - 4}
            </span>
          )}
        </div>
      </div>
    );
  }, [t, formatDate, formatCurrency]);

  // ── Render del detalle de un pedido processing seleccionado ──
  const renderOrderDetail = () => {
    if (!selectedOrder) return null;
    const hasOthers = pendingConfirmation.length > 1 || completedUnrated.length > 0;

    return (
      <div className="flex flex-col" style={{ gap: fluidSizing.space.md }}>
        {/* Volver a la lista */}
        {hasOthers && (
          <a
            href="#"
            onClick={(e) => { e.preventDefault(); if (!confirming) handleBack(); }}
            className="text-primario hover:text-hover transition-colors underline self-start inline-flex items-center"
            style={{ fontSize: fluidSizing.text.xs, gap: '3px' }}
          >
            <FiArrowLeft style={{ width: '0.75rem', height: '0.75rem' }} />
            {t('orderConfirm.backToList', 'Ver otros pedidos')}
          </a>
        )}

        {/* Info general del pedido */}
        <div className="flex items-center justify-between">
          <div className="flex items-center" style={{ gap: fluidSizing.space.xs }}>
            <FiCalendar className="text-texto/50" style={{ width: '0.875rem', height: '0.875rem' }} />
            <span className="text-texto" style={{ fontSize: fluidSizing.text.xs }}>
              {formatDate(selectedOrder.date)}
            </span>
          </div>
          <span className="text-primario font-semibold" style={{ fontSize: fluidSizing.text.sm }}>
            {formatCurrency(selectedOrder.total)}
          </span>
        </div>

        {/* Lista de productos */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.sm }}>
          <p className="text-texto font-medium" style={{ fontSize: fluidSizing.text.xs }}>
            {t('orderConfirm.productsInOrder', 'Productos del pedido:')}
          </p>
          <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.xs, maxHeight: '14rem', overflowY: 'auto' }}>
            {selectedOrder.items.map((item, idx) => (
              <div
                key={`${item.product_id}-${idx}`}
                className="flex items-center bg-gray-50 rounded-lg"
                style={{ padding: fluidSizing.space.sm, gap: fluidSizing.space.sm }}
              >
                <div
                  className="flex-shrink-0 bg-white rounded-md overflow-hidden"
                  style={{ width: fluidSizing.size.buttonLg, height: fluidSizing.size.buttonLg }}
                >
                  {item.image ? (
                    <img
                      src={item.image}
                      alt={item.name}
                      className="w-full h-full object-cover"
                      loading="lazy"
                    />
                  ) : (
                    <div className="w-full h-full bg-gray-200" />
                  )}
                </div>
                <div className="flex-1 min-w-0">
                  <p className="text-oscuro font-medium truncate" style={{ fontSize: fluidSizing.text.xs }}>
                    {item.name}
                  </p>
                  <span className="text-texto" style={{ fontSize: fluidSizing.text.xs }}>
                    x{item.quantity}
                  </span>
                </div>
              </div>
            ))}
          </div>
        </div>

        {/* FC Reward */}
        {reviewPoints > 0 && (
          <VirtualCoinsRewardBadge points={reviewPoints} />
        )}

        {/* Pregunta de confirmación */}
        <div className="flex flex-col" style={{ gap: fluidSizing.space.sm }}>
          <p className="text-oscuro font-medium text-center" style={{ fontSize: fluidSizing.text.sm }}>
            {t('orderConfirm.receivedQuestion', '¿Recibiste este pedido?')}
          </p>
          <div className="flex" style={{ gap: fluidSizing.space.sm }}>
            <button
              type="button"
              onClick={handleBack}
              className="flex-1 flex items-center justify-center border-2 border-gray-200 text-texto rounded-lg font-medium hover:border-gray-300 transition-colors"
              style={{ padding: `${fluidSizing.space.sm} ${fluidSizing.space.md}`, fontSize: fluidSizing.text.sm }}
              disabled={confirming}
            >
              {t('orderConfirm.receivedNo', 'No, aún no')}
            </button>
            <button
              type="button"
              onClick={handleConfirmYes}
              className="flex-1 flex items-center justify-center bg-primario text-white rounded-lg font-medium hover:bg-hover transition-colors disabled:opacity-50"
              style={{ padding: `${fluidSizing.space.sm} ${fluidSizing.space.md}`, fontSize: fluidSizing.text.sm, gap: fluidSizing.space.xs }}
              disabled={confirming}
            >
              {confirming ? (
                <>
                  <FiLoader className="animate-spin" style={{ width: '1rem', height: '1rem' }} />
                  {t('orderConfirm.confirming', 'Confirmando...')}
                </>
              ) : (
                <>
                  <FiCheckCircle style={{ width: '1rem', height: '1rem' }} />
                  {t('orderConfirm.receivedYes', 'Sí, lo recibí')}
                </>
              )}
            </button>
          </div>
        </div>
      </div>
    );
  };

  return (
    <AnimatedModal
      isOpen={isOpen}
      onClose={handleClose}
      title={title}
      maxWidth="max-w-lg"
    >
      {/* Loader mientras se cargan los pedidos */}
      {loadingOrders ? (
        <div style={{ padding: fluidSizing.space.lg }}>
          <Loader size="small" />
        </div>
      ) : selectedOrder ? renderOrderDetail() : (
        <div className="flex flex-col" style={{ gap: fluidSizing.space.md }}>

          {/* SECCIÓN PRINCIPAL: Pedidos pendientes de confirmar (processing) */}
          {pendingConfirmation.length === 0 && completedUnrated.length > 0 && (
            <p className="text-texto text-center" style={{ fontSize: fluidSizing.text.sm }}>
              {t('orderConfirm.noProcessing', 'No tienes retiros pendientes de confirmar. ¡Pero puedes calificar los que ya recibiste!')}
            </p>
          )}
          {pendingConfirmation.length > 0 && (
            <div className="flex flex-col" style={{ gap: fluidSizing.space.sm }}>
              <p className="text-texto" style={{ fontSize: fluidSizing.text.sm }}>
                {t('orderConfirm.pendingDescription', {
                  count: pendingConfirmation.length,
                  defaultValue: `Tienes ${pendingConfirmation.length} retiro${pendingConfirmation.length > 1 ? 's' : ''} por confirmar. ¿Cuál recibiste?`
                })}
              </p>

              {reviewPoints > 0 && (
                <VirtualCoinsRewardBadge points={reviewPoints} />
              )}

              <div className="flex flex-col bg-secundario/20 rounded-lg" style={{ gap: fluidSizing.space.xs, maxHeight: '16rem', overflowY: 'auto', padding: fluidSizing.space.sm }}>
                {pendingConfirmation.map((order) =>
                  renderOrderCard(order, () => setSelectedOrder(order))
                )}
              </div>
            </div>
          )}

          {/* DESPLEGABLE: Pedidos completados sin calificar */}
          {completedUnrated.length > 0 && (
            <CollapsibleSection
              title={t('orderConfirm.previousOrdersTitle', 'Califica y gana')}
              icon={FiStar}
              variant="soft"
              defaultExpanded={false}
              expanded={completedExpanded}
              onExpandedChange={setCompletedExpanded}
            >
              <p className="text-texto" style={{ fontSize: fluidSizing.text.xs, marginBottom: fluidSizing.space.sm }}>
                {t('orderConfirm.previousOrdersPrefix', 'Tienes retiros entregados sin calificar. Déjanos tu opinión y gana')}{' '}
                {reviewPoints > 0 && <><VirtualCoinPrice amount={reviewPoints} size="xs" showLabel={true} inheritColor className="inline-flex align-middle" />{' '}</>}
                {t('orderConfirm.previousOrdersSuffix', 'por cada uno.')}
              </p>
              <div className="flex flex-col bg-secundario/20 rounded-lg" style={{ gap: fluidSizing.space.xs, maxHeight: '16rem', overflowY: 'auto', padding: fluidSizing.space.sm }}>
                {completedUnrated.map((order) =>
                  renderOrderCard(order, () => onSelectForRating(order))
                )}
              </div>
            </CollapsibleSection>
          )}

          {/* Si no hay nada */}
          {pendingConfirmation.length === 0 && completedUnrated.length === 0 && (
            <p className="text-texto text-center" style={{ fontSize: fluidSizing.text.sm, padding: fluidSizing.space.md }}>
              {t('orderConfirm.noPendingOrders', 'No tienes pedidos pendientes')}
            </p>
          )}
        </div>
      )}
    </AnimatedModal>
  );
};

export default OrderConfirmModal;
