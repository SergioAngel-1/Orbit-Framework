/**
 * StarRating - Componente átomo para mostrar/seleccionar estrellas
 * 
 * Modos:
 *  - display: Muestra estrellas de solo lectura (por defecto)
 *  - interactive: Permite seleccionar rating con hover y click
 */

import { useState, useCallback } from 'react';
import { FiStar } from 'react-icons/fi';
import { fluidSizing } from '../../../utils/fluidSizing';

interface StarRatingProps {
  /** Rating actual (1-5) */
  rating: number;
  /** Modo interactivo para selección */
  interactive?: boolean;
  /** Callback al seleccionar rating (solo en modo interactive) */
  onChange?: (rating: number) => void;
  /** Tamaño de las estrellas */
  size?: 'xs' | 'sm' | 'md' | 'lg';
  /** Mostrar valor numérico al lado */
  showValue?: boolean;
}

const SIZES = {
  xs: fluidSizing.size.iconXs,
  sm: fluidSizing.size.iconSm,
  md: fluidSizing.size.iconMd,
  lg: fluidSizing.size.iconLg,
};

const StarRating = ({ rating, interactive = false, onChange, size = 'md', showValue = false }: StarRatingProps) => {
  const [hoverRating, setHoverRating] = useState(0);
  const starSize = SIZES[size];
  const displayRating = hoverRating || rating;

  const handleClick = useCallback((star: number) => {
    if (interactive && onChange) {
      onChange(star);
    }
  }, [interactive, onChange]);

  const handleMouseEnter = useCallback((star: number) => {
    if (interactive) {
      setHoverRating(star);
    }
  }, [interactive]);

  const handleMouseLeave = useCallback(() => {
    if (interactive) {
      setHoverRating(0);
    }
  }, [interactive]);

  return (
    <div className="flex items-center" style={{ gap: '2px' }}>
      {[1, 2, 3, 4, 5].map((star) => {
        const isFilled = star <= displayRating;
        return (
          <button
            key={star}
            type="button"
            className={`flex-shrink-0 transition-colors ${
              interactive ? 'cursor-pointer hover:scale-110 transition-transform' : 'cursor-default'
            }`}
            style={{ 
              width: starSize, 
              height: starSize,
              padding: 0,
              border: 'none',
              background: 'none',
            }}
            onClick={() => handleClick(star)}
            onMouseEnter={() => handleMouseEnter(star)}
            onMouseLeave={handleMouseLeave}
            tabIndex={interactive ? 0 : -1}
            aria-label={interactive ? `${star} estrella${star > 1 ? 's' : ''}` : undefined}
            disabled={!interactive}
          >
            <FiStar
              style={{ width: '100%', height: '100%' }}
              className={isFilled ? 'text-primario' : 'text-gray-300'}
              fill={isFilled ? 'currentColor' : 'none'}
              strokeWidth={isFilled ? 1 : 1.5}
            />
          </button>
        );
      })}
      {showValue && rating > 0 && (
        <span
          className="text-texto font-semibold ml-1"
          style={{ fontSize: fluidSizing.text.sm }}
        >
          {rating.toFixed(1)}
        </span>
      )}
    </div>
  );
};

export default StarRating;
