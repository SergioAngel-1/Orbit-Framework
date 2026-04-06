/**
 * CategoriesContext - Contexto para compartir datos de categorías
 * 
 * Evita múltiples llamadas a useCategories() desde diferentes componentes
 * (CategoryCarousel, ShopPage, SearchPage, etc.) que generaban requests
 * duplicados a /wc/products/categories.
 * Centraliza la carga de categorías y las comparte via contexto.
 */

import { createContext, useContext, useMemo, ReactNode } from 'react';
import { useCategories } from '../hooks/useWooCommerce';
import { Category } from '../types/woocommerce';

interface CategoriesContextType {
  categories: Category[] | null;
  loading: boolean;
  error: Error | null;
}

const CategoriesContext = createContext<CategoriesContextType | undefined>(undefined);

/**
 * Hook para consumir el contexto de categorías
 * Si se usa fuera del Provider, devuelve undefined (fallback al hook directo)
 */
export const useCategoriesContext = (): CategoriesContextType => {
  const context = useContext(CategoriesContext);
  if (context === undefined) {
    throw new Error('useCategoriesContext debe ser usado dentro de un CategoriesProvider');
  }
  return context;
};

/**
 * Hook seguro: usa el contexto si está disponible, o devuelve null
 */
export const useCategoriesContextSafe = (): CategoriesContextType | null => {
  return useContext(CategoriesContext) ?? null;
};

interface CategoriesProviderProps {
  children: ReactNode;
}

/**
 * Provider que carga las categorías una sola vez y las comparte
 */
export const CategoriesProvider = ({ children }: CategoriesProviderProps) => {
  const { data: categories, loading, error } = useCategories();

  const value: CategoriesContextType = useMemo(() => ({
    categories,
    loading,
    error,
  }), [categories, loading, error]);

  return (
    <CategoriesContext.Provider value={value}>
      {children}
    </CategoriesContext.Provider>
  );
};

export default CategoriesContext;
