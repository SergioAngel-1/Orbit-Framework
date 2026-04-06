/**
 * ProductRating - Rating compacto para tarjetas de producto
 * Muestra estrellas + cantidad de reseñas. Si no hay rating, no renderiza nada.
 */

import { FiStar } from 'react-icons/fi';
import StarRating from '../reviews/atoms/StarRating';

interface ProductRatingProps {
  averageRating: string;
  ratingCount: number;
  /** Modo compacto: sin conteo, alineado a la derecha, tamaño de texto de categoría */
  compact?: boolean;
}

const ProductRating = ({ averageRating, ratingCount, compact = false }: ProductRatingProps) => {
  const rating = parseFloat(averageRating || '0');
  const roundedRating = Math.round(rating);

  if (rating === 0 || ratingCount === 0) {
    return null;
  }

  if (compact) {
    return (
      <div className="flex items-center flex-shrink-0" style={{ gap: '1px' }}>
        {[1, 2, 3, 4, 5].map((star) => (
          <FiStar
            key={star}
            className={`text-2xs sm:text-xs ${star <= roundedRating ? 'text-primario' : 'text-gray-300'}`}
            fill={star <= roundedRating ? 'currentColor' : 'none'}
            strokeWidth={star <= roundedRating ? 1 : 1.5}
          />
        ))}
      </div>
    );
  }

  return (
    <div className="flex items-center gap-1">
      <StarRating rating={roundedRating} size="xs" />
      <span className="text-2xs text-gray-400">
        ({ratingCount})
      </span>
    </div>
  );
};

export default ProductRating;
