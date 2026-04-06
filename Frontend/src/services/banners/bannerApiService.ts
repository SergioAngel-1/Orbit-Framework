import { api, createApiRequest } from '../apiConfig';
import logger from '../../utils/logger';
import { Banner, BannerType } from './bannerTypes';

/**
 * Servicio de API para banners
 * 
 * IMPORTANTE: El backend ya filtra automáticamente los banners según la membresía del usuario.
 * - Para usuarios autenticados: retorna solo los banners/imágenes que puede ver según su nivel
 * - Para usuarios anónimos: retorna solo banners públicos (nivel 0)
 * - Las imágenes del carrusel se filtran individualmente por membresía
 * 
 * No es necesario filtrar en el frontend, el backend ya lo hace.
 */
// Promesa in-flight para deduplicar llamadas simultáneas a getAll()
let inflightGetAll: Promise<Banner[]> | null = null;

const bannerApiService = {
  /**
   * Obtener todos los banners
   * 
   * El backend filtra automáticamente según la membresía del usuario autenticado.
   * Si el usuario no está autenticado, retorna solo banners públicos (nivel 0).
   * 
   * DEDUPLICACIÓN: Si múltiples componentes llaman getAll() simultáneamente
   * (HomePage, useSocialNetworks, Footer), comparten el mismo request HTTP.
   * 
   * @returns Promesa con los banners filtrados por membresía
   */
  async getAll(): Promise<Banner[]> {
    // Si ya hay un request en vuelo, reusar la misma promesa
    if (inflightGetAll) {
      logger.info('bannerApiService', 'Reusando request in-flight de banners');
      return inflightGetAll;
    }
    
    logger.info('bannerApiService', 'Obteniendo todos los banners (filtrados por membresía)');
    
    inflightGetAll = (async () => {
      try {
        // Agregar timestamp para evitar caché del navegador
        const response = await createApiRequest(() => api.get('/starter/v1/banners', {
          params: { _t: Date.now() }
        }));
        
        // El backend ya retorna los banners filtrados
        const banners = response.data || [];
        
        logger.info('bannerApiService', `Recibidos ${banners.length} banners`);
        
        return banners;
      } catch (error) {
        logger.error('bannerApiService', 'Error al obtener banners');
        throw error;
      } finally {
        // Limpiar la promesa in-flight para que futuras llamadas hagan un nuevo request
        inflightGetAll = null;
      }
    })();
    
    return inflightGetAll;
  },

  /**
   * Obtener banners por tipo
   * 
   * El backend filtra automáticamente según la membresía del usuario.
   * Para banners tipo 'main' (carrusel), cada imagen se filtra individualmente.
   * 
   * @param type Tipo de banner ('main', 'middle', 'bottom')
   * @returns Promesa con los banners del tipo especificado, filtrados por membresía
   */
  async getByType(type: BannerType): Promise<Banner[]> {
    logger.info('bannerApiService', `Obteniendo banners tipo '${type}' (filtrados por membresía)`);
    
    try {
      // Agregar timestamp para evitar caché del navegador
      const response = await createApiRequest(() => api.get(`/starter/v1/banners/${type}`, {
        params: { _t: Date.now() }
      }));
      
      // El backend ya retorna los banners filtrados
      const banners = response.data || [];
      
      logger.info('bannerApiService', `Recibidos ${banners.length} banners de tipo '${type}'`);
      
      return banners;
    } catch (error) {
      logger.error('bannerApiService', `Error al obtener banners tipo '${type}'`);
      throw error;
    }
  }
};

export default bannerApiService;
