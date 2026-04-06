// Tipos e interfaces para el servicio de consultas optimizadas

// Tipos de contenido que pueden cachearse
export type CacheableContentType = 
  | 'product'
  | 'products' 
  | 'category' 
  | 'categories' 
  | 'order' 
  | 'user' 
  | 'banner'
  | 'homeSection'
  | 'legal'
  | 'benefits'
  | 'membership'
  | 'points'
  | 'transactions'
  | 'referrals'
  | 'membershipHistory'
  | 'membershipStats'
  | 'membershipLevels'
  | 'membershipProducts'
  | 'categoryAccess'
  | 'reviews';

// Interfaz para definir la estructura de la caché
export interface CacheItem<T> {
  data: T;
  timestamp: number;
  expiresAt: number;
}

// Interfaz para opciones de consulta optimizada
export interface QueryOptions {
  ttl?: number;               // Tiempo de vida en milisegundos
  skipCache?: boolean;        // Omitir caché para esta solicitud
  forceRefresh?: boolean;     // Forzar actualización incluso si hay datos en caché
  batchKey?: string;          // Clave para agrupar solicitudes en lote
  loadingCallback?: (isLoading: boolean) => void; // Callback para estado de carga
  errorCallback?: (error: any) => void;          // Callback para errores
}

// Definición de interfaz para solicitudes en lote
export interface BatchRequest {
  id: string;
  url: string;
  method: string;
  params?: any;
  data?: any;
  resolve: (value: { data: any }) => void;
  reject: (reason: any) => void;
}

// Valores predeterminados TTL (Time To Live) en milisegundos por tipo de contenido
export const DEFAULT_TTL: Record<CacheableContentType, number> = {
  product: 5 * 60 * 1000,        // 5 minutos para productos individuales
  products: 2 * 60 * 1000,       // 2 minutos para listas de productos
  category: 5 * 60 * 1000,       // 5 minutos para categorías individuales (membership-dependent)
  categories: 5 * 60 * 1000,     // 5 minutos para listas de categorías (membership-dependent)
  order: 0,                      // Sin caché para órdenes (datos sensibles)
  user: 10 * 60 * 1000,          // 10 minutos para datos de usuario
  banner: 30 * 60 * 1000,        // 30 minutos para banners
  homeSection: 5 * 60 * 1000,    // 5 minutos para secciones de inicio
  legal: 60 * 60 * 1000,         // 60 minutos para contenido legal (cambia poco)
  benefits: 5 * 60 * 1000,       // 5 minutos para beneficios activos del usuario
  membership: 2 * 60 * 1000,     // 2 minutos para datos de membresía (cambia con compras)
  points: 5 * 60 * 1000,         // 5 minutos para balance de puntos del usuario
  transactions: 5 * 60 * 1000,   // 5 minutos para historial de transacciones
  referrals: 10 * 60 * 1000,     // 10 minutos para datos de referidos
  membershipHistory: 5 * 60 * 1000,   // 5 minutos para historial de membresía
  membershipStats: 5 * 60 * 1000,     // 5 minutos para estadísticas de membresía
  membershipLevels: 30 * 60 * 1000,   // 30 minutos para niveles (cambian poco)
  membershipProducts: 10 * 60 * 1000, // 10 minutos para productos de membresía
  categoryAccess: 5 * 60 * 1000,      // 5 minutos para acceso a categorías
  reviews: 5 * 60 * 1000               // 5 minutos para reseñas de productos
};
