import { api } from '../apiConfig';
import logger from '../../utils/logger';
import { CacheableContentType, QueryOptions, DEFAULT_TTL } from './types';
import { cacheManager } from './cacheManager';

/**
 * Clase principal para consultas optimizadas con caché
 * Proporciona métodos para realizar consultas a la API con soporte de caché,
 * agrupación de solicitudes y manejo de errores.
 */
export class OptimizedQuery {
  /**
   * Método genérico para ejecutar cualquier consulta con caché
   */
  async query<T>(
    contentType: CacheableContentType,
    endpoint: string,
    id: string | number | null = null,
    params: any = {},
    options: QueryOptions = {}
  ): Promise<T> {
    const {
      ttl = DEFAULT_TTL[contentType],
      skipCache = false,
      forceRefresh = false,
      batchKey,
      loadingCallback,
      errorCallback
    } = options;
    
    // Notificar inicio de carga
    if (loadingCallback) {
      loadingCallback(true);
    }
    
    try {
      // Construir clave de caché
      const cacheKey = cacheManager.buildCacheKey(contentType, id, params);
      
      // Verificar caché si no se omite
      if (!skipCache && !forceRefresh && ttl > 0) {
        const cachedData = cacheManager.get<T>(cacheKey);
        if (cachedData) {
          if (process.env.NODE_ENV === 'development') {
            logger.debug('cache', `✅ Cache hit [${cacheKey}]`);
          }
          
          // Notificar fin de carga
          if (loadingCallback) {
            loadingCallback(false);
          }
          
          return cachedData;
        } else if (process.env.NODE_ENV === 'development') {
          logger.debug('cache', `❌ Cache miss [${cacheKey}]`);
        }
      } else if (process.env.NODE_ENV === 'development' && forceRefresh) {
        logger.debug('cache', `🔄 Force refresh [${cacheKey}]`);
      }
      
      // Si no está en caché, realizar solicitud
      let response: { data: T };
      
      if (batchKey) {
        // Ejecutar como parte de un lote
        response = await new Promise<{ data: T }>((resolve, reject) => {
          cacheManager.addToBatch(batchKey, {
            id: `${contentType}_${id || 'list'}_${Date.now()}`,
            url: endpoint,
            method: 'GET',
            params,
            resolve,
            reject
          });
        });
      } else {
        // Ejecutar como solicitud individual
        response = await api.get<T>(endpoint, { params });
      }
      
      // Guardar en caché si tiene TTL
      if (ttl > 0 && !skipCache) {
        cacheManager.set(cacheKey, response.data, ttl);
      }
      
      // Notificar fin de carga
      if (loadingCallback) {
        loadingCallback(false);
      }
      
      return response.data;
    } catch (error) {
      // Manejar error
      if (errorCallback) {
        errorCallback(error);
      }
      
      // Notificar fin de carga
      if (loadingCallback) {
        loadingCallback(false);
      }
      
      throw error;
    }
  }

  // --- Métodos específicos para diferentes tipos de datos ---

  /**
   * Obtiene una lista de productos publicados
   */
  async getProducts(params: any = {}, options: QueryOptions = {}): Promise<any[]> {
    // Asegurar que solo se muestren productos publicados
    return this.query('products', '/wc/v3/products', null, { ...params, status: 'publish' }, options);
  }
  
  /**
   * Obtiene un producto por su ID
   */
  async getProductById(id: number, options: QueryOptions = {}): Promise<any> {
    return this.query('product', `/wc/v3/products/${id}`, id, {}, options);
  }
  
  /**
   * Obtiene productos por categoría
   */
  async getProductsByCategory(categoryId: number, params: any = {}, options: QueryOptions = {}): Promise<any[]> {
    return this.query('products', '/wc/v3/products', null, { ...params, category: categoryId, status: 'publish' }, options);
  }
  
  /**
   * Busca productos por término de búsqueda
   */
  async searchProducts(searchTerm: string, params: any = {}, options: QueryOptions = {}): Promise<any[]> {
    // Búsquedas normalmente no se cachean o tienen TTL bajo
    const searchOptions = { ...options, ttl: options.ttl || 60 * 1000 }; // 1 minuto por defecto
    return this.query('products', '/wc/v3/products', null, { ...params, search: searchTerm, status: 'publish' }, searchOptions);
  }
  
  /**
   * Obtiene todas las categorías
   */
  async getCategories(params: any = {}, options: QueryOptions = {}): Promise<any[]> {
    return this.query('categories', '/wc/v3/products/categories', null, { ...params, per_page: 100 }, options);
  }
  
