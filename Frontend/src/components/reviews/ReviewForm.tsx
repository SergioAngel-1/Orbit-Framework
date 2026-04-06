/**
 * ReviewForm - Formulario para crear una nueva reseña de producto
 * 
 * Comportamiento:
 *  - Si no autenticado → botón "Inicia sesión para reseñar"
 *  - Si no verified buyer → mensaje "Debes comprar este producto"
 *  - Si ya reseñó → mensaje "Ya dejaste tu reseña"
 *  - Si puede reseñar → star selector + textarea + FC reward hint + submit
 */

import { useState, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { useAuth } from '../../contexts/AuthContext';
import { useLanguage } from '../../contexts/LanguageContext';
import { fluidSizing } from '../../utils/fluidSizing';
import { FiLogIn, FiShoppingBag, FiCheckCircle, FiEdit3 } from 'react-icons/fi';
import StarRating from './atoms/StarRating';
import VirtualCoinsRewardBadge from './atoms/VirtualCoinsRewardBadge';
import VirtualCoinPrice from '../common/VirtualCoinPrice';
import AnimatedModal from '../ui/AnimatedModal';
import type { CanReviewResponse, ReviewFormData } from '../../services/reviews/reviewTypes';

interface ReviewFormProps {
  productId: number;
  canReviewData: CanReviewResponse | null;
  loadingCanReview: boolean;
  submitting: boolean;
  onSubmit: (formData: ReviewFormData) => Promise<any>;
}

const ReviewForm = ({ productId, canReviewData, loadingCanReview, submitting, onSubmit }: ReviewFormProps) => {
  const { t } = useTranslation('reviews');
  const { isAuthenticated } = useAuth();
  const { localizedPath } = useLanguage();
  const [showModal, setShowModal] = useState(false);
  const [rating, setRating] = useState(0);
  const [reviewText, setReviewText] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [successPoints, setSuccessPoints] = useState<number | null>(null);

  const handleSubmit = useCallback(async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    if (rating === 0) {
      setError(t('selectRating', 'Selecciona una calificación'));
      return;
    }
    if (reviewText.trim().length < 10) {
      setError(t('reviewTooShort', 'La reseña debe tener al menos 10 caracteres.'));
      return;
    }

    try {
      const result = await onSubmit({ product_id: productId, rating, review: reviewText.trim() });
      setRating(0);
      setReviewText('');
      setSuccessPoints(result?.points_awarded ?? 0);
    } catch (err: any) {
      setError(err.response?.data?.message || err.message || t('submitError', 'Error al enviar la reseña'));
    }
  }, [rating, reviewText, productId, onSubmit, t]);

  // No autenticado
  if (!isAuthenticated) {
    return (
      <div
        className="flex flex-col items-center justify-center text-center bg-secundario/10 rounded-xl border border-secundario/20"
        style={{ padding: fluidSizing.space.md, gap: fluidSizing.space.sm, minHeight: '5rem' }}
      >
        <FiLogIn className="text-primario" style={{ width: fluidSizing.size.iconMd, height: fluidSizing.size.iconMd }} />
        <p className="text-texto/70" style={{ fontSize: fluidSizing.text.sm }}>
          {t('loginToReview', 'Inicia sesión para dejar tu reseña')}
        </p>
        <Link
          to={localizedPath('/iniciar-sesion')}
          className="bg-primario text-white rounded-lg font-medium hover:bg-hover hover:text-white transition-colors"
          style={{ padding: `${fluidSizing.space.xs} ${fluidSizing.space.lg}`, fontSize: fluidSizing.text.sm }}
        >
          {t('loginButton', 'Iniciar sesión')}
        </Link>
      </div>
    );
  }

  // Cargando estado can-review
  if (loadingCanReview || !canReviewData) {
    return null;
  }

  // Ya dejó reseña
  if (canReviewData.already_reviewed) {
    return (
      <div
        className="flex items-center gap-1.5 text-green-600/70"
        style={{ fontSize: fluidSizing.text.xs }}
      >
        <FiCheckCircle style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm, flexShrink: 0 }} />
        {t('alreadyReviewed', 'Ya dejaste tu reseña para este beneficio.')}
      </div>
    );
  }

  // No es verified buyer
  if (!canReviewData.is_verified_buyer) {
    return (
      <div
        className="flex items-center justify-center bg-secundario/10 text-texto/70 rounded-xl border border-secundario/20"
        style={{ padding: fluidSizing.space.md, gap: fluidSizing.space.sm, fontSize: fluidSizing.text.sm, minHeight: '5rem' }}
      >
        <FiShoppingBag className="text-primario" style={{ width: fluidSizing.size.iconMd, height: fluidSizing.size.iconMd, flexShrink: 0 }} />
        {t('mustBuyFirst', 'Debes comprar este producto para poder dejar una reseña.')}
      </div>
    );
  }

  const handleModalClose = () => {
    setShowModal(false);
    setRating(0);
    setReviewText('');
    setError(null);
    setSuccessPoints(null);
  };

  // Botón trigger + modal con el formulario
  return (
    <>
      <button
        type="button"
        onClick={() => setShowModal(true)}
        className="flex items-center justify-center gap-2 w-full bg-primario text-white rounded-lg font-medium hover:bg-hover transition-colors"
        style={{ fontSize: fluidSizing.text.sm, padding: `${fluidSizing.space.sm} ${fluidSizing.space.lg}` }}
      >
        <FiEdit3 style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
        {t('openReviewModal', 'Dar mi opinión de este beneficio')}
      </button>

      <AnimatedModal
        isOpen={showModal}
        onClose={handleModalClose}
        title={t('modalTitle', 'Tu reseña')}
        maxWidth="max-w-lg"
      >
        {successPoints !== null ? (
          /* Estado de éxito */
          <div className="flex flex-col items-center text-center" style={{ gap: fluidSizing.space.md, padding: fluidSizing.space.md }}>
            <FiCheckCircle className="text-green-500" style={{ width: fluidSizing.size.iconXl, height: fluidSizing.size.iconXl }} />
            <h3 className="text-oscuro font-semibold" style={{ fontSize: fluidSizing.text.lg }}>
              {t('reviewSubmitted', '¡Reseña enviada!')}
            </h3>
            {successPoints > 0 && (
              <p className="text-yellow-700 font-medium inline-flex flex-wrap items-center justify-center" style={{ fontSize: fluidSizing.text.sm, gap: '4px' }}>
                {t('pointsAwardedPrefix', 'Has ganado')}
                <VirtualCoinPrice amount={successPoints} size="xs" showLabel={true} inheritColor />
              </p>
            )}
            <button
              type="button"
              onClick={handleModalClose}
              className="bg-primario text-white rounded-lg font-medium hover:bg-hover transition-colors"
              style={{ padding: `${fluidSizing.space.sm} ${fluidSizing.space.xl}`, fontSize: fluidSizing.text.sm }}
            >
              {t('close', 'Cerrar')}
            </button>
          </div>
        ) : (
          /* Formulario */
          <form onSubmit={handleSubmit} className="flex flex-col" style={{ gap: fluidSizing.space.md }}>
            {/* FC Reward Hint */}
            {canReviewData.review_points > 0 && (
              <VirtualCoinsRewardBadge points={canReviewData.review_points} />
            )}

            {/* Star Selector */}
            <div className="flex flex-col" style={{ gap: fluidSizing.space.xs }}>
              <label className="text-texto font-medium" style={{ fontSize: fluidSizing.text.sm }}>
                {t('yourRating', 'Tu calificación')}
              </label>
              <StarRating rating={rating} interactive onChange={setRating} size="lg" />
            </div>

            {/* Textarea */}
            <div className="flex flex-col" style={{ gap: fluidSizing.space.xs }}>
              <label className="text-texto font-medium" style={{ fontSize: fluidSizing.text.sm }}>
                {t('yourReview', 'Tu reseña')}
              </label>
              <textarea
                value={reviewText}
                onChange={(e) => setReviewText(e.target.value)}
                placeholder={t('reviewPlaceholder', 'Cuéntanos tu experiencia con este producto...')}
                className="w-full border border-gray-200 rounded-lg text-texto focus:outline-none focus:border-primario/50 resize-none transition-colors"
                style={{ fontSize: fluidSizing.text.sm, padding: fluidSizing.space.sm, minHeight: '6rem' }}
                maxLength={2000}
                disabled={submitting}
              />
              <div className="flex justify-between" style={{ fontSize: fluidSizing.text['2xs'] }}>
                <span className="text-texto/40">{t('minChars', 'Mínimo 10 caracteres')}</span>
                <span className="text-texto/40">{reviewText.length}/2000</span>
              </div>
            </div>

            {/* Error */}
            {error && (
              <p className="text-red-500" style={{ fontSize: fluidSizing.text.xs }}>{error}</p>
            )}

            {/* Submit */}
            <button
              type="submit"
              className="w-full bg-primario text-white rounded-lg font-medium hover:bg-hover transition-colors disabled:opacity-50"
              style={{ padding: `${fluidSizing.space.sm} ${fluidSizing.space.lg}`, fontSize: fluidSizing.text.sm }}
              disabled={submitting || rating === 0 || reviewText.trim().length < 10}
            >
              {submitting ? t('submitting', 'Enviando...') : t('submitReview', 'Enviar reseña')}
            </button>
          </form>
        )}
      </AnimatedModal>
    </>
  );
};

export default ReviewForm;
