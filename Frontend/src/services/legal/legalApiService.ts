import { api } from '../apiConfig';
import logger from '../../utils/logger';

/**
 * Servicio de API para contenido legal
 */
const legalApiService = {
  /**
   * Obtener la política de privacidad
   * @returns Promesa con la política de privacidad
   */
  getPrivacyPolicy() {
    logger.info('legalApiService', 'Obteniendo política de privacidad');
    return api.get('/starter/v1/legal/privacy-policy');
  },

  /**
   * Obtener los términos y condiciones
   * @returns Promesa con los términos y condiciones
   */
  getTermsConditions() {
    logger.info('legalApiService', 'Obteniendo términos y condiciones');
    return api.get('/starter/v1/legal/terms-conditions');
  },

  /**
   * Obtener el marco legal protector
   * @returns Promesa con el documento de marco legal protector
   */
  getProtectiveLaws() {
    logger.info('legalApiService', 'Obteniendo marco legal protector');
    return api.get('/starter/v1/legal/protective_laws');
  }
};

export default legalApiService;
