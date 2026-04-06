/**
 * Módulo de servicios de membresía
 * Exporta tipos, constantes y servicios para el sistema de membresías
 */

// Tipos e interfaces
export * from './membershipTypes';

// Servicio de API
export { default as membershipApiService } from './membershipApiService';
export {
  getCurrentMembership,
  getDefaultMembership,
  hasAccessToLevel,
  getMembershipHistory,
  getMembershipStats,
  getLevelBenefits,
  getMembershipProducts,
  getMembershipLevels,
  checkCategoryAccess,
  canAccessCategory,
  getAccessibleCategories,
} from './membershipApiService';

// Servicio de beneficios (para beneficios activos del usuario)
export { default as benefitsApiService } from './benefitsApiService';
