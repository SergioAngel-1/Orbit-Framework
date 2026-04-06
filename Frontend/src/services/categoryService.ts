import { Category } from '../types/woocommerce';
import { generateSlug } from '../utils/formatters';
import { categoryService as apiCategoryService } from './api';
import logger from '../utils/logger';

/**
 * Servicio para manejar operaciones relacionadas con categorías
 */
const categoryService = {
  /**
   * Normaliza un slug (elimina acentos, convierte a minúsculas, etc.)
   */
  normalizeSlug: (text: string): string => {
    if (!text) return '';
    return text
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .replace(/[^\w\s-]/g, '')
      .replace(/\s+/g, '-')
      .replace(/-+/g, '-')
      .trim();
  },

  /**
   * Busca una categoría por su slug
   * Primero intenta usar la API, luego busca en las categorías locales
   */
  findCategoryBySlug: async (
    slug: string,
    localCategories: Category[] | null | undefined
  ): Promise<{ category: Category | null; error: boolean }> => {
    try {
      logger.info('categoryService', 'Buscando categoría para slug:', slug);
      
      // Intentar buscar por API primero
      const response = await apiCategoryService.getBySlug(slug);
      
      // Verificar y convertir la respuesta al tipo esperado
      if (response && typeof response === 'object' && 'data' in response) {
        const category = response.data as Category;
        logger.info('categoryService', 'Categoría encontrada por API:', category);
        return { category, error: false };
      }
      
      // Si no se pudo convertir, devolver null
      logger.warn('categoryService', 'La respuesta de la API no tiene el formato esperado');
      return { category: null, error: true };
    } catch (error) {
      logger.error('categoryService', 'Error al buscar categoría por slug:', error);
      
      // Si falla la API, intentar buscar en las categorías locales
      if (localCategories && localCategories.length > 0) {
        logger.info('categoryService', 'Intentando buscar en categorías cargadas localmente');
        const normalizedUrlSlug = categoryService.normalizeSlug(slug);
        
        const category = localCategories.find(cat => {
          const categorySlug = cat.slug || generateSlug(cat.name);
          const normalizedCategorySlug = categoryService.normalizeSlug(categorySlug);
          
          logger.info('categoryService', `Comparando normalizado: ${normalizedCategorySlug} con ${normalizedUrlSlug}`);
          return normalizedCategorySlug === normalizedUrlSlug;
        });
        
        if (category) {
          logger.info('categoryService', 'Categoría encontrada localmente:', category);
          return { category, error: false };
        } else {
          logger.info('categoryService', 'No se encontró categoría localmente');
          return { category: null, error: true };
        }
      } else {
        logger.info('categoryService', 'No hay categorías disponibles localmente');
        return { category: null, error: true };
      }
    }
  }
};

export default categoryService;