  /**
   * Obtiene una categoría por su ID
   */
  async getCategoryById(id: number, options: QueryOptions = {}): Promise<any> {
    return this.query('category', `/wc/v3/products/categories/${id}`, id, {}, options);
  }
  
  /**
   * Obtiene las secciones de la página de inicio
   */
  async getHomeSections(options: QueryOptions = {}): Promise<any[]> {
    return this.query('homeSection', '/starter/v1/home-sections', null, {}, options);
  }
  
  /**
   * Invalida la caché de productos
   */
  invalidateProducts(): void {
    cacheManager.invalidateByType('products');
    cacheManager.invalidateByType('product');
  }
  
  /**
   * Invalida la caché de un producto específico
   */
  invalidateProduct(id: number): void {
    cacheManager.invalidateRelated('product', id);
  }
  
  /**
   * Invalida la caché de categorías
   */
  invalidateCategories(): void {
    cacheManager.invalidateByType('categories');
    cacheManager.invalidateByType('category');
  }
  
  /**
   * Invalida la caché de una categoría específica
   */
  invalidateCategory(id: number): void {
    cacheManager.invalidateRelated('category', id);
  }
  
  /**
   * Obtiene productos paginados
   */
  async getPaginatedProducts(
    page: number = 1,
    perPage: number = 10,
    params: any = {},
    options: QueryOptions = {}
  ): Promise<{ data: any[], totalPages: number, totalItems: number }> {
    const {
      ttl = DEFAULT_TTL['products'],
      skipCache = false,
      forceRefresh = false,
      loadingCallback,
      errorCallback
    } = options;

    // Notificar inicio de carga
    if (loadingCallback) {
      loadingCallback(true);
    }
    
    try {
      const fullParams = { ...params, page, per_page: perPage, status: 'publish' };
      const cacheKey = cacheManager.buildCacheKey('products', null, fullParams);

      // Verificar caché
      if (!skipCache && !forceRefresh && ttl > 0) {
        const cached = cacheManager.get<{ data: any[], totalPages: number, totalItems: number }>(cacheKey);
        if (cached) {
          if (process.env.NODE_ENV === 'development') {
            logger.debug('cache', `✅ Cache hit [${cacheKey}] (paginated)`);
          }
          if (loadingCallback) {
            loadingCallback(false);
          }
          return cached;
        }
      }

      const response = await api.get('/wc/v3/products', { params: fullParams });
      
      // Extraer información de paginación de los headers
      const totalItems = parseInt(response.headers['x-wp-total'] || '0', 10);
      const totalPages = parseInt(response.headers['x-wp-totalpages'] || '0', 10);
      
      const result = { data: response.data, totalPages, totalItems };

      // Guardar en caché (incluye data + metadata de paginación)
      if (ttl > 0 && !skipCache) {
        cacheManager.set(cacheKey, result, ttl);
      }

      if (loadingCallback) {
        loadingCallback(false);
      }
      
      return result;
    } catch (error) {
      if (errorCallback) {
        errorCallback(error);
      }
      
      if (loadingCallback) {
        loadingCallback(false);
      }
      
      throw error;
    }
  }
  
  /**
   * Carga datos en lotes
   */
  async batchLoad<T>(
    requests: Array<{
      contentType: CacheableContentType;
      endpoint: string;
      id?: string | number;
      params?: any;
    }>,
    options: QueryOptions = {}
  ): Promise<Record<string, T>> {
    const batchKey = options.batchKey || `batch_${Date.now()}`;
    const results: Record<string, T> = {};
    const promises: Promise<void>[] = [];
    
    // Crear una promesa para cada solicitud
    for (const req of requests) {
      const promise = new Promise<void>(async (resolve) => {
        try {
          const data = await this.query<T>(
            req.contentType,
            req.endpoint,
            req.id || null,
            req.params || {},
            { ...options, batchKey }
          );
          
          // Usar el ID como clave si está disponible, de lo contrario usar el endpoint
          const key = req.id ? `${req.contentType}_${req.id}` : req.endpoint;
          results[key] = data;
        } catch (error) {
          // Registrar error pero no fallar todo el lote
          logger.error('batch', `Error loading ${req.endpoint}: ${error}`);
        }
        
        resolve();
      });
      
      promises.push(promise);
    }
    
    // Esperar a que todas las promesas se resuelvan
    await Promise.all(promises);
    
    return results;
  }
}
