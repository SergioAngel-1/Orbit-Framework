import React, { useEffect, useState } from 'react';
import { useParams, Navigate, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useLanguage } from '../contexts/LanguageContext';
import { useMembership } from '../contexts/MembershipContext';
import useMembershipLevels from '../hooks/useMembershipLevels';
import { buildProductUrl, getMembershipSlugForLevel, resolveMembershipSlug } from '../utils/membershipRouteUtils';
import BackButton from '../components/common/BackButton';
import useProductDetail from '../hooks/useProductDetail';
import ProductGallery from '../components/products/ProductGallery';
import ProductDetailContent from '../components/products/ProductDetailContent';
import PromotionalGrid from '../components/products/PromotionalGrid';
import ProductRecommendationsSection from '../components/products/ProductRecommendationsSection';
import ProductDetailLoader from '../components/products/ProductDetailLoader';
import ProductDescriptionSection from '../components/products/ProductDescriptionSection';
import { ReviewsProvider } from '../contexts/ReviewsContext';
import ProductReviewsSection from '../components/reviews/ProductReviewsSection';
import Breadcrumbs from '../components/ui/Breadcrumbs';
import { logger } from '../utils/logger';
import { useSEO } from '../hooks/useSEO';
import { buildProductSchema, getBaseUrl } from '../utils/seo';
import i18n from '../config/i18n';
import { gsap } from 'gsap';
import { fluidSizing } from '../utils/fluidSizing';

interface ProductDetailPageProps {
  /** Slug de categoría cuando viene del resolver CatalogTwoSegmentPage */
  _categorySlug?: string;
  /** Slug de producto cuando viene del resolver CatalogTwoSegmentPage */
  _productSlug?: string;
}

/**
 * Página de detalle de producto refactorizada
 * Utiliza componentes independientes y hooks personalizados para mantener la lógica separada
 */
