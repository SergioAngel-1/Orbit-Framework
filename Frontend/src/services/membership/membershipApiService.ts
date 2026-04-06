/**
 * Servicio de API para membresías
 * Consume todos los endpoints del plugin starter-memberships
 */

import { api } from '../apiConfig';
import logger from '../../utils/logger';
import { cacheManager } from '../query/cacheManager';
import { DEFAULT_TTL } from '../query/types';
import {
  MembershipData,
  MembershipResponse,
  MembershipLevel,
  MembershipLevelsResponse,
  MembershipProduct,
  MembershipProductsResponse,
  MembershipBenefit,
  LevelBenefitsResponse,
  MembershipHistoryEntry,
  MembershipHistoryResponse,
  MembershipStats,
  MembershipStatsResponse,
  CategoryAccessInfo,
  CategoryAccessResponse,
  AccessibleCategory,
  AccessibleCategoriesResponse,
} from './membershipTypes';

// ============================================
// MEMBRESÍA DEL USUARIO ACTUAL
// ============================================

/**
 * Obtener la membresía actual del usuario autenticado
 * Endpoint: GET /starter/v1/membership
 * 
 * IMPORTANTE: Si hay un error 401, se propaga el error para que el contexto
 * pueda manejar el logout correctamente. NO retornamos membresía por defecto
 * silenciosamente porque eso causa inconsistencia entre Auth y Membership.
 */
export const getCurrentMembership = async (): Promise<MembershipData | null> => {
  try {
    const response = await api.get<MembershipResponse>('/starter/v1/membership');
    
    if (response.data.success && response.data.data) {
      logger.info('membershipApiService', 'Membresía del usuario obtenida');
      return response.data.data;
    }
    
    return null;
  } catch (error: any) {
    // Si es un error 401, propagar el error para que el contexto maneje el logout
    // NO retornar membresía por defecto silenciosamente - esto causa el bug de
    // "membresía básica" mientras el usuario sigue "autenticado"
    if (error.response?.status === 401) {
      logger.warn('membershipApiService', 'Error 401 al obtener membresía - token posiblemente expirado');
      // Propagar el error con información adicional
      error.isAuthError = true;
      throw error;
    }
    
    // CRÍTICO: Para errores de red/servidor, propagar el error en lugar de retornar default
    // Esto permite que el contexto maneje el retry y evita que el usuario pierda su membresía
    // por un error temporal de red
    logger.error('membershipApiService', 'Error al obtener membresía del usuario', error);
    
    // Marcar como error de red para que el contexto pueda decidir qué hacer
    error.isNetworkError = true;
    throw error;
  }
};

/**
 * Obtener membresía por defecto (nivel 0 - Zanahoria)
 * Datos hardcodeados del nivel 0 para usuarios anónimos o sin membresía
 * Estos valores deben coincidir con Starter_Memberships::$membership_levels[0] del backend
 */
export const getDefaultMembership = (): MembershipData => {
  return {
    level: 0,
    name: 'Zanahoria',
    slug: 'zanahoria',
    icon: '🥕',
    color: '#FF6B35',
    monthly_points: 0,
    description: 'Nivel gratuito - Acceso básico',
    start_date: null,
    end_date: null,
    status: 'none',
    auto_renew: false,
    days_remaining: null,
    is_active: false,
    can_upgrade: true,
  };
};

/**
 * Verificar si el usuario tiene acceso a un nivel específico
 */
export const hasAccessToLevel = (userLevel: number, requiredLevel: number): boolean => {
  return userLevel >= requiredLevel;
};

// ============================================
// HISTORIAL DE MEMBRESÍAS
// ============================================

/**
 * Obtener historial de membresías del usuario
 * Endpoint: GET /starter/v1/membership/history
 */
