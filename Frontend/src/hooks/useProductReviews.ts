/**
 * useProductReviews - Hook para gestión de reseñas de un producto
 * 
 * Wrapper conveniente sobre ReviewsContext que auto-carga reseñas
 * y estado can-review al montar el componente.
 * 
 * Uso:
 *   const { reviews, stats, canReviewData, loading, ... } = useProductReviews(productId);
 * 
 * IMPORTANTE: El componente que use este hook debe estar envuelto en <ReviewsProvider>.
 */

import { useEffect, useRef } from 'react';
import { useReviews } from '../contexts/ReviewsContext';

export const useProductReviews = () => {
  const context = useReviews();
  const initialLoadDone = useRef(false);

  const { fetchReviews, fetchCanReview } = context;

  // Carga inicial automática de reseñas y can-review
  useEffect(() => {
    if (initialLoadDone.current) return;
    initialLoadDone.current = true;

    fetchReviews(1);
    fetchCanReview();
  }, [fetchReviews, fetchCanReview]);

  return context;
};

export default useProductReviews;
