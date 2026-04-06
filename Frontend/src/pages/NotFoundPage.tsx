import { useState, useEffect, useRef } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useAuth } from '../contexts/AuthContext';
import { useLanguage } from '../contexts/LanguageContext';
import { useMembership } from '../contexts/MembershipContext';
import { useSEO } from '../hooks/useSEO';
import { cacheManager } from '../services/query/cacheManager';
import productApiService from '../services/products/productApiService';
import { productService } from '../services/api';
import { fetchMembershipLevels } from '../hooks/useMembershipLevels';
import useMembershipLevels from '../hooks/useMembershipLevels';
import { buildProductUrl } from '../utils/membershipRouteUtils';
import logger from '../utils/logger';
import ProductDetailLoader from '../components/products/ProductDetailLoader';

/**
 * Extrae el slug de producto de la URL si es una ruta de producto.
 * Rutas válidas:
 *   /catalogo/:category/:slug
 *   /catalogo/:membership/:category/:slug
 *   /catalog/:category/:slug
 *   /en/catalogo/:category/:slug  (etc.)
 */
function extractProductSlug(pathname: string): { slug: string; categorySlug: string } | null {
  // 3 segmentos: /catalogo/{membership}/{category}/{product}
  const match3 = pathname.match(/^(?:\/en)?\/(?:catalogo|catalog)\/([^/]+)\/([^/]+)\/([^/]+)\/?$/);
  if (match3 && match3[2] && match3[3]) {
    return { categorySlug: match3[2], slug: match3[3] };
  }
  // 2 segmentos: /catalogo/{category}/{product}
  const match2 = pathname.match(/^(?:\/en)?\/(?:catalogo|catalog)\/([^/]+)\/([^/]+)\/?$/);
  if (match2 && match2[1] && match2[2]) {
    return { categorySlug: match2[1], slug: match2[2] };
  }
  return null;
}

