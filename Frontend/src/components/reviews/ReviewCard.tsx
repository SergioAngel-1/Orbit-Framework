/**
 * ReviewCard - Molécula que muestra una reseña individual
 * Incluye avatar, nombre, membership badge, estrellas, fecha, texto,
 * verified badge, hilo de respuestas y botón de responder.
 */

import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useAuth } from '../../contexts/AuthContext';
import { useLanguage } from '../../contexts/LanguageContext';
import { fluidSizing } from '../../utils/fluidSizing';
import { FiMessageCircle, FiChevronDown, FiChevronUp } from 'react-icons/fi';
import StarRating from './atoms/StarRating';
import ReviewAvatar from './atoms/ReviewAvatar';
import VerifiedBadge from './atoms/VerifiedBadge';
import ReviewReplyForm from './ReviewReplyForm';
import type { Review, ReviewReply } from '../../services/reviews/reviewTypes';

interface ReviewCardProps {
  review: Review;
  onReply: (reviewId: number, content: string) => Promise<void>;
  /** Si es una respuesta anidada (render más compacto) */
  isReply?: boolean;
}

const ReviewCard = ({ review, onReply, isReply = false }: ReviewCardProps) => {
  const { t } = useTranslation('reviews');
  const { isAuthenticated } = useAuth();
  const { currentLang } = useLanguage();
  const [showReplyForm, setShowReplyForm] = useState(false);
  const [showReplies, setShowReplies] = useState(false);

  const repliesCount = (!isReply && review.replies) ? Math.min(review.replies.length, 3) : 0;

  const formattedDate = new Date(review.date_created).toLocaleDateString(
    currentLang === 'en' ? 'en-US' : 'es-CO',
    { day: 'numeric', month: 'short', year: 'numeric' }
  );

  const handleReply = async (content: string) => {
    await onReply(review.id, content);
    setShowReplyForm(false);
  };

  // Wrapper: card con fondo para padre, indent para respuesta
  const wrapperClass = isReply
    ? 'pl-4 border-l-2 border-primario/15'
    : 'bg-gray-50/70 rounded-xl border border-gray-100/80';

  return (
    <div
      className={wrapperClass}
      style={{
        padding: isReply ? `${fluidSizing.space.sm} 0 0 ${fluidSizing.space.sm}` : fluidSizing.space.md,
      }}
    >
      <div className="flex" style={{ gap: fluidSizing.space.sm }}>
        {/* Avatar */}
        <ReviewAvatar
          avatarUrl={review.author.avatar}
          name={review.author.name}
          isAdmin={review.author.is_admin}
          size={isReply ? '1.75rem' : '2.25rem'}
        />

        {/* Contenido */}
        <div className="flex-1 min-w-0">
          {/* Header: nombre + badges + fecha */}
          <div className="flex flex-wrap items-center" style={{ gap: fluidSizing.space.xs }}>
            <span
              className="font-semibold text-oscuro"
              style={{ fontSize: fluidSizing.text.sm }}
            >
              {review.author.name}
            </span>
            {review.author.is_admin && (
              <span
                className="text-primario bg-primario/10 rounded-full font-medium"
                style={{
                  fontSize: fluidSizing.text['2xs'],
                  padding: `1px ${fluidSizing.space.xs}`,
                }}
              >
                {t('adminBadge', 'Admin')}
              </span>
            )}
            {review.author.membership_level > 0 && !review.author.is_admin && (
              <span
                className="text-texto/50"
                style={{ fontSize: fluidSizing.text['2xs'] }}
              >
                {t('membershipLevel', { level: review.author.membership_level, defaultValue: `Nvl ${review.author.membership_level}` })}
              </span>
            )}
            <span className="text-texto/40" style={{ fontSize: fluidSizing.text['2xs'] }}>
              · {formattedDate}
            </span>
          </div>

          {/* Stars + Verified (solo para reseñas padre) */}
          {!isReply && review.rating > 0 && (
            <div className="flex items-center" style={{ gap: fluidSizing.space.sm, marginTop: '2px' }}>
              <StarRating rating={review.rating} size="sm" />
              {review.verified_buyer && <VerifiedBadge />}
            </div>
          )}

          {/* Texto de la reseña */}
          <p
            className="text-texto"
            style={{
              fontSize: fluidSizing.text.sm,
              lineHeight: 1.6,
              marginTop: fluidSizing.space.xs,
            }}
          >
            {review.review}
          </p>

          {/* Botón de responder (solo para reseñas padre, si autenticado y menos de 3 respuestas) */}
          {!isReply && isAuthenticated && (!review.replies || review.replies.length < 3) && (
            <a
              role="button"
              className="inline-flex items-center text-texto/50 hover:text-primario transition-colors mt-1 cursor-pointer"
              style={{ fontSize: fluidSizing.text['2xs'], gap: '4px' }}
              onClick={() => setShowReplyForm(!showReplyForm)}
            >
              <FiMessageCircle style={{ width: fluidSizing.size.iconXs, height: fluidSizing.size.iconXs }} />
              {t('reply', 'Responder')}
            </a>
          )}

          {/* Formulario de respuesta */}
          {showReplyForm && (
            <div style={{ marginTop: fluidSizing.space.sm }}>
              <ReviewReplyForm
                onSubmit={handleReply}
                onCancel={() => setShowReplyForm(false)}
              />
            </div>
          )}

          {/* Respuestas anidadas (máximo 3, siempre ocultas por defecto) */}
          {!isReply && repliesCount > 0 && (
            <>
              <a
                role="button"
                className="inline-flex items-center text-texto/50 hover:text-primario transition-colors cursor-pointer"
                style={{ fontSize: fluidSizing.text['2xs'], gap: '4px', marginTop: fluidSizing.space.xs }}
                onClick={() => setShowReplies(!showReplies)}
              >
                {showReplies ? (
                  <FiChevronUp style={{ width: fluidSizing.size.iconXs, height: fluidSizing.size.iconXs }} />
                ) : (
                  <FiChevronDown style={{ width: fluidSizing.size.iconXs, height: fluidSizing.size.iconXs }} />
                )}
                {showReplies
                  ? t('hideReplies', 'Ocultar respuestas')
                  : t('showReplies', { count: repliesCount, defaultValue: `Ver ${repliesCount} respuesta${repliesCount !== 1 ? 's' : ''}` })
                }
              </a>

              {showReplies && (
                <div
                  className="flex flex-col"
                  style={{ marginTop: fluidSizing.space.xs, gap: fluidSizing.space.xs }}
                >
                  {review.replies!.slice(0, 3).map((reply: ReviewReply) => (
                    <ReviewCard
                      key={reply.id}
                      review={reply as any}
                      onReply={onReply}
                      isReply
                    />
                  ))}
                </div>
              )}
            </>
          )}
        </div>
      </div>
    </div>
  );
};

export default ReviewCard;
