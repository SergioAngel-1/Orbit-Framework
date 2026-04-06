import { Product } from '../types/woocommerce';
import logger from '../utils/logger';

/**
 * Tipo para los criterios de ordenamiento disponibles
 */
export type SortCriteria = 'default' | 'price-asc' | 'price-desc' | 'date' | 'popularity';

/**
 * Servicio para manejar la lógica de filtrado y ordenamiento de productos
 */
const shopService = {
  /**
   * Normaliza un slug (elimina acentos, convierte a minúsculas, etc.)
   * @param text - Texto a normalizar
   * @returns Texto normalizado como slug
   */
  normalizeSlug: (text: string): string => {
    if (!text) return '';
    try {
      return text
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '') // Eliminar acentos
        .replace(/[^\w\s-]/g, '') // Eliminar caracteres especiales
        .replace(/\s+/g, '-') // Reemplazar espacios con guiones
        .replace(/-+/g, '-') // Eliminar guiones duplicados
        .trim();
    } catch (error) {
      logger.error('shopService', 'Error al normalizar slug:', error);
      return text.toLowerCase().replace(/\s+/g, '-').trim();
    }
  },

  /**
   * Verifica si un producto es válido para mostrar en la tienda
   * @param product - Producto a verificar
   * @returns true si el producto es válido, false en caso contrario
   */
  isValidProduct: (product: Product): boolean => {
    // Verificar que el producto tenga un slug válido
    if (!product.slug || product.slug === 'producto' || product.slug === '/producto/') {
      return false;
    }
    
    // Verificar que el producto tenga un nombre
    if (!product.name) {
      return false;
    }
    
    // Verificar que el producto no esté marcado como borrador o privado
    if (product.status && ['draft', 'private', 'trash'].includes(product.status)) {
      return false;
    }
    
    return true;
  },

  /**
   * Filtra productos por término de búsqueda y ordena según criterio
   * @param products - Lista de productos a filtrar y ordenar
   * @param searchTerm - Término de búsqueda
   * @param sortBy - Criterio de ordenamiento
   * @returns Lista de productos filtrados y ordenados
   */
  filterAndSortProducts: (
    products: Product[] | undefined,
    searchTerm: string,
    sortBy: string
  ): Product[] => {
    try {
      // Validar entrada
      if (!products || !Array.isArray(products)) {
        logger.warn('shopService', 'No hay productos para filtrar');
        return [];
      }

      logger.debug('shopService', `Filtrando ${products.length} productos (búsqueda: "${searchTerm}", orden: ${sortBy})`);

      // Crear una copia para no modificar el original
      let filtered = [...products];
      
      // Filtrar productos inválidos
      filtered = filtered.filter(product => shopService.isValidProduct(product));

      // Filtrar por término de búsqueda
      if (searchTerm && searchTerm.trim() !== '') {
        const term = searchTerm.toLowerCase().trim();
        filtered = filtered.filter(product => {
          // Buscar en nombre
          const nameMatch = product.name.toLowerCase().includes(term);
          
          // Buscar en descripción corta
          const shortDescMatch = product.short_description && 
            typeof product.short_description === 'string' && 
            product.short_description.toLowerCase().includes(term);
          
          // Buscar en categorías
          const categoryMatch = product.categories && 
            Array.isArray(product.categories) && 
            product.categories.some(cat => 
              cat.name && cat.name.toLowerCase().includes(term)
            );
          
          return nameMatch || shortDescMatch || categoryMatch;
        });
      }

      // IMPORTANTE: Solo ordenar si el usuario seleccionó un criterio específico (no 'default').
      // Cuando sortBy es 'default', mantener el orden original del backend para evitar
      // reorganización visual durante infinite scroll. El backend ya envía los productos
      // ordenados por título (orderby: 'title', order: 'asc').
      // useProductsPaginated ya maneja la separación in-stock/out-of-stock correctamente,
      // por lo que NO re-separamos aquí para evitar procesamiento redundante.
      if (sortBy !== 'default') {
        // Ordenar productos según el criterio seleccionado por el usuario
        // Separar solo cuando hay ordenamiento custom para mantener out-of-stock al final
        const inStockProducts = filtered.filter(product => product.stock_status !== 'outofstock');
        const outOfStockProducts = filtered.filter(product => product.stock_status === 'outofstock');
        
        try {
          const sortProducts = (products: Product[]) => {
            switch (sortBy) {
              case 'price-asc':
                return products.sort((a, b) => {
                  const priceA = a.price ? parseFloat(a.price) : 0;
                  const priceB = b.price ? parseFloat(b.price) : 0;
                  return priceA - priceB;
                });
              case 'price-desc':
                return products.sort((a, b) => {
                  const priceA = a.price ? parseFloat(a.price) : 0;
                  const priceB = b.price ? parseFloat(b.price) : 0;
                  return priceB - priceA;
                });
              case 'date':
                return products.sort((a, b) => {
                  try {
                    return new Date(b.date_created || 0).getTime() - new Date(a.date_created || 0).getTime();
                  } catch (error) {
                    return 0;
                  }
                });
              case 'popularity':
                return products.sort((a, b) => {
                  const salesA = a.total_sales ? parseInt(a.total_sales.toString()) : 0;
                  const salesB = b.total_sales ? parseInt(b.total_sales.toString()) : 0;
                  return salesB - salesA;
                });
              default:
                return products;
            }
          };
          
          sortProducts(inStockProducts);
          sortProducts(outOfStockProducts);
          filtered = [...inStockProducts, ...outOfStockProducts];
        } catch (sortError) {
          logger.error('shopService', 'Error al ordenar productos:', sortError);
        }
      }

      logger.debug('shopService', `Resultado: ${filtered.length} productos`);
      return filtered;
    } catch (error) {
      logger.error('shopService', 'Error al filtrar y ordenar productos:', error);
      return [];
    }
  }
};

export default shopService;