const NotFoundPage = () => {
  const { t } = useTranslation('notFoundPage');
  const { isAuthenticated } = useAuth();
  const { localizedPath } = useLanguage();
  const { levels } = useMembershipLevels();
  const { currentLevel: membershipLevel, loading: membershipLoading } = useMembership();
  const location = useLocation();
  const navigate = useNavigate();
  // Estado de revalidación: 'idle' | 'revalidating' | 'confirmed_404'
  const [revalidationState, setRevalidationState] = useState<'idle' | 'revalidating' | 'confirmed_404'>('idle');
  const revalidatedRef = useRef(false);

  // SEO: Página 404 - noindex
  useSEO({
    title: t('title', { defaultValue: 'Página no encontrada' }),
    description: t('description', { defaultValue: 'La página que buscas no existe.' }),
    noIndex: true,
  });

  // Revalidación: si la URL es de un producto, limpiar cachés y verificar si el producto existe.
  // Prevención de loop: usa sessionStorage para no revalidar el mismo slug dos veces seguidas.
  useEffect(() => {
    if (revalidatedRef.current) return;
    
    const productInfo = extractProductSlug(location.pathname);
    
    // Si no es una ruta de producto, confirmar 404 inmediatamente
    if (!productInfo) {
      setRevalidationState('confirmed_404');
      return;
    }
    
    // Prevención de loop infinito: si ya revalidamos este slug y volvimos a 404,
    // es un 404 real. No revalidar de nuevo.
    const revalidationKey = `notfound_revalidated_${productInfo.slug}`;
    const alreadyRevalidated = sessionStorage.getItem(revalidationKey);
    if (alreadyRevalidated) {
      sessionStorage.removeItem(revalidationKey);
      logger.info('NotFoundPage', `Producto "${productInfo.slug}" ya fue revalidado previamente. 404 confirmado.`);
      setRevalidationState('confirmed_404');
      return;
    }
    
    // Esperar a que la membresía termine de cargar antes de revalidar
    if (membershipLoading) return;
    
    revalidatedRef.current = true;
    setRevalidationState('revalidating');
    
    const revalidate = async () => {
      const { slug, categorySlug } = productInfo;
      
      logger.info('NotFoundPage', `Revalidando producto "${slug}" (categoría: ${categorySlug}). Usuario autenticado: ${isAuthenticated}, nivel: ${membershipLevel}`);
      
      // 1. Limpiar cachés de productos que podrían tener datos stale
      // NOTA: NO limpiar membership levels cache — los levels son necesarios para
      // construir URLs correctas con el slug de membresía. Limpiarlos causa que
      // buildProductUrl genere URLs sin el membership slug → loop de 404.
      try {
        cacheManager.clearAll(false);
        productApiService.clearInvalidProductCache();
        logger.info('NotFoundPage', 'Cachés de productos limpiados');
      } catch (e) {
        logger.error('NotFoundPage', 'Error limpiando cachés', e);
      }
      
      // 2. Re-fetch del producto directamente (sin caché)
      try {
        const response = await productService.getBySlugNoCache(slug);
        
        if (response.data && Array.isArray(response.data) && response.data.length > 0) {
          // El producto EXISTE — marcar que revalidamos y redirigir
          // Si vuelve a caer en 404, el flag evitará loop infinito
          sessionStorage.setItem(revalidationKey, '1');
          logger.info('NotFoundPage', `Producto "${slug}" encontrado tras revalidación. Redirigiendo...`);
          const product = response.data[0];
          const categoryMinMembership = product.categories && product.categories.length > 0 
            ? product.categories[0].min_membership_level ?? 0 
            : 0;
          // Obtener levels frescos async para garantizar que buildProductUrl
          // incluya el membership slug cuando el producto lo requiere
          const freshLevels = categoryMinMembership > 0 ? await fetchMembershipLevels() : levels;
          navigate(localizedPath(buildProductUrl(categorySlug, slug, categoryMinMembership, freshLevels)), { replace: true });
          return;
        }
      } catch (err) {
        logger.warn('NotFoundPage', `Revalidación falló para "${slug}":`, err);
      }
      
      // 3. El producto realmente no existe → confirmar 404
      logger.info('NotFoundPage', `Producto "${slug}" no encontrado tras revalidación. Confirmando 404.`);
      setRevalidationState('confirmed_404');
    };
    
    revalidate();
  }, [location.pathname, isAuthenticated, membershipLevel, membershipLoading, navigate, localizedPath]);

  // Mientras se revalida o la membresía está cargando, mostrar loader
  if (revalidationState === 'idle' || revalidationState === 'revalidating') {
    const productInfo = extractProductSlug(location.pathname);
    if (productInfo) {
      return <ProductDetailLoader />;
    }
  }

  return (
    <div className="min-h-[80vh] flex items-center justify-center bg-secundario-50">
      <div className="container mx-auto px-4 py-12 text-center">
        <div className="max-w-lg mx-auto bg-white rounded-lg shadow-lg p-8 md:p-12 relative overflow-hidden">
          {/* Decorative elements */}
          <div className="absolute top-0 left-0 w-32 h-32 bg-primario/10 rounded-full -translate-x-1/2 -translate-y-1/2"></div>
          <div className="absolute bottom-0 right-0 w-40 h-40 bg-secundario/10 rounded-full translate-x-1/3 translate-y-1/3"></div>
          
          {/* Error code */}
          <h1 className="text-8xl md:text-9xl font-bold text-primario mb-4 relative">
            {t('code')}
            <span className="absolute -top-3 -right-3 text-xl md:text-2xl text-white bg-primario px-2 py-1 rounded-lg transform rotate-12">
              {t('badge')}
            </span>
          </h1>
          
          {/* Message */}
          <h2 className="text-2xl md:text-3xl font-semibold text-gray-800 mb-4">
            {t('title')}
          </h2>
          <p className="text-gray-600 mb-8 max-w-md mx-auto">
            {t('description')}
          </p>
          
          {/* Action buttons */}
          <div className="flex flex-col gap-4 items-center">
            <Link 
              to={localizedPath('/')} 
              className="px-6 py-3 bg-primario hover:bg-hover text-white hover:text-white font-medium rounded-lg transition-colors shadow-md inline-flex items-center justify-center"
            >
              {t('home')}
            </Link>
            {isAuthenticated ? (
              <Link 
                to={localizedPath('/catalogo')} 
                className="px-6 py-3 bg-white border border-primario text-primario hover:text-white hover:bg-primario/5 font-medium rounded-lg transition-colors inline-flex items-center justify-center"
              >
                {t('catalog')}
              </Link>
            ) : (
              <div className="flex flex-row gap-4 justify-center">
                <Link 
                  to={localizedPath('/iniciar-sesion')} 
                  className="px-6 py-3 bg-primario hover:bg-hover text-white hover:text-white font-medium rounded-lg transition-colors shadow-md inline-flex items-center justify-center"
                >
                  {t('loginBtn', { defaultValue: 'Iniciar sesión' })}
                </Link>
                <Link 
                  to={localizedPath('/registro')} 
                  className="px-6 py-3 bg-white border border-primario text-primario hover:text-white hover:bg-primario/5 font-medium rounded-lg transition-colors inline-flex items-center justify-center"
                >
                  {t('registerBtn', { defaultValue: 'Registrarse' })}
                </Link>
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
};

export default NotFoundPage;
