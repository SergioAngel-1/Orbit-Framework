import logger from '../../utils/logger';
import { api } from '../api';

export interface SystemStatus {
  systems: {
    points_enabled: boolean;
    referrals_enabled: boolean;
  };
  user_permissions: {
    can_use_points: boolean;
    can_use_referrals: boolean;
    user_role: string;
    allowed_roles: string[];
  };
  configuration: {
    points_conversion_rate: number;
    points_percentage: number; // Puntos que va al REFERIDOR por compra del referido
    min_points_redemption: number;
    max_points_per_order: number;
    points_expiry_days: number;
    point_triggers: string[];
    points_registration: number; // Puntos de bienvenida para nuevos usuarios
    points_review: number;
    points_birthday: number;
    referral_commission_first: number;
    referral_commission_subsequent: number;
    referral_commission_duration: number;
    enable_second_level: boolean;
    second_level_commission: number;
    signup_points_level1: number;
    signup_points_level2: number;
  };
  messages: Array<{
    type: 'info' | 'warning' | 'error';
    code: string;
    message: string;
  }>;
}

export interface PublicSystemConfig {
  systems: {
    points_enabled: boolean;
    referrals_enabled: boolean;
  };
  registration: {
    auto_approval_enabled: boolean;
  };
  configuration: {
    points_conversion_rate: number;
    points_percentage: number; // Puntos que va al REFERIDOR por compra del referido
    min_points_redemption: number;
    max_points_per_order: number;
    points_expiry_days: number;
    point_triggers: string[];
    points_registration: number; // Puntos de bienvenida para nuevos usuarios
    points_review: number;
    points_birthday: number;
    referral_commission_first: number;
    referral_commission_subsequent: number;
    referral_commission_duration: number;
    enable_second_level: boolean;
    second_level_commission: number;
    signup_points_level1: number;
    signup_points_level2: number;
    display_points_checkout: boolean;
    redeem_points_text: string;
    insufficient_points_text: string;
    discount_applied_text: string;
  };
}

export interface MinimumOrderConfig {
  minimum_amount: number;
  currency: string;
  currency_symbol: string;
  formatted: string;
}

/**
 * Servicio API para gestión del estado del sistema
 */
export const systemApiService = {
  /**
   * Obtener estado del sistema para el usuario actual
   */
  getSystemStatus: async (): Promise<SystemStatus> => {
    try {
      const response = await api.get('/starter/v1/system/status');
      return response.data;
    } catch (error) {
      logger.error('systemApiService', 'Error al obtener estado del sistema:', error);
      throw error;
    }
  },

  /**
   * Obtener configuración pública del sistema
   */
  getPublicConfig: async (): Promise<PublicSystemConfig> => {
    try {
      const response = await api.get('/starter/v1/system/config');
      return response.data;
    } catch (error) {
      logger.error('systemApiService', 'Error al obtener configuración pública del sistema:', error);
      throw error;
    }
  },

  /**
   * Verificar si el sistema de puntos está habilitado
   */
  isPointsSystemEnabled: async (): Promise<boolean> => {
    try {
      const config = await systemApiService.getPublicConfig();
      return config.systems.points_enabled;
    } catch (error) {
      logger.error('systemApiService', 'Error al verificar estado del sistema de puntos:', error);
      return false;
    }
  },

  /**
   * Verificar si el sistema de referidos está habilitado
   */
  isReferralsSystemEnabled: async (): Promise<boolean> => {
    try {
      const config = await systemApiService.getPublicConfig();
      return config.systems.referrals_enabled;
    } catch (error) {
      logger.error('systemApiService', 'Error al verificar estado del sistema de referidos:', error);
      return false;
    }
  },

  /**
   * Verificar si el usuario puede usar el sistema de puntos
   */
  canUserUsePoints: async (): Promise<boolean> => {
    try {
      const status = await systemApiService.getSystemStatus();
      return status.systems.points_enabled && status.user_permissions.can_use_points;
    } catch (error) {
      logger.error('systemApiService', 'Error al verificar permisos de puntos del usuario:', error);
      return false;
    }
  },

  /**
   * Verificar si el usuario puede usar el sistema de referidos
   */
  canUserUseReferrals: async (): Promise<boolean> => {
    try {
      const status = await systemApiService.getSystemStatus();
      return status.systems.referrals_enabled && status.user_permissions.can_use_referrals;
    } catch (error) {
      logger.error('systemApiService', 'Error al verificar permisos de referidos del usuario:', error);
      return false;
    }
  },

  /**
   * Obtener configuración de pedido mínimo
   */
  getMinimumOrder: async (): Promise<MinimumOrderConfig> => {
    try {
      const response = await api.get('/starter/v1/settings/minimum-order');
      return response.data;
    } catch (error) {
      logger.error('systemApiService', 'Error al obtener pedido mínimo:', error);
      throw error;
    }
  }
};