/**
 * Hook para calcular y aplicar descuentos de membresía en checkout
 * 
 * Calcula descuentos basados en los beneficios 'category_discount' y 'events_discount' del usuario,
 * aplicando el porcentaje configurado a productos de categorías específicas.
 * 
 * El descuento de eventos tiene prioridad si aplica a un producto.
 */

import { useState, useEffect, useCallback, useMemo } from 'react';
import { useAuth } from '../contexts/AuthContext';
import benefitsApiService, { BenefitConfig } from '../services/membership/benefitsApiService';
import { CartItem } from '../types/woocommerce';
import logger from '../utils/logger';
import { ceilTo50COP } from '../utils/formatters';
import i18n from '../config/i18n';

/**
 * Descuento aplicado a un item del carrito
 */
export interface ItemDiscount {
  productId: number;
  variationId?: number;
  productName: string;
  originalPrice: number;
  discountPercentage: number;
  discountAmount: number;
  finalPrice: number;
  quantity: number;
  totalDiscount: number;
  categoryId: number;
  categoryName: string;
  /** Tipo de descuento aplicado: 'category' o 'events' */
  discountType: 'category' | 'events';
}

/**
 * Resumen de descuentos de membresía
 */
export interface MembershipDiscountSummary {
  /** Si el usuario tiene el beneficio de descuento activo */
  hasDiscount: boolean;
  /** Porcentaje de descuento configurado */
  discountPercentage: number;
  /** Lista de items con descuento aplicado */
  discountedItems: ItemDiscount[];
  /** Total de descuento en COP */
  totalDiscount: number;
  /** Número de items con descuento */
  itemsWithDiscount: number;
  /** Categorías que aplican para el descuento (IDs) */
  eligibleCategories: number[];
  /** Nombres de las categorías elegibles */
  eligibleCategoryNames: string[];
  /** Si aplica a todas las categorías */
  appliesToAllCategories: boolean;
}

/**
 * Hook para gestionar descuentos de membresía en checkout
 */
