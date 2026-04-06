import React, { Suspense, useEffect, useState } from 'react';
import { Navigate, Route, BrowserRouter as Router, Routes, useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import ErrorBoundary from './components/common/ErrorBoundary';
import ScrollToTop from './components/common/ScrollToTop';
import { LanguageProvider, useLanguage } from './contexts/LanguageContext';
import Layout from './components/layout/Layout';
import Loader from './components/ui/Loader';
import { AuthProvider, useAuth } from './contexts/AuthContext';
import { SiteConfigProvider, useSiteFeatures } from './contexts/SiteConfigContext';
import type { SiteFeatures } from './types/siteConfig';
import { CartProvider } from './contexts/CartContext';
import { CategoriesProvider } from './contexts/CategoriesContext';
import { MembershipProvider } from './contexts/MembershipContext';
import ModalProvider from './contexts/ModalContext';
import useCartSync from './hooks/useCartSync';
import { PopupManager } from './components/popups';
import './index.css';
import alertService from './services/alertService';
import i18n from './config/i18n';
import { productService } from './services/api';
import { fluidSizing } from './utils/fluidSizing';
import logger from './utils/logger';
import { buildProductUrl } from './utils/membershipRouteUtils';
import useMembershipLevels from './hooks/useMembershipLevels';

// HomePage se carga síncrona (ruta principal, debe pintar inmediato)
import HomePage from './pages/HomePage';

// Lazy loading de todas las demás páginas para reducir el bundle inicial
const CartPage = React.lazy(() => import('./pages/CartPage'));
const CheckoutPage = React.lazy(() => import('./pages/CheckoutPage'));
const ContactPage = React.lazy(() => import('./pages/ContactPage'));
const LandingPage = React.lazy(() => import('./pages/LandingPage'));
const MembershipsPage = React.lazy(() => import('./pages/MembershipsPage'));
const NotFoundPage = React.lazy(() => import('./pages/NotFoundPage'));
const PoliceProcedurePage = React.lazy(() => import('./pages/PoliceProcedurePage'));
const ProtectiveLawsPage = React.lazy(() => import('./pages/ProtectiveLawsPage'));
const PrivacyPolicyPage = React.lazy(() => import('./pages/PrivacyPolicyPage'));
const CatalogTwoSegmentPage = React.lazy(() => import('./pages/CatalogTwoSegmentPage'));
const ProductDetailPage = React.lazy(() => import('./pages/ProductDetailPage'));
const ReferidosPage = React.lazy(() => import('./pages/ReferidosPage'));
const ReferralPolicyPage = React.lazy(() => import('./pages/ReferralPolicyPage'));
const ResetPasswordPage = React.lazy(() => import('./pages/ResetPasswordPage'));
const SearchPage = React.lazy(() => import('./pages/SearchPage'));
const ShopPage = React.lazy(() => import('./pages/ShopPage'));
const TermsConditionsPage = React.lazy(() => import('./pages/TermsConditionsPage'));
const TouresPage = React.lazy(() => import('./pages/TouresPage'));
const FAQPage = React.lazy(() => import('./pages/FAQPage'));
const WalletPage = React.lazy(() => import('./pages/WalletPage'));
const VerifyMemberPage = React.lazy(() => import('./pages/VerifyMemberPage'));

// Componente para redirigir usuarios autenticados de /login o /register a home con alerta
// Solo muestra alerta si el usuario navegó manualmente (no viene de un login exitoso)
const AuthenticatedRedirect = () => {
  const navigate = useNavigate();
  const { localizedPath } = useLanguage();
  
  useEffect(() => {
    // Verificar si hay un flag de login reciente en sessionStorage
    const justLoggedIn = sessionStorage.getItem('just_logged_in');
    
    if (justLoggedIn) {
      // Viene de un login exitoso, solo redirigir sin alerta
      // Remover el flag con delay para evitar race conditions con StrictMode
      setTimeout(() => {
        sessionStorage.removeItem('just_logged_in');
      }, 500);
      navigate(localizedPath('/'), { replace: true });
      return;
    }
    
    // Verificar si ya mostramos la alerta recientemente para evitar duplicados
    const alertShown = sessionStorage.getItem('auth_redirect_alert');
    if (alertShown) {
      navigate(localizedPath('/'), { replace: true });
      return;
    }
    
    // Marcar que mostramos la alerta y limpiar después de 2 segundos
    sessionStorage.setItem('auth_redirect_alert', 'true');
    setTimeout(() => {
      sessionStorage.removeItem('auth_redirect_alert');
    }, 2000);
    
    // Usuario navegó manualmente a /login o /register estando logueado
    alertService.info(i18n.t('alerts:auth.logoutForDifferentAccount'));
    navigate(localizedPath('/'), { replace: true });
  }, [navigate, localizedPath]);
  
  return null;
};

// Componentes para redirecciones de URLs antiguas a nuevas

// Redirecciona de /producto/:slug o /catalogo/producto/:slug a /catalogo/[categoría-real]/producto
// Esta ruta resuelve productos sin categoría buscando la categoría real en la API
const ProductRedirect = () => {
  const { slug } = useParams<{ slug: string }>();
  const navigate = useNavigate();
  const { localizedPath, currentLang } = useLanguage();
  const { levels } = useMembershipLevels();
  const { t } = useTranslation('uiComponents');
  const [loading, setLoading] = useState(true);
  
  useEffect(() => {
    // Buscar la categoría real del producto
    if (slug) {
      productService.getBySlug(slug)
        .then((response: any) => {
          const product = response.data;
          if (product && product.categories && product.categories.length > 0) {
            // Usar la primera categoría del producto
            const categorySlug = product.categories[0].slug;
            const categoryMinMembership = product.categories[0].min_membership_level ?? 0;
            const url = buildProductUrl(categorySlug, slug, categoryMinMembership, levels);
            navigate(localizedPath(url), { replace: true });
          } else {
            // Si el producto existe pero no tiene categoría, mostrar 404
            // Esto es un estado inválido - todos los productos deberían tener categoría
            logger.warn('ProductRedirect:', `Producto "${slug}" no tiene categorías asignadas`);
            navigate(localizedPath('/404'), { replace: true });
          }
        })
        .catch((error: Error) => {
          logger.error('ProductRedirect:','Error al buscar producto', error);
          // En caso de error (producto no existe), mostrar 404
          navigate(localizedPath('/404'), { replace: true });
        })
        .finally(() => setLoading(false));
    }
  }, [slug, navigate, localizedPath, currentLang, levels]);
  
  // Mostrar un indicador de carga mientras se busca la categoría
  return loading ? (
    <div className="flex items-center justify-center min-h-screen">
      <Loader text={t('loading.loadingBenefit')} size="large" />
    </div>
  ) : null;
};

// Redirecciona de /categoria/:slug a /catalogo/:categorySlug
const CategoryRedirect = () => {
  const { slug: categorySlug } = useParams<{ slug: string }>();
  const navigate = useNavigate();
  const { localizedPath } = useLanguage();
  
  useEffect(() => {
    navigate(localizedPath(`/catalogo/${categorySlug}`), { replace: true });
  }, [navigate, categorySlug, localizedPath]);
  
  return null;
};

// Redirige respetando el prefijo de idioma actual (para legacy routes)
const LocalizedRedirect = ({ to }: { to: string }) => {
  const { localizedPath } = useLanguage();
  return <Navigate to={localizedPath(to)} replace />;
};

// Nota: PreserveRefCodeRedirect eliminado - ya no es necesario
// El home ahora es público y los códigos de referido se manejan en LandingPage

// Rutas de catálogo: siempre incluyen AMBOS idiomas (ES + EN).
// Esto evita 404 transitorios durante el cambio de idioma, cuando useLangRoutes
// puede retornar rutas del idioma anterior por un render antes de que i18n se sincronice.
// React Router selecciona la ruta correcta por la URL real, no por el idioma del contexto.
const catalogRoutes = (
  <>
    {/* ES */}
    <Route path="/catalogo" element={<ShopPage />} />
    <Route path="/catalogo/buscar" element={<SearchPage />} />
    {/* Ruta de fallback para productos sin categoría - resuelve la categoría real y redirige */}
    <Route path="/catalogo/producto/:slug" element={<ProductRedirect />} />
    <Route path="/catalogo/:categorySlug" element={<ShopPage />} />
    <Route path="/catalogo/:seg1/:seg2" element={<CatalogTwoSegmentPage />} />
    <Route path="/catalogo/:membershipSlug/:categorySlug/:productSlug" element={<ProductDetailPage />} />
    {/* EN */}
    <Route path="/catalog" element={<ShopPage />} />
    <Route path="/catalog/buscar" element={<SearchPage />} />
    {/* Fallback route for products without category - resolves real category and redirects */}
    <Route path="/catalog/product/:slug" element={<ProductRedirect />} />
    <Route path="/catalog/:categorySlug" element={<ShopPage />} />
    <Route path="/catalog/:seg1/:seg2" element={<CatalogTwoSegmentPage />} />
    <Route path="/catalog/:membershipSlug/:categorySlug/:productSlug" element={<ProductDetailPage />} />
  </>
);

// Rutas no-catálogo en español (solo accesibles sin prefijo /en)
const getEsRoutes = (features: SiteFeatures) => (
  <>
    <Route path="/reserva" element={<CartPage />} />
    <Route path="/finalizar-retiro" element={<CheckoutPage />} />
    {features.referrals_points && <Route path="/invitados" element={<ReferidosPage />} />}
    {features.referrals_points && <Route path="/referidos" element={<LocalizedRedirect to="/invitados" />} />}
    {features.memberships && <Route path="/membresias" element={<MembershipsPage />} />}
    {features.referrals_points && <Route path="/fondo-de-aportes" element={<WalletPage />} />}
    <Route path="/privacidad" element={<PrivacyPolicyPage />} />
    <Route path="/terminos" element={<TermsConditionsPage />} />
    {features.referrals_points && <Route path="/politica-invitados" element={<ReferralPolicyPage />} />}
    {features.referrals_points && <Route path="/referidos-politica" element={<LocalizedRedirect to="/politica-invitados" />} />}
    <Route path="/guia-requisa" element={<PoliceProcedurePage />} />
    <Route path="/marco-legal" element={<ProtectiveLawsPage />} />
    <Route path="/contacto" element={<ContactPage />} />
    <Route path="/toures" element={<TouresPage />} />
    <Route path="/verificar-socio/:token" element={<VerifyMemberPage />} />
  </>
);

// Rutas no-catálogo en inglés (solo accesibles bajo prefijo /en)
const getEnRoutes = (features: SiteFeatures) => (
  <>
    <Route path="/cart" element={<CartPage />} />
    <Route path="/checkout" element={<CheckoutPage />} />
    {features.referrals_points && <Route path="/referrals" element={<ReferidosPage />} />}
    {features.memberships && <Route path="/memberships" element={<MembershipsPage />} />}
    {features.referrals_points && <Route path="/wallet" element={<WalletPage />} />}
    <Route path="/privacy" element={<PrivacyPolicyPage />} />
    <Route path="/terms" element={<TermsConditionsPage />} />
    {features.referrals_points && <Route path="/referral-policy" element={<ReferralPolicyPage />} />}
    <Route path="/search-guide" element={<PoliceProcedurePage />} />
    <Route path="/legal-framework" element={<ProtectiveLawsPage />} />
    <Route path="/contact" element={<ContactPage />} />
    <Route path="/tours" element={<TouresPage />} />
    <Route path="/verify-member/:token" element={<VerifyMemberPage />} />
  </>
);

// Rutas compartidas (sin traducción o accesibles en ambos idiomas)
const sharedRoutes = (
  <>
    <Route path="/" element={<HomePage />} />
    {/* Rutas legacy (solo ES, pero también bajo /en por compatibilidad temporal) */}
    <Route path="/producto/:slug" element={<ProductRedirect />} />
    <Route path="/categoria/:slug" element={<CategoryRedirect />} />
    {/* Sin traducción */}
    <Route path="/faq" element={<FAQPage />} />
    <Route path="/reset-password" element={<ResetPasswordPage />} />
    <Route path="/404" element={<NotFoundPage />} />
    <Route path="*" element={<NotFoundPage />} />
  </>
);

// Hook que retorna las rutas según el idioma actual y features activos
const useLangRoutes = () => {
  const { currentLang } = useLanguage();
  const features = useSiteFeatures();
  return currentLang === 'en'
    ? <>{catalogRoutes}{getEnRoutes(features)}{sharedRoutes}</>
    : <>{catalogRoutes}{getEsRoutes(features)}{sharedRoutes}</>;
};

// Rutas de autenticación según idioma
const useAuthRoutes = (authElement: React.ReactElement) => {
  const { currentLang } = useLanguage();
  const el = authElement;
  if (currentLang === 'en') {
    return (
      <>
        <Route path="/login" element={el} />
        <Route path="/register" element={el} />
      </>
    );
  }
  return (
    <>
      <Route path="/iniciar-sesion" element={el} />
      <Route path="/registrarse" element={el} />
    </>
  );
};

// Componente para rutas autenticadas
const AuthenticatedRoutes = () => {
  const langRoutes = useLangRoutes();
  const authRoutes = useAuthRoutes(<AuthenticatedRedirect />);
  return (
    <Layout>
      <Suspense fallback={<div className="flex justify-center items-center min-h-[50vh]"><Loader size="large" /></div>}>
        <Routes>
          {langRoutes}
          {authRoutes}
        </Routes>
      </Suspense>
    </Layout>
  );
};

// Componente para rutas no autenticadas
const UnauthenticatedRoutes = () => {
  const { currentLang } = useLanguage();
  const langRoutes = useLangRoutes();
  return (
    <Suspense fallback={<div className="flex justify-center items-center min-h-[50vh]"><Loader size="large" /></div>}>
      <Routes>
        {/* Rutas de login/registro sin Layout (sin Header) */}
        {currentLang === 'en' ? (
          <>
            <Route path="/login" element={<LandingPage />} />
            <Route path="/register" element={<LandingPage />} />
          </>
        ) : (
          <>
            <Route path="/iniciar-sesion" element={<LandingPage />} />
            <Route path="/registrarse" element={<LandingPage />} />
          </>
        )}
        
        {/* Rutas con Layout normal */}
        <Route path="*" element={
          <Layout>
            <Routes>
              {langRoutes}
            </Routes>
          </Layout>
        } />
      </Routes>
    </Suspense>
  );
};

// Contenido interno de la app (sin prefijo de idioma)
function AppInner() {
  const { isAuthenticated, loading } = useAuth();
  const { t } = useTranslation('uiComponents');
  
  // Sincronizar carrito con autenticación
  useCartSync();
  
  // Mientras se verifica la autenticación, mostrar pantalla de carga
  if (loading) {
    return (
      <div 
        className="flex justify-center items-center bg-gray-50"
        style={{ 
          padding: fluidSizing.space.md,
          height: '100vh',
          maxHeight: '100vh',
          overflow: 'hidden'
        }}
      >
        <Loader text={t('loading.loadingApp')} size="large" />
      </div>
    );
  }
  
  return (
    <>
      {isAuthenticated ? <AuthenticatedRoutes /> : <UnauthenticatedRoutes />}
      <PopupManager />
    </>
  );
}

// Componente principal que maneja el prefijo de idioma en la URL
// /en/* → renderiza AppInner (LanguageProvider se encarga de cambiar i18n)
// /* → renderiza AppInner (idioma por defecto: ES)
function AppContent() {
  return (
    <Routes>
      <Route path="/en/*" element={<AppInner />} />
      <Route path="/*" element={<AppInner />} />
    </Routes>
  );
}



function App() {
  return (
    <ErrorBoundary>
      <SiteConfigProvider>
        <Router>
          <AuthProvider>
            <MembershipProvider>
              <CartProvider>
                <ModalProvider>
                  <ScrollToTop />
                  <LanguageProvider>
                    <CategoriesProvider>
                      <ErrorBoundary>
                        <AppContent />
                      </ErrorBoundary>
                    </CategoriesProvider>
                  </LanguageProvider>
                </ModalProvider>
              </CartProvider>
            </MembershipProvider>
          </AuthProvider>
        </Router>
      </SiteConfigProvider>
    </ErrorBoundary>
  );
}

export default App
