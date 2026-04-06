/**
 * Tipos e interfaces para el sistema de membresías
 * Basado en la API REST del plugin starter-memberships
 */

// ============================================
// TIPOS BASE DE MEMBRESÍA
// ============================================

/**
 * Información básica de un nivel de membresía
 */
export interface MembershipLevel {
  id: number;
  level?: number;
  name: string;
  slug: string;
  slug_en?: string;
  icon: string;
  icon_url: string;
  color: string;
  price_min: number;
  price_max: number;
  /** Virtual Coins que se otorgan UNA ÚNICA VEZ al activar la membresía */
  monthly_points: number;
  description: string;
  is_free: boolean;
  purchasable: boolean;
  admin_only: boolean;
  /** Días mínimos de antigüedad requeridos para acceder a este nivel */
  min_registration_days: number;
  /** Si el usuario actual cumple con la antigüedad mínima */
  user_meets_seniority: boolean;
  /** Días que faltan para que el usuario sea elegible (0 si ya es elegible) */
  user_days_until_eligible: number;
  /** Si existe un producto WooCommerce asociado a este nivel */
  has_product: boolean;
  /** Información del producto WC asociado (si existe) */
  product_info?: {
    product_id: number;
    product_name: string;
    /** Precio actual del producto (puede ser sale_price si existe) */
    product_price: number;
    /** Precio regular del producto */
    product_regular_price: number;
    /** Precio de oferta (null si no hay oferta) */
    product_sale_price: number | null;
    product_permalink: string;
    /** Imagen del producto WC (usada como icono del nivel) */
    product_image: string;
    /** Virtual Coins por periodo configurados en el producto */
    monthly_points: number;
    /** Días mínimos de antigüedad requeridos para comprar este producto */
    min_registration_days: number;
    /** Periodo de renovación: none, monthly, bimonthly, quarterly, biannual, annual */
    renewal_period: 'none' | 'monthly' | 'bimonthly' | 'quarterly' | 'biannual' | 'annual';
    /** Duración de la membresía en días */
    duration_days: number;
  };
}

/**
 * Datos de entregas gratis del usuario
 * Las entregas están asociadas a la MEMBRESÍA, no al usuario
 */
export interface FreeDeliveriesData {
  total_allowed: number;
  used: number;
  remaining: number;
  can_use: boolean;
  /** ID de la membresía activa */
  membership_id?: number | null;
  /** Pedidos pendientes de completar que usan envío gratis por membresía */
  pending_orders_count?: number;
  /** Fecha del último envío gratis usado */
  last_used_at?: string | null;
  /** ID del pedido del último envío gratis usado */
  last_used_order_id?: number | null;
}

/**
 * Datos de muestras gratis del usuario (v2.0)
 * 
 * LÓGICA SIMPLIFICADA:
 * - orders_in_period: Contador acumulativo de pedidos completados
 * - deliveries_earned: floor(orders_in_period / every_orders)
 * - grams_delivered: deliveries_earned * grams_per_delivery
 * - orders_in_current_cycle: orders_in_period % every_orders
 * - orders_until_next: every_orders - orders_in_current_cycle
 */
export interface FreeSamplesData {
  // Configuración del beneficio
  total_grams: number;
  grams_per_delivery: number;
  every_orders: number;
  
  // Estado actual (v2.0)
  /** Total acumulativo de pedidos completados en el período */
  orders_in_period: number;
  /** Pedidos completados en el ciclo actual (0 a every_orders-1) */
  orders_in_current_cycle: number;
  /** Pedidos que faltan para la próxima muestra (null si ya completó todas) */
  orders_until_next: number | null;
  /** Número de entregas de muestra realizadas */
  deliveries_earned: number;
  /** Gramos totales entregados */
  grams_delivered: number;
  /** Gramos restantes por entregar */
  grams_remaining: number;
  /** Si aún puede recibir más muestras */
  can_receive_more: boolean;
  
