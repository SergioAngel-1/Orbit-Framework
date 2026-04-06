/**
 * Servicio para gestionar membresías de usuario
 * 
 * Este archivo mantiene compatibilidad con el código existente.
 * Para nuevas implementaciones, usar el módulo completo en ./membership/
 * 
 * @see ./membership/membershipApiService.ts - Servicio completo con todos los endpoints
 * @see ./membership/membershipTypes.ts - Tipos e interfaces completos
 */

// Re-exportar tipos desde el módulo de membresía
export type {
  MembershipLevel,
  MembershipData,
  MembershipResponse,
  MembershipStatus,
  MembershipProduct,
  MembershipBenefit,
  MembershipHistoryEntry,
  MembershipStats,
  CategoryAccessInfo,
  AccessibleCategory,
  BenefitType,
  ActiveBenefitData,
  FreeSamplesData,
  FreeDeliveriesData,
} from './membership/membershipTypes';


// Re-exportar funciones del servicio de API
export {
  getCurrentMembership,
  getDefaultMembership,
  hasAccessToLevel,
  canAccessCategory,
  getMembershipHistory,
  getMembershipStats,
  getLevelBenefits,
  getMembershipProducts,
  getMembershipLevels,
  checkCategoryAccess,
  getAccessibleCategories,
} from './membership/membershipApiService';

// Para beneficios activos del usuario, usar:
// import benefitsApiService from './membership/benefitsApiService';
// benefitsApiService.getActiveBenefits();
// O usar el hook: useActiveBenefits(isAuthenticated)

// Importar el servicio completo para el export default
import membershipApiService from './membership/membershipApiService';

// Mantener compatibilidad con el objeto membershipService existente
export const membershipService = membershipApiService;

export default membershipService;
