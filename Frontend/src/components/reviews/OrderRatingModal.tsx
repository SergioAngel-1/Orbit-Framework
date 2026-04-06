/**
 * OrderRatingModal - Modal para calificar un pedido completado
 * 
 * Muestra:
 *  - Lista de productos del pedido (nombre + imagen)
 *  - StarRating interactivo (calificación del pedido)
 *  - Observación del pedido (textarea opcional)
 *  - Reseña para productos (textarea requerida, se aplica a todos los productos)
 *  - VirtualCoinsRewardBadge
 *  - Estado de éxito tras enviar
 */

import { useState, useCallback, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { FiCheckCircle } from 'react-icons/fi';
import AnimatedModal from '../ui/AnimatedModal';
import StarRating from './atoms/StarRating';
import VirtualCoinsRewardBadge from './atoms/VirtualCoinsRewardBadge';
import VirtualCoinPrice from '../common/VirtualCoinPrice';
import { fluidSizing } from '../../utils/fluidSizing';
import type { PendingOrder, RateOrderFormData, RateOrderResponse } from '../../services/reviews/reviewTypes';

interface OrderRatingModalProps {
  isOpen: boolean;
  onClose: () => void;
  order: PendingOrder;
  reviewPoints: number;
  submitting: boolean;
  onSubmit: (formData: RateOrderFormData) => Promise<RateOrderResponse>;
}

const OrderRatingModal = ({ isOpen, onClose, order, reviewPoints, submitting, onSubmit }: OrderRatingModalProps) => {
  const { t } = useTranslation('reviews');
  const [receivedOk, setReceivedOk] = useState<boolean | null>(null);
  const [rating, setRating] = useState(0);
  const [observation, setObservation] = useState('');
  const [reviewText, setReviewText] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<RateOrderResponse | null>(null);

  // Resetear formulario cuando cambia el pedido
  useEffect(() => {
    setReceivedOk(null);
    setRating(0);
    setObservation('');
    setReviewText('');
    setError(null);
    setSuccess(null);
  }, [order.order_id]);

  const handleSubmit = useCallback(async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    if (receivedOk === null) {
      setError(t('orderRating.selectReceivedOk', 'Indica si recibiste a conformidad'));
      return;
    }
    if (rating === 0) {
      setError(t('orderRating.selectRating', 'Selecciona una calificación'));
      return;
    }
    if (!order.all_products_reviewed && reviewText.trim().length < 10) {
      setError(t('orderRating.reviewTooShort', 'La reseña debe tener al menos 10 caracteres.'));
      return;
    }

    try {
      const result = await onSubmit({
        order_id: order.order_id,
        rating,
        observation: observation.trim(),
        ...(order.all_products_reviewed ? {} : { review_text: reviewText.trim() }),
        received_ok: receivedOk!,
      });
      setSuccess(result);
    } catch (err: any) {
      setError(err.response?.data?.message || err.message || t('orderRating.submitError', 'Error al enviar la calificación'));
    }
  }, [receivedOk, rating, observation, reviewText, order.order_id, order.all_products_reviewed, onSubmit, t]);

  const handleClose = () => {
    setReceivedOk(null);
    setRating(0);
    setObservation('');
    setReviewText('');
    setSuccess(null);
    setError(null);
    onClose();
  };

  return (
    <AnimatedModal
      isOpen={isOpen}
      onClose={handleClose}
      title={t('orderRating.modalTitle', { orderId: order.order_id, defaultValue: `Califica tu pedido #${order.order_id}` })}
      maxWidth="max-w-lg"
    >
      {success ? (
        /* Estado de éxito */
        <div className="flex flex-col items-center text-center" style={{ gap: fluidSizing.space.md, padding: fluidSizing.space.md }}>
          <FiCheckCircle className="text-green-500" style={{ width: fluidSizing.size.iconXl, height: fluidSizing.size.iconXl }} />
          <h3 className="text-oscuro font-semibold" style={{ fontSize: fluidSizing.text.lg }}>
            {t('orderRating.successTitle', '¡Pedido calificado!')}
          </h3>
          <p className="text-texto" style={{ fontSize: fluidSizing.text.sm }}>
            {success.reviews_created > 0
              ? t('orderRating.successReviews', { count: success.reviews_created, defaultValue: `Se crearon ${success.reviews_created} reseñas de beneficios.` })
              : t('orderRating.successNoNewReviews', 'Tus beneficios ya tenían reseña. ¡Gracias por calificar tu retiro!')
            }
          </p>
          {success.points_awarded > 0 && (
            <p className="text-yellow-700 font-medium inline-flex flex-wrap items-center justify-center" style={{ fontSize: fluidSizing.text.sm, gap: '4px' }}>
              {t('orderRating.successPointsPrefix', 'Ganaste')}
              <VirtualCoinPrice amount={success.points_awarded} size="xs" showLabel={true} inheritColor />
            </p>
          )}
          <button
            type="button"
            onClick={handleClose}
            className="bg-primario text-white rounded-lg font-medium hover:bg-hover transition-colors"
            style={{ padding: `${fluidSizing.space.sm} ${fluidSizing.space.xl}`, fontSize: fluidSizing.text.sm }}
          >
            {t('orderRating.close', 'Cerrar')}
          </button>
        </div>
      ) : (
        /* Formulario */
        <form onSubmit={handleSubmit} className="flex flex-col" style={{ gap: fluidSizing.space.md }}>
          {/* Productos del pedido */}
          <div>
            <p className="text-texto font-medium" style={{ fontSize: fluidSizing.text.sm, marginBottom: fluidSizing.space.xs }}>
              {t('orderRating.productsLabel', 'Productos del pedido:')}
            </p>
            <div
              className="flex flex-col bg-gray-50 rounded-lg overflow-hidden"
              style={{ maxHeight: '8rem', overflowY: 'auto' }}
            >
              {order.items.map((item, idx) => (
                <div
                  key={`${item.product_id}-${idx}`}
                  className="flex items-center border-b border-gray-100 last:border-0"
                  style={{ padding: fluidSizing.space.xs, gap: fluidSizing.space.sm }}
                >
                  {item.image ? (
                    <img
                      src={item.image}
                      alt={item.name}
                      className="rounded flex-shrink-0 object-cover"
                      style={{ width: '2rem', height: '2rem' }}
                      loading="lazy"
                    />
                  ) : (
                    <div className="rounded flex-shrink-0 bg-gray-200" style={{ width: '2rem', height: '2rem' }} />
                  )}
                  <span className="text-texto truncate flex-1" style={{ fontSize: fluidSizing.text.xs }}>
                    {item.name}
                  </span>
                  {item.quantity > 1 && (
                    <span className="text-texto/50 flex-shrink-0" style={{ fontSize: fluidSizing.text['2xs'] }}>
                      x{item.quantity}
                    </span>
                  )}
                </div>
              ))}
            </div>
          </div>

          {/* FC Reward Hint */}
          {reviewPoints > 0 && (
            <VirtualCoinsRewardBadge points={reviewPoints} />
          )}

          {/* ¿Recibió a conformidad? */}
          <div className="flex flex-col" style={{ gap: fluidSizing.space.xs }}>
            <label className="text-texto font-medium" style={{ fontSize: fluidSizing.text.sm }}>
              {t('orderRating.receivedOkLabel', '¿Recibió a conformidad?')}
            </label>
            <div className="flex" style={{ gap: fluidSizing.space.sm }}>
              <button
                type="button"
                onClick={() => setReceivedOk(true)}
                className={`flex-1 rounded-lg font-medium border transition-colors ${
                  receivedOk === true
                    ? 'bg-green-50 border-green-400 text-green-700'
                    : 'bg-white border-gray-200 text-texto hover:border-gray-300'
                }`}
                style={{ padding: `${fluidSizing.space.sm} ${fluidSizing.space.md}`, fontSize: fluidSizing.text.sm }}
                disabled={submitting}
              >
                {t('orderRating.yes', 'Sí')}
              </button>
              <button
                type="button"
                onClick={() => setReceivedOk(false)}
                className={`flex-1 rounded-lg font-medium border transition-colors ${
                  receivedOk === false
                    ? 'bg-red-50 border-red-400 text-red-700'
                    : 'bg-white border-gray-200 text-texto hover:border-gray-300'
                }`}
                style={{ padding: `${fluidSizing.space.sm} ${fluidSizing.space.md}`, fontSize: fluidSizing.text.sm }}
                disabled={submitting}
              >
                {t('orderRating.no', 'No')}
              </button>
            </div>
          </div>

          {/* Calificación */}
          <div className="flex flex-col" style={{ gap: fluidSizing.space.xs }}>
            <label className="text-texto font-medium" style={{ fontSize: fluidSizing.text.sm }}>
              {t('orderRating.ratingLabel', 'Calificación del pedido')}
            </label>
            <StarRating rating={rating} interactive onChange={setRating} size="lg" />
          </div>

          {/* Observación del pedido */}
          <div className="flex flex-col" style={{ gap: fluidSizing.space.xs }}>
            <label className="text-texto font-medium" style={{ fontSize: fluidSizing.text.sm }}>
              {t('orderRating.observationLabel', 'Observación del pedido')}
              <span className="text-texto/40 font-normal ml-1">({t('orderRating.optional', 'opcional')})</span>
            </label>
            <textarea
              value={observation}
              onChange={(e) => setObservation(e.target.value)}
              placeholder={t('orderRating.observationPlaceholder', '¿Cómo fue tu experiencia con el pedido? (tiempo de entrega, empaque, etc.)')}
              className="w-full border border-gray-200 rounded-lg text-texto focus:outline-none focus:border-primario/50 resize-none transition-colors"
              style={{ fontSize: fluidSizing.text.sm, padding: fluidSizing.space.sm, minHeight: '3.5rem' }}
              maxLength={500}
              disabled={submitting}
            />
          </div>

          {/* Reseña para productos */}
          {order.all_products_reviewed ? (
            <div
              className="flex items-center bg-blue-50 text-blue-700 rounded-lg border border-blue-200"
              style={{ padding: fluidSizing.space.sm, fontSize: fluidSizing.text.xs, gap: fluidSizing.space.xs }}
            >
              <FiCheckCircle className="flex-shrink-0" style={{ width: '1rem', height: '1rem' }} />
              <span>{t('orderRating.allProductsReviewed', 'Ya dejaste tu reseña en todos los beneficios de este retiro. Solo falta tu calificación general.')}</span>
            </div>
          ) : (
            <div className="flex flex-col" style={{ gap: fluidSizing.space.xs }}>
              <label className="text-texto font-medium" style={{ fontSize: fluidSizing.text.sm }}>
                {t('orderRating.reviewLabel', 'Reseña para los productos')}
              </label>
              <textarea
                value={reviewText}
                onChange={(e) => setReviewText(e.target.value)}
                placeholder={t('orderRating.reviewPlaceholder', 'Esta reseña se aplicará a todos los productos del pedido...')}
                className="w-full border border-gray-200 rounded-lg text-texto focus:outline-none focus:border-primario/50 resize-none transition-colors"
                style={{ fontSize: fluidSizing.text.sm, padding: fluidSizing.space.sm, minHeight: '5rem' }}
                maxLength={2000}
                disabled={submitting}
              />
              <div className="flex justify-between" style={{ fontSize: fluidSizing.text['2xs'] }}>
                <span className="text-texto/40">{t('orderRating.minChars', 'Mínimo 10 caracteres')}</span>
                <span className="text-texto/40">{reviewText.length}/2000</span>
              </div>
            </div>
          )}

          {/* Error */}
          {error && (
            <p className="text-red-500" style={{ fontSize: fluidSizing.text.xs }}>{error}</p>
          )}

          {/* Submit */}
          <button
            type="submit"
            className="w-full bg-primario text-white rounded-lg font-medium hover:bg-hover transition-colors disabled:opacity-50"
            style={{ padding: `${fluidSizing.space.sm} ${fluidSizing.space.lg}`, fontSize: fluidSizing.text.sm }}
            disabled={submitting || receivedOk === null || rating === 0 || (!order.all_products_reviewed && reviewText.trim().length < 10)}
          >
            {submitting ? t('orderRating.submitting', 'Enviando...') : t('orderRating.submitButton', 'Enviar calificación')}
          </button>
        </form>
      )}
    </AnimatedModal>
  );
};

export default OrderRatingModal;
