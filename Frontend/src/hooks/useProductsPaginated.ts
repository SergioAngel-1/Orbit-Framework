import { useState, useEffect, useCallback, useRef, useMemo } from 'react';
import { productService } from '../services/api';
import { Product } from '../types/woocommerce';
import { useMembership } from '../contexts/MembershipContext';
import { useLanguage } from '../contexts/LanguageContext';
import { cacheManager } from '../services/query/cacheManager';
import { DEFAULT_TTL } from '../services/query/types';
import logger from '../utils/logger';

/**
 * Hook para cargar productos con paginación real desde la API.
 * A diferencia de useProducts que carga todo de una vez, este hook:
 * 1. Carga solo la primera página inicialmente
 * 2. Expone loadMore() para cargar páginas adicionales bajo demanda
 * 3. Acumula productos conforme se cargan
 * 
 * Ideal para infinite scroll con grandes catálogos.
 */

interface UseProductsPaginatedState {
  products: Product[];           // Productos in-stock mostrados
  outOfStockProducts: Product[];  // Productos agotados acumulados (se muestran al final)
  loading: boolean;
  loadingMore: boolean;
  error: Error | null;
  hasMore: boolean;
  totalProducts: number;
  currentPage: number;
}

interface UseProductsPaginatedReturn extends UseProductsPaginatedState {
  loadMore: () => Promise<void>;
  reset: () => void;
}

const PRODUCTS_PER_PAGE = 24;

