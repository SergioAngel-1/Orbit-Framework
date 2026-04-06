/**
 * ReviewsContext - Contexto para gestionar reseñas de un producto específico
 * 
 * Diseñado para ser usado dentro de ProductDetailPage, envolviendo
 * la sección de reseñas con un ReviewsProvider por producto.
 */

import { createContext, useContext, useState, useCallback, ReactNode, useMemo } from 'react';
import { useAuth } from './AuthContext';
import reviewApiService from '../services/reviews/reviewApiService';
import alertService from '../services/alertService';
import logger from '../utils/logger';
import i18n from '../config/i18n';
import type {
  Review,
  ReviewStats,
  CanReviewResponse,
  ReviewFormData,
  ReplyFormData,
  CreateReviewResponse,
  CreateReplyResponse,
} from '../services/reviews/reviewTypes';

// ============================================
// TIPOS DEL CONTEXTO
// ============================================

interface ReviewsContextType {
  // Producto
  productId: number;
  // Estado
  reviews: Review[];
  stats: ReviewStats | null;
  canReviewData: CanReviewResponse | null;
  loading: boolean;
  loadingCanReview: boolean;
  submitting: boolean;
  error: string | null;
  
  // Paginación
  page: number;
  totalPages: number;
  total: number;

  // Acciones
  fetchReviews: (page?: number) => Promise<void>;
  fetchCanReview: () => Promise<void>;
  submitReview: (formData: ReviewFormData) => Promise<CreateReviewResponse>;
  submitReply: (reviewId: number, formData: ReplyFormData) => Promise<CreateReplyResponse>;
  loadMoreReviews: () => Promise<void>;
}

const ReviewsContext = createContext<ReviewsContextType | undefined>(undefined);

/**
 * Hook para usar el contexto de reseñas
 */
export const useReviews = () => {
  const context = useContext(ReviewsContext);
  if (context === undefined) {
    throw new Error('useReviews debe ser usado dentro de un ReviewsProvider');
  }
  return context;
};

// ============================================
// PROVIDER
// ============================================

interface ReviewsProviderProps {
  productId: number;
  children: ReactNode;
}

/**
 * Provider del contexto de reseñas para un producto específico
 */
export const ReviewsProvider = ({ productId, children }: ReviewsProviderProps) => {
  const { isAuthenticated } = useAuth();

  const [reviews, setReviews] = useState<Review[]>([]);
  const [stats, setStats] = useState<ReviewStats | null>(null);
  const [canReviewData, setCanReviewData] = useState<CanReviewResponse | null>(null);
  const [loading, setLoading] = useState(false);
  const [loadingCanReview, setLoadingCanReview] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(0);
  const [total, setTotal] = useState(0);

  const PER_PAGE = 2;

  /**
   * Cargar reseñas de un producto (reemplaza la lista actual)
   */
  const fetchReviews = useCallback(async (requestedPage = 1) => {
    try {
      setLoading(true);
      setError(null);

      const data = await reviewApiService.getProductReviews(productId, requestedPage, PER_PAGE);

      setReviews(data.reviews);

      setStats(data.stats);
      setPage(data.page);
      setTotalPages(data.pages);
      setTotal(data.total);
    } catch (err: any) {
      logger.error('ReviewsContext', 'Error al cargar reseñas:', err);
      setError(err.message || i18n.t('errors:reviews.loadError', 'Error al cargar las reseñas'));
    } finally {
      setLoading(false);
    }
  }, [productId]);

  /**
   * Cargar más reseñas (paginación incremental, acumula con las existentes)
   */
  const loadMoreReviews = useCallback(async () => {
    if (page >= totalPages || loading) return;

    try {
      setLoading(true);
      setError(null);

      const nextPage = page + 1;
      const data = await reviewApiService.getProductReviews(productId, nextPage, PER_PAGE);

      setReviews(prev => [...prev, ...data.reviews]);
      setStats(data.stats);
      setPage(data.page);
      setTotalPages(data.pages);
      setTotal(data.total);
    } catch (err: any) {
      logger.error('ReviewsContext', 'Error al cargar más reseñas:', err);
      setError(err.message || i18n.t('errors:reviews.loadError', 'Error al cargar las reseñas'));
    } finally {
      setLoading(false);
    }
  }, [page, totalPages, loading, productId]);

  /**
   * Verificar si el usuario puede dejar reseña
   */
  const fetchCanReview = useCallback(async () => {
    if (!isAuthenticated) {
      setCanReviewData(null);
      return;
    }

    try {
      setLoadingCanReview(true);
      const data = await reviewApiService.canReview(productId);
      setCanReviewData(data);
    } catch (err: any) {
      logger.error('ReviewsContext', 'Error al verificar can-review:', err);
      setCanReviewData(null);
    } finally {
      setLoadingCanReview(false);
    }
  }, [productId, isAuthenticated]);

  /**
   * Enviar una nueva reseña
   */
  const submitReview = useCallback(async (formData: ReviewFormData): Promise<CreateReviewResponse> => {
    try {
      setSubmitting(true);
      setError(null);

      const result = await reviewApiService.createReview(formData);

      // Recargar reseñas y can-review después de crear
      await Promise.all([
        fetchReviews(1),
        fetchCanReview(),
      ]);

      alertService.success(i18n.t('reviews:alerts.reviewCreated'));
      return result;
    } catch (err: any) {
      logger.error('ReviewsContext', 'Error al crear reseña:', err);
      const message = err.response?.data?.message || err.message || i18n.t('errors:reviews.submitError', 'Error al enviar la reseña');
      setError(message);
      alertService.error(i18n.t('reviews:alerts.reviewError'));
      throw err;
    } finally {
      setSubmitting(false);
    }
  }, [fetchReviews, fetchCanReview]);

  /**
   * Responder a una reseña
   */
  const submitReply = useCallback(async (reviewId: number, formData: ReplyFormData): Promise<CreateReplyResponse> => {
    try {
      setSubmitting(true);
      setError(null);

      const result = await reviewApiService.replyToReview(reviewId, formData);

      // Recargar reseñas para mostrar la nueva respuesta
      await fetchReviews(1);

      alertService.success(i18n.t('reviews:alerts.replyCreated'));
      return result;
    } catch (err: any) {
      logger.error('ReviewsContext', 'Error al responder reseña:', err);
      const message = err.response?.data?.message || err.message || i18n.t('errors:reviews.replyError', 'Error al enviar la respuesta');
      setError(message);
      alertService.error(i18n.t('reviews:alerts.replyError'));
      throw err;
    } finally {
      setSubmitting(false);
    }
  }, [fetchReviews]);

  // Memoizar el valor del contexto
  const value = useMemo<ReviewsContextType>(() => ({
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
    total,
    fetchReviews,
    fetchCanReview,
    submitReview,
    submitReply,
    loadMoreReviews,
  }), [
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
    total,
    fetchReviews,
    fetchCanReview,
    submitReview,
    submitReply,
    loadMoreReviews,
  ]);

  return (
    <ReviewsContext.Provider value={value}>
      {children}
    </ReviewsContext.Provider>
  );
};

export default ReviewsContext;
