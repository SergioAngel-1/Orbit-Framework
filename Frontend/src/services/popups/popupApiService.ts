import { api } from '../apiConfig';
import logger from '../../utils/logger';
import i18n from '../../config/i18n';
import type { 
  Popup, 
  PopupType, 
  PopupInteractionPayload, 
  LegacyMembershipResponse 
} from './popupTypes';

const POPUP_ENDPOINT = '/starter/v1/popups';

/**
 * Servicio de API para popups
 * 
 * El backend filtra automáticamente los popups según:
 * - Tipo de popup y elegibilidad del usuario
 * - Estado de membresía (para expiración)
 * - Antigüedad del usuario (para membresía legacy)
 * - Bonificaciones pendientes (para referidos)
 */
const popupApiService = {
  /**
   * Obtener todos los popups activos para el usuario actual
   * El backend filtra automáticamente según elegibilidad
   */
  async getActivePopups(): Promise<Popup[]> {
    logger.info('popupApiService', 'Obteniendo popups activos');
    
    try {
      const response = await api.get<Popup[]>(POPUP_ENDPOINT);
      const popups = response.data || [];
      
      logger.info('popupApiService', `Recibidos ${popups.length} popups`);
      return popups;
    } catch (error) {
      logger.error('popupApiService', 'Error al obtener popups:', error);
      return [];
    }
  },

  /**
   * Obtener popup por tipo específico
   */
  async getPopupByType(type: PopupType): Promise<Popup | null> {
    logger.info('popupApiService', `Obteniendo popup tipo '${type}'`);
    
    try {
      const response = await api.get<Popup | null>(`${POPUP_ENDPOINT}/${type}`);
      return response.data;
    } catch (error) {
      logger.error('popupApiService', `Error al obtener popup tipo '${type}':`, error);
      return null;
    }
  },

  /**
   * Registrar interacción con un popup (visto, cerrado, click)
   */
  async registerInteraction(popupId: number, payload: PopupInteractionPayload): Promise<boolean> {
    logger.info('popupApiService', `Registrando interacción '${payload.action}' para popup ${popupId}`);
    
    try {
      await api.post(`${POPUP_ENDPOINT}/${popupId}/interact`, payload);
      return true;
    } catch (error) {
      logger.error('popupApiService', 'Error al registrar interacción:', error);
      return false;
    }
  },

  /**
   * Responder a la oferta de membresía por antigüedad
   */
  async respondToLegacyMembership(response: 'accepted' | 'rejected'): Promise<LegacyMembershipResponse> {
    logger.info('popupApiService', `Respondiendo a membresía legacy: ${response}`);
    
    try {
      const res = await api.post<LegacyMembershipResponse>(
        `${POPUP_ENDPOINT}/legacy-membership/respond`,
        { response }
      );
      
      logger.info('popupApiService', 'Respuesta registrada:', res.data);
      return res.data;
    } catch (error: any) {
      logger.error('popupApiService', 'Error al responder a membresía legacy:', error);
      
      return {
        success: false,
        message: error?.response?.data?.message || i18n.t('errors:generic.popupResponseError'),
        response,
      };
    }
  },
};

export default popupApiService;
