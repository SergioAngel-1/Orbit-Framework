import { api, createApiRequest } from '../apiConfig';
import logger from '../../utils/logger';
import type { AxiosRequestConfig } from 'axios';

/**
 * Servicio de API para secciones de inicio
 */
const homeSectionApiService = {
  /**
   * Obtener todas las secciones
   * @returns Promesa con las secciones
   */
  getAll(includeProducts = false) {
    logger.info('homeSectionApiService', `Obteniendo todas las secciones de inicio (include_products: ${includeProducts})`);
    // No usar _t cache-buster: el backend envía Cache-Control: no-store para REST
    // y el transient cache del backend (10 min) debe poder funcionar
    const params: Record<string, any> = {};
    if (includeProducts) {
      params.include_products = 1;
    }
    return createApiRequest(() => api.get('/starter/v1/home-sections', { params }));
  },
 
  /**
   * Obtener productos de una sección específica
   * @param sectionId ID de la sección
   * @param options Opciones de configuración para la solicitud
   * @returns Promesa con los productos de la sección
   */
  getSectionProducts(sectionId: string, options: AxiosRequestConfig = {}) {
    logger.info('homeSectionApiService', 'Obteniendo productos de la sección:', sectionId);
    // No usar _t cache-buster: el backend envía Cache-Control: no-store para REST
    return createApiRequest(() =>
      api.get(`/starter/v1/home-sections/${sectionId}`, options)
    );
  }
};

export default homeSectionApiService;
