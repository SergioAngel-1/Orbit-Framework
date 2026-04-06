import { useState, useEffect, memo } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router-dom';
import { Product } from '../../types/woocommerce';
import { generateSlug, getVariablePriceRange } from '../../utils/formatters';
import { useCart } from '../../contexts/CartContext';
import QuantityCounter from '../common/QuantityCounter';
import VirtualCoinPrice from '../common/VirtualCoinPrice';
import { FiDollarSign } from 'react-icons/fi';
import { useLanguage } from '../../contexts/LanguageContext';
import useMembershipLevels from '../../hooks/useMembershipLevels';
import { buildProductUrl, buildCatalogUrl } from '../../utils/membershipRouteUtils';
import ProductRating from './ProductRating';

interface ProductCardProps {
  product: Product;
  className?: string; // Additional class for different section styling
  animationClass?: string; // For GSAP animations
  comingSoon?: boolean; // Variant: blurs card and shows "coming soon" button
}

/**
 * Componente de tarjeta de producto mejorado con mejor accesibilidad y diseño
 * Muestra la información básica del producto y permite agregarlo al carrito
 */
const ProductCard = ({ product, className = '', animationClass = '', comingSoon = false }: ProductCardProps) => {
  const { t } = useTranslation('productCard');
  const { localizedPath } = useLanguage();
  const { levels } = useMembershipLevels();
  const [quantity, setQuantity] = useState(0);
  const { items, addItem } = useCart();
  const navigate = useNavigate();

  // Detectar si es un producto de la categoría "Paquetes de Virtual Coins"
  const isVirtualCoinsPackage = product.categories?.some(
    cat => cat.slug === 'paquetes-virtual-coins'
  ) ?? false;

  // Generar slug para la URL del producto
  const productSlug = product.slug || generateSlug(product.name);

  // Obtener la categoría del producto para la URL
  // Si no hay categoría, buildProductUrl generará una URL de fallback que el router resolverá
  const categorySlug = product.categories && product.categories.length > 0
    ? product.categories[0].slug
    : undefined;

  // Construir la URL con prefijo de membresía según la categoría
  const categoryMinMembership = product.categories && product.categories.length > 0 
    ? product.categories[0].min_membership_level ?? 0 
    : 0;
  const rawProductUrl = buildProductUrl(categorySlug, productSlug, categoryMinMembership, levels);
  const productUrl = localizedPath(rawProductUrl);
  
  // Actualizar la cantidad cuando cambian los items del carrito
  useEffect(() => {
    // Manejar tanto formato ligero (solo IDs) como formato completo
    const existingItem = items.find(item => {
      const itemProductId = item.product?.id || item.id;
      return itemProductId === product.id;
    });
    setQuantity(existingItem ? existingItem.quantity : 0);
  }, [items, product.id]);

  // Manejar agregar al carrito
  const handleAddToCart = (e: React.MouseEvent) => {
    e.preventDefault(); // Evitar navegación si se hace clic en el botón
    e.stopPropagation(); // Evitar propagación del evento
    addItem(product);
    setQuantity(1);
  };
  
  // Verificar si el producto es variable
  const isVariableProduct = product.type === 'variable';
  
  // Handler para abrir el modal de compra de Virtual Coins
  const handleBuyVirtualCoins = (e: React.MouseEvent) => {
    e.preventDefault();
    e.stopPropagation();
    // Navegar a WalletPage con estado para abrir el modal de compra
    navigate(localizedPath('/fondo-de-aportes'), { state: { openBuyModal: true } });
  };
  
  // El manejo de cambios de cantidad ahora lo realiza QuantityCounter mediante CartContext (con loader)
  
  // Obtener la imagen principal o una imagen de respaldo
  const getProductImage = () => {
    if (product.images && product.images.length > 0 && product.images[0].src) {
      return product.images[0].src;
    }
    return null;
  };

  // Obtener el precio como número
  const price = parseFloat(product.price || '0');
  const regularPrice = parseFloat(product.regular_price || '0');
  
  // Rango de precios para productos variables
  const priceRange = getVariablePriceRange(product);
  
  // Verificar si el producto tiene descuento
  const hasDiscount = !isVariableProduct && product.regular_price && product.sale_price && 
    parseFloat(product.regular_price) > parseFloat(product.sale_price);
  
  // Calcular el porcentaje de descuento si existe
  const discountPercentage = hasDiscount && product.regular_price && product.sale_price ? 
    Math.round((1 - (parseFloat(product.sale_price) / parseFloat(product.regular_price))) * 100) : 0;
  
  return (
    <article 
      className={`group relative bg-white rounded-lg overflow-hidden shadow-md hover:shadow-lg transition-shadow duration-300 flex flex-col h-full ${className} ${animationClass}`}
      data-testid="product-card"
    >
      {/* Overlay "Coming Soon" */}
      {comingSoon && (
        <div className="absolute inset-0 z-20 pointer-events-none" />
      )}

      {/* Badge de descuento */}
      {!comingSoon && hasDiscount && discountPercentage > 0 && (
        <div className="absolute top-0 left-0 bg-red-700 text-white text-xs font-bold px-2 py-1 rounded-br-lg z-10">
          {t('badges.off', { percent: discountPercentage })}
        </div>
      )}

      {/* Badge de Agotado */}
      {!comingSoon && product.stock_status === 'outofstock' && (
        <div className="absolute top-0 right-0 bg-gray-700 text-white text-xs font-bold px-2 py-1 rounded-bl-lg z-10">
          {t('badges.outOfStock')}
        </div>
      )}

      {/* Badge de Próximamente */}
      {comingSoon && (
        <div className="absolute top-0 left-0 bg-oscuro text-white text-xs font-bold px-2 py-1 rounded-br-lg z-30">
          {t('badges.comingSoon')}
        </div>
      )}
      
      {/* Contenedor de imagen con enlace separado - altura fija */}
      <div className={`relative overflow-hidden rounded-t-lg flex-shrink-0 ${comingSoon ? 'blur-[6px] grayscale-[30%] pointer-events-none select-none' : ''}`}>
        {getProductImage() ? (
          <>
            <div className="aspect-[4/3] w-full overflow-hidden">
              <img 
                src={getProductImage() || undefined} 
                alt={product.name} 
                className="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110"
                loading="lazy"
              />
              <div className="absolute inset-0 bg-black opacity-0 group-hover:opacity-10 transition-opacity duration-300"></div>
              {product.stock_status === 'outofstock' && (
                <div className="absolute inset-0 bg-black bg-opacity-40 flex items-center justify-center">
                  <span className="text-white font-bold text-lg">{t('badges.outOfStock')}</span>
                </div>
              )}
            </div>
          </>
        ) : (
          <div className="aspect-[4/3] w-full bg-gray-100 flex items-center justify-center">
            <span className="text-4xl">🛍️</span>
          </div>
        )}
        
        {/* Enlace principal a la página del producto (sobre la imagen) */}
        {/* Para paquetes de Virtual Coins, redirigir al modal en lugar de la página de detalle */}
        {isVirtualCoinsPackage ? (
          <button
            onClick={handleBuyVirtualCoins}
            className="absolute inset-0 focus:outline-none focus:ring-2 focus:ring-primario focus:ring-offset-2 z-[1] cursor-pointer bg-transparent border-none"
            aria-label={t('aria.buy', { name: product.name })}
          >
            <span className="sr-only">{t('srOnly.buy', { name: product.name })}</span>
          </button>
        ) : (
          <Link 
            to={productUrl} 
            className="absolute inset-0 focus:outline-none focus:ring-2 focus:ring-primario focus:ring-offset-2 z-[1]"
            state={{ fromCategory: true }}
            aria-label={t('aria.viewDetails', { name: product.name })}
          >
            <span className="sr-only">{t('srOnly.viewDetails', { name: product.name })}</span>
          </Link>
        )}
        
        {/* Botón rápido para Virtual Coins - abre modal de compra */}
        {quantity === 0 && product.stock_status !== 'outofstock' && isVirtualCoinsPackage && (
          <button 
            onClick={handleBuyVirtualCoins}
            className="absolute bottom-2 right-2 bg-primario hover:bg-hover text-white p-2 rounded-full shadow-lg transition-all duration-300 z-10 flex items-center justify-center opacity-100 translate-y-0 [@media(hover:hover)]:opacity-0 [@media(hover:hover)]:translate-y-4 [@media(hover:hover)]:group-hover:opacity-100 [@media(hover:hover)]:group-hover:translate-y-0"
            aria-label={t('aria.buy', { name: product.name })}
          >
            <FiDollarSign className="h-5 w-5" />
          </button>
        )}
        
        {/* Botón rápido de agregar al carrito en hover (no dentro del Link) */}
        {quantity === 0 && product.stock_status !== 'outofstock' && !isVariableProduct && !isVirtualCoinsPackage && (
          <button 
            onClick={handleAddToCart}
            className="absolute bottom-2 right-2 bg-primario hover:bg-hover text-white p-2 rounded-full shadow-lg transition-all duration-300 z-10 flex items-center justify-center opacity-100 translate-y-0 [@media(hover:hover)]:opacity-0 [@media(hover:hover)]:translate-y-4 [@media(hover:hover)]:group-hover:opacity-100 [@media(hover:hover)]:group-hover:translate-y-0"
            aria-label={t('aria.addToCart', { name: product.name })}
          >
            <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
            </svg>
          </button>
        )}
        
        {/* Botón rápido para productos variables - sin usar Link con clase especial para evitar anidación */}
        {quantity === 0 && product.stock_status !== 'outofstock' && isVariableProduct && (
          <button 
            onClick={(e) => {
              e.preventDefault();
              navigate(productUrl);
            }}
            className="absolute bottom-2 right-2 bg-primario hover:bg-hover text-white p-2 rounded-full shadow-lg transition-all duration-300 z-10 flex items-center justify-center opacity-100 translate-y-0 [@media(hover:hover)]:opacity-0 [@media(hover:hover)]:translate-y-4 [@media(hover:hover)]:group-hover:opacity-100 [@media(hover:hover)]:group-hover:translate-y-0"
            aria-label={t('aria.viewOptions', { name: product.name })}
          >
            <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
            </svg>
          </button>
        )}
      </div>
      
      {/* Información del producto - sección flexible que empuja el botón hacia abajo */}
      <div className={`p-2 sm:p-3 md:p-4 flex flex-col flex-grow ${comingSoon ? 'blur-[4px] grayscale-[20%] pointer-events-none select-none' : ''}`}>
        {/* Categoría y rating del producto */}
        <div className="flex items-center justify-between mb-0.5 sm:mb-1">
          {product.categories && product.categories.length > 0 && (
            <Link
              to={localizedPath(buildCatalogUrl(
                product.categories[0].slug,
                product.categories[0].min_membership_level ?? 0,
                levels
              ))}
              className="text-2xs sm:text-xs text-gray-500 hover:text-primario transition-colors truncate relative z-[2]"
              onClick={(e) => e.stopPropagation()}
            >
              {product.categories[0].name}
            </Link>
          )}
          <ProductRating averageRating={product.average_rating} ratingCount={product.rating_count} compact />
        </div>
        
        {/* Nombre del producto con enlace */}
        {/* Para paquetes de Virtual Coins, redirigir al modal - mismo estilo que Link */}
        {isVirtualCoinsPackage ? (
          <div onClick={handleBuyVirtualCoins} className="block flex-grow cursor-pointer">
            <h3 className="font-medium text-2xs sm:text-xs md:text-sm mb-0.5 sm:mb-1 text-oscuro line-clamp-2 group-hover:text-primario transition-colors min-h-[2rem] sm:min-h-[2.5rem]">
              {product.name}
            </h3>
          </div>
        ) : (
          <Link to={productUrl} state={{ fromCategory: true }} className="block flex-grow">
            <h3 className="font-medium text-2xs sm:text-xs md:text-sm mb-0.5 sm:mb-1 text-oscuro line-clamp-2 group-hover:text-primario transition-colors min-h-[2rem] sm:min-h-[2.5rem]">
              {product.name}
            </h3>
          </Link>
        )}

        {/* Precios y botón de agregar al carrito - siempre al final */}
        <div className="flex flex-col w-full mt-auto">
          {/* Precio */}
          <div className="flex items-center justify-between mb-2">
            {priceRange ? (
              <div className="flex items-center gap-0.5 flex-wrap">
                {!priceRange.max && <span className="text-2xs text-gray-500">{t('price.from')}</span>}
                <VirtualCoinPrice amount={priceRange.min} size="xs" showLabel={false} className="text-primario font-bold" />
                {priceRange.max && (
                  <>
                    <span className="text-2xs text-gray-500">–</span>
                    <VirtualCoinPrice amount={priceRange.max} size="xs" showLabel={false} className="text-primario font-bold" />
                  </>
                )}
              </div>
            ) : (
              <VirtualCoinPrice 
                amount={price} 
                size="sm" 
                showLabel={false}
                className="text-primario font-bold"
              />
            )}
            {hasDiscount && (
              <VirtualCoinPrice 
                amount={regularPrice} 
                size="xs" 
                showLabel={false}
                className="line-through text-gray-400"
              />
            )}
          </div>
          
          {/* Botón de agregar o controles de cantidad - altura fija */}
          <div className={`h-[34px] flex items-center ${comingSoon ? '!blur-0 !grayscale-0 !pointer-events-auto !select-auto' : ''}`}>
            {/* Botón de Próximamente */}
            {comingSoon && (
              <span className="bg-gray-400 text-white p-1.5 py-1.5 px-2 rounded-md flex items-center justify-center gap-1 w-full h-full cursor-default text-xs font-medium">
                {t('buttons.comingSoon')}
              </span>
            )}
            {/* Botón especial para Paquetes de Virtual Coins */}
            {!comingSoon && product.stock_status !== 'outofstock' && isVirtualCoinsPackage && (
              <button 
                onClick={handleBuyVirtualCoins}
                className="bg-primario hover:bg-hover text-white hover:text-white p-1.5 py-1.5 px-2 rounded-md transition-colors duration-300 flex items-center justify-center gap-1 w-full h-full"
                aria-label={t('aria.buy', { name: product.name })}
              >
                <FiDollarSign className="h-3.5 w-3.5 flex-shrink-0" />
                <span className="text-xs whitespace-nowrap">{t('buttons.buyFC')}</span>
              </button>
            )}
            
            {/* Botón normal de agregar al carrito */}
            {!comingSoon && product.stock_status !== 'outofstock' && !isVariableProduct && !isVirtualCoinsPackage && (
              quantity === 0 ? (
                <button 
                  onClick={handleAddToCart}
                  className="bg-primario hover:bg-hover text-white hover:text-white p-1.5 py-1.5 px-2 rounded-md transition-colors duration-300 flex items-center justify-center gap-1 w-full h-full"
                  aria-label={t('aria.addToCart', { name: product.name })}
                >
                  <svg xmlns="http://www.w3.org/2000/svg" className="h-3.5 w-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                  </svg>
                  <span className="text-xs whitespace-nowrap">{t('buttons.addToCart')}</span>
                </button>
              ) : (
                <div className="bg-gray-50 p-1 rounded-lg w-full flex justify-center h-full items-center">
                  <QuantityCounter
                    productId={product.id}
                    quantity={quantity}
                    productName={product.name}
                    size="sm"
                    className="h-full"
                    maxQuantity={product.stock_quantity ?? undefined}
                  />
                </div>
              )
            )}
            
            {/* Botón de ver opciones para productos variables - Siempre a ancho completo */}
            {!comingSoon && product.stock_status !== 'outofstock' && isVariableProduct && !isVirtualCoinsPackage && (
              <Link 
                to={productUrl}
                state={{ fromCategory: true }}
                className="bg-primario hover:bg-hover text-white hover:text-white p-1.5 py-1.5 px-2 rounded-md transition-colors duration-300 flex items-center justify-center gap-1 w-full h-full"
                aria-label={t('aria.viewOptions', { name: product.name })}
              >
                <svg xmlns="http://www.w3.org/2000/svg" className="h-3.5 w-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
              </svg>
                <span className="text-xs whitespace-nowrap">{quantity > 0 ? t('buttons.viewMoreOptions') : t('buttons.viewOptions')}</span>
              </Link>
            )}
            
            {/* Mensaje de agotado en lugar del botón */}
            {!comingSoon && product.stock_status === 'outofstock' && quantity === 0 && (
              <span className="text-2xs sm:text-xs text-gray-500 italic h-full flex items-center">
                {t('stock.outOfStock')}
              </span>
            )}
          </div>
        </div>
      </div>
    </article>
  );
};

// Usar React.memo para evitar re-renderizados innecesarios
export default memo(ProductCard, (prevProps, nextProps) => {
  // Solo re-renderizar si cambia el producto o las clases
  return (
    prevProps.product.id === nextProps.product.id &&
    prevProps.product.price === nextProps.product.price &&
    prevProps.product.stock_status === nextProps.product.stock_status &&
    prevProps.product.stock_quantity === nextProps.product.stock_quantity &&
    prevProps.product.average_rating === nextProps.product.average_rating &&
    prevProps.product.rating_count === nextProps.product.rating_count &&
    prevProps.className === nextProps.className &&
    prevProps.animationClass === nextProps.animationClass &&
    prevProps.comingSoon === nextProps.comingSoon
  );
});