export const getMembershipHistory = async (limit: number = 10): Promise<MembershipHistoryEntry[]> => {
  const cacheKey = cacheManager.buildCacheKey('membershipHistory', null, { limit });
  const cached = cacheManager.get<MembershipHistoryEntry[]>(cacheKey);
  if (cached) return cached;

  try {
    const response = await api.get<MembershipHistoryResponse>('/starter/v1/membership/history', {
      params: { limit }
    });
    
    if (response.data.success && response.data.data) {
      logger.info('membershipApiService', `Historial obtenido: ${response.data.data.length} entradas`);
      cacheManager.set(cacheKey, response.data.data, DEFAULT_TTL.membershipHistory);
      return response.data.data;
    }
    
    return [];
  } catch (error: any) {
    logger.error('membershipApiService', 'Error al obtener historial de membresías', error);
    return [];
  }
};

// ============================================
// ESTADÍSTICAS DE MEMBRESÍA
// ============================================

/**
 * Obtener estadísticas de membresía del usuario
 * Endpoint: GET /starter/v1/membership/stats
 */
export const getMembershipStats = async (): Promise<MembershipStats | null> => {
  const cacheKey = cacheManager.buildCacheKey('membershipStats', null);
  const cached = cacheManager.get<MembershipStats>(cacheKey);
  if (cached) return cached;

  try {
    const response = await api.get<MembershipStatsResponse>('/starter/v1/membership/stats');
    
    if (response.data.success && response.data.data) {
      logger.info('membershipApiService', 'Estadísticas de membresía obtenidas');
      cacheManager.set(cacheKey, response.data.data, DEFAULT_TTL.membershipStats);
      return response.data.data;
    }
    
    return null;
  } catch (error: any) {
    logger.error('membershipApiService', 'Error al obtener estadísticas de membresía', error);
    return null;
  }
};

// ============================================
// BENEFICIOS
// ============================================

/**
 * NOTA: Para obtener beneficios activos del usuario, usar:
 * import benefitsApiService from './benefitsApiService';
 * benefitsApiService.getActiveBenefits();
 * 
 * Endpoint: GET /starter/v1/membership/benefits/active
 * Hook: useActiveBenefits(isAuthenticated)
 */

/**
 * Obtener beneficios de un nivel específico (público)
 * Endpoint: GET /starter/v1/memberships/levels/{level}/benefits
 */
export const getLevelBenefits = async (level: number): Promise<MembershipBenefit[]> => {
  const cacheKey = cacheManager.buildCacheKey('benefits', level);
  const cached = cacheManager.get<MembershipBenefit[]>(cacheKey);
  if (cached) return cached;

  try {
    const response = await api.get<LevelBenefitsResponse>(`/starter/v1/memberships/levels/${level}/benefits`);
    
    if (response.data.success && response.data.data) {
      logger.info('membershipApiService', `Beneficios del nivel ${level} obtenidos`);
      cacheManager.set(cacheKey, response.data.data.benefits, DEFAULT_TTL.benefits);
      return response.data.data.benefits;
    }
    
    return [];
  } catch (error: any) {
    logger.error('membershipApiService', `Error al obtener beneficios del nivel ${level}`, error);
    return [];
  }
};

// ============================================
// PRODUCTOS DE MEMBRESÍA
// ============================================

/**
 * Obtener todos los productos de membresía disponibles
 * Endpoint: GET /starter/v1/memberships/products
 */
export const getMembershipProducts = async (): Promise<{
  products: MembershipProduct[];
  userLevel: number;
  isLoggedIn: boolean;
}> => {
  const cacheKey = cacheManager.buildCacheKey('membershipProducts', null);
  const cached = cacheManager.get<{ products: MembershipProduct[]; userLevel: number; isLoggedIn: boolean }>(cacheKey);
  if (cached) return cached;

  try {
    const response = await api.get<MembershipProductsResponse>('/starter/v1/memberships/products');
    
    if (response.data.success && response.data.data) {
      logger.info('membershipApiService', `Productos de membresía obtenidos: ${response.data.data.products.length}`);
      const result = {
        products: response.data.data.products,
        userLevel: response.data.data.user_level,
        isLoggedIn: response.data.data.is_logged_in,
      };
      cacheManager.set(cacheKey, result, DEFAULT_TTL.membershipProducts);
      return result;
    }
    
    return { products: [], userLevel: 0, isLoggedIn: false };
  } catch (error: any) {
    logger.error('membershipApiService', 'Error al obtener productos de membresía', error);
    return { products: [], userLevel: 0, isLoggedIn: false };
  }
};

