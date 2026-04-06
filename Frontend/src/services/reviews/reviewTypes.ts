/**
 * Tipos e interfaces para el sistema de reseñas de productos
 * Basado en la API REST custom /starter/v1/reviews
 */

// ============================================
// TIPOS BASE DE RESEÑAS
// ============================================

/**
 * Autor de una reseña o respuesta
 */
export interface ReviewAuthor {
  id: number;
  name: string;
  avatar: string;
  membership_level: number;
  is_admin: boolean;
}

/**
 * Respuesta a una reseña
 */
export interface ReviewReply {
  id: number;
  product_id: number;
  author: ReviewAuthor;
  rating: number;
  review: string;
  verified_buyer: boolean;
  date_created: string;
  status: 'approved' | 'pending';
}

/**
 * Reseña de producto con respuestas anidadas
 */
export interface Review {
  id: number;
  product_id: number;
  author: ReviewAuthor;
  rating: number;
  review: string;
  verified_buyer: boolean;
  date_created: string;
  status: 'approved' | 'pending';
  replies: ReviewReply[];
}

// ============================================
// TIPOS DE ESTADÍSTICAS
// ============================================

/**
 * Distribución de ratings (1-5 estrellas)
 */
export interface RatingDistribution {
  1: number;
  2: number;
  3: number;
  4: number;
  5: number;
}

/**
 * Estadísticas de ratings de un producto
 */
export interface ReviewStats {
  average: number;
  total: number;
  distribution: RatingDistribution;
  review_points: number;
}

// ============================================
// TIPOS DE RESPUESTAS DE API
// ============================================

/**
 * Respuesta del endpoint GET /reviews/{product_id}
 */
export interface ReviewsListResponse {
  reviews: Review[];
  stats: ReviewStats;
  page: number;
  per_page: number;
  total: number;
  pages: number;
}

/**
 * Respuesta del endpoint GET /reviews/can-review/{product_id}
 */
export interface CanReviewResponse {
  can_review: boolean;
  is_verified_buyer: boolean;
  already_reviewed: boolean;
  reason: 'not_verified_buyer' | 'already_reviewed' | null;
  review_points: number;
}

/**
 * Respuesta del endpoint POST /reviews
 */
export interface CreateReviewResponse {
  success: boolean;
  review: Review;
  points_awarded: number;
}

/**
 * Respuesta del endpoint POST /reviews/{id}/reply
 */
export interface CreateReplyResponse {
  success: boolean;
  reply: ReviewReply;
}

// ============================================
// TIPOS DE FORMULARIO
// ============================================

/**
 * Datos del formulario de creación de reseña
 */
export interface ReviewFormData {
  product_id: number;
  rating: number;
  review: string;
}

/**
 * Datos del formulario de respuesta a reseña
 */
export interface ReplyFormData {
  content: string;
}

// ============================================
// TIPOS DE CALIFICACIÓN DE PEDIDO
// ============================================

/**
 * Item de un pedido pendiente de calificar
 */
export interface PendingOrderItem {
  product_id: number;
  name: string;
  image: string;
  quantity: number;
  already_reviewed: boolean;
}

/**
 * Pedido pendiente de calificar
 */
export interface PendingOrder {
  order_id: number;
  date: string;
  total: string;
  status: 'processing' | 'completed';
  items: PendingOrderItem[];
  all_products_reviewed: boolean;
}

/**
 * Respuesta del endpoint GET /reviews/pending-orders
 */
export interface PendingOrdersResponse {
  pending_confirmation: PendingOrder[];
  completed_unrated: PendingOrder[];
  total: number;
  review_points: number;
}

/**
 * Respuesta del endpoint POST /reviews/confirm-order
 */
export interface ConfirmOrderResponse {
  success: boolean;
  order_id: number;
  new_status: string;
}

/**
 * Datos del formulario de calificación de pedido
 */
export interface RateOrderFormData {
  order_id: number;
  rating: number;
  observation: string;
  review_text?: string;
  received_ok: boolean;
}

/**
 * Respuesta del endpoint POST /reviews/rate-order
 */
export interface RateOrderResponse {
  success: boolean;
  reviews_created: number;
  skipped_products: number;
  points_awarded: number;
}