export const useProductsPaginated = (categoryId?: number): UseProductsPaginatedReturn => {
  const { currentLevel, membershipVersion } = useMembership();
  const { currentLang } = useLanguage();
  
  const [state, setState] = useState<UseProductsPaginatedState>({
    products: [],
    outOfStockProducts: [],
    loading: true,
    loadingMore: false,
    error: null,
    hasMore: true,
    totalProducts: 0,
    currentPage: 0,
  });

  // Refs para controlar peticiones
  const abortControllerRef = useRef<AbortController | null>(null);
  const loadMoreAbortRef = useRef<AbortController | null>(null);
  const isLoadingRef = useRef(false);
  
  // Ref para trackear la categoría actual y evitar mezclar datos
  const currentCategoryRef = useRef<number | undefined>(categoryId);
  
  // Ref para estabilizar loadInitialPage y evitar que reset cambie en cada render
  const loadInitialPageRef = useRef<() => Promise<void>>(() => Promise.resolve());

  /**
   * Carga una página específica de productos
   */
  const fetchPage = useCallback(async (page: number, signal: AbortSignal): Promise<{
    products: Product[];
    totalPages: number;
    totalProducts: number;
  }> => {
    const params = { 
      per_page: PRODUCTS_PER_PAGE, 
      page, 
      status: 'publish',
      orderby: 'title',
      order: 'asc'
    };
    
    const response = categoryId 
      ? await productService.getByCategory(categoryId, params)
      : await productService.getAll(params);
    
    if (signal.aborted) {
      throw new DOMException('Aborted', 'AbortError');
    }
    
    const totalPages = parseInt(response.headers?.['x-wp-totalpages'] || '1', 10);
    const totalProducts = parseInt(response.headers?.['x-wp-total'] || '0', 10);
    
    return {
      products: response.data,
      totalPages,
      totalProducts
    };
  }, [categoryId]);

  /**
   * Carga la primera página (reset completo)
   */
  const loadInitialPage = useCallback(async () => {
    // Cancelar petición anterior
    if (abortControllerRef.current) {
      abortControllerRef.current.abort();
    }
    
    abortControllerRef.current = new AbortController();
    const signal = abortControllerRef.current.signal;
    
    // Verificar caché (usamos 'products' como tipo con sufijo '-p1' para diferenciar de carga completa)
    const cacheKey = cacheManager.buildCacheKey('products', `${categoryId || 'all'}-p1`, { 
      level: currentLevel
    });
    const cached = cacheManager.get<{ products: Product[]; totalProducts: number; totalPages: number }>(cacheKey);
    
    if (cached) {
      logger.info('useProductsPaginated', `Cache HIT [${cacheKey}]: ${cached.products.length} productos`);
      // Separar productos in-stock y out-of-stock del caché
      const inStock = cached.products.filter(p => p.stock_status !== 'outofstock');
      const outOfStock = cached.products.filter(p => p.stock_status === 'outofstock');
      setState({
        products: inStock,
        outOfStockProducts: outOfStock,
        loading: false,
        loadingMore: false,
        error: null,
        hasMore: cached.totalPages > 1,
        totalProducts: cached.totalProducts,
        currentPage: 1,
      });
      return;
    }
    
    setState(prev => ({ ...prev, loading: true, error: null }));
    
    try {
      logger.info('useProductsPaginated', `Cargando página 1 (categoría: ${categoryId || 'todas'}, nivel: ${currentLevel})`);
      
      const result = await fetchPage(1, signal);
      
      if (signal.aborted) return;
      
      // Verificar que seguimos en la misma categoría
      if (currentCategoryRef.current !== categoryId) {
        logger.info('useProductsPaginated', 'Categoría cambió durante fetch, ignorando resultado');
        return;
      }
      
      // Separar productos in-stock y out-of-stock
      // Los out-of-stock se acumulan y se muestran al final cuando no hay más páginas
      const allProducts = result.products;
      const inStock = allProducts.filter(p => p.stock_status !== 'outofstock');
      const outOfStock = allProducts.filter(p => p.stock_status === 'outofstock');
      
      // Cachear primera página completa (para restaurar correctamente)
      cacheManager.set(cacheKey, {
        products: allProducts,
        totalProducts: result.totalProducts,
        totalPages: result.totalPages
      }, DEFAULT_TTL.products);
      
      logger.info('useProductsPaginated', `Página 1 cargada: ${inStock.length} in-stock, ${outOfStock.length} out-of-stock de ${result.totalProducts} total`);
      
      setState({
        products: inStock,
        outOfStockProducts: outOfStock,
        loading: false,
        loadingMore: false,
        error: null,
        hasMore: result.totalPages > 1,
        totalProducts: result.totalProducts,
        currentPage: 1,
      });
    } catch (error: any) {
      if (error.name === 'AbortError' || signal.aborted) {
        logger.info('useProductsPaginated', 'Petición cancelada');
        return;
      }
      
      logger.error('useProductsPaginated', 'Error cargando página inicial:', error);
      setState(prev => ({
        ...prev,
        loading: false,
        loadingMore: false,
        error: error instanceof Error ? error : new Error('Error al cargar productos'),
      }));
    }
  }, [categoryId, currentLevel, fetchPage]);
  
  // Mantener ref actualizado para que reset sea estable
  loadInitialPageRef.current = loadInitialPage;

  /**
   * Carga la siguiente página de productos
   */
  const loadMore = useCallback(async () => {
    // Evitar cargas duplicadas
    if (isLoadingRef.current || !state.hasMore || state.loading) {
      return;
    }
    
    isLoadingRef.current = true;
    const nextPage = state.currentPage + 1;
    
    // Cancelar petición anterior de loadMore si existe
    if (loadMoreAbortRef.current) {
      loadMoreAbortRef.current.abort();
    }
    
    // Crear nuevo AbortController y guardarlo en ref para poder cancelarlo
    // si el usuario cambia de categoría mientras la petición está en vuelo
    loadMoreAbortRef.current = new AbortController();
    const signal = loadMoreAbortRef.current.signal;
    
    // Capturar la categoría actual ANTES de la petición async
    const categoryAtStart = currentCategoryRef.current;
    
    setState(prev => ({ ...prev, loadingMore: true, error: null })); // Limpiar error previo al reintentar
    
    try {
      logger.info('useProductsPaginated', `Cargando página ${nextPage} (categoría: ${categoryAtStart || 'todas'})`);
      
      const result = await fetchPage(nextPage, signal);
      
      if (signal.aborted) {
        isLoadingRef.current = false;
        return;
      }
      
      // Verificar que seguimos en la misma categoría usando el ref (valor actual),
      // NO el closure (valor al momento de crear el callback).
      // Esto previene race conditions cuando el usuario cambia de categoría rápidamente.
      if (currentCategoryRef.current !== categoryAtStart) {
        logger.info('useProductsPaginated', 'Categoría cambió durante loadMore, ignorando resultado');
        isLoadingRef.current = false;
        return;
      }
      
      const newProducts = result.products;
      const hasMorePages = nextPage < result.totalPages;
      
      // Separar productos in-stock y out-of-stock de la nueva página
      const newInStock = newProducts.filter(p => p.stock_status !== 'outofstock');
      const newOutOfStock = newProducts.filter(p => p.stock_status === 'outofstock');
      
      logger.info('useProductsPaginated', `Página ${nextPage} cargada: ${newInStock.length} in-stock, ${newOutOfStock.length} out-of-stock`);
      
      setState(prev => {
        // Acumular productos in-stock para mostrar inmediatamente
        // Acumular productos out-of-stock para mostrar al final cuando no haya más páginas
        const allInStock = [...prev.products, ...newInStock];
        const allOutOfStock = [...prev.outOfStockProducts, ...newOutOfStock];
        
        return {
          ...prev,
          products: allInStock,
          outOfStockProducts: allOutOfStock,
          loadingMore: false,
          hasMore: hasMorePages,
          currentPage: nextPage,
        };
      });
    } catch (error: any) {
      if (error.name === 'AbortError') {
        logger.info('useProductsPaginated', 'loadMore cancelado');
      } else {
        logger.error('useProductsPaginated', `Error cargando página ${nextPage}:`, error);
        setState(prev => ({
          ...prev,
          loadingMore: false,
          error: error instanceof Error ? error : new Error('Error al cargar más productos'),
        }));
      }
    } finally {
      isLoadingRef.current = false;
    }
  }, [state.hasMore, state.loading, state.currentPage, fetchPage]);

  /**
   * Resetea el estado y recarga desde la primera página.
   * NOTA: Esta función es estable (no cambia entre renders) gracias al uso de ref.
   * Esto evita que los useEffect que dependen de reset se disparen innecesariamente.
   */
  const reset = useCallback(() => {
    setState({
      products: [],
      outOfStockProducts: [],
      loading: true,
      loadingMore: false,
      error: null,
      hasMore: true,
      totalProducts: 0,
      currentPage: 0,
    });
    // Usar ref para llamar a la versión más reciente sin crear dependencia
    loadInitialPageRef.current();
  }, []); // Sin dependencias = función estable

  // Efecto para cargar la primera página cuando cambia la categoría o membresía
  // NOTA: Usamos loadInitialPageRef en lugar de loadInitialPage como dependencia
  // para evitar loops causados por la recreación del callback.
  // NOTA: NO bloqueamos por membershipLoading. Cargamos inmediatamente con el nivel
  // actual (0 si aún no se resuelve) y recargamos cuando membershipVersion cambie.
  useEffect(() => {
    // Sentinel value: categoryId=-1 significa "no buscar productos"
    if (categoryId !== undefined && categoryId < 0) {
      setState({
        products: [],
        outOfStockProducts: [],
        loading: false,
        loadingMore: false,
        error: null,
        hasMore: false,
        totalProducts: 0,
        currentPage: 0,
      });
      return;
    }
    
    // Actualizar ref de categoría actual
    currentCategoryRef.current = categoryId;
    
    // Cargar primera página usando ref para obtener la versión más reciente
    loadInitialPageRef.current();
    
    // Cleanup: cancelar AMBAS peticiones (loadInitialPage y loadMore) al cambiar de categoría
    return () => {
      if (abortControllerRef.current) {
        abortControllerRef.current.abort();
      }
      if (loadMoreAbortRef.current) {
        loadMoreAbortRef.current.abort();
      }
    };
  }, [categoryId, currentLevel, membershipVersion, currentLang]);

  // Cuando no hay más páginas, combinar productos in-stock con out-of-stock
  // Los out-of-stock se muestran al final de TODO el catálogo
  // IMPORTANTE: useMemo para evitar crear un nuevo array en cada render,
  // lo cual causaba un loop infinito en el efecto de filtrado de useShopPageState.
  const finalProducts = useMemo(() => {
    if (state.hasMore) return state.products;
    if (state.outOfStockProducts.length === 0) return state.products;
    return [...state.products, ...state.outOfStockProducts];
  }, [state.products, state.outOfStockProducts, state.hasMore]);

  return {
    ...state,
    products: finalProducts,  // Sobrescribir products con la lista combinada cuando corresponda
    loadMore,
    reset,
  };
};

export default useProductsPaginated;
