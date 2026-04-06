import { api } from '../apiConfig';
import logger from '../../utils/logger';
import { cacheManager } from '../query/cacheManager';
import type {
  ReviewsListResponse,
  CanReviewResponse,
  CreateReviewResponse,
  CreateReplyResponse,
  ReviewFormData,
  ReplyFormData,
  PendingOrdersResponse,
  RateOrderFormData,
  RateOrderResponse,
  ConfirmOrderResponse,
} from './reviewTypes';

const REVIEWS_CACHE_TTL = 5 * 60 * 1000; // 5 minutos
const PENDING_ORDERS_CACHE_TTL = 2 * 60 * 1000; // 2 minutos

/**
 * Servicio de API para reseñas de productos
 */
const reviewApiService = {
  /**
   * Obtiene las reseñas enriquecidas de un producto
   * @param productId ID del producto
   * @param page Número de página
   * @param perPage Reseñas por página
   */
  getProductReviews(productId: number, page = 1, perPage = 10): Promise<ReviewsListResponse> {
    const cacheKey = `reviews_${productId}_p${page}_pp${perPage}`;
    const cached = cacheManager.get<ReviewsListResponse>(cacheKey);
    if (cached) {
      return Promise.resolve(cached);
    }

    logger.info('reviewApiService', `Obteniendo reseñas del producto ${productId}, página ${page}`);
    return api.get(`/starter/v1/reviews/${productId}`, {
      params: { page, per_page: perPage }
    }).then(response => {
      const data = response.data as ReviewsListResponse;
      cacheManager.set(cacheKey, data, REVIEWS_CACHE_TTL);
      return data;
    });
  },

  /**
   * Verifica si el usuario actual puede dejar reseña en un producto
   * @param productId ID del producto
   */
  canReview(productId: number): Promise<CanReviewResponse> {
    const cacheKey = `reviews_can_${productId}`;
    const cached = cacheManager.get<CanReviewResponse>(cacheKey);
    if (cached) {
      return Promise.resolve(cached);
    }

    logger.info('reviewApiService', `Verificando si puede reseñar producto ${productId}`);
    return api.get(`/starter/v1/reviews/can-review/${productId}`).then(response => {
      const data = response.data as CanReviewResponse;
      cacheManager.set(cacheKey, data, REVIEWS_CACHE_TTL);
      return data;
    });
  },

  /**
   * Crea una nueva reseña de producto
   * @param formData Datos de la reseña
   */
  createReview(formData: ReviewFormData): Promise<CreateReviewResponse> {
    logger.info('reviewApiService', `Creando reseña para producto ${formData.product_id}`);
    return api.post('/starter/v1/reviews', formData).then(response => {
      const data = response.data as CreateReviewResponse;
      // Invalidar caché de reseñas de este producto y la verificación can-review
      reviewApiService.invalidateProductReviewsCache(formData.product_id);
      return data;
    });
  },

  /**
   * Responde a una reseña existente
   * @param reviewId ID de la reseña padre
   * @param formData Datos de la respuesta
   */
  replyToReview(reviewId: number, formData: ReplyFormData): Promise<CreateReplyResponse> {
    logger.info('reviewApiService', `Respondiendo a reseña ${reviewId}`);
    return api.post(`/starter/v1/reviews/${reviewId}/reply`, formData).then(response => {
      const data = response.data as CreateReplyResponse;
      // Invalidar caché — no sabemos el productId aquí, así que invalidamos todo reviews
      reviewApiService.invalidateAllReviewsCache();
      return data;
    });
  },

  /**
   * Invalida la caché de reseñas de un producto específico
   * @param productId ID del producto
   */
  invalidateProductReviewsCache(_productId: number) {
    // Invalidar todas las reseñas cacheadas (keys comienzan con 'reviews')
    cacheManager.invalidateByType('reviews');
    // Invalidar también caché de puntos (se pueden haber otorgado FC)
    cacheManager.invalidateByType('points');
    cacheManager.invalidateByType('transactions');
  },

  /**
   * Invalida toda la caché de reseñas
   */
  invalidateAllReviewsCache() {
    cacheManager.invalidateByType('reviews');
    cacheManager.invalidateByType('points');
    cacheManager.invalidateByType('transactions');
  },

  // ============================================
  // CALIFICACIÓN DE PEDIDO
  // ============================================

  /**
   * Obtiene los pedidos completados pendientes de calificar
   */
  getPendingOrders(skipCache = false): Promise<PendingOrdersResponse> {
    const cacheKey = 'reviews_pending_orders';
    if (!skipCache) {
      const cached = cacheManager.get<PendingOrdersResponse>(cacheKey);
      if (cached) {
        return Promise.resolve(cached);
      }
    }

    logger.info('reviewApiService', 'Obteniendo pedidos pendientes de calificar');
    return api.get('/starter/v1/reviews/pending-orders').then(response => {
      const data = response.data as PendingOrdersResponse;
      cacheManager.set(cacheKey, data, PENDING_ORDERS_CACHE_TTL);
      return data;
    });
  },

  /**
   * Confirma la recepción de un pedido (processing → completed)
   * @param orderId ID del pedido a confirmar
   */
  confirmOrder(orderId: number): Promise<ConfirmOrderResponse> {
    logger.info('reviewApiService', `Confirmando recepción de pedido #${orderId}`);
    return api.post('/starter/v1/reviews/confirm-order', { order_id: orderId }).then(response => {
      const data = response.data as ConfirmOrderResponse;
      // Invalidar caché de pending orders y orders (para OrdersSection)
      cacheManager.invalidateByType('reviews');
      cacheManager.invalidateByType('order');
      return data;
    });
  },

  /**
   * Califica un pedido: crea reviews en todos sus productos
   * @param formData Datos de calificación del pedido
   */
  rateOrder(formData: RateOrderFormData): Promise<RateOrderResponse> {
    logger.info('reviewApiService', `Calificando pedido #${formData.order_id}`);
    return api.post('/starter/v1/reviews/rate-order', formData).then(response => {
      const data = response.data as RateOrderResponse;
      // Invalidar todo: reviews, pending-orders, puntos, orders
      reviewApiService.invalidateAllReviewsCache();
      cacheManager.invalidateByType('order');
      return data;
    });
  },
};

export default reviewApiService;
