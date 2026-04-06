/**
 * ProductReviewsSection - Organismo principal del sistema de reseñas
 * 
 * Integra RatingSummary + ReviewForm + lista de ReviewCards dentro
 * de un CollapsibleSection variant="soft". Usa ReviewsProvider + useProductReviews.
 * 
 * Uso:
 *   <ReviewsProvider productId={id}>
 *     <ProductReviewsSection />
 *   </ReviewsProvider>
 */

import { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { FiStar, FiMessageSquare } from 'react-icons/fi';
import { fluidSizing } from '../../utils/fluidSizing';
import CollapsibleSection from '../common/CollapsibleSection';
import Pagination from '../common/Pagination';
import Loader from '../ui/Loader';
import { useProductReviews } from '../../hooks/useProductReviews';
import RatingSummary from './RatingSummary';
import ReviewForm from './ReviewForm';
import ReviewCard from './ReviewCard';
import type { ReviewFormData, ReplyFormData } from '../../services/reviews/reviewTypes';

const ProductReviewsSection = () => {
  const { t } = useTranslation('reviews');
  const {
    productId,
    reviews,
    stats,
    canReviewData,
    loading,
    loadingCanReview,
    submitting,
    error,
    page,
    totalPages,
    fetchReviews,
    submitReview,
    submitReply,
  } = useProductReviews();

  const [expanded, setExpanded] = useState(false);

  // Expandir automáticamente cuando stats carga con reseñas
  useEffect(() => {
    if (stats && stats.total > 0) {
      setExpanded(true);
    }
  }, [stats]);

  const handleSubmitReview = async (formData: ReviewFormData) => {
    return await submitReview(formData);
  };

  const handleReply = async (reviewId: number, content: string) => {
    const formData: ReplyFormData = { content };
    await submitReply(reviewId, formData);
  };

  const subtitle = stats && stats.total > 0
    ? `${stats.average.toFixed(1)} ★ · ${stats.total} ${stats.total === 1 ? t('reviewSingular', 'reseña') : t('reviewPlural', 'reseñas')}`
    : undefined;

  return (
    <CollapsibleSection
      title={t('sectionTitle', 'Reseñas')}
      subtitle={subtitle}
      icon={FiStar}
      variant="soft"
      expanded={expanded}
      onExpandedChange={setExpanded}
      id="reviews"
    >
      {loading && reviews.length === 0 ? (
        <div className="flex justify-center items-center" style={{ padding: fluidSizing.space.xl }}>
          <Loader size="medium" />
        </div>
      ) : (
        <div
          className="grid grid-cols-1 lg:grid-cols-[5fr_9fr] items-stretch"
          style={{ gap: fluidSizing.space.lg }}
        >
          {/* Columna izquierda: Estrellas + Form/Status */}
          <div className="flex flex-col justify-between" style={{ gap: fluidSizing.space.md }}>
            {/* RatingSummary */}
            {stats && (
              <RatingSummary stats={stats} />
            )}

            {/* ReviewForm / Status */}
            <ReviewForm
              productId={productId}
              canReviewData={canReviewData}
              loadingCanReview={loadingCanReview}
              submitting={submitting}
              onSubmit={handleSubmitReview}
            />

            {/* Error */}
            {error && (
              <p className="text-red-500" style={{ fontSize: fluidSizing.text.sm }}>
                {error}
              </p>
            )}
          </div>

          {/* Columna derecha: Reseñas en grid 2x2 */}
          {reviews.length > 0 ? (
            <div className="flex flex-col" style={{ gap: fluidSizing.space.md }}>
              <div
                className={`grid ${reviews.length === 1 ? 'grid-cols-1' : 'grid-cols-1 sm:grid-cols-2'}`}
                style={{ gap: fluidSizing.space.md }}
              >
                {reviews.map((review) => (
                  <ReviewCard key={review.id} review={review} onReply={handleReply} />
                ))}
              </div>
              <Pagination
                currentPage={page}
                totalPages={totalPages}
                onPageChange={fetchReviews}
                ariaLabel={t('pagination.reviews', 'Paginación de reseñas')}
              />
            </div>
          ) : !loading ? (
            <div
              className="flex flex-col items-center justify-center text-center"
              style={{ padding: `${fluidSizing.space.xl} ${fluidSizing.space.lg}`, gap: fluidSizing.space.sm, minHeight: '8rem' }}
            >
              <FiMessageSquare
                className="text-texto/20"
                style={{ width: fluidSizing.size.iconXl, height: fluidSizing.size.iconXl }}
              />
              <p className="text-texto/40" style={{ fontSize: fluidSizing.text.sm }}>
                {t('noReviews', 'Aún no hay reseñas para este producto. ¡Sé el primero!')}
              </p>
            </div>
          ) : null}
        </div>
      )}
    </CollapsibleSection>
  );
};

export default ProductReviewsSection;