export const useMembershipDiscount = (cartItems: CartItem[]) => {
  const { isAuthenticated } = useAuth();
  
  const [loading, setLoading] = useState(true);
  const [config, setConfig] = useState<BenefitConfig | null>(null);
  const [eventsConfig, setEventsConfig] = useState<BenefitConfig | null>(null);
  const [categoryNames, setCategoryNames] = useState<string[]>([]);
  const [eventsCategoryNames, setEventsCategoryNames] = useState<string[]>([]);
  const [error, setError] = useState<string | null>(null);

  /**
   * Cargar configuración de los beneficios de descuento
   */
  const loadDiscountConfig = useCallback(async () => {
    if (!isAuthenticated) {
      setConfig(null);
      setEventsConfig(null);
      setCategoryNames([]);
      setEventsCategoryNames([]);
      setLoading(false);
      return;
    }

    try {
      setLoading(true);
      setError(null);
      
      // Cargar ambos tipos de descuento en paralelo
      const [categoryResult, eventsResult] = await Promise.all([
        benefitsApiService.getCategoryDiscountConfig(),
        benefitsApiService.getEventsDiscountConfig()
      ]);
      
      if (categoryResult) {
        setConfig(categoryResult.config);
        setCategoryNames(categoryResult.categoryNames);
        logger.info('MembershipDiscount', `Descuento de categorías cargado: ${categoryResult.config.percentage}%`);
      } else {
        setConfig(null);
        setCategoryNames([]);
      }
      
      if (eventsResult) {
        setEventsConfig(eventsResult.config);
        setEventsCategoryNames(eventsResult.categoryNames);
        logger.info('MembershipDiscount', `Descuento de eventos cargado: ${eventsResult.config.percentage}%`);
      } else {
        setEventsConfig(null);
        setEventsCategoryNames([]);
      }
    } catch (err: any) {
      setError(err.message || i18n.t('checkoutComponents:errors.loadMembershipDiscount'));
      logger.error('MembershipDiscount', 'Error al cargar configuración:', err);
    } finally {
      setLoading(false);
    }
  }, [isAuthenticated]);

  /**
   * Cargar configuración al montar o cuando cambia autenticación
   */
  useEffect(() => {
    loadDiscountConfig();
  }, [loadDiscountConfig]);

  /**
   * Verificar si un producto califica para el descuento de eventos
   */
  const productQualifiesForEventsDiscount = useCallback((item: CartItem): { qualifies: boolean; categoryId: number; categoryName: string; percentage: number } => {
    if (!eventsConfig || !eventsConfig.enabled || !eventsConfig.percentage) {
      return { qualifies: false, categoryId: 0, categoryName: '', percentage: 0 };
    }

    const categories = item.product?.categories || [];
    const eligibleCategories = eventsConfig.categories || [];
    
    // Si no hay categorías específicas, no aplica (eventos requiere categorías específicas)
    if (eligibleCategories.length === 0) {
      return { qualifies: false, categoryId: 0, categoryName: '', percentage: 0 };
    }

    // Verificar si alguna categoría del producto está en la lista de eventos
    for (const category of categories) {
      if (eligibleCategories.includes(category.id)) {
        return { 
          qualifies: true, 
          categoryId: category.id, 
          categoryName: category.name,
          percentage: eventsConfig.percentage
        };
      }
    }

    return { qualifies: false, categoryId: 0, categoryName: '', percentage: 0 };
  }, [eventsConfig]);

  /**
   * Verificar si un producto califica para el descuento de categorías
   */
  const productQualifiesForCategoryDiscount = useCallback((item: CartItem): { qualifies: boolean; categoryId: number; categoryName: string; percentage: number } => {
    if (!config || !config.enabled || !config.percentage) {
      return { qualifies: false, categoryId: 0, categoryName: '', percentage: 0 };
    }

    const categories = item.product?.categories || [];
    const eligibleCategories = config.categories || [];
    
    // Si no hay categorías específicas, aplica a todas
    if (eligibleCategories.length === 0) {
      const firstCategory = categories[0];
      return { 
        qualifies: true, 
        categoryId: firstCategory?.id || 0, 
        categoryName: firstCategory?.name || 'General',
        percentage: config.percentage
      };
    }

    // Verificar si alguna categoría del producto está en la lista elegible
    for (const category of categories) {
      if (eligibleCategories.includes(category.id)) {
        return { 
          qualifies: true, 
          categoryId: category.id, 
          categoryName: category.name,
          percentage: config.percentage
        };
      }
    }

    return { qualifies: false, categoryId: 0, categoryName: '', percentage: 0 };
  }, [config]);

  /**
   * Calcular descuentos para todos los items del carrito
   * Prioridad: descuento de eventos > descuento de categorías
   */
  const discountSummary = useMemo<MembershipDiscountSummary>(() => {
    // Estado inicial sin descuento
    const noDiscount: MembershipDiscountSummary = {
      hasDiscount: false,
      discountPercentage: 0,
      discountedItems: [],
      totalDiscount: 0,
      itemsWithDiscount: 0,
      eligibleCategories: [],
      eligibleCategoryNames: [],
      appliesToAllCategories: false
    };

    // Verificar si hay algún descuento configurado
    const hasCategoryDiscount = config && config.enabled && config.percentage && config.percentage > 0;
    const hasEventsDiscount = eventsConfig && eventsConfig.enabled && eventsConfig.percentage && eventsConfig.percentage > 0;
    
    if (!hasCategoryDiscount && !hasEventsDiscount) {
      return noDiscount;
    }

    const discountedItems: ItemDiscount[] = [];
    let totalDiscount = 0;
    let maxDiscountPercentage = 0;

    for (const item of cartItems) {
      // Primero verificar descuento de eventos (tiene prioridad)
      const eventsResult = productQualifiesForEventsDiscount(item);
      
      // Luego verificar descuento de categorías
      const categoryResult = productQualifiesForCategoryDiscount(item);
      
      // Usar el descuento que aplique (eventos tiene prioridad)
      let appliedDiscount: { qualifies: boolean; categoryId: number; categoryName: string; percentage: number; discountType: 'category' | 'events' } | null = null;
      
      if (eventsResult.qualifies) {
        appliedDiscount = { ...eventsResult, discountType: 'events' };
      } else if (categoryResult.qualifies) {
        appliedDiscount = { ...categoryResult, discountType: 'category' };
      }
      
      if (!appliedDiscount || !appliedDiscount.qualifies) continue;

      // Obtener precio del item
      const variationPrice = item.variation?.price;
      const productPrice = item.product?.price;
      const itemPrice = parseFloat(variationPrice || productPrice || '0');
      
      if (itemPrice <= 0) continue;

      const discountPercentage = appliedDiscount.percentage;
      const discountAmount = itemPrice * (discountPercentage / 100);

      if (discountPercentage > maxDiscountPercentage) {
        maxDiscountPercentage = discountPercentage;
      }

      const roundedDiscountAmount = ceilTo50COP(discountAmount);
      const roundedFinalPrice = itemPrice - roundedDiscountAmount;
      const roundedTotalItemDiscount = roundedDiscountAmount * item.quantity;

      discountedItems.push({
        productId: item.id,
        variationId: item.variation_id,
        productName: item.product?.name || i18n.t('commonComponents:fallbacks.productName'),
        originalPrice: itemPrice,
        discountPercentage,
        discountAmount: roundedDiscountAmount,
        finalPrice: roundedFinalPrice,
        quantity: item.quantity,
        totalDiscount: roundedTotalItemDiscount,
        categoryId: appliedDiscount.categoryId,
        categoryName: appliedDiscount.categoryName,
        discountType: appliedDiscount.discountType
      });

      totalDiscount += roundedTotalItemDiscount;
    }

    // Combinar categorías elegibles
    const allEligibleCategories = [
      ...(config?.categories || []),
      ...(eventsConfig?.categories || [])
    ];
    const allCategoryNames = [...categoryNames, ...eventsCategoryNames];
    const appliesToAllCategories = (config?.categories || []).length === 0;

    return {
      hasDiscount: discountedItems.length > 0,
      discountPercentage: maxDiscountPercentage,
      discountedItems,
      totalDiscount: ceilTo50COP(totalDiscount),
      itemsWithDiscount: discountedItems.length,
      eligibleCategories: allEligibleCategories,
      eligibleCategoryNames: allCategoryNames,
      appliesToAllCategories
    };
  }, [cartItems, config, eventsConfig, productQualifiesForEventsDiscount, productQualifiesForCategoryDiscount, categoryNames, eventsCategoryNames]);

  /**
   * Refrescar la configuración de descuento
   */
  const refreshDiscount = useCallback(async () => {
    await loadDiscountConfig();
  }, [loadDiscountConfig]);

  return {
    ...discountSummary,
    loading,
    error,
    refreshDiscount
  };
};

export default useMembershipDiscount;
