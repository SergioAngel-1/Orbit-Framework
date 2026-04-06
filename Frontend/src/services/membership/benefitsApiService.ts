/**
 * Servicio de API para beneficios de membresía
 * Consume los endpoints del módulo de handlers de beneficios
 */

import { api } from '../apiConfig';
import logger from '../../utils/logger';
import { cacheManager } from '../query/cacheManager';
import { DEFAULT_TTL } from '../query/types';

/**
 * Beneficio activo del usuario
 */
export interface ActiveBenefit {
  key: string;
  name: string;
  description: string;
  display_value: string;
  icon: string;
  config?: BenefitConfig;
  categories?: string[]; // Para category_discount
}

/**
 * Configuración de un beneficio
 */
export interface BenefitConfig {
  enabled: boolean;
  percentage?: number;
  categories?: number[];
  quantity?: number;
  grams?: number;
  price?: number;
  home_delivery?: boolean;
  pickup?: boolean;
  safe_space?: boolean;
  legal_advice?: boolean;
  legal_advice_whatsapp?: string;
  percentage_level2?: number;
  // Para referral_membership_bonus
  membership_level?: number;
  duration_days?: number;
}

/**
 * Respuesta de beneficios activos
 */
export interface ActiveBenefitsResponse {
  success: boolean;
  data: {
    user_id: number;
    level: number;
    benefits: ActiveBenefit[];
    total_active: number;
  };
}

/**
 * Respuesta de estado de un beneficio específico
 */
export interface BenefitStatusResponse {
  success: boolean;
  data: {
    key: string;
    name: string;
    description: string;
    is_enabled: boolean;
    display_value: string | null;
    config: BenefitConfig | null;
    icon: string;
    category_names?: string[]; // Para category_discount
  };
}

/**
 * Descuento calculado por categoría
 */
export interface CategoryDiscountResult {
  product_id: number;
  product_name: string;
  original_price: number;
  discount_percentage: number;
  discount_amount: number;
  final_price: number;
  category_id: number;
  category_name: string;
}

/**
 * Servicio de beneficios de membresía
 */
const benefitsApiService = {
  /**
   * Obtener todos los beneficios activos del usuario actual
   * Implementa caché para evitar peticiones repetidas cuando membershipVersion cambia
   * pero los beneficios no han cambiado realmente.
   * 
   * @param signal - AbortSignal opcional para cancelar la petición
   * @param skipCache - Si es true, ignora el caché y hace petición al servidor
   */
  async getActiveBenefits(signal?: AbortSignal, skipCache: boolean = false): Promise<ActiveBenefit[]> {
    // Construir clave de caché (incluye nivel de membresía automáticamente)
    const cacheKey = cacheManager.buildCacheKey('benefits', 'active');
    
    // Verificar caché primero (si no se omite)
    if (!skipCache) {
      const cached = cacheManager.get<ActiveBenefit[]>(cacheKey);
      if (cached) {
        logger.debug('BenefitsAPI', `Cache hit: ${cached.length} beneficios activos`);
        return cached;
      }
    }
    
    try {
      const response = await api.get<ActiveBenefitsResponse>(
        '/starter/v1/membership/benefits/active',
        { signal }
      );
      
      if (response.data.success) {
        const benefits = response.data.data.benefits;
        
        // Guardar en caché
        cacheManager.set(cacheKey, benefits, DEFAULT_TTL.benefits);
        
        logger.info('BenefitsAPI', `${response.data.data.total_active} beneficios activos cargados y cacheados`);
        return benefits;
      }
      
      return [];
    } catch (error: any) {
      // Ignorar errores de peticiones canceladas
      if (error.name === 'AbortError' || error.code === 'ERR_CANCELED') {
        return [];
      }
      // Si no está autenticado, retornar array vacío sin error
      if (error.response?.status === 401) {
        return [];
      }
      logger.error('BenefitsAPI', 'Error al obtener beneficios activos:', error);
      return [];
    }
  },

  /**
   * Invalida el caché de beneficios activos
   * Debe llamarse cuando cambia la membresía del usuario
   */
  invalidateBenefitsCache(): void {
    cacheManager.invalidateByType('benefits');
    logger.debug('BenefitsAPI', 'Cache de beneficios invalidado');
  },

  /**
   * Obtener estado de un beneficio específico
   * 
   * NOTA: Un 404 significa que el handler no está registrado o el beneficio
   * no existe para el nivel del usuario. Esto es un estado válido, no un error.
   */
  async getBenefitStatus(benefitKey: string): Promise<BenefitStatusResponse['data'] | null> {
    try {
      const response = await api.get<BenefitStatusResponse>(`/starter/v1/membership/benefits/${benefitKey}`);
      
      if (response.data.success) {
        return response.data.data;
      }
      
      return null;
    } catch (error: any) {
      // 401 = no autenticado, 404 = beneficio no encontrado/no disponible
      // Ambos son estados válidos, no errores
      if (error.response?.status === 401 || error.response?.status === 404) {
        return null;
      }
      logger.error('BenefitsAPI', `Error al obtener estado de beneficio ${benefitKey}:`, error);
      return null;
    }
  },

  /**
   * Obtener configuración del beneficio de descuento en categorías
   */
  async getCategoryDiscountConfig(): Promise<{ config: BenefitConfig; categoryNames: string[] } | null> {
    const status = await this.getBenefitStatus('category_discount');
    
    if (status?.is_enabled && status.config) {
      return {
        config: status.config,
        categoryNames: status.category_names || []
      };
    }
    
    return null;
  },

  /**
   * Obtener configuración del beneficio de descuento en eventos
   */
  async getEventsDiscountConfig(): Promise<{ config: BenefitConfig; categoryNames: string[] } | null> {
    const status = await this.getBenefitStatus('events_discount');
    
    if (status?.is_enabled && status.config) {
      return {
        config: status.config,
        categoryNames: status.category_names || []
      };
    }
    
    return null;
  },

  /**
   * Verificar si el usuario tiene un beneficio específico
   */
  async hasBenefit(benefitKey: string): Promise<boolean> {
    const status = await this.getBenefitStatus(benefitKey);
    return status?.is_enabled ?? false;
  },

  /**
   * Aplicar un beneficio (para beneficios que requieren acción)
   */
  async applyBenefit(benefitKey: string, context: Record<string, any> = {}): Promise<any> {
    try {
      const response = await api.post(`/starter/v1/membership/benefits/${benefitKey}/apply`, context);
      
      if (response.data.success) {
        return response.data.data.result;
      }
      
      return null;
    } catch (error: any) {
      logger.error('BenefitsAPI', `Error al aplicar beneficio ${benefitKey}:`, error);
      throw error;
    }
  }
};

export default benefitsApiService;