  // Información de última entrega
  /** Fecha de la última muestra entregada */
  last_delivery_at?: string | null;
  /** Gramos entregados en la última muestra */
  last_delivery_grams?: number | null;
  /** ID del pedido de la última entrega */
  last_delivery_order_id?: number | null;
  /** Verdadero si acaba de recibir muestra (última hora) */
  just_delivered?: boolean;
  
  // Información del período
  /** Fecha de inicio del período de membresía */
  period_start?: string | null;
  
  // Pedidos pendientes
  /** Cantidad de pedidos pendientes de completar */
  pending_orders_count?: number;
  
  // Campos legacy para compatibilidad
  /** @deprecated Usar orders_in_period */
  orders_count?: number;
  /** @deprecated Usar orders_in_current_cycle */
  orders_in_cycle?: number;
}

/**
 * Beneficios activos del usuario
 */
export interface MembershipBenefitsData {
  free_deliveries: FreeDeliveriesData | null;
  free_samples: FreeSamplesData | null;
}

/**
 * Beneficio activo incluido en la respuesta de membresía
 */
export interface ActiveBenefitData {
  key: string;
  name: string;
  description: string;
  display_value: string;
  icon: string;
  categories?: string[];
}

/**
 * Datos completos de la membresía de un usuario
 */
export interface MembershipData {
  level: number;
  name: string;
  slug: string;
  icon: string;
  color: string;
  /** Virtual Coins que se otorgan UNA ÚNICA VEZ al activar la membresía */
  monthly_points: number;
  description: string;
  start_date: string | null;
  end_date: string | null;
  status: MembershipStatus;
  auto_renew: boolean;
  days_remaining: number | null;
  is_active: boolean;
  can_upgrade: boolean;
  /** Periodo de renovación: none, monthly, bimonthly, quarterly, biannual, annual */
  renewal_period?: 'none' | 'monthly' | 'bimonthly' | 'quarterly' | 'biannual' | 'annual';
  /** Etiqueta legible del periodo de renovación */
  renewal_period_label?: string;
  /** Duración de la membresía en días */
  duration_days?: number;
  /** Beneficios especiales (entregas y muestras gratis) */
  benefits?: MembershipBenefitsData;
  /** Todos los beneficios activos (optimización: evita llamada adicional a /benefits/active) */
  active_benefits?: ActiveBenefitData[];
}

/**
 * Estados posibles de una membresía
 */
export type MembershipStatus = 'active' | 'expired' | 'cancelled' | 'none';

// ============================================
// TIPOS DE PRODUCTOS DE MEMBRESÍA
// ============================================

/**
 * Producto de membresía disponible para compra
 */
export interface MembershipProduct {
  id: number;
  name: string;
  price: number;
  regular_price: number;
  sale_price: number | null;
  membership_level: number;
  level_name: string;
  level_icon: string;
  monthly_points: number;
  duration_days: number;
  benefits: string[];
  image: string | null;
  permalink: string;
  // Campos adicionales según el usuario actual
  user_can_purchase: boolean;
  is_current_level: boolean;
  is_upgrade: boolean;
  is_downgrade: boolean;
  purchase_message?: string;
}

/**
 * Respuesta del endpoint de productos de membresía
 */
export interface MembershipProductsResponse {
  success: boolean;
  data: {
    products: MembershipProduct[];
    user_level: number;
    is_logged_in: boolean;
  };
}

// ============================================
// TIPOS DE BENEFICIOS
// ============================================

/**
 * Tipos de beneficios disponibles
 */
export type BenefitType = 
  | 'category_discount'
  | 'referral_bonus'
  | 'points_multiplier'
  | 'free_shipping_threshold'
  | 'birthday_bonus'
  | 'exclusive_products'
  | 'early_access'
  | 'priority_support'
  | 'exclusive_events'
  | 'gift_wrapping'
  | 'extended_returns'
  | 'personal_advisor';

