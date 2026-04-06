import { wooCommerceApi, api, createApiRequest } from '../apiConfig';
import logger from '../../utils/logger';
import i18n from '../../config/i18n';

/**
 * Servicio de API para categorías
 */
const categoryApiService = {
  /**
   * Obtener todas las categorías
   * @param params Parámetros de la petición
   * @returns Promesa con las categorías
   */
  async getAll(params = {}) {
    return createApiRequest(() => 
      wooCommerceApi.get('/products/categories', {
        params: {
          per_page: 100,  // Obtener más categorías por página
          ...params
        }
      })
    );
  },

  /**
   * Obtener una categoría por su ID
   * @param id ID de la categoría
   * @returns Promesa con la categoría
   */
  async getById(id: number) {
    return createApiRequest(() => wooCommerceApi.get(`/products/categories/${id}`));
  },

  /**
   * Obtener una categoría por su slug
   * @param slug Slug de la categoría
   * @returns Promesa con la categoría
   */
  async getBySlug(slug: string) {
    return createApiRequest(async () => {
      const response = await wooCommerceApi.get('/products/categories', { params: { slug } });
      if (response.data && response.data.length > 0) {
        // Devolver la primera categoría que coincida con el slug
        return { data: response.data[0] };
      } else {
        throw new Error(i18n.t('errors:products.categoryNotFound'));
      }
    });
  },

  /**
   * Obtener categorías destacadas
   * @returns Promesa con las categorías destacadas
   */
  async getFeatured() {
    logger.info('categoryApiService', 'Obteniendo categorías destacadas');
    // Agregar timestamp para evitar caché del navegador
    return createApiRequest(() => api.get('/starter/v1/featured-categories', {
      params: { _t: Date.now() }
    }));
  }
};

export default categoryApiService;
