import { useState, useEffect } from 'react';
import { productService, categoryService } from '../services/api';
import { Product, Category } from '../types/woocommerce';
import { useMembership } from '../contexts/MembershipContext';
import { useAuth } from '../contexts/AuthContext';
import { useLanguage } from '../contexts/LanguageContext';
import { cacheManager } from '../services/query/cacheManager';
import { DEFAULT_TTL } from '../services/query/types';
import logger from '../utils/logger';
import i18n from '../config/i18n';

/**
 * IMPORTANTE: Filtrado por Membresía
 * 
 * El backend (WordPress/WooCommerce) ya filtra automáticamente:
 * - Productos según la membresía mínima de sus categorías
 * - Categorías según el meta field '_min_membership_level'
 * 
 * Para usuarios autenticados: retorna solo productos/categorías accesibles
 * Para usuarios anónimos: retorna solo contenido público (nivel 0)
 * 
 * El frontend solo necesita mostrar los datos recibidos.
 */

interface UseWooCommerceState<T> {
  data: T | null;
  loading: boolean;
  error: Error | null;
}

// Hook para un producto individual
export const useProduct = (productId: number) => {
  const [state, setState] = useState<UseWooCommerceState<Product>>({
    data: null,
    loading: true,
    error: null,
  });

  useEffect(() => {
    const fetchProduct = async () => {
      try {
        setState(prev => ({ ...prev, loading: true }));
        const response = await productService.getById(productId);
        setState({
          data: response.data,
          loading: false,
          error: null,
        });
      } catch (error) {
        setState({
          data: null,
          loading: false,
          error: error instanceof Error ? error : new Error(i18n.t('errors:products.unknownLoadBenefitError')),
        });
      }
    };

    fetchProduct();
  }, [productId]);

  return state;
};

// Hook para categorías
export const useCategories = () => {
  const { currentLevel, membershipVersion } = useMembership();
  const { isAuthenticated } = useAuth();
  const { currentLang } = useLanguage();
  const [state, setState] = useState<UseWooCommerceState<Category[]>>({
    data: null,
    loading: true,
    error: null,
  });

  // NOTA: NO bloqueamos por membershipLoading. Cargamos inmediatamente con el nivel
  // actual (0 si aún no se resuelve) y recargamos cuando membershipVersion cambie.
  // El backend filtra por JWT del usuario, así que siempre retorna datos correctos.
  useEffect(() => {
    const fetchCategories = async () => {
      try {
        setState(prev => ({ ...prev, loading: true }));
        
        logger.info('useCategories', `Cargando categorías (Membresía nivel ${currentLevel}, auth: ${isAuthenticated})`);
        
        // Verificar caché in-memory antes de hacer petición HTTP
        const cacheKey = cacheManager.buildCacheKey('categories', 'all', { level: currentLevel });
        const cached = cacheManager.get<Category[]>(cacheKey);
        if (cached) {
          logger.info('useCategories', `Cache HIT [${cacheKey}]: ${cached.length} categorías`);
          setState({ data: cached, loading: false, error: null });
          return;
        }
        
        const response = await categoryService.getAll();
        
        // El backend ya filtra las categorías según la membresía del usuario
        logger.info('useCategories', `Categorías cargadas (filtradas por backend): ${response.data.length}`);
        
        // Guardar en caché in-memory (30 min — categorías cambian poco)
        cacheManager.set(cacheKey, response.data, DEFAULT_TTL.categories);
        
        setState({
          data: response.data,
          loading: false,
          error: null,
        });
      } catch (error) {
        logger.error('useCategories', 'Error al cargar categorías');
        setState({
          data: null,
          loading: false,
          error: error instanceof Error ? error : new Error(i18n.t('errors:products.unknownLoadCategoriesError')),
        });
      }
    };

    fetchCategories();
  }, [currentLevel, membershipVersion, isAuthenticated, currentLang]); // Recargar cuando cambie la membresía, autenticación o idioma

  return state;
};

