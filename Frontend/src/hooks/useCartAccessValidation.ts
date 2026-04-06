/**
 * Hook para validar acceso a productos del carrito según membresía
 * 
 * Verifica que el usuario tenga el nivel de membresía requerido para
 * todos los productos en su carrito antes de proceder al checkout.
 */

import { useMemo } from 'react';
import { useMembership } from '../contexts/MembershipContext';
import useMembershipLevels from './useMembershipLevels';
import { CartItem, Category } from '../types/woocommerce';
import logger from '../utils/logger';

interface RestrictedProduct {
  productId: number;
  productName: string;
  categoryId: number;
  categoryName: string;
  requiredLevel: number;
  requiredLevelName: string;
  requiredLevelIcon: string;
}

interface CartAccessValidationResult {
  isValid: boolean;
  restrictedProducts: RestrictedProduct[];
  currentLevel: number;
  currentLevelName: string;
  currentLevelIcon: string;
  errorMessage: string | null;
}

/**
 * Obtiene el nivel mínimo de membresía de una categoría
 */
const getCategoryMinLevel = (category: Category): number => {
  // El backend puede enviar el nivel en diferentes formatos
  const cat = category as any;
  
  if (cat.min_membership_level !== undefined) {
    return cat.min_membership_level;
  }
  
  if (cat.min_membership_info?.level !== undefined) {
    return cat.min_membership_info.level;
  }
  
  if (cat.membership_info?.min_level !== undefined) {
    return cat.membership_info.min_level;
  }
  
  // Por defecto, nivel 0 (público)
  return 0;
};

/**
 * Hook para validar acceso a productos del carrito
 */
export const useCartAccessValidation = (cartItems: CartItem[]): CartAccessValidationResult => {
  const { currentLevel, loading: membershipLoading } = useMembership();
  const { getLevelName, getLevelIcon } = useMembershipLevels();

  const result = useMemo(() => {
    // Si aún está cargando la membresía, asumir válido temporalmente
    if (membershipLoading) {
      return {
        isValid: true,
        restrictedProducts: [],
        currentLevel,
        currentLevelName: getLevelName(currentLevel) || 'Desconocido',
        currentLevelIcon: getLevelIcon(currentLevel)?.icon || '❓',
        errorMessage: null,
      };
    }

    const restrictedProducts: RestrictedProduct[] = [];

    for (const item of cartItems) {
      const product = item.product;
      
      if (!product || !product.categories || product.categories.length === 0) {
        continue;
      }

      // Verificar cada categoría del producto
      for (const category of product.categories) {
        const requiredLevel = getCategoryMinLevel(category);
        
        if (requiredLevel > 0 && currentLevel < requiredLevel) {
          const levelName = getLevelName(requiredLevel) || `Nivel ${requiredLevel}`;
          const levelIcon = getLevelIcon(requiredLevel)?.icon || '🔒';
          
          restrictedProducts.push({
            productId: product.id,
            productName: product.name,
            categoryId: category.id,
            categoryName: category.name,
            requiredLevel,
            requiredLevelName: levelName,
            requiredLevelIcon: levelIcon,
          });
          
          logger.warn('useCartAccessValidation', 
            `Producto "${product.name}" requiere nivel ${requiredLevel} (${levelName}), usuario tiene nivel ${currentLevel}`
          );
          
          // Solo necesitamos una categoría restringida por producto
          break;
        }
      }
    }

    const isValid = restrictedProducts.length === 0;
    const currentLevelName = getLevelName(currentLevel) || 'Desconocido';
    const currentLevelIcon = getLevelIcon(currentLevel)?.icon || '❓';

    let errorMessage: string | null = null;
    if (!isValid) {
      const productList = restrictedProducts
        .map(p => `• ${p.productName} (requiere ${p.requiredLevelIcon} ${p.requiredLevelName})`)
        .join('\n');
      
      errorMessage = `Tu membresía actual (${currentLevelIcon} ${currentLevelName}) no tiene acceso a:\n${productList}`;
    }

    return {
      isValid,
      restrictedProducts,
      currentLevel,
      currentLevelName,
      currentLevelIcon,
      errorMessage,
    };
  }, [cartItems, currentLevel, membershipLoading, getLevelName, getLevelIcon]);

  return result;
};

export default useCartAccessValidation;
