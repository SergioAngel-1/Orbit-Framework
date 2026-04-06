import { useEffect } from "react";
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams, useNavigate } from "react-router-dom";
import { FiArrowLeft } from "react-icons/fi";
import { useLanguage } from '../contexts/LanguageContext';
import useMembershipLevels from '../hooks/useMembershipLevels';
import { buildCatalogUrl } from '../utils/membershipRouteUtils';
import { useSEO } from '../hooks/useSEO';
import { getBaseUrl } from '../utils/seo';
import { useSearchProducts } from "../hooks/useWooCommerce";
import { useCategoriesContext } from '../contexts/CategoriesContext';
import ProductCard from "../components/products/ProductCard";
import InfiniteScroll from "../components/shop/InfiniteScroll";
import useInfiniteScroll from "../hooks/useInfiniteScroll";
import Loader from "../components/ui/Loader";
import ResultCounter from "../components/common/ResultCounter";
import PopularCategories from "../components/shop/PopularCategories";

const SearchPage = () => {
  const { t } = useTranslation('searchPage');
  const { localizedPath } = useLanguage();
  const { levels } = useMembershipLevels();
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const query = searchParams.get("q") || "";

  // Fijar el número de productos por página a 12, igual que en ShopPage
  const PRODUCTS_PER_PAGE = 12;

  // Obtener todos los productos de búsqueda (sin paginación en el hook)
  const {
    data: allProducts,
    loading,
    error,
    totalProducts,
  } = useSearchProducts(query, 1, 999, true); // Cargar todos los productos disponibles

  // Obtener categorías para mostrar sugerencias
  const { categories } = useCategoriesContext();

  // Hook de scroll infinito
  const {
    visibleProducts,
    hasMore,
    loadMore,
    reset
  } = useInfiniteScroll(allProducts || [], PRODUCTS_PER_PAGE);

  // SEO: Búsqueda interna - noindex para evitar contenido duplicado infinito por query params
  useSEO({
    title: t('documentTitle', { query }),
    description: t('seo.description', { query, defaultValue: `Resultados de búsqueda para "${query}".` }),
    url: `${getBaseUrl()}/catalogo/buscar`,
    noIndex: true,
    noCanonical: true,
  });

  // Resetear scroll infinito cuando cambia la búsqueda
  useEffect(() => {
    reset();
  }, [query, reset]);

  // Manejar cambio de categoría
  const handleCategoryChange = (categoryId: number) => {
    const category = categories?.find(cat => cat.id === categoryId);
    if (category) {
      navigate(localizedPath(buildCatalogUrl(category.slug, category.min_membership_level ?? 0, levels)));
    }
  };

  return (
    <div className="container mx-auto px-4 py-8">
      <div className="flex flex-col-reverse sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <h1 className="text-2xl font-bold">
          {t('title')}{" "}
          <span className="text-primario">{query}</span>
        </h1>
        <Link
          to={localizedPath("/catalogo")}
          className="inline-flex items-center text-primario hover:text-primario-dark transition-colors duration-200 font-medium"
        >
          <FiArrowLeft className="mr-1" /> {t('backToCatalog')}
        </Link>
      </div>

      {loading && (
        <div className="flex justify-center items-center py-12">
          <Loader />
        </div>
      )}

      {error && (
        <div className="bg-red-50 text-red-600 p-4 rounded-md mb-6">
          {t('error')}
        </div>
      )}

      {!loading && allProducts && allProducts.length === 0 && (
        <div className="bg-gray-50 p-8 rounded-md text-center">
          <h2 className="text-xl font-medium mb-2">
            {t('noResults')}
          </h2>
          <p className="text-gray-600">
            {t('noResultsDesc', { query })}
          </p>
        </div>
      )}

      {!loading && allProducts && allProducts.length > 0 && (
        <>
          {/* Contador de resultados */}
          <ResultCounter
            total={totalProducts}
            showing={visibleProducts.length}
            itemName={t('itemName')}
          />

          <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 md:gap-6 product-grid">
            {visibleProducts.map((product) => (
              <ProductCard
                key={product.id}
                product={product}
                className="h-full transform transition-transform hover:scale-[1.02] hover:shadow-md"
                animationClass="search-product-animate"
              />
            ))}
          </div>

          {/* Componente de scroll infinito */}
          {visibleProducts.length > 0 && (
            <InfiniteScroll
              hasMore={hasMore}
              loading={false}
              onLoadMore={loadMore}
              threshold={300}
            />
          )}

          {/* Mostrar categorías populares cuando no hay más productos */}
          {!loading && !hasMore && visibleProducts.length > 0 && (
            <PopularCategories 
              categories={categories || undefined}
              onCategoryChange={handleCategoryChange}
              showCondition={true}
            />
          )}
        </>
      )}

      {/* Mostrar categorías populares cuando no hay productos */}
      {!loading && allProducts && allProducts.length === 0 && (
        <PopularCategories 
          categories={categories || undefined}
          onCategoryChange={handleCategoryChange}
          showCondition={true}
        />
      )}
    </div>
  );
};

export default SearchPage;