/**
 * Valor de beneficio - puede ser string o objeto con categorías
 */
export type BenefitValue = string | {
  text: string;
  categories?: string[];
};

/**
 * Beneficio formateado para mostrar al usuario
 */
export interface MembershipBenefit {
  key: BenefitType;
  name: string;
  icon: string;
  description: string;
  value?: BenefitValue; // Valor formateado para beneficios variables
  category?: string;
}

/**
 * Configuración de un beneficio (como viene del backend)
 */
export interface BenefitConfig {
  enabled: boolean;
  // Campos variables según el tipo de beneficio
  percentage?: number;
  multiplier?: number;
  amount?: number;
  points?: number;
  categories?: number[];
}

/**
 * Respuesta del endpoint de beneficios del usuario
 */
export interface UserBenefitsResponse {
  success: boolean;
  data: {
    level: number;
    benefits: MembershipBenefit[];
    raw_benefits: Record<BenefitType, BenefitConfig>;
  };
}

/**
 * Respuesta del endpoint de beneficios por nivel
 */
export interface LevelBenefitsResponse {
  success: boolean;
  data: {
    level: number;
    level_info: MembershipLevel;
    benefits: MembershipBenefit[];
  };
}

// ============================================
// TIPOS DE HISTORIAL
// ============================================

/**
 * Acciones posibles en el historial de membresía
 */
export type MembershipAction = 
  | 'activation'
  | 'renewal'
  | 'upgrade'
  | 'downgrade'
  | 'expiration'
  | 'cancellation'
  | 'admin_activation'
  | 'admin_deactivation';

/**
 * Entrada del historial de membresía
 */
export interface MembershipHistoryEntry {
  id: number;
  action: MembershipAction;
  action_label: string;
  old_level: number | null;
  old_level_name: string | null;
  new_level: number | null;
  new_level_name: string | null;
  details: Record<string, any> | null;
  created_at: string;
}

/**
 * Respuesta del endpoint de historial
 */
export interface MembershipHistoryResponse {
  success: boolean;
  data: MembershipHistoryEntry[];
}

// ============================================
// TIPOS DE ESTADÍSTICAS
// ============================================

/**
 * Estadísticas de membresía del usuario
 */
export interface MembershipStats {
  total_memberships: number;
  total_points_received: number;
  current_level: number;
  days_remaining: number;
  is_active: boolean;
  auto_renew: boolean;
}

/**
 * Respuesta del endpoint de estadísticas
 */
export interface MembershipStatsResponse {
  success: boolean;
  data: MembershipStats;
}

// ============================================
// TIPOS DE ACCESO A CATEGORÍAS
// ============================================

/**
 * Información de acceso a una categoría
 */
export interface CategoryAccessInfo {
  category_id: number;
  has_access: boolean;
  user_level: number;
  required_level: number;
  required_level_name: string;
  required_level_icon: string;
  is_public: boolean;
}

/**
 * Respuesta del endpoint de verificación de acceso a categoría
 */
export interface CategoryAccessResponse {
  success: boolean;
  data: CategoryAccessInfo;
}

/**
 * Categoría con información de membresía
 */
export interface AccessibleCategory {
  id: number;
  name: string;
  slug: string;
  count: number;
  min_level: number;
  min_level_name: string;
  min_level_icon: string;
}

/**
 * Respuesta del endpoint de categorías accesibles
 */
export interface AccessibleCategoriesResponse {
  success: boolean;
  data: {
    user_level: number;
    accessible: AccessibleCategory[];
  };
}

// ============================================
// TIPOS DE RESPUESTAS GENÉRICAS
// ============================================

/**
 * Respuesta genérica de la API de membresías
 */
export interface MembershipResponse {
  success: boolean;
  data: MembershipData;
}

/**
 * Respuesta del endpoint de niveles
 */
export interface MembershipLevelsResponse {
  success: boolean;
  data: MembershipLevel[];
}

