import React, { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { FiCreditCard, FiShoppingBag } from 'react-icons/fi';
import { useLanguage } from '../contexts/LanguageContext';
import useMembershipLevels from '../hooks/useMembershipLevels';
import { buildCatalogUrl } from '../utils/membershipRouteUtils';
import { useCart } from '../contexts/CartContext';
import ScrollToTopLink from '../components/common/ScrollToTopLink';
import MinimumOrderAlert from '../components/cart/MinimumOrderAlert';
import CartItemList from '../components/cart/CartItemList';
import VirtualCoinPrice from '../components/common/VirtualCoinPrice';
import CollapsibleSection from '../components/common/CollapsibleSection';
import Loader from '../components/ui/Loader';
import { categoryService } from '../services/api';
import { Category } from '../types/woocommerce';
import logger from '../utils/logger';
import { fluidSizing } from '../utils/fluidSizing';
import { useSEO } from '../hooks/useSEO';
import { getBaseUrl } from '../utils/seo';

const CartPage: React.FC = () => {
  const { t } = useTranslation('cartPage');
  const { localizedPath } = useLanguage();
  const { levels } = useMembershipLevels();

  // SEO: Página privada - noindex para evitar indexación
  useSEO({
    title: t('seo.title'),
    description: t('seo.description'),
    url: `${getBaseUrl()}/reserva`,
    noIndex: true
  });
  
  const { 
    items, 
    total, 
    subtotal, 
    shipping,
    removeItem,
    clearCart,
    // Propiedades de pedido mínimo
    minimumAmount,
    meetsMinimum,
    missingAmount,
    minimumProgress
  } = useCart();
  
  const [featuredCategories, setFeaturedCategories] = useState<Category[]>([]);
  const [loadingCategories, setLoadingCategories] = useState(true);

  // Obtener categorías destacadas para mostrar en carrito vacío
  useEffect(() => {
    const fetchFeaturedCategories = async () => {
      try {
        setLoadingCategories(true);
        const response = await categoryService.getFeatured();
        if (response.data && Array.isArray(response.data)) {
          // Tomar máximo 4 categorías destacadas
          setFeaturedCategories(response.data.slice(0, 4));
        }
      } catch (error) {
        logger.error('CartPage', 'Error al cargar categorías destacadas:', error);
        // Fallback: intentar obtener todas las categorías y tomar las primeras
        try {
          const allCategoriesResponse = await categoryService.getAll({ per_page: 4 });
          if (allCategoriesResponse.data) {
            setFeaturedCategories(allCategoriesResponse.data.slice(0, 4));
          }
        } catch (fallbackError) {
          logger.error('CartPage', 'Error en fallback de categorías:', fallbackError);
        }
      } finally {
        setLoadingCategories(false);
      }
    };

    if (items.length === 0) {
      fetchFeaturedCategories();
    }
  }, [items.length]);

  // Manejar eliminación de item
  const handleRemoveItem = (productId: number, variationId?: number) => {
    removeItem(productId, variationId);
  };


  // Mostrar pantalla de carga mientras se inicializa el carrito
  if (!items) {
    return (
      <div className="container mx-auto px-4 py-8">
        <div className="flex justify-center items-center h-64">
          <Loader text={t('loading')} size="large" />
        </div>
      </div>
    );
  }

  // Si el carrito está vacío, mostrar diseño mejorado
  if (items.length === 0) {
    return (
      <div className="container mx-auto px-4 py-6 sm:py-8">
        <h1 className="text-xl md:text-2xl font-bold mb-6">{t('pageTitle')}</h1>
        
        <div className="max-w-2xl mx-auto">
          <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-8 sm:p-12 text-center">
            {/* Icono de carrito vacío - más limpio */}
            <div className="flex justify-center mb-6">
              <div className="w-24 h-24 sm:w-28 sm:h-28 rounded-full bg-gray-50 flex items-center justify-center">
                <FiShoppingBag className="w-12 h-12 sm:w-14 sm:h-14 text-gray-300" />
              </div>
            </div>
            
            <h2 className="text-xl sm:text-2xl font-bold text-gray-900 mb-2">{t('empty.title')}</h2>
            <p className="text-gray-500 text-sm sm:text-base mb-8 max-w-md mx-auto">
              {t('empty.subtitle')}
            </p>
            
            {/* Botón principal */}
            <ScrollToTopLink 
              to={localizedPath('/catalogo')} 
              className="inline-flex items-center justify-center bg-primario text-white py-3 px-8 rounded-lg hover:bg-primario-dark transition-all duration-200 font-medium hover:text-white shadow-sm hover:shadow-md mb-8"
            >
              <FiShoppingBag className="mr-2" />
              {t('empty.exploreButton')}
            </ScrollToTopLink>
            
            {/* Categorías populares */}
            {!loadingCategories && featuredCategories.length > 0 && (
              <div className="border-t border-gray-100 pt-8">
                <p className="text-sm font-medium text-gray-700 mb-4">{t('empty.popularCategories')}</p>
                <div className="flex flex-wrap justify-center gap-2">
                  {featuredCategories.map((category) => (
                    <ScrollToTopLink 
                      key={category.id}
                      to={localizedPath(buildCatalogUrl(category.slug, category.min_membership_level ?? 0, levels))}
                      className="inline-flex items-center bg-gray-50 hover:bg-primario hover:text-white border border-gray-200 hover:border-primario rounded-full px-4 py-2 text-sm transition-all duration-200"
                    >
                      {category.name}
                    </ScrollToTopLink>
                  ))}
                </div>
              </div>
            )}
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="container mx-auto px-3 sm:px-4 py-4 sm:py-6 md:py-8">
      <div className="md:grid md:grid-cols-5 lg:grid-cols-3 md:gap-4 lg:gap-6 space-y-4 md:space-y-0">
        {/* Lista de productos */}
        <div className="md:col-span-3 lg:col-span-2">
          <CartItemList
            items={items}
            onRemoveItem={handleRemoveItem}
            onClearCart={clearCart}
          />
        </div>
        
        {/* Resumen del pedido */}
        <div className="md:col-span-2 lg:col-span-1">
          {/* Alerta de pedido mínimo */}
          <MinimumOrderAlert
            minimumAmount={minimumAmount}
            currentTotal={subtotal}
            meetsMinimum={meetsMinimum}
            missingAmount={missingAmount}
            progress={minimumProgress}
          />
          
          <div className="sticky top-4">
            <CollapsibleSection
              title={t('summary.title')}
              variant="soft"
              collapsible={false}
              showCollapseButton={false}
              className="mb-4"
            >
              <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.md }}>
                <div className="flex justify-between items-center" style={{ fontSize: fluidSizing.text.sm }}>
                  <span className="text-texto">{t('summary.subtotal')}</span>
                  <VirtualCoinPrice amount={subtotal} size="sm" />
                </div>
                
                
                <div className="flex justify-between" style={{ fontSize: fluidSizing.text.sm }}>
                  <span className="text-texto">{t('summary.shipping')}</span>
                  {shipping > 0 ? <VirtualCoinPrice amount={shipping} size="sm" /> : <span className="font-medium text-oscuro">{t('summary.shippingTbd')}</span>}
                </div>
                
                <div className="flex justify-between items-center pt-2 border-t border-secundario/30 font-bold" style={{ fontSize: fluidSizing.text.lg }}>
                  <span className="text-oscuro">{t('summary.total')}</span>
                  <VirtualCoinPrice amount={total} size="md" />
                </div>
              </div>
              
              {/* Cupón - deshabilitado temporalmente */}
              <div className="border-t border-secundario/30" style={{ marginTop: fluidSizing.space.md, paddingTop: fluidSizing.space.md }}>
                <p className="text-texto/50 italic text-center" style={{ fontSize: fluidSizing.text.xs }}>
                  {t('summary.noCouponsAvailable', 'No hay cupones disponibles en este momento. ¡Pendiente a nuestras redes para futuras promociones!')}
                </p>
              </div>
              
              {/* Botón de pago */}
              <div className="border-t border-secundario/30" style={{ marginTop: fluidSizing.space.md, paddingTop: fluidSizing.space.md }}>
                {meetsMinimum ? (
                  <ScrollToTopLink 
                    to={localizedPath('/finalizar-retiro')} 
                    className="block w-full bg-primario text-white text-center rounded-md hover:bg-hover transition-colors font-medium hover:text-white"
                    style={{ padding: fluidSizing.space.md, fontSize: fluidSizing.text.sm }}
                  >
                    <span className="flex items-center justify-center" style={{ gap: fluidSizing.space.sm }}>
                      <FiCreditCard style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                      {t('summary.checkoutButton')}
                    </span>
                  </ScrollToTopLink>
                ) : (
                  <button
                    disabled
                    className="block w-full bg-gray-300 text-gray-500 text-center rounded-md cursor-not-allowed font-medium"
                    style={{ padding: fluidSizing.space.md, fontSize: fluidSizing.text.sm }}
                    title={t('summary.checkoutDisabledTitle', { amount: missingAmount })}
                  >
                    <span className="flex items-center justify-center" style={{ gap: fluidSizing.space.sm }}>
                      <FiCreditCard style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                      {t('summary.checkoutButton')}
                    </span>
                  </button>
                )}
              </div>
              
              {/* Políticas del club */}
              <div className="bg-secundario/10 rounded-lg" style={{ marginTop: fluidSizing.space.md, padding: fluidSizing.space.md }}>
                <h3 className="font-medium text-oscuro" style={{ fontSize: fluidSizing.text.sm, marginBottom: fluidSizing.space.sm }}>{t('policies.title')}</h3>
                <ul style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.xs }}>
                  <li className="flex items-start text-texto" style={{ fontSize: fluidSizing.text.xs }}>
                    <span className="text-primario mr-2">•</span>
                    <span>{t('policies.shipping')}</span>
                  </li>
                  <li className="flex items-start text-texto" style={{ fontSize: fluidSizing.text.xs }}>
                    <span className="text-primario mr-2">•</span>
                    <span>{t('policies.quality')}</span>
                  </li>
                  <li className="flex items-start text-texto" style={{ fontSize: fluidSizing.text.xs }}>
                    <span className="text-primario mr-2">•</span>
                    <span>{t('policies.security')}</span>
                  </li>
                  <li className="flex items-start text-texto" style={{ fontSize: fluidSizing.text.xs }}>
                    <span className="text-primario mr-2">•</span>
                    <span>{t('policies.support')}</span>
                  </li>
                </ul>
              </div>
            </CollapsibleSection>
          </div>
        </div>
      </div>
    </div>
  );
};

export default CartPage;
