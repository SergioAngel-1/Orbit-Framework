import { useState, useMemo, useCallback } from 'react';

/**
 * Hook personalizado para manejar scroll infinito
 * Carga productos de forma incremental en lugar de paginación tradicional
 */
export const useInfiniteScroll = (
  allProducts: any[],
  itemsPerLoad: number = 12
) => {
  // Número de items cargados actualmente
  const [loadedCount, setLoadedCount] = useState(itemsPerLoad);

  // Productos visibles actualmente
  const visibleProducts = useMemo(() => {
    return allProducts.slice(0, loadedCount);
  }, [allProducts, loadedCount]);

  // Verificar si hay más productos para cargar
  const hasMore = useMemo(() => {
    return loadedCount < allProducts.length;
  }, [loadedCount, allProducts.length]);

  // Función para cargar más productos
  const loadMore = useCallback(() => {
    if (!hasMore) return;
    setLoadedCount(prev => Math.min(prev + itemsPerLoad, allProducts.length));
  }, [hasMore, itemsPerLoad, allProducts.length]);

  // Resetear cuando cambian los productos (ej: cambio de categoría o filtros)
  const reset = useCallback(() => {
    setLoadedCount(itemsPerLoad);
  }, [itemsPerLoad]);

  return {
    visibleProducts,
    hasMore,
    loadMore,
    reset,
    loadedCount,
    totalCount: allProducts.length
  };
};

export default useInfiniteScroll;
