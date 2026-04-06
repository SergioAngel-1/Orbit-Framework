import { api, baseApiUrl } from '../apiConfig';
import logger from '../../utils/logger';
import axios, { AxiosResponse, AxiosError } from 'axios';
import i18n from '../../config/i18n';
import { cacheManager } from '../query/cacheManager';
import { DEFAULT_TTL } from '../query/types';

/**
 * Interfaz para la información del referido
 */
export interface ReferrerInfo {
  user_id: number;
  name: string;
  email?: string;
  [key: string]: any; // Para otras propiedades que pueda tener la respuesta
}

/**
 * Interfaz para la respuesta de validación de código de referido
 */
export interface ReferralCodeValidationResponse {
  valid: boolean;
  referrer?: ReferrerInfo;
  error?: string;
  message?: string;
}

/**
 * Interfaz para la respuesta de transferencia de puntos
 */
export interface PointsTransferResponse {
  success: boolean;
  message: string;
  new_balance?: number;
  points_sent?: number;
  points_received?: number;
  commission?: number;
  recipient?: {
    id: number;
    name: string;
  };
  data?: any;
}

/**
 * Servicio de API para puntos y referidos
 */
const pointsApiService = {
  /**
   * Obtiene los Virtual Coins del usuario actual
   * @returns Promesa con la información de puntos
   */
  getUserPoints() {
    const cacheKey = cacheManager.buildCacheKey('points', null);
    const cached = cacheManager.get(cacheKey);
    if (cached) {
      return Promise.resolve({ data: cached });
    }

    logger.info('pointsApiService', 'Obteniendo Virtual Coins del usuario');
    return api.get('/starter/v1/points').then((response: AxiosResponse) => {
      cacheManager.set(cacheKey, response.data, DEFAULT_TTL.points);
      return response;
    });
  },

  /**
   * Obtiene las estadísticas de referidos del usuario
   * @returns Promesa con las estadísticas de referidos
   */
  getReferralStats() {
    const cacheKey = cacheManager.buildCacheKey('referrals', 'stats');
    const cached = cacheManager.get(cacheKey);
    if (cached) {
      return Promise.resolve({ data: cached });
    }

    logger.info('pointsApiService', 'Obteniendo estadísticas de referidos');
    return api.get('/starter/v1/referrals/stats').then((response: AxiosResponse) => {
      cacheManager.set(cacheKey, response.data, DEFAULT_TTL.referrals);
      return response;
    });
  },

  /**
   * Obtiene el historial de transacciones de Virtual Coins del usuario
   * @param page Número de página
   * @param perPage Número de transacciones por página
   * @returns Promesa con el historial de transacciones
   */
  getPointsTransactions(page = 1, perPage = 20) {
    const cacheKey = cacheManager.buildCacheKey('transactions', null, { page, per_page: perPage });
    const cached = cacheManager.get(cacheKey);
    if (cached) {
      return Promise.resolve({ data: cached });
    }

    logger.info('pointsApiService', 'Obteniendo historial de transacciones de Virtual Coins');
    return api.get('/starter/v1/points/transactions', {
      params: { page, per_page: perPage }
    }).then((response: AxiosResponse) => {
      cacheManager.set(cacheKey, response.data, DEFAULT_TTL.transactions);
      return response;
    });
  },

  /**
   * Obtiene el código de referido
   * @returns Promesa con el código de referido
   */
  getReferralCode() {
    const cacheKey = cacheManager.buildCacheKey('referrals', 'code');
    const cached = cacheManager.get(cacheKey);
    if (cached) {
      return Promise.resolve({ data: cached });
    }

    logger.info('pointsApiService', 'Obteniendo código de referido');
    return api.get('/starter/v1/referrals/code').then((response: AxiosResponse) => {
      cacheManager.set(cacheKey, response.data, DEFAULT_TTL.referrals);
      return response;
    });
  },

  /**
   * Obtiene información del referido por código
   * @param code Código de referido
   * @returns Promesa con la información del referido
   */
  getReferrerByCode(code: string): Promise<ReferrerInfo> {
    logger.info('pointsApiService', 'Obteniendo información de referido por código:', code);
    
    return new Promise<ReferrerInfo>((resolve, reject) => {
      if (!code || code.trim() === '') {
        reject(new Error(i18n.t('errors:referral.codeRequired')));
        return;
      }
      
      // Crear una instancia independiente de axios para esta llamada específica
      // para asegurar que funcione sin autenticación durante el registro
      const publicApi = axios.create({
        baseURL: `${baseApiUrl}/wp-json`,
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      });
      
      // Usar la instancia pública para validar el código
      publicApi.get(`/starter/v1/referrals/validate-code`, {
        params: { code }
      })
        .then((response: AxiosResponse) => {
          if (response.data && response.data.valid && response.data.referrer) {
            resolve({
              user_id: response.data.referrer.id,
              name: response.data.referrer.name
            } as ReferrerInfo);
          } else {
            reject(new Error(i18n.t('errors:referral.codeInvalidOrNotFound')));
          }
        })
        .catch((error: AxiosError) => {
          logger.error('pointsApiService', 'Error al obtener información de referido:', error);
          reject(error);
        });
    });
  },

  /**
   * Valida si un código de referido existe y devuelve información del usuario asociado
   * @param code Código de referido
   * @returns Promesa con la información del usuario referido
   */
  validateReferralCode(code: string): Promise<ReferralCodeValidationResponse> {
    logger.info('pointsApiService', 'Validando código de referido:', code);
    
    return new Promise<ReferralCodeValidationResponse>((resolve, reject) => {
      if (!code || code.trim() === '') {
        reject(new Error(i18n.t('errors:referral.codeRequired')));
        return;
      }
      
      // Crear una instancia independiente de axios para esta llamada específica
      // para asegurar que funcione sin autenticación
      const publicApi = axios.create({
        baseURL: `${baseApiUrl}/wp-json`,
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      });
      
      // Usar la misma ruta que getReferrerByCode que funciona correctamente
      publicApi.get(`/starter/v1/referrals/validate-code`, {
        params: { code }
      })
        .then(response => {
          if (response.data && response.data.valid) {
            resolve(response.data);
          } else {
            reject(new Error(i18n.t('errors:referral.codeInvalidOrNotFound')));
          }
        })
        .catch(error => {
          logger.error('pointsApiService', 'Error al validar código de referido:', error);
          reject(error);
        });
    });
  },

  /**
   * Transfiere Virtual Coins a otro usuario utilizando su código de referido
   * @param recipientCode Código de referido del destinatario
   * @param pointsAmount Cantidad de puntos a transferir
   * @param notes Notas adicionales
   * @returns Promesa con el resultado de la transferencia
   */
  transferPoints(recipientCode: string, pointsAmount: number, notes: string = ''): Promise<{data: PointsTransferResponse}> {
    logger.info('pointsApiService', 'Transfiriendo Virtual Coins:', { recipientCode, pointsAmount, notes });
    
    return new Promise<{data: PointsTransferResponse}>((resolve, reject) => {
      if (!recipientCode || recipientCode.trim() === '') {
        reject(new Error(i18n.t('errors:referral.recipientCodeRequired')));
        return;
      }
      
      if (!pointsAmount || pointsAmount <= 0) {
        reject(new Error(i18n.t('errors:referral.amountMustBePositive')));
        return;
      }
      
      // El backend espera los parámetros en el cuerpo JSON, no como parámetros de consulta
      // Y la ruta correcta es /starter/v1/points/transfer sin el prefijo /wp-json
      api.post('/starter/v1/points/transfer', {
        points: pointsAmount,
        recipient_code: recipientCode,
        description: notes // El backend espera 'description' en lugar de 'notes'
      })
        .then(response => {
          // Invalidar caché de puntos y transacciones tras transferencia exitosa
          cacheManager.invalidateByType('points');
          cacheManager.invalidateByType('transactions');
          resolve(response.data);
        })
        .catch(error => {
          logger.error('pointsApiService', 'Error al transferir Virtual Coins:', error);
          reject(error);
        });
    });
  },

  /**
   * Obtiene información del referidor del usuario actual (quién me refirió)
   * @returns Promesa con la información del referidor o null si no tiene
   */
  getMyReferrer(): Promise<{ has_referrer: boolean; referrer: { id: number; name: string; status: string } | null }> {
    const cacheKey = cacheManager.buildCacheKey('referrals', 'my-referrer');
    const cached = cacheManager.get<{ has_referrer: boolean; referrer: { id: number; name: string; status: string } | null }>(cacheKey);
    if (cached) {
      return Promise.resolve(cached);
    }

    logger.info('pointsApiService', 'Obteniendo información del referidor del usuario actual');
    
    return api.get('/starter/v1/referrals/my-referrer')
      .then(response => {
        logger.info('pointsApiService', 'Respuesta de mi referidor:', response.data);
        cacheManager.set(cacheKey, response.data, DEFAULT_TTL.referrals);
        return response.data;
      })
      .catch(error => {
        logger.error('pointsApiService', 'Error al obtener referidor:', error);
        throw error;
      });
  }
};

export default pointsApiService;