// ============================================
// NIVELES DE MEMBRESÍA
// ============================================

/**
 * Obtener todos los niveles de membresía
 * Endpoint: GET /starter/v1/memberships/levels
 */
export const getMembershipLevels = async (): Promise<MembershipLevel[]> => {
  const cacheKey = cacheManager.buildCacheKey('membershipLevels', null);
  const cached = cacheManager.get<MembershipLevel[]>(cacheKey);
  if (cached) return cached;

  try {
    const response = await api.get<MembershipLevelsResponse>('/starter/v1/memberships/levels');
    
    if (response.data.success && response.data.data) {
      logger.info('membershipApiService', `Niveles de membresía obtenidos: ${response.data.data.length}`);
      cacheManager.set(cacheKey, response.data.data, DEFAULT_TTL.membershipLevels);
      return response.data.data;
    }
    
    return [];
  } catch (error: any) {
    logger.error('membershipApiService', 'Error al obtener niveles de membresía', error);
    return [];
  }
};

// ============================================
// ACCESO A CATEGORÍAS
// ============================================

/**
 * Verificar si el usuario puede acceder a una categoría
 * Endpoint: GET /starter/v1/membership/category-access/{id}
 */
export const checkCategoryAccess = async (categoryId: number): Promise<CategoryAccessInfo | null> => {
  const cacheKey = cacheManager.buildCacheKey('categoryAccess', categoryId);
  const cached = cacheManager.get<CategoryAccessInfo>(cacheKey);
  if (cached) return cached;

  try {
    const response = await api.get<CategoryAccessResponse>(`/starter/v1/membership/category-access/${categoryId}`);
    
    if (response.data.success && response.data.data) {
      cacheManager.set(cacheKey, response.data.data, DEFAULT_TTL.categoryAccess);
      return response.data.data;
    }
    
    return null;
  } catch (error: any) {
    logger.error('membershipApiService', `Error al verificar acceso a categoría ${categoryId}`, error);
    return null;
  }
};

/**
 * Verificar si el usuario puede acceder a una categoría (versión simplificada)
 */
export const canAccessCategory = async (categoryId: number): Promise<boolean> => {
  const accessInfo = await checkCategoryAccess(categoryId);
  return accessInfo?.has_access || false;
};

/**
 * Obtener categorías accesibles y restringidas para el usuario
 * Endpoint: GET /starter/v1/membership/accessible-categories
 */
export const getAccessibleCategories = async (): Promise<{
  userLevel: number;
  accessible: AccessibleCategory[];
}> => {
  const cacheKey = cacheManager.buildCacheKey('categoryAccess', 'all');
  const cached = cacheManager.get<{ userLevel: number; accessible: AccessibleCategory[] }>(cacheKey);
  if (cached) return cached;

  try {
    const response = await api.get<AccessibleCategoriesResponse>('/starter/v1/membership/accessible-categories');
    
    if (response.data.success && response.data.data) {
      logger.info('membershipApiService', 
        `Categorías: ${response.data.data.accessible.length} accesibles`
      );
      const result = {
        userLevel: response.data.data.user_level,
        accessible: response.data.data.accessible,
      };
      cacheManager.set(cacheKey, result, DEFAULT_TTL.categoryAccess);
      return result;
    }
    
    return { userLevel: 0, accessible: [] };
  } catch (error: any) {
    logger.error('membershipApiService', 'Error al obtener categorías accesibles', error);
    return { userLevel: 0, accessible: [] };
  }
};

// ============================================
// EXPORTACIÓN DEL SERVICIO
// ============================================

const membershipApiService = {
  // Membresía del usuario
  getCurrentMembership,
  getDefaultMembership,
  hasAccessToLevel,
  
  // Historial
  getMembershipHistory,
  
  // Estadísticas
  getMembershipStats,
  
  // Beneficios (usar benefitsApiService.getActiveBenefits() para beneficios del usuario)
  getLevelBenefits,
  
  // Productos
  getMembershipProducts,
  
  // Niveles
  getMembershipLevels,
  
  // Categorías
  checkCategoryAccess,
  canAccessCategory,
  getAccessibleCategories,
};

export default membershipApiService;
