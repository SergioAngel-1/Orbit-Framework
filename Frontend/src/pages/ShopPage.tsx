import React, { useEffect, useCallback, useMemo } from 'react';
import { useParams, useNavigate, Navigate, useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useLanguage } from '../contexts/LanguageContext';
import { generateSlug } from '../utils/formatters';
import { useSEO } from '../hooks/useSEO';
import { OG_IMAGES, getBaseUrl } from '../utils/seo';
import i18n from '../config/i18n';
import useMembershipLevels from '../hooks/useMembershipLevels';
import { buildCatalogUrl, getMembershipInfoForSlug, getMembershipSlugForLevel } from '../utils/membershipRouteUtils';
import { logger } from '../utils/logger';

// Hooks
import useShopPageState from '../hooks/useShopPageState';

// Componentes
import ShopFilters from '../components/shop/ShopFilters';
import ProductGridSimple from '../components/shop/ProductGridSimple';
import PopularCategories from '../components/shop/PopularCategories';
import CategoryHeader from '../components/shop/CategoryHeader';
import CategoryNotFoundMessage from '../components/shop/CategoryNotFoundMessage';
import ShopPageFiltersToggle from '../components/shop/ShopPageFiltersToggle';
import InfiniteScroll from '../components/shop/InfiniteScroll';
import AccessDeniedMessage from '../components/membership/AccessDeniedMessage';

interface ShopPageProps {
  /** Slug de membresía cuando viene del resolver CatalogTwoSegmentPage */
  _membershipSlug?: string;
  /** Slug de categoría cuando viene del resolver CatalogTwoSegmentPage */
  _categorySlug?: string;
}

/**
 * Página principal de la tienda que muestra productos filtrados por categoría
 * y permite búsqueda y ordenamiento con scroll infinito
 */
