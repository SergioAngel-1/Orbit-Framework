import { useState, useEffect, useMemo, useRef, useCallback } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useProductsPaginated } from './useProductsPaginated';
import { useCategoriesContext } from '../contexts/CategoriesContext';
import { useCategoryAccess } from './useCategoryAccess';
import { useMembership } from '../contexts/MembershipContext';
import { Product } from '../types/woocommerce';
import shopService from '../services/shopService';
import categoryService from '../services/categoryService';
import { resolveMembershipSlug } from '../utils/membershipRouteUtils';
import useMembershipLevels from './useMembershipLevels';
import i18n from '../config/i18n';

/**
 * Custom hook para manejar toda la lógica de estado de la página de tienda
 * Permite separar la lógica del componente de presentación
 */
export const useShopPageState = (categorySlug?: string, membershipSlug?: string) => {
  // Estado de navegación y parámetros
  const [searchParams, setSearchParams] = useSearchParams();
  
  // Hook de validación de acceso a categorías
  const { validateCategoryAccess, filterAccessibleCategories } = useCategoryAccess();
  
  // Contexto de membresía (filterAccessibleCategories usa el nivel internamente)
  useMembership();
  const { levels } = useMembershipLevels();
  
  // Ref para debounce de cambio de categoría
  const categoryDebounceRef = useRef<NodeJS.Timeout | null>(null);
  // Ref para cancelar peticiones de categoría en curso
  const categoryAbortRef = useRef<AbortController | null>(null);
  
  // Estados relacionados con categorías
  const [selectedCategory, setSelectedCategory] = useState<number | undefined>(undefined);
  const [categoryName, setCategoryName] = useState<string>(i18n.t('shopPage:defaults.allProducts'));
  const [categoryMinMembership, setCategoryMinMembership] = useState<number | undefined>(undefined);
  const [loadingCategory, setLoadingCategory] = useState<boolean>(false);
  const [categoryNotFound, setCategoryNotFound] = useState<boolean>(false);
  const [categoryAccessDenied, setCategoryAccessDenied] = useState<boolean>(false);
  const [accessDeniedReason, setAccessDeniedReason] = useState<string | null>(null);
  const [isCategoryChanging, setIsCategoryChanging] = useState<boolean>(false);
  // Indica que el slug de membresía en la URL no corresponde al nivel real de la categoría
  const [membershipSlugMismatch, setMembershipSlugMismatch] = useState<boolean>(false);
  // Ref sincrónica: slug de categoría para el cual se resolvió categoryMinMembership.
  // Los refs se actualizan síncronamente y son legibles durante render, a diferencia de los
  // estados que se aplican después del render. Esto permite a las guardas de ShopPage
  // detectar datos stale cuando los props cambian antes de que los efectos se ejecuten.
  const resolvedForSlugRef = useRef<string | undefined>(undefined);
  
  // Estado de productos con paginación real
  // Solo cargar productos cuando la categoría esté lista y tenga acceso
  const shouldFetchProducts = categorySlug 
    ? (selectedCategory !== undefined && !loadingCategory && !categoryAccessDenied && !membershipSlugMismatch) 
    : true;
  const { 
    products, 
    loading: productsLoading, 
    loadingMore,
    hasMore: hasMoreProducts,
    totalProducts,
    loadMore: loadMoreProducts,
    reset: resetProducts,
    error: productsError
  } = useProductsPaginated(shouldFetchProducts ? selectedCategory : -1);
  const { categories } = useCategoriesContext();
  const [filteredProducts, setFilteredProducts] = useState<Product[]>([]);
  
  // Ref para categories - evita que fetchCategoryBySlug se recree cuando categories cambia
  // Debe declararse DESPUÉS de obtener categories del contexto
  const categoriesRef = useRef(categories);
  categoriesRef.current = categories;
  
  // Refs para funciones que cambian frecuentemente - evita recrear fetchCategoryBySlug
  const validateCategoryAccessRef = useRef(validateCategoryAccess);
  validateCategoryAccessRef.current = validateCategoryAccess;
  const levelsRef = useRef(levels);
  levelsRef.current = levels;
  
  // FASE 3.1: Memoizar filtrado de categorías accesibles
  // Solo se recalcula cuando cambian las categorías o el nivel de membresía
  // NOTA: El count de cada categoría viene correcto desde el backend (ya filtrado por membresía).
  // No se recalcula aquí porque solo tenemos los productos paginados (ej: 24 de 100),
  // lo cual corrompería el count real.
  const accessibleCategories = useMemo(() => {
    if (!Array.isArray(categories)) return null;
    return filterAccessibleCategories(categories);
  }, [categories, filterAccessibleCategories]);
  
  // Estados para filtros
  const [searchTerm, setSearchTerm] = useState<string>('');
  const [debouncedSearchTerm, setDebouncedSearchTerm] = useState<string>('');
  const [sortBy, setSortBy] = useState<string>('default');
  const [filtersVisible, setFiltersVisible] = useState<boolean>(false);
  
  // Debounce del término de búsqueda para evitar filtrados excesivos mientras el usuario escribe
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearchTerm(searchTerm);
    }, 300); // 300ms de debounce
    
    return () => clearTimeout(timer);
  }, [searchTerm]);

  // FASE 3.2: Función para buscar categoría con soporte de cancelación
  // NOTA: Usamos refs para todas las dependencias que cambian frecuentemente para evitar
  // que el callback se recree, lo cual causaba loops infinitos y congelamiento de la UI.
  const fetchCategoryBySlug = useCallback(async (slug: string, signal: AbortSignal) => {
    try {
      // Usar refs para obtener valores actuales sin crear dependencias
      const categoriesArray = Array.isArray(categoriesRef.current) ? categoriesRef.current : [];
      const result = await categoryService.findCategoryBySlug(slug, categoriesArray);
      
      // Verificar si la petición fue cancelada
      if (signal.aborted) return;
      
      if (result.category) {
        // VALIDACIÓN DE ACCESO: Verificar si el usuario tiene acceso a esta categoría
        const accessResult = validateCategoryAccessRef.current(result.category);
        
        // VALIDACIÓN DE SLUG DE MEMBRESÍA:
        // Si hay un slug de membresía en la URL, verificar que corresponda
        // al nivel mínimo real de la categoría. Solo la URL canónica es válida.
        // Ej: si "hongos" requiere Plata, solo /membresia-plata/hongos es válido;
        //     /membresia-oro/hongos → mismatch → 404
        //
        // La comparación se hace por NIVEL numérico (agnóstico de idioma) usando
        // resolveMembershipSlug, para evitar falsos mismatch durante cambio de idioma.
        const currentLevels = levelsRef.current;
        if (membershipSlug && currentLevels.length > 0) {
          const requiredLevel = accessResult.requiredLevel;
          const resolved = resolveMembershipSlug(membershipSlug, currentLevels);
          const slugLevel = resolved?.level;
          // Mismatch si: slug desconocido, categoría no requiere membresía,
          // o el nivel del slug no coincide con el requerido
          if (!resolved || (requiredLevel <= 0) || (slugLevel !== requiredLevel)) {
            setMembershipSlugMismatch(true);
            setCategoryName(result.category.name);
            setCategoryMinMembership(requiredLevel);
            resolvedForSlugRef.current = slug;
            // No establecer selectedCategory para evitar cargar productos
            return;
          }
        }
        
        if (!accessResult.hasAccess) {
          setCategoryAccessDenied(true);
          setAccessDeniedReason(accessResult.accessDeniedReason);
          setCategoryName(result.category.name);
          setCategoryMinMembership(accessResult.requiredLevel);
          resolvedForSlugRef.current = slug;
          // No establecer selectedCategory para evitar cargar productos
        } else {
          setSelectedCategory(result.category.id);
          setCategoryName(result.category.name);
          setCategoryMinMembership(accessResult.requiredLevel);
          resolvedForSlugRef.current = slug;
        }
      } else if (result.error) {
        setCategoryNotFound(true);
      }
    } catch (err: any) {
      // Ignorar errores de peticiones canceladas
      if (err?.name === 'AbortError' || signal.aborted) return;
      setCategoryNotFound(true);
    } finally {
      if (!signal.aborted) {
        setLoadingCategory(false);
        // Marcar que terminamos de cambiar de categoría (éxito o error)
        // Esto previene que isCategoryChanging quede en true si hay errores
        setIsCategoryChanging(false);
      }
    }
  }, [membershipSlug]); // Solo membershipSlug como dependencia - el resto usa refs
  
  // Ref para fetchCategoryBySlug - evita que el efecto se dispare cuando el callback cambia
  const fetchCategoryBySlugRef = useRef(fetchCategoryBySlug);
  fetchCategoryBySlugRef.current = fetchCategoryBySlug;

  // Efecto para establecer la categoría seleccionada basada en el categorySlug
  // FASE 3.2: Implementa debounce de 150ms para evitar peticiones excesivas
  useEffect(() => {
    // Marcar que estamos cambiando de categoría
    setIsCategoryChanging(true);
    // Resetear estados de acceso y categoría anterior
    setCategoryAccessDenied(false);
    setAccessDeniedReason(null);
    setCategoryNotFound(false);
    setMembershipSlugMismatch(false);
    setCategoryMinMembership(undefined);
    
    // Cancelar debounce anterior si existe
    if (categoryDebounceRef.current) {
      clearTimeout(categoryDebounceRef.current);
      categoryDebounceRef.current = null;
    }
    
    // Cancelar petición anterior si existe
    if (categoryAbortRef.current) {
      categoryAbortRef.current.abort();
      categoryAbortRef.current = null;
    }
    
    if (!categorySlug) {
      setSelectedCategory(undefined);
      setCategoryName(i18n.t('shopPage:defaults.allProducts'));
      setCategoryMinMembership(undefined);
      setLoadingCategory(false);
      setIsCategoryChanging(false);
      return;
    }

    setLoadingCategory(true);
    
    // FASE 3.2: Debounce de 150ms antes de buscar la categoría
    categoryDebounceRef.current = setTimeout(() => {
      // Crear nuevo AbortController para esta petición
      categoryAbortRef.current = new AbortController();
      // Usar ref para llamar a la versión más reciente sin crear dependencia
      fetchCategoryBySlugRef.current(categorySlug, categoryAbortRef.current.signal);
    }, 150);
    
    // Cleanup: cancelar debounce y petición al desmontar o cambiar slug
    return () => {
      if (categoryDebounceRef.current) {
        clearTimeout(categoryDebounceRef.current);
      }
      if (categoryAbortRef.current) {
        categoryAbortRef.current.abort();
      }
    };
  }, [categorySlug]); // Solo categorySlug - fetchCategoryBySlug usa ref
  
  // Efecto para filtrar productos usando el servicio
  // Usa debouncedSearchTerm para evitar filtrados excesivos mientras el usuario escribe
  useEffect(() => {
    if (!products) return;
    // Si hay categorySlug, solo filtrar cuando loadingCategory sea false y selectedCategory esté definido
    if (categorySlug) {
      if (loadingCategory || selectedCategory === undefined) {
        return;
      }
    }
 
    // Usar el servicio para filtrar y ordenar productos
    const filtered = shopService.filterAndSortProducts(products, debouncedSearchTerm, sortBy);
    
    setFilteredProducts(filtered);
    // NOTA: isCategoryChanging se resetea en fetchCategoryBySlug (finally).
    // Para el caso sin categorySlug (catálogo general), lo reseteamos aquí.
    if (!categorySlug) {
      setIsCategoryChanging(false);
    }
  }, [products, debouncedSearchTerm, sortBy, loadingCategory, categorySlug, selectedCategory]);

  return {
    // Estados
    searchTerm,
    debouncedSearchTerm,
    sortBy,
    categoryName,
    categoryMinMembership,
    selectedCategory,
    loadingCategory,
    categoryNotFound,
    categoryAccessDenied,
    accessDeniedReason,
    membershipSlugMismatch,
    resolvedForSlug: resolvedForSlugRef.current,
    productsLoading,
    loadingMore,
    filtersVisible,
    isCategoryChanging,
    productsError,
    
    // Datos
    products,
    categories: accessibleCategories, // Categorías filtradas por membresía (count viene correcto del backend)
    filteredProducts,
    
    // Paginación
    hasMoreProducts,
    totalProducts,
    loadMoreProducts,
    resetProducts,
    
    // Setters
    setSearchTerm,
    setSortBy,
    setFiltersVisible,
    
    // Utilidades
    searchParams,
    setSearchParams
  };
};

export default useShopPageState;
