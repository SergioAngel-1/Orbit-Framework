import gsap from 'gsap';
import React, { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FiCreditCard, FiShoppingCart, FiTrash2 } from 'react-icons/fi';
import { useCart } from '../../contexts/CartContext';
import { useMembership } from '../../contexts/MembershipContext';
import { lockBodyScroll, unlockBodyScroll } from '../../utils/bodyScrollLock';
import VirtualCoinPrice from '../common/VirtualCoinPrice';
import MembershipBadge from '../common/MembershipBadge';
import QuantityCounter from '../common/QuantityCounter';
import ScrollToTopLink from '../common/ScrollToTopLink';
import { useLanguage } from '../../contexts/LanguageContext';
import useMembershipLevels from '../../hooks/useMembershipLevels';
import { buildProductUrl } from '../../utils/membershipRouteUtils';

interface CartModalProps {
  isOpen: boolean;
  onClose: () => void;
}

const CartModal: React.FC<CartModalProps> = ({ isOpen, onClose }) => {
  const { t } = useTranslation('cartModal');
  const { localizedPath } = useLanguage();
  const { levels } = useMembershipLevels();
  const { 
    items: cartItems, 
    subtotal,
    removeItem,
    minimumAmount,
    meetsMinimum,
    missingAmount
  } = useCart();
  
  const { getCategoryMembershipLevel } = useMembership();
  
  // Estado para almacenar los niveles de membresía requeridos por producto
  const [productMembershipLevels, setProductMembershipLevels] = useState<Record<string, number>>({});

  // Obtener niveles de membresía para cada producto del carrito
  useEffect(() => {
    const fetchMembershipLevels = async () => {
      const levels: Record<string, number> = {};
      
      for (const item of cartItems) {
        const itemKey = `${item.id}-${item.variation_id || 'none'}`;
        const categories = item.product?.categories || [];
        let highestLevel = 0;
        
        for (const category of categories) {
          const level = await getCategoryMembershipLevel(category.id);
          if (level > highestLevel) {
            highestLevel = level;
          }
        }
        
        levels[itemKey] = highestLevel;
      }
      
      setProductMembershipLevels(levels);
    };
    
    if (cartItems.length > 0 && isOpen) {
      fetchMembershipLevels();
    }
  }, [cartItems, getCategoryMembershipLevel, isOpen]);

  // Cerrar el modal al presionar Escape
  useEffect(() => {
    const handleEscapeKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        onClose();
      }
    };
    
    window.addEventListener('keydown', handleEscapeKey);
    return () => window.removeEventListener('keydown', handleEscapeKey);
  }, [onClose]);
  
  // Bloquear el scroll del body cuando el modal está abierto
  useEffect(() => {
    if (isOpen) {
      lockBodyScroll();
      return () => {
        unlockBodyScroll();
      };
    }
  }, [isOpen]);
  
  // Manejar eliminación de item
  const handleRemoveItem = (productId: number, variationId?: number) => {
    removeItem(productId, variationId);
  };

  // Referencia al panel del carrito para la animación
  const cartRef = useRef<HTMLDivElement>(null);
  
  // Efecto para animar la apertura y cierre del carrito
  useEffect(() => {
    if (!cartRef.current) return;
    
    if (isOpen) {
      // Animación de entrada (derecha a izquierda)
      gsap.fromTo(cartRef.current,
        { x: '100%', opacity: 0.5 },
        { x: '0%', opacity: 1, duration: 0.3, ease: 'power2.out' }
      );
    }
  }, [isOpen]);
  
  // Función para manejar el cierre con animación
  const handleClose = () => {
    if (!cartRef.current) {
      onClose();
      return;
    }
    
    // Animación de salida (izquierda a derecha)
    gsap.to(cartRef.current, {
      x: '100%',
      opacity: 0.5,
      duration: 0.25,
      ease: 'power2.in',
      onComplete: onClose
    });
  };

  // Si el modal no está abierto, no renderizar nada
  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 overflow-hidden">
      {/* Overlay con efecto de desenfoque */}
      <div 
        className="absolute inset-0 bg-black/30 backdrop-blur-sm" 
        onClick={handleClose}
      ></div>
      
      {/* Panel lateral del carrito - similar al MobileMenu */}
      <div 
        ref={cartRef} 
        className="absolute top-0 right-0 h-[100dvh] w-4/5 max-w-sm bg-white/95 shadow-xl flex flex-col"
        onClick={(e) => e.stopPropagation()}
      >
        {/* Cabecera del carrito */}
        <div className="flex justify-between items-center p-4 border-b sticky top-0 bg-white z-10">
          <h2 className="text-lg font-semibold text-primario">{t('title')}</h2>
          <button 
            onClick={handleClose}
            className="text-primario hover:text-hover transition-colors !bg-white"
            aria-label={t('closeAria')}
          >
            <svg xmlns="http://www.w3.org/2000/svg" className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
        
        {/* Contenido del carrito */}
        <div className="flex-grow overflow-y-auto p-4 overscroll-contain pb-24">
          {cartItems.length === 0 ? (
            <div className="flex flex-col items-center justify-center h-full text-center">
              <p className="text-gray-500 mb-4">{t('empty.message')}</p>
              <button 
                onClick={onClose}
                className="px-4 py-2 bg-primario text-white rounded-md hover:bg-primario-dark transition-colors"
              >
                {t('empty.continue')}
              </button>
            </div>
          ) : (
            <ul className="divide-y divide-gray-200">
              {cartItems.map((item) => (
                <li key={`${item.id}-${item.variation_id || 'none'}`} className="py-4 relative">
                  {/* Enlace que cubre toda la tarjeta */}
                  {item.product?.slug && (
                  <ScrollToTopLink 
                    to={localizedPath(buildProductUrl(item.product?.categories?.[0]?.slug, item.product.slug, (item.product?.categories?.[0] as any)?.min_membership_level ?? 0, levels))} 
                    className="absolute inset-0 z-10"
                    onClick={onClose}
                    aria-label={t('item.viewAria', { name: item.product?.name || t('item.defaultName') })}
                  >
                    <span className="sr-only">{t('item.viewBenefit')}</span>
                  </ScrollToTopLink>
                  )}
                  
                  <div className="flex relative">
                    {/* Imagen del producto */}
                    <div className="h-24 w-24 flex-shrink-0 overflow-hidden rounded-md border border-gray-200 relative">
                      <img
                        src={item.product?.images?.[0]?.src || '/wp-content/themes/Starter/assets/img/no-image.svg'}
                        alt={item.product?.name || t('item.defaultName')}
                        className="h-full w-full object-cover object-center"
                      />
                      {productMembershipLevels[`${item.id}-${item.variation_id || 'none'}`] > 0 && (
                        <div className="absolute top-1 left-1">
                          <MembershipBadge 
                            level={productMembershipLevels[`${item.id}-${item.variation_id || 'none'}`]} 
                            size="xs" 
                          />
                        </div>
                      )}
                    </div>
                    
                    {/* Información del producto */}
                    <div className="flex-1 ml-3">
                      <h3 className="text-sm font-medium text-gray-900 hover:text-primario transition-colors">
                        {item.product?.name || t('item.defaultName')}
                      </h3>
                      {!!(item.variation_id && (item as any).variation?.attributes && (item as any).variation.attributes.length > 0) && (
                        <div className="mt-0.5">
                          <span className="inline-block text-2xs text-gray-700 bg-gray-100 border border-gray-200 rounded-full px-2 py-0.5">
                            {(() => {
                              try {
                                const values = ((item as any).variation.attributes as any[])
                                  .map((a: any) => a?.option)
                                  .filter((v: any) => !!v)
                                  .join(' · ');
                                return t('item.variation', { values });
                              } catch {
                                return '';
                              }
                            })()}
                          </span>
                        </div>
                      )}
                      <div className="mt-1">
                        <div className="flex items-center gap-1 text-sm text-gray-500">
                          <VirtualCoinPrice 
                            amount={parseFloat((item.variation_id && (item as any).variation?.price) ? (item as any).variation.price : (item.product?.price || '0'))}
                            size="xs"
                            showLabel={false}
                          />
                          <span>x {item.quantity}</span>
                        </div>
                      </div>
                      
                      {/* Controles de cantidad */}
                      <div className="flex justify-between items-center mt-2">
                        <div className="relative z-20" onClick={(e) => e.stopPropagation()}>
                          <QuantityCounter 
                            productId={item.id}
                            variationId={item.variation_id}
                            quantity={item.quantity}
                            productName={item.product?.name || t('item.defaultName')}
                            size="sm"
                            maxQuantity={
                              item.variation_id && (item as any).variation?.stock_quantity !== undefined
                                ? (item as any).variation.stock_quantity
                                : item.product?.stock_quantity ?? undefined
                            }
                          />
                        </div>
                        <button 
                          onClick={(e) => {
                            e.stopPropagation();
                            e.preventDefault();
                            handleRemoveItem(item.id, item.variation_id);
                          }}
                          className="font-medium text-red-600 hover:text-red-500 flex items-center relative z-20 !bg-transparent !border-none !p-0"
                          aria-label={t('item.removeAria', { name: item.product?.name || t('item.defaultName') })}
                        >
                          <FiTrash2 className="w-4 h-4" />
                        </button>
                      </div>
                    </div>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>
        
        {/* Footer con total y botones */}
        {cartItems.length > 0 && (
          <div className="sticky bottom-0 z-10 border-t border-gray-200 px-3 py-2.5 bg-white mt-0 pb-[env(safe-area-inset-bottom)]">
            <div className="flex justify-between items-center text-sm font-semibold text-gray-900 mb-2">
              <p>{t('footer.subtotal')}</p>
              <VirtualCoinPrice amount={subtotal} size="sm" />
            </div>
            
            {/* Alerta de success cuando se cumple el mínimo */}
            {meetsMinimum && minimumAmount > 0 && (
              <div className="mb-3 p-2.5 bg-green-50 border border-green-200 rounded-md">
                <div className="flex items-center gap-1">
                  <p className="text-xs text-green-800 font-medium">
                    {t('footer.minimumReached')}
                  </p>
                </div>
                <div className="text-xs text-green-700 mt-1">
                  {t('footer.minimumReachedDesc')}{' '}
                  <span className="inline-flex items-center">
                    <VirtualCoinPrice amount={minimumAmount} size="xs" showLabel={false} className="text-green-700 font-semibold" />
                  </span>
                </div>
              </div>
            )}
            
            {/* Disclaimer de aporte mínimo de mantenimiento */}
            {!meetsMinimum && minimumAmount > 0 && (
              <div className="mb-3 p-2.5 bg-yellow-50 border border-yellow-200 rounded-md">
                <div className="flex items-center gap-1 mb-1">
                  <p className="text-xs text-yellow-800 font-medium">
                    {t('footer.minimumLabel')}
                  </p>
                  <VirtualCoinPrice amount={minimumAmount} size="xs" showLabel={false} />
                </div>
                <div className="flex items-center gap-1">
                  <p className="text-xs text-yellow-700">
                    {t('footer.missingPrefix')}
                  </p>
                  <VirtualCoinPrice amount={missingAmount} size="xs" showLabel={false} />
                  <p className="text-xs text-yellow-700">
                    {t('footer.missingSuffix')}
                  </p>
                </div>
              </div>
            )}
            
            <div className="flex flex-col space-y-2 mb-4">
              {meetsMinimum ? (
                <ScrollToTopLink 
                  to={localizedPath('/finalizar-retiro')} 
                  className="flex items-center justify-center rounded-md border border-transparent bg-primario px-4 py-2 text-sm font-medium text-white hover:bg-primario-dark hover:!text-white"
                  onClick={onClose}
                >
                  <FiCreditCard className="mr-1.5 w-4 h-4" />
                  {t('footer.checkout')}
                </ScrollToTopLink>
              ) : (
                <button
                  disabled
                  className="flex items-center justify-center rounded-md border border-transparent bg-gray-300 px-4 py-2 text-sm font-medium text-gray-500 cursor-not-allowed"
                  title={t('footer.checkoutDisabledTitle', { amount: missingAmount })}
                >
                  <FiCreditCard className="mr-1.5 w-4 h-4" />
                  {t('footer.checkout')}
                </button>
              )}
              <ScrollToTopLink 
                to={localizedPath('/reserva')} 
                className="flex items-center justify-center rounded-md border border-primario px-4 py-2 text-sm font-medium text-primario hover:bg-gray-100"
                onClick={onClose}
              >
                <FiShoppingCart className="mr-1.5 w-4 h-4" />
                {t('footer.viewCart')}
              </ScrollToTopLink>
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

export default CartModal;
