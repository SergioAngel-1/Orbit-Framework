import { wooCommerceApi, api, createApiRequest } from '../apiConfig';
import logger from '../../utils/logger';
import i18n from '../../config/i18n';

// Tipos específicos para filtros de productos
export interface ProductFilters {
  page?: number;
  per_page?: number;
  search?: string;
  category?: number;
  tag?: number;
  status?: string;
  featured?: boolean;
  [key: string]: any;
}

// Caché de productos inválidos (que sabemos que no existen)
// NOTA: Se limpia en login/logout/cambio de membresía porque un 404 por restricción
// de membresía no significa que el producto no exista permanentemente.
const invalidProductIds = new Set<number>();

/**
 * Servicio de API para productos
 */
const productApiService = {
  /**
   * Limpia la caché de productos inválidos.
   * Debe llamarse cuando cambia el estado de autenticación o membresía,
   * ya que un producto marcado como "inválido" (404) podría haber sido
   * restringido por membresía y ahora ser accesible.
   */
  clearInvalidProductCache() {
    if (invalidProductIds.size > 0) {
      logger.info('productApiService', `Limpiando caché de ${invalidProductIds.size} productos inválidos`);
      invalidProductIds.clear();
    }
  },

  /**
   * Obtener todos los productos
   * @param params Parámetros de la petición
   * @returns Promesa con los productos
   */
  getAll(params: ProductFilters = {}) {
    return wooCommerceApi.get('/products', { 
      params: { 
        ...params, 
        status: 'publish' // Solo mostrar productos publicados
      } 
    });
  },

  /**
   * Obtener un producto por su ID
   * @param id ID del producto
   * @param params Parámetros adicionales
   * @returns Promesa con el producto
   */
  getById(id: number, params = {}) {
    // Si el ID es 0 o negativo, no es un producto válido
    if (id <= 0 || !id) {
      return Promise.reject({
        response: {
          status: 404,
          data: {
            code: 'invalid_product',
            message: i18n.t('errors:generic.requestError')
          }
        }
      });
    }

    // Si ya sabemos que este producto no existe, evitar la solicitud
    if (invalidProductIds.has(id)) {
      logger.info('productApiService', `Beneficio ${id} en caché como inválido, evitando solicitud`);
      return Promise.reject({
        response: {
          status: 404,
          data: {
            code: 'woocommerce_rest_product_invalid_id',
            message: i18n.t('errors:products.invalidProductCache')
          }
        }
      });
    }
    
    return wooCommerceApi.get(`/products/${id}`, { params })
      .catch(error => {
        // Si el error es 404, añadir el ID a la lista de productos inválidos
        if (error.response && error.response.status === 404) {
          invalidProductIds.add(id);
          logger.info('productApiService', `Añadido beneficio ${id} a la caché de inválidos`);
        }
        return Promise.reject(error);
      });
  },

  /**
   * Obtener un producto por su slug
   * @param slug Slug del producto
   * @param params Parámetros adicionales
   * @returns Promesa con el producto
   */
  getBySlug(slug: string, params = {}) {
    return createApiRequest(() =>
      wooCommerceApi.get('/products', {
        params: {
          ...params,
          slug,
          status: 'publish', // Solo mostrar productos publicados
          _: Date.now().toString() // Añadir timestamp para evitar caché
        }
      })
    );
  },
  
  /**
   * Obtener un producto por su slug sin usar caché
   * @param slug Slug del producto
   * @returns Promesa con el producto
   */
  getBySlugNoCache(slug: string) {
    return createApiRequest(() =>
      wooCommerceApi.get('/products', {
        params: {
          slug,
          status: 'publish', // Solo mostrar productos publicados
          _: Date.now().toString() // Añadir timestamp para evitar caché
        }
      })
    );
  },

  /**
   * Obtener productos por categoría
   * @param categoryId ID de la categoría
   * @param params Parámetros adicionales
   * @returns Promesa con los productos
   */
  getByCategory(categoryId: number, params = {}) {
    return createApiRequest(() =>
      wooCommerceApi.get('/products', {
        params: {
          ...params,
          category: categoryId,
          status: 'publish' // Solo mostrar productos publicados
        }
      })
    );
  },

  /**
   * Buscar productos
   * @param searchTerm Término de búsqueda
   * @param params Parámetros adicionales
   * @returns Promesa con los productos
   */
  search(searchTerm: string, params = {}) {
    return wooCommerceApi.get('/products', { 
      params: { 
        ...params,
        search: searchTerm,
        status: 'publish' // Solo mostrar productos publicados
      },
      timeout: 30000 // Usar timeout global de 30s (antes 15s)
    });
    // Removido catch que ocultaba errores 502 - ahora se manejan en apiConfig.ts
  },

  /**
   * Obtener variaciones de un producto variable
   * @param productId ID del producto padre
   * @param params Parámetros adicionales (include, per_page, etc.)
   * @returns Promesa con las variaciones
   */
  getVariations(productId: number, params = {}) {
    logger.info('productApiService', `Obteniendo variaciones para producto ${productId}`);
    return createApiRequest(() =>
      wooCommerceApi.get(`/products/${productId}/variations`, {
        params: {
          ...params,
          status: 'publish'
        }
      })
    );
  },

  /**
   * Obtener productos recomendados
   * @param limit Límite de productos
   * @returns Promesa con los productos recomendados
   */
  getRecommended(limit = 6) {
    logger.info('productApiService', 'Obteniendo productos recomendados');
    return api.get('/starter/v1/recommended-products', {
      params: { limit }
    });
  },
};

export default productApiService;