const ProductDetailPage: React.FC<ProductDetailPageProps> = ({ _categorySlug, _productSlug }) => {
  const { t } = useTranslation('productDetailPage');
  const {
    categorySlug: cSlugParam,
    productSlug: pSlugParam,
    membershipSlug: mSlugParam,
  } = useParams<{ categorySlug?: string; productSlug?: string; membershipSlug?: string }>();
  const effectiveCategorySlug = _categorySlug ?? cSlugParam;
  const effectiveProductSlug = _productSlug ?? pSlugParam;
  // mSlugParam solo existe cuando se accede vía ruta de 3 segmentos /catalogo/:m/:c/:p
  // _categorySlug se pasa desde CatalogTwoSegmentPage (ruta de 2 segmentos, sin membresía)
  const hasMembershipSlugInUrl = !!mSlugParam;
  const navigate = useNavigate();
  const { localizedPath, currentLang } = useLanguage();
  const { currentLevel: membershipLevel } = useMembership();
  const { levels, loading: levelsLoading } = useMembershipLevels();

  // Usamos el hook personalizado para toda la lógica del producto
  const {
    product,
    displayProduct,
    categories,
    loading,
    error,
    quantity,
    selectedVariation,
    variationData,
    preselectedVariationId,
    handleQuantityChange,
    handleVariationSelect
  } = useProductDetail({ productSlug: effectiveProductSlug, categorySlug: effectiveCategorySlug });

  // Estado para validación de acceso
  const [accessValidation, setAccessValidation] = useState<{
    hasAccess: boolean;
    accessDeniedReason: string | null;
    isChecking: boolean;
    requiredMembershipLevel: number;
  }>({ hasAccess: true, accessDeniedReason: null, isChecking: true, requiredMembershipLevel: 0 });

  // SEO: Meta tags dinámicos para el producto (title, description, OG, schema Product)
  // noIndex cuando el producto requiere cualquier nivel de membresía (coherente con ShopPage)
  const requiresMembership = accessValidation.requiredMembershipLevel > 0;
  const productName = product?.name || '';
  const rawDesc = product?.short_description || product?.description || '';
  const productDesc = rawDesc.replace(/<[^>]*>/g, '').replace(/&[^;]+;/g, ' ').replace(/\s+/g, ' ').trim();
  const productImage = product?.images?.[0]?.src;
  const productPrice = product?.price ? parseFloat(product.price) : undefined;
  const productCategorySlug = effectiveCategorySlug || product?.categories?.[0]?.slug;
  const baseUrl = getBaseUrl();
  const requiredLevelForUrl = accessValidation.requiredMembershipLevel;
  const productUrlPath = buildProductUrl(productCategorySlug, effectiveProductSlug ?? '', requiredLevelForUrl, levels);
  const productUrl = `${baseUrl}${productUrlPath}`;

  const productSchema = buildProductSchema({
    name: productName,
    description: productDesc,
    url: productUrl,
    image: productImage,
    sku: product?.sku,
    price: productPrice,
    stockStatus: product?.stock_status,
    averageRating: product?.average_rating,
    ratingCount: product?.rating_count,
  });

  useSEO({
    title: productName ? `${productName} ${i18n.t('seo:product.titleSuffix')}` : '',
    description: productDesc || i18n.t('seo:product.defaultDescription', { name: productName }),
    image: productImage,
    url: productUrl,
    type: 'product',
    noIndex: requiresMembership,
    schema: productSchema,
  });

  // Detectar si es un producto de la categoría "Paquetes de Virtual Coins"
  // Si es así, redirigir automáticamente al modal de compra en WalletPage
  useEffect(() => {
    if (loading || !product) return;
    
    const isVirtualCoinsPackage = product.categories?.some(
      (cat: { slug: string }) => cat.slug === 'paquetes-virtual-coins'
    );
    
    if (isVirtualCoinsPackage) {
      logger.info('ProductDetailPage', 'Producto es paquete de Virtual Coins, redirigiendo al modal de compra');
      navigate(localizedPath('/fondo-de-aportes'), { state: { openBuyModal: true }, replace: true });
    }
  }, [product, loading, navigate]);
  
  // Validar acceso al producto (defensa en profundidad)
  // El backend ahora retorna 404 para productos sin acceso, así que el producto normalmente
  // ni siquiera llega aquí si el usuario no tiene permiso. Esta validación es una capa extra
  // de seguridad por si el producto llega con membership_required anotado (ej. caché viejo).
  // Si el usuario no tiene acceso, redirige a /404 (no muestra datos del producto).
  useEffect(() => {
    if (loading || !product) return;

    // Guard: si el producto cargado no corresponde al slug de la URL actual,
    // los datos son stale (navegación entre productos sin unmount). Saltar validación
    // hasta que useProductDetail cargue el producto correcto.
    if (product.slug !== effectiveProductSlug) return;
    
    // IMPORTANTE: La URL se construye usando el nivel de la PRIMERA CATEGORÍA del producto,
    // no el membership_required (que es el máximo de todas las categorías).
    // Por eso, para validar la URL debemos usar el nivel de la categoría en la URL.
    const categoryInUrl = product.categories?.find((c: any) => c.slug === effectiveCategorySlug);
    const categoryRequiredLevel = (categoryInUrl as any)?.min_membership_level ?? 0;
    
    // Para validar acceso del usuario, usamos el nivel máximo del producto
    const productRequiredLevel = product.membership_required ?? 0;
    const hasAccess = membershipLevel >= productRequiredLevel;
    
    // Si la categoría en la URL requiere membresía pero la URL no tiene el prefijo → 404
    // Esperar a que levels carguen para evitar falsos positivos durante cambio de idioma
    if (categoryRequiredLevel > 0 && !hasMembershipSlugInUrl && !levelsLoading) {
      logger.warn('ProductDetailPage', `Categoría "${effectiveCategorySlug}" requiere membresía (nivel ${categoryRequiredLevel}) pero URL no tiene prefijo. Redirigiendo a 404.`);
      navigate(localizedPath('/404'), { replace: true });
      return;
    }

    // Si el slug de membresía en la URL no es el correcto para la categoría → auto-corregir o 404.
    // La validación usa resolveMembershipSlug que reconoce slugs en CUALQUIER idioma (ES y EN).
    // IMPORTANTE: Solo validar cuando tenemos datos de levels disponibles para evitar 404 falsos
    // durante cambio de idioma o carga inicial. resolveMembershipSlug usa fallback al caché global.
    if (hasMembershipSlugInUrl) {
      const resolved = resolveMembershipSlug(mSlugParam!, levels);
      
      // Si no se pudo resolver el slug Y no estamos cargando Y hay levels disponibles → 404
      // La condición levels.length > 0 asegura que tenemos datos para validar
      if (!resolved && !levelsLoading && levels.length > 0) {
        logger.warn('ProductDetailPage', `Slug de membresía desconocido en URL ("${mSlugParam}"). 404.`);
        navigate(localizedPath('/404'), { replace: true });
        return;
      }
      
      // Si se resolvió pero el nivel no coincide con el requerido por la categoría → 404
      if (resolved && resolved.level !== categoryRequiredLevel) {
        logger.warn('ProductDetailPage', `Slug "${mSlugParam}" corresponde a nivel ${resolved.level}, categoría requiere ${categoryRequiredLevel}. 404.`);
        navigate(localizedPath('/404'), { replace: true });
        return;
      }
      
      // El nivel es correcto; verificar si el slug está en el idioma actual para auto-corregir
      // Solo auto-corregir cuando tenemos levels cargados para evitar redirecciones incorrectas
      if (resolved && levels.length > 0) {
        const expectedSlug = getMembershipSlugForLevel(categoryRequiredLevel, currentLang, levels);
        if (mSlugParam !== expectedSlug && expectedSlug && productCategorySlug && effectiveProductSlug) {
          const catalogBase = localizedPath('/catalogo');
          navigate(`${catalogBase}/${expectedSlug}/${productCategorySlug}/${effectiveProductSlug}`, { replace: true });
          return;
        }
      }
    }

    // Validar acceso del usuario al producto (usando nivel máximo del producto)
    if (!hasAccess && productRequiredLevel > 0) {
      logger.warn('ProductDetailPage', `Producto "${product.name}" requiere nivel ${productRequiredLevel}, usuario tiene ${membershipLevel}. Redirigiendo a 404.`);
      navigate(localizedPath('/404'), { replace: true });
    }

    setAccessValidation({
      hasAccess,
      accessDeniedReason: null,
      isChecking: false,
      requiredMembershipLevel: productRequiredLevel
    });
  }, [product, loading, membershipLevel, hasMembershipSlugInUrl, mSlugParam, levels, levelsLoading, currentLang, navigate, localizedPath, productCategorySlug, effectiveProductSlug, effectiveCategorySlug]);

  // Animación de entrada para la página
  useEffect(() => {
    if (product && !loading && !accessValidation.isChecking) {
      // Pequeño delay para asegurar que el DOM esté listo
      const timer = setTimeout(() => {
        const elements = document.querySelectorAll('.product-detail-enter');
        if (elements.length > 0) {
          gsap.fromTo(
            elements,
            { opacity: 0, y: 20 },
            { opacity: 1, y: 0, duration: 0.5, stagger: 0.1 }
          );
        }
      }, 50);
      return () => clearTimeout(timer);
    }
  }, [product, loading, accessValidation.isChecking]);

  // Manejador para el botón de regreso
  const handleGoBack = () => {
    // Si hay historial, volver atrás
    if (window.history.length > 2) {
      navigate(-1);
    } else {
      // Si no, ir al catálogo
      navigate(localizedPath('/catalogo'));
    }
  };

  if (error) {
    return <Navigate to={localizedPath('/404')} replace />;
  }

  if (!effectiveProductSlug || effectiveProductSlug === 'undefined' || effectiveProductSlug === 'null') {
    return <Navigate to={localizedPath('/catalogo')} replace />;
  }

  if (loading || !product || accessValidation.isChecking) {
    return <ProductDetailLoader />;
  }

  // Defensa en profundidad: si de alguna forma el producto llegó sin acceso,
  // redirigir a 404 (el useEffect ya debería haber navegado, esto es fallback)
  if (!accessValidation.hasAccess) {
    return <Navigate to={localizedPath('/404')} replace />;
  }

  /**
   * Encuentra la categoría principal del producto para la grilla promocional
   * Prioriza categorías que podrían tener configuración de grilla
   * @param categories Array de categorías del producto
   * @returns ID de la categoría principal o undefined
   */
  const findMainCategory = (categories?: Array<{id: number, name: string, slug: string}>): number | undefined => {
    if (!categories || categories.length === 0) {
      logger.info('ProductDetailPage', 'No hay categorías para este beneficio');
      return undefined;
    }
    
    logger.info('ProductDetailPage', `Seleccionando categoría principal entre ${categories.length} categorías`, 
      categories.map(cat => `${cat.name} (${cat.id})`));
    
    // Si solo hay una categoría, usarla
    if (categories.length === 1) {
      logger.info('ProductDetailPage', `Solo hay una variedad: ${categories[0].name} (${categories[0].id})`);
      return categories[0].id;
    }
    
    // Estrategia 1: Buscar categorías principales (nivel superior)
    // Nota: Esto es una aproximación, ya que no tenemos la jerarquía completa en el frontend
    // Categorías principales suelen tener slugs simples (sin guiones)
    const possibleMainCategories = categories.filter(cat => !cat.slug.includes('-'));
    if (possibleMainCategories.length === 1) {
      logger.info('ProductDetailPage', `Usando categoría principal: ${possibleMainCategories[0].name} (${possibleMainCategories[0].id})`);
      return possibleMainCategories[0].id;
    }
    
    // Si hay múltiples candidatas o ninguna, usar la primera categoría
    logger.info('ProductDetailPage', `Usando primera categoría por defecto: ${categories[0].name} (${categories[0].id})`);
    return categories[0].id;
  };

  return (
    <div className="container mx-auto py-2 md:py-4 px-4">
      {/* Botón de regreso + Breadcrumbs en mobile */}
      <div className="md:mb-2 product-detail-enter flex items-center justify-between gap-2">
        <BackButton onClick={handleGoBack} />
        <div className="md:hidden overflow-x-auto [&_nav]:mb-0">
          <Breadcrumbs
            categories={product.categories || []}
            currentCategory={product.categories && product.categories.length > 0 ? product.categories[0].name : undefined}
            currentCategoryMinMembership={product.categories && product.categories.length > 0 ? product.categories[0].min_membership_level ?? 0 : 0}
            hideCurrentProduct
          />
        </div>
      </div>

      {/* Título del producto solo visible en móvil */}
      <div 
        className="block md:hidden product-detail-enter rounded-lg shadow-sm"
        style={{ marginBottom: fluidSizing.space.md, padding: fluidSizing.space.md }}
      >
        <h1 
          className="font-bold text-oscuro text-center"
          style={{ fontSize: fluidSizing.text['2xl'] }}
        >
          {product.name}
        </h1>
        {product.categories && product.categories.length > 0 && (
          <div 
            className="text-gray-600 flex items-center justify-center"
            style={{ fontSize: fluidSizing.text.xs, marginTop: fluidSizing.space.xs }}
          >
            <span 
              className="bg-primario bg-opacity-10 text-primario rounded-md"
              style={{ padding: `${fluidSizing.space.xs} ${fluidSizing.space.sm}` }}
            >
              {product.categories[0].name}
            </span>
          </div>
        )}
      </div>

      {/* Contenido principal - Layout rediseñado para mayor armonia */}
      <div className="grid grid-cols-1 md:grid-cols-12 gap-6 md:gap-8 relative" style={{ zIndex: 20 }}>
        {/* Galería de imágenes - Ocupa 5 columnas en dispositivos medianos o mayores */}
        <div className="md:col-span-5 product-detail-enter">
          <ProductGallery 
            images={product.images} 
            productName={product.name} 
          />
        </div>

        {/* Información del producto y acciones de compra - Ocupa 7 columnas */}
        <div className="md:col-span-7 product-detail-enter" style={{ overflow: 'visible', zIndex: 10 }}>
          <div className="rounded-lg shadow-md p-0 sm:p-0 border border-gray-100 md:p-5" style={{ overflow: 'visible' }}>
            <ProductDetailContent 
              product={product}
              displayProduct={displayProduct}
              categories={categories}
              quantity={quantity}
              selectedVariation={selectedVariation}
              variationData={variationData}
              preselectedVariationId={preselectedVariationId}
              loading={loading}
              minMembershipLevel={accessValidation.requiredMembershipLevel}
              onQuantityChange={handleQuantityChange}
              onVariationSelect={handleVariationSelect}
            />
          </div>
        </div>
      </div>
      
      {/* Nueva sección de descripción y promocional con proporción 65-35 */}
      <div className="grid grid-cols-1 md:grid-cols-12 gap-6 mt-6 relative" style={{ zIndex: 1 }}>
        {/* Sección de descripción - Ocupa 65% del espacio (8/12 columnas) */}
        <div className="md:col-span-8 product-detail-enter">
          <ProductDescriptionSection 
            description={product.description}
            shortDescription={product.short_description}
          />
        </div>
        
        {/* Sección de productos promocionales - Ocupa 35% del espacio (4/12 columnas) */}
        <div className="md:col-span-4 product-detail-enter">
          <PromotionalGrid 
            categoryId={findMainCategory(product.categories)} 
            displayAsRow={true}
            customTitle={t('page.recommendedBenefits')}
          />
        </div>
      </div>

      {/* Sección de reseñas */}
      <div className="mt-6 product-detail-enter">
        <ReviewsProvider productId={product.id}>
          <ProductReviewsSection />
        </ReviewsProvider>
      </div>

      {/* Sección de recomendaciones */}
      <div className="mt-8 product-detail-enter">
        <ProductRecommendationsSection 
          productId={product.id}
          categories={categories} 
        />
      </div>
    </div>
  );
};

export default ProductDetailPage;