const ShopPage = ({ _membershipSlug, _categorySlug }: ShopPageProps) => {
  const { t } = useTranslation('shopPage');
  const { localizedPath } = useLanguage();
  const params = useParams<{ categorySlug?: string }>();
  const rawCategorySlug = _categorySlug ?? params.categorySlug;
  const navigate = useNavigate();
  const [urlSearchParams] = useSearchParams();
  const { levels, loading: levelsLoading } = useMembershipLevels();

  // Detectar si el slug de "categoría" es en realidad un slug de membresía
  // Ej: /catalogo/membresia-bronce → modo "explorar membresía"
  // IMPORTANTE: Solo resolver cuando levels estén cargados para evitar que
  // isMembershipBrowse oscile entre false/true y cause loops de renders.
  const membershipBrowseInfo = useMemo(
    () => (!levelsLoading && rawCategorySlug) ? getMembershipInfoForSlug(rawCategorySlug, levels) : undefined,
    [rawCategorySlug, levels, levelsLoading]
  );
  const isMembershipBrowse = !!membershipBrowseInfo;

  // Si es modo membresía-browse, no pasar slug como categoría (cargar todos los productos)
  // Mientras levels cargan, rawCategorySlug pasa tal cual (será tratado como categoría normal
  // y no disparará carga porque shouldFetchProducts espera a selectedCategory)
  const effectiveCategorySlug = isMembershipBrowse ? undefined : rawCategorySlug;
  const effectiveMembershipSlug = _membershipSlug ?? (isMembershipBrowse ? rawCategorySlug : undefined);

  // Detectar si hay parámetros de búsqueda para evitar canonical duplicado
  const hasSearchParams = urlSearchParams.has('q') || urlSearchParams.has('sort');
  
  // Usamos nuestro hook personalizado para manejar el estado
  const {
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
    resolvedForSlug,
    productsLoading,
    loadingMore,
    filtersVisible,
    isCategoryChanging,
    productsError,
    categories,
    filteredProducts,
    hasMoreProducts,
    totalProducts,
    loadMoreProducts,
    resetProducts,
    setSearchTerm,
    setSortBy,
    setFiltersVisible,
    searchParams
  } = useShopPageState(effectiveCategorySlug, effectiveMembershipSlug);

  // Detectar si los datos de categoría son frescos (corresponden al slug actual).
  // Cuando el usuario navega entre categorías con diferentes niveles de membresía,
  // los props (effectiveCategorySlug) se actualizan inmediatamente pero el estado
  // interno del hook (categoryMinMembership, etc.) es stale hasta que el efecto
  // se ejecute (los efectos corren DESPUÉS del render). Sin esta guarda, las
  // validaciones compararían el nuevo membershipSlug contra datos de la categoría anterior.
  const categoryDataFresh = resolvedForSlug === effectiveCategorySlug;

  // SEO: Meta tags dinámicos — se adaptan a catálogo general vs categoría específica
  // Categorías que requieren membresía (nivel > 0) se marcan como noIndex
  const BASE_URL = getBaseUrl();
  const isCategory = !isMembershipBrowse && !!effectiveCategorySlug && !!categoryName && !loadingCategory && !categoryNotFound;
  const categoryRequiresMembership = isMembershipBrowse || (categoryMinMembership ?? 0) > 0;

  // Título para modo membresía-browse: nombre del producto de membresía
  const membershipBrowseTitle = membershipBrowseInfo
    ? membershipBrowseInfo.name
    : '';

  const seoConfig = useMemo(() => {
    if (isCategory) {
      // La URL canónica usa el nivel mínimo real de la categoría, no el slug que llegó en la URL.
      // Esto evita contenido duplicado cuando se accede con un slug de membresía de nivel mayor al requerido.
      // Siempre en ES (idioma default) para que la canonical sea consistente entre idiomas.
      const canonicalMSlug = (categoryMinMembership ?? 0) > 0
        ? getMembershipSlugForLevel(categoryMinMembership ?? 0, 'es', levels)
        : undefined;
      const categoryPath = canonicalMSlug
        ? `/catalogo/${canonicalMSlug}/${effectiveCategorySlug}`
        : `/catalogo/${effectiveCategorySlug}`;
      const categoryUrl = `${BASE_URL}${categoryPath}`;
      return {
        title: `${categoryName} ${i18n.t('seo:category.titleSuffix')}`,
        description: i18n.t('seo:category.defaultDescription', { name: categoryName }),
        keywords: t('seo.keywords'),
        url: categoryUrl,
        canonicalUrl: categoryUrl,
        type: 'website' as const,
        image: OG_IMAGES.catalogo,
        noIndex: categoryRequiresMembership,
        noCanonical: hasSearchParams,
        schema: {
          '@context': 'https://schema.org',
          '@type': 'CollectionPage',
          'name': categoryName,
          'description': i18n.t('seo:category.defaultDescription', { name: categoryName }),
          'url': categoryUrl,
          'isPartOf': {
            '@type': 'WebSite',
            'name': 'My Store',
            'url': BASE_URL
          },
          'breadcrumb': {
            '@type': 'BreadcrumbList',
            'itemListElement': [
              {
                '@type': 'ListItem',
                'position': 1,
                'name': t('seo.breadcrumbHome'),
                'item': BASE_URL
              },
              {
                '@type': 'ListItem',
                'position': 2,
                'name': t('seo.breadcrumbCatalog'),
                'item': `${BASE_URL}/catalogo`
              },
              {
                '@type': 'ListItem',
                'position': 3,
                'name': categoryName,
                'item': categoryUrl
              }
            ]
          }
        }
      };
    }

    // SEO para modo membresía-browse (ej: /catalogo/membresia-bronce)
    if (isMembershipBrowse) {
      const membershipUrl = `${BASE_URL}/catalogo/${rawCategorySlug}`;
      return {
        title: membershipBrowseTitle,
        description: t('seo.description'),
        keywords: t('seo.keywords'),
        url: membershipUrl,
        type: 'website' as const,
        image: OG_IMAGES.catalogo,
        noIndex: true,
      };
    }

    // SEO para catálogo general (sin categoría)
    return {
      title: t('seo.title'),
      description: t('seo.description'),
      keywords: t('seo.keywords'),
      url: `${BASE_URL}/catalogo`,
      canonicalUrl: `${BASE_URL}/catalogo`,
      type: 'website' as const,
      image: OG_IMAGES.catalogo,
      noCanonical: hasSearchParams,
      schema: {
        '@context': 'https://schema.org',
        '@type': 'CollectionPage',
        'name': t('seo.schemaName'),
        'description': t('seo.schemaDescription'),
        'url': `${BASE_URL}/catalogo`,
        'isPartOf': {
          '@type': 'WebSite',
          'name': 'My Store',
          'url': BASE_URL
        },
        'breadcrumb': {
          '@type': 'BreadcrumbList',
          'itemListElement': [
            {
              '@type': 'ListItem',
              'position': 1,
              'name': t('seo.breadcrumbHome'),
              'item': BASE_URL
            },
            {
              '@type': 'ListItem',
              'position': 2,
              'name': t('seo.breadcrumbCatalog'),
              'item': `${BASE_URL}/catalogo`
            }
          ]
        }
      }
    };
  }, [isCategory, isMembershipBrowse, effectiveCategorySlug, effectiveMembershipSlug, rawCategorySlug, categoryName, membershipBrowseTitle, categoryRequiresMembership, hasSearchParams, categoryMinMembership, levels, t]);

  useSEO(seoConfig);

  // En modo membresía-browse, filtrar solo productos que requieren ese nivel de membresía.
  // TODO: OPTIMIZACIÓN FUTURA - Actualmente se cargan todos los productos y se filtran en frontend.
  // Esto es ineficiente para catálogos grandes. Para optimizar:
  // 1. Agregar parámetro `membership_level` al endpoint de productos en el backend
  // 2. Filtrar en el proxy WC antes de enviar la respuesta
  // 3. Ajustar hasMoreProducts/totalProducts para reflejar el total filtrado
  // Por ahora, el impacto es menor porque el backend ya filtra por membresía del usuario.
  const membershipBrowseLevel = membershipBrowseInfo ? (membershipBrowseInfo.level ?? membershipBrowseInfo.id) : 0;
  const displayProducts = useMemo(() => {
    if (!filteredProducts) return [];
    if (!isMembershipBrowse) return filteredProducts;
    return filteredProducts.filter(p => (p.membership_required ?? 0) === membershipBrowseLevel);
  }, [filteredProducts, isMembershipBrowse, membershipBrowseLevel]);

  // En modo membresía-browse, el total real es desconocido (filtrado en frontend).
  // Usamos el conteo de productos filtrados como aproximación para evitar confusión.
  // hasMore también se ajusta: si no hay productos filtrados en la página actual, asumimos que no hay más.
  const effectiveTotalProducts = isMembershipBrowse ? displayProducts.length : totalProducts;
  const effectiveHasMore = isMembershipBrowse 
    ? (hasMoreProducts && displayProducts.length > 0) 
    : hasMoreProducts;

  // Resetear paginación cuando cambian los filtros de búsqueda/ordenamiento.
  // NOTA: Usamos debouncedSearchTerm para evitar resets excesivos mientras el usuario escribe.
  // No incluimos selectedCategory porque el cambio de categoría ya dispara
  // el useEffect de useProductsPaginated automáticamente. Incluirlo aquí causaría
  // doble carga (reset + useEffect del hook).
  useEffect(() => {
    resetProducts();
  }, [debouncedSearchTerm, sortBy, isMembershipBrowse, resetProducts]);

  /**
   * Maneja el cambio de categoría seleccionada
   * Actualiza el estado, la URL y resetea la paginación
   */
  const handleCategoryChange = useCallback((categoryId: number | undefined) => {
    // Actualizar el nombre de la categoría para mostrar en la interfaz
    if (categoryId === undefined) {
      // Preservar los parámetros de búsqueda y ordenamiento actuales
      const newParams = new URLSearchParams();
      const currentSearchTerm = searchParams.get('q');
      const currentSort = searchParams.get('sort');
      
      if (currentSearchTerm) {
        newParams.set('q', currentSearchTerm);
      }
      
      if (currentSort) {
        newParams.set('sort', currentSort);
      }
      
      // Navegar a la página principal del catálogo con los parámetros preservados
      const queryString = newParams.toString();
      navigate(localizedPath(`/catalogo${queryString ? `?${queryString}` : ''}`), { replace: true });
    } else {
      const selectedCat = categories?.find(cat => cat.id === categoryId);
      if (selectedCat) {
        // Generar el slug para la URL
        const slug = selectedCat.slug || generateSlug(selectedCat.name);

        // Preservar los parámetros de búsqueda y ordenamiento actuales
        const newParams = new URLSearchParams();
        const currentSearchTerm = searchParams.get('q');
        const currentSort = searchParams.get('sort');

        if (currentSearchTerm) {
          newParams.set('q', currentSearchTerm);
        }

        if (currentSort) {
          newParams.set('sort', currentSort);
        }

        // Construir URL con prefijo de membresía si la categoría lo requiere
        const minLevel = selectedCat.min_membership_level ?? 0;
        const rawUrl = buildCatalogUrl(slug, minLevel, levels);
        const queryString = newParams.toString();
        navigate(localizedPath(`${rawUrl}${queryString ? `?${queryString}` : ''}`), { replace: true });
      }
    }
  }, [categories, navigate, searchParams, localizedPath, levels]);

  /**
   * Maneja el cambio en el campo de búsqueda
   */
  const handleSearchChange = useCallback((e: React.ChangeEvent<HTMLInputElement>) => {
    setSearchTerm(e.target.value);
  }, [setSearchTerm]);

  /**
   * Maneja el cambio en el criterio de ordenamiento
   */
  const handleSortChange = useCallback((e: React.ChangeEvent<HTMLSelectElement>) => {
    setSortBy(e.target.value);
  }, [setSortBy]);

  // Función para alternar la visibilidad de los filtros
  // IMPORTANTE: Debe estar antes de cualquier early return para evitar error de hooks
  const toggleFilters = useCallback(() => {
    setFiltersVisible(prev => !prev);
  }, [setFiltersVisible]);

  // Si la categoría no existe, redirigir a la página 404
  // (no aplica en modo membresía-browse, donde effectiveCategorySlug es undefined)
  // IMPORTANTE: Esperar a que los niveles carguen antes de decidir, porque un slug de
  // membresía podría confundirse con una categoría inexistente si los niveles aún no llegan.
  if (categoryNotFound && !loadingCategory && !isMembershipBrowse && !levelsLoading) {
    return <Navigate to={localizedPath('/404')} replace />;
  }

  // Si la categoría requiere membresía pero la URL no tiene el prefijo de membresía → 404
  // La URL correcta es /catalogo/{membershipSlug}/{categorySlug}, no /catalogo/{categorySlug}
  // (no aplica en modo membresía-browse, donde la URL ya ES el slug de membresía)
  // categoryDataFresh evita falsos positivos durante transiciones entre categorías.
  if (categoryDataFresh && !loadingCategory && !isMembershipBrowse && !levelsLoading && categoryRequiresMembership && !effectiveMembershipSlug) {
    return <Navigate to={localizedPath('/404')} replace />;
  }

  // Si el membership slug de la URL NO coincide con el nivel real de la categoría → 404.
  // Solo la URL con el slug exacto de la membresía requerida es válida.
  // Ej: si "hongos" requiere Plata, solo /membresia-plata/hongos existe; /membresia-oro/hongos → 404.
  // membershipSlugMismatch se calcula dentro de useShopPageState de forma determinista
  // junto con la carga de la categoría (sin gaps de timing).
  if (membershipSlugMismatch && !loadingCategory && !isMembershipBrowse && categoryDataFresh) {
    logger.warn('ShopPage', `Membership slug mismatch: URL="${effectiveMembershipSlug}", categoryMinMembership=${categoryMinMembership}. 404.`);
    return <Navigate to={localizedPath('/404')} replace />;
  }

  return (
    <div className="container mx-auto px-4 xl:px-16 pb-8 pt-4">
      {/* Encabezado de la categoría con botón de filtros */}
      <CategoryHeader
        title={isMembershipBrowse ? membershipBrowseTitle : categoryName}
        categorySlug={effectiveCategorySlug}
        isLoading={(loadingCategory && !isMembershipBrowse) || (levelsLoading && !!rawCategorySlug)}
        isCategoryChanging={isCategoryChanging && !isMembershipBrowse}
        minMembershipLevel={isMembershipBrowse ? (membershipBrowseInfo?.level ?? membershipBrowseInfo?.id) : categoryMinMembership}
      >
        {/* Botón de filtro para móvil */}
        <ShopPageFiltersToggle 
          onToggleFilters={toggleFilters}
          filtersVisible={filtersVisible}
        />
      </CategoryHeader>
      
      {/* Error si no se encontró la categoría */}
      {categoryNotFound && !isMembershipBrowse ? (
        <CategoryNotFoundMessage />
      ) : categoryAccessDenied && !isMembershipBrowse ? (
        <AccessDeniedMessage reason={accessDeniedReason} />
      ) : (
        <>
          {/* Componente de filtros - visible/oculto según el estado */}
          <div className={`${filtersVisible ? 'block' : 'hidden'} md:block`}>
            <ShopFilters
              searchTerm={searchTerm}
              sortBy={sortBy}
              selectedCategory={selectedCategory}
              categories={categories || undefined}
              onSearchChange={handleSearchChange}
              onSortChange={handleSortChange}
              onCategoryChange={handleCategoryChange}
            />
          </div>

          {/* Grid de productos con paginación real */}
          <ProductGridSimple 
            products={displayProducts}
            loading={productsLoading || loadingCategory || isCategoryChanging}
            searchTerm={searchTerm}
            selectedCategory={selectedCategory}
            totalItems={effectiveTotalProducts}
          />

          {/* Componente de scroll infinito - Carga más productos desde la API */}
          {!productsLoading && displayProducts.length > 0 && (
            <InfiniteScroll
              hasMore={effectiveHasMore}
              loading={loadingMore}
              onLoadMore={loadMoreProducts}
              threshold={300}
              error={productsError}
              onRetry={loadMoreProducts}
            />
          )}
        </>
      )}

      {/* Categorías populares: siempre montado para evitar salto visual al cambiar de categoría.
          Se oculta internamente vía showCondition mientras hay más productos por cargar. */}
      <PopularCategories 
        categories={categories || undefined}
        onCategoryChange={handleCategoryChange}
        showCondition={!productsLoading && !isCategoryChanging && !loadingCategory && !effectiveHasMore}
      />
    </div>
  );
};

export default ShopPage;