// Interface para el estado de búsqueda de productos
interface UseSearchProductsState {
  data: Product[];
  loading: boolean;
  error: Error | null;
  totalPages: number;
  totalProducts: number;
}

export const useSearchProducts = (searchTerm: string, page: number = 1, perPage: number = 12, showOutOfStock: boolean = true) => {
  const { currentLevel, membershipVersion } = useMembership();
  const { currentLang } = useLanguage();
  const [state, setState] = useState<UseSearchProductsState>({
    data: [],  
    loading: false,
    error: null,
    totalPages: 1,
    totalProducts: 0
  });

  // NOTA: NO bloqueamos por membershipLoading. Cargamos inmediatamente con el nivel
  // actual (0 si aún no se resuelve) y recargamos cuando membershipVersion cambie.
  useEffect(() => {
    // No realizar búsquedas con términos muy cortos
    if (!searchTerm || searchTerm.trim().length < 2) {
      setState({
        data: [],
        loading: false,
        error: null,
        totalPages: 1,
        totalProducts: 0
      });
      return;
    }

    // Debounce para no hacer peticiones inmediatas
    const searchDelay = setTimeout(async () => {
      try {
        setState(prev => ({ ...prev, loading: true, error: null }));
        
        logger.info('useSearchProducts', `Buscando "${searchTerm}" (Membresía nivel ${currentLevel})`);
        
        // Añadir parámetros de paginación y filtrar solo productos publicados (no privados)
        const params = {
          per_page: 100, // Solicitamos hasta 100 productos por consulta para tener mejores resultados
          page: 1, // Siempre pedimos la primera página ya que haremos paginación manual
          status: 'publish' // Solo mostrar productos publicados (no privados)
        };
        
        // Obtener categorías desde caché si están disponibles (evita fetch redundante por búsqueda)
        const categoriesCacheKey = cacheManager.buildCacheKey('categories', 'all', { level: currentLevel });
        const cachedCategories = cacheManager.get<Category[]>(categoriesCacheKey);
        
        // Obtener los productos y categorías en paralelo para mejorar el rendimiento
        const [productResponse, categoriesResponse] = await Promise.all([
          productService.search(searchTerm, params),
          cachedCategories 
            ? Promise.resolve({ data: cachedCategories }) 
            : categoryService.getAll() // Solo fetch si no están en caché
        ]);
        
        // Si las categorías vinieron del API, cachearlas
        if (!cachedCategories && categoriesResponse.data) {
          cacheManager.set(categoriesCacheKey, categoriesResponse.data, DEFAULT_TTL.categories);
        }
        
        // Obtener el total de páginas y productos de los headers
        let totalProducts = 0;
        let totalPages = 1;
        
        // Verificar si response es un objeto con la propiedad headers (respuesta de Axios)
        if (productResponse && typeof productResponse === 'object' && 'headers' in productResponse) {
          totalProducts = parseInt(productResponse.headers['x-wp-total'] || '0', 10);
          totalPages = parseInt(productResponse.headers['x-wp-totalpages'] || '1', 10);
        }
        
        // Términos de búsqueda normalizados para comparaciones (sin acentos, todo minúscula)
        const normalizedSearchTerm = searchTerm.toLowerCase()
          .normalize("NFD").replace(/[\u0300-\u036f]/g, "");
          
        // Ordenar los productos por relevancia con nuestra lógica personalizada
        const products = productResponse.data;
        const categories = categoriesResponse.data || [];
        
        // Encontrar categorías que coinciden con el término de búsqueda
        const matchingCategories = categories.filter((cat: Category) => {
          const normalizedCatName = cat.name.toLowerCase()
            .normalize("NFD").replace(/[\u0300-\u036f]/g, "");
          return normalizedCatName.includes(normalizedSearchTerm);
        });
        
        // IDs de categorías que coinciden con la búsqueda
        const matchingCategoryIds = matchingCategories.map((cat: Category) => cat.id);
        
        // Asignar puntuación a cada producto para ordenarlos
        const scoredProducts = products.map((product: Product) => {
          let score = 0;
          const normalizedName = product.name.toLowerCase()
            .normalize("NFD").replace(/[\u0300-\u036f]/g, "");
          const normalizedDescription = product.short_description
            ? product.short_description.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "")
            : "";
            
          // Agregar un puntaje base alto para productos en stock
          // Esto garantiza que todos los productos en stock aparezcan primero
          const stockBaseScore = product.stock_status === 'instock' ? 100000 : 0;
          
          // Puntuación basada en el nombre del producto (prioridad máxima)
          if (normalizedName === normalizedSearchTerm) score += 10000; // Coincidencia exacta
          else if (normalizedName.startsWith(normalizedSearchTerm)) score += 5000; // Comienza con
          else if (normalizedName.includes(normalizedSearchTerm)) score += 1000; // Contiene
          
          // Puntuación basada en categorías (segunda prioridad)
          if (product.categories && product.categories.some((cat: {id: number}) => matchingCategoryIds.includes(cat.id))) {
            score += 500;
          }
          
          // Puntuación basada en descripción (tercera prioridad)
          if (normalizedDescription.includes(normalizedSearchTerm)) score += 100;
          
          // Sumar el puntaje base por stock al puntaje total
          score += stockBaseScore;
          
          return { ...product, _score: score };
        });
        
        // Filtrar productos agotados si es necesario
        let filteredScoredProducts = scoredProducts;
        if (!showOutOfStock) {
          filteredScoredProducts = scoredProducts.filter((product: Product) => product.stock_status === 'instock');
        }
        
        // Definir el tipo para productos con puntuación
        type ScoredProduct = Product & { _score: number };
        
        // Ordenar primero por disponibilidad (instock primero) y luego por puntuación
        filteredScoredProducts.sort((a: ScoredProduct, b: ScoredProduct) => {
          // Si uno está en stock y el otro no, el que está en stock va primero
          if (a.stock_status === 'instock' && b.stock_status !== 'instock') return -1;
          if (a.stock_status !== 'instock' && b.stock_status === 'instock') return 1;
          
          // Si ambos tienen el mismo estado de stock, ordenar por puntuación
          return b._score - a._score || a.name.localeCompare(b.name, 'es', { sensitivity: 'base' });
        });
        
        // Calcular el número total de productos después de filtrar y ordenar
        const totalFilteredProducts = filteredScoredProducts.length;
        
        // Calcular el total de páginas basado en el total de productos filtrados
        const adjustedTotalPages = Math.ceil(totalFilteredProducts / perPage);
        
        // Verificar que la página solicitada sea válida
        const validPage = page <= adjustedTotalPages ? page : 1;
        const validStartIndex = (validPage - 1) * perPage;
        const validEndIndex = Math.min(validStartIndex + perPage, totalFilteredProducts);
        
        // Aplicar paginación manual sobre los resultados ya ordenados por relevancia
        const paginatedProducts = filteredScoredProducts.slice(validStartIndex, validEndIndex);
        
        // Limpiar la propiedad _score antes de devolver los resultados
        const cleanedProducts = paginatedProducts.map((p: ScoredProduct) => {
          const { _score, ...product } = p;
          return product as Product;
        });

        setState({
          data: cleanedProducts,
          loading: false,
          error: null,
          totalPages: adjustedTotalPages || totalPages,
          totalProducts: filteredScoredProducts.length || totalProducts
        });
      } catch (error) {
        logger.error('useSearchProducts', 'Error en búsqueda:', error);
        
        // Mostrar un array vacío en caso de error, para no romper la UI
        setState({
          data: [],
          loading: false,
          error: error instanceof Error ? error : new Error(i18n.t('errors:products.searchError')),
          totalPages: 1,
          totalProducts: 0
        });
      }
    }, 500); 

    return () => clearTimeout(searchDelay);
  }, [searchTerm, page, perPage, currentLevel, membershipVersion, currentLang]); // Recargar cuando cambie la membresía o el idioma

  return state;
};

export default {
  useProduct,
  useCategories,
  useSearchProducts
};
