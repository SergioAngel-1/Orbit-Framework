/**
 * RatingSummary - Resumen de calificaciones de un producto
 * Muestra: promedio grande + estrellas + barras de distribución (5→1) + total count
 */

import { useTranslation } from 'react-i18next';
import { fluidSizing } from '../../utils/fluidSizing';
import StarRating from './atoms/StarRating';
import type { ReviewStats } from '../../services/reviews/reviewTypes';

interface RatingSummaryProps {
  stats: ReviewStats;
}

const RatingSummary = ({ stats }: RatingSummaryProps) => {
  const { t } = useTranslation('reviews');
  const { average, total, distribution } = stats;

  return (
    <div className="flex items-center" style={{ gap: fluidSizing.space.md }}>
      {/* Promedio grande */}
      <div className="flex flex-col items-center flex-shrink-0">
        <span
          className="font-bold text-oscuro"
          style={{ fontSize: fluidSizing.text['3xl'], lineHeight: 1 }}
        >
          {average > 0 ? average.toFixed(1) : '—'}
        </span>
        <StarRating rating={Math.round(average)} size="sm" />
        <span
          className="text-texto/50 mt-1"
          style={{ fontSize: fluidSizing.text['2xs'] }}
        >
          {t('totalReviews', { count: total, defaultValue: `${total} reseña${total !== 1 ? 's' : ''}` })}
        </span>
      </div>

      {/* Barras de distribución */}
      <div className="flex-1 flex flex-col" style={{ gap: '3px' }}>
        {[5, 4, 3, 2, 1].map((star) => {
          const count = distribution[star as keyof typeof distribution] || 0;
          const percentage = total > 0 ? (count / total) * 100 : 0;

          return (
            <div key={star} className="flex items-center" style={{ gap: fluidSizing.space.xs }}>
              <span
                className="text-texto/60 font-medium flex-shrink-0 text-right"
                style={{ fontSize: fluidSizing.text['2xs'], width: '0.75rem' }}
              >
                {star}
              </span>
              <div
                className="flex-1 bg-gray-100 rounded-full overflow-hidden"
                style={{ height: '0.375rem' }}
              >
                <div
                  className="h-full rounded-full transition-all duration-500"
                  style={{
                    width: `${percentage}%`,
                    background: 'linear-gradient(90deg, #FBBF24, #F59E0B)',
                    minWidth: count > 0 ? '4px' : 0,
                  }}
                />
              </div>
              <span
                className="text-texto/40 flex-shrink-0 text-right"
                style={{ fontSize: fluidSizing.text['2xs'], width: '1.25rem' }}
              >
                {count}
              </span>
            </div>
          );
        })}
      </div>
    </div>
  );
};

export default RatingSummary;
