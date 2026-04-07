/**
 * Hook para validar acceso a categorías según membresía
 * Implementa reglas estrictas de acceso para /catalogo
 */

import { useState, useEffect, useCallback } from 'react';
import { useMembership } from '../contexts/MembershipContext';
import useMembershipLevels from './useMembershipLevels';
import { Category } from '../types/woocommerce';
import logger from '../utils/logger';

/**
 * Extensión de Category con campos de membresía
 * Estandarizado para ser consistente con MembershipContext
 */
interface CategoryWithMembership extends Category {
  /** Nivel mínimo de membresía requerido (propiedad estandarizada) */
  min_membership_level?: number;
  /** Info detallada del nivel de membresía (propiedad estandarizada) */
  min_membership_info?: {
    level: number;
    name: string;
    slug?: string;
    icon: string;
    color: string;
    is_public?: boolean;
  };
  // Legacy: formato antiguo del endpoint de categorías destacadas
  /** @deprecated Usar min_membership_info */
  membership_info?: {
    min_level: number;
    level_name: string;
    level_icon: string;
  };
}

interface CategoryAccessResult {
  hasAccess: boolean;
  requiredLevel: number;
  currentLevel: number;
  isLoading: boolean;
  accessDeniedReason: string | null;
}

interface UseCategoryAccessReturn {
  validateCategoryAccess: (category: Category | null) => CategoryAccessResult;
  filterAccessibleCategories: (categories: Category[]) => Category[];
  getCategoryMinLevel: (category: Category) => number;
  isLoading: boolean;
}

/**
 * Hook para validar y filtrar acceso a categorías según membresía
 */
export const useCategoryAccess = (): UseCategoryAccessReturn => {
  const { currentLevel, loading: membershipLoading, hasAccessToLevel } = useMembership();
  const { getLevelName } = useMembershipLevels();
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    setIsLoading(membershipLoading);
  }, [membershipLoading]);

  /**
   * Obtiene el nivel mínimo de membresía requerido para una categoría
   */
  const getCategoryMinLevel = useCallback((category: Category): number => {
    const cat = category as CategoryWithMembership;
    
    // Verificar si viene como propiedad directa (prioridad)
    if (cat.min_membership_level !== undefined) {
      return cat.min_membership_level;
    }
    
    // Verificar si viene en min_membership_info (formato de la API de WooCommerce con filtro del backend)
    if (cat.min_membership_info?.level !== undefined) {
      return cat.min_membership_info.level;
    }
    
    // Verificar si viene en membership_info (formato del endpoint de categorías destacadas)
    if (cat.membership_info?.min_level !== undefined) {
      return cat.membership_info.min_level;
    }
    
    // Por defecto, nivel 0 (público)
    return 0;
  }, []);

  /**
   * Valida si el usuario tiene acceso a una categoría específica
   */
  const validateCategoryAccess = useCallback((category: Category | null): CategoryAccessResult => {
    if (!category) {
      return {
        hasAccess: true,
        requiredLevel: 0,
        currentLevel,
        isLoading: membershipLoading,
        accessDeniedReason: null
      };
    }

    const requiredLevel = getCategoryMinLevel(category);
    const hasAccess = hasAccessToLevel(requiredLevel);

    let accessDeniedReason: string | null = null;
    if (!hasAccess) {
      // Obtener nombre del nivel dinámicamente desde la API
      const levelName = getLevelName(requiredLevel) || `nivel ${requiredLevel}`;
      accessDeniedReason = `Esta categoría requiere membresía ${levelName}`;
      
      logger.warn('useCategoryAccess', `Acceso denegado a categoría "${category.name}". Requiere nivel ${requiredLevel}, usuario tiene nivel ${currentLevel}`);
    }

    return {
      hasAccess,
      requiredLevel,
      currentLevel,
      isLoading: membershipLoading,
      accessDeniedReason
    };
  }, [currentLevel, membershipLoading, hasAccessToLevel, getCategoryMinLevel, getLevelName]);

  /**
   * Filtra categorías accesibles según el nivel de membresía del usuario
   */
  const filterAccessibleCategories = useCallback((categories: Category[]): Category[] => {
    if (!Array.isArray(categories) || categories.length === 0) {
      return [];
    }

    const accessible = categories.filter(category => {
      const requiredLevel = getCategoryMinLevel(category);
      const hasAccess = hasAccessToLevel(requiredLevel);
      
      if (!hasAccess) {
        logger.debug('useCategoryAccess', `Filtrando categoría "${category.name}" (requiere nivel ${requiredLevel})`);
      }
      
      return hasAccess;
    });

    logger.info('useCategoryAccess', `Categorías filtradas: ${accessible.length}/${categories.length} accesibles para nivel ${currentLevel}`);
    
    return accessible;
  }, [currentLevel, hasAccessToLevel, getCategoryMinLevel]);

  return {
    validateCategoryAccess,
    filterAccessibleCategories,
    getCategoryMinLevel,
    isLoading
  };
};

export default useCategoryAccess;
