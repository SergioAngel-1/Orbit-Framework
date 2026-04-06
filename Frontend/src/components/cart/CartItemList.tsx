/**
 * CartItemList - Componente para mostrar la lista de productos en el carrito
 * Usa CollapsibleSection y diseño de tabla responsive
 */

import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FiTrash2 } from 'react-icons/fi';
import { useLanguage } from '../../contexts/LanguageContext';
import { useMembership } from '../../contexts/MembershipContext';
import useMembershipLevels from '../../hooks/useMembershipLevels';
import { CartItem } from '../../types/woocommerce';
import { fluidSizing } from '../../utils/fluidSizing';
import { buildProductUrl } from '../../utils/membershipRouteUtils';
import CollapsibleSection from '../common/CollapsibleSection';
import VirtualCoinPrice from '../common/VirtualCoinPrice';
import MembershipBadge from '../common/MembershipBadge';
import QuantityCounter from '../common/QuantityCounter';
import ScrollToTopLink from '../common/ScrollToTopLink';

interface CartItemListProps {
  items: CartItem[];
  onRemoveItem: (productId: number, variationId?: number) => void;
  onClearCart: () => void;
}

const CartItemList: React.FC<CartItemListProps> = ({
  items,
  onRemoveItem,
  onClearCart
}) => {
  const { t } = useTranslation('cartPage');
  const { localizedPath } = useLanguage();
  const { levels } = useMembershipLevels();

  // Usar el contexto de membresía para obtener niveles con caché
  const { getCategoryMembershipLevel } = useMembership();
  
  // Estado para almacenar los niveles de membresía requeridos por producto
  const [productMembershipLevels, setProductMembershipLevels] = useState<Record<string, number>>({});

  // Obtener niveles de membresía para cada producto del carrito
  useEffect(() => {
    const fetchMembershipLevels = async () => {
      const levels: Record<string, number> = {};
      
      for (const item of items) {
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
    
    if (items.length > 0) {
      fetchMembershipLevels();
    }
  }, [items, getCategoryMembershipLevel]);

  // Helper para obtener precio del item
  const getItemPrice = (item: CartItem) => {
    const hasVar = !!item.variation_id && (item as any).variation;
    const unitPrice = hasVar 
      ? parseFloat((item as any).variation?.price || '0') 
      : parseFloat(item.product?.price || '0');
    const regularPrice = hasVar 
      ? parseFloat((item as any).variation?.regular_price || '0') 
      : parseFloat(item.product?.regular_price || '0');
    const onSale = hasVar 
      ? (regularPrice > 0 && regularPrice > unitPrice) 
      : !!item.product?.on_sale;
    
    return { unitPrice, regularPrice, onSale };
  };

  // Helper para obtener variación
  const getVariationText = (item: CartItem) => {
    if (!item.variation_id || !(item as any).variation?.attributes?.length) return null;
    try {
      return ((item as any).variation.attributes as any[])
        .map((a: any) => a?.option)
        .filter((v: any) => !!v)
        .join(' · ');
    } catch {
      return null;
    }
  };

  // Helper para obtener stock disponible del item
  const getItemStock = (item: CartItem): number | undefined => {
    // Si es variación, usar stock de la variación
    if (item.variation_id && (item as any).variation) {
      const variationStock = (item as any).variation?.stock_quantity;
      if (variationStock !== undefined && variationStock !== null) {
        return variationStock;
      }
    }
    // Si no, usar stock del producto
    return item.product?.stock_quantity ?? undefined;
  };

  return (
    <CollapsibleSection
      title={t('itemList.title')}
      collapsible={false}
      showCollapseButton={false}
    >
      {/* Header de tabla - solo desktop */}
      <div 
        className="hidden sm:grid sm:grid-cols-12 border-b border-secundario/30 text-texto font-medium"
        style={{ 
          padding: `${fluidSizing.space.sm} ${fluidSizing.space.md}`,
          fontSize: fluidSizing.text.xs,
          marginBottom: fluidSizing.space.sm
        }}
      >
        <div className="sm:col-span-6">{t('itemList.colProduct')}</div>
        <div className="sm:col-span-2 text-center">{t('itemList.colPrice')}</div>
        <div className="sm:col-span-3 text-center">{t('itemList.colQuantity')}</div>
        <div className="sm:col-span-1"></div>
      </div>

      {/* Lista de items */}
      <div className="divide-y divide-secundario/20">
        {items.map((item) => {
          const { unitPrice, regularPrice, onSale } = getItemPrice(item);
          const variationText = getVariationText(item);
          const imageUrl = item.product?.images?.[0]?.src || '/wp-content/themes/Starter/assets/img/no-image.svg';
          const productName = item.product?.name || t('itemList.noName');
          const categoryName = item.product?.categories?.[0]?.name || t('itemList.noCategory');
          const productUrl = item.product?.slug 
            ? localizedPath(buildProductUrl(item.product?.categories?.[0]?.slug, item.product.slug, (item.product?.categories?.[0] as any)?.min_membership_level ?? 0, levels))
            : null;

          return (
            <div 
              key={`${item.id}-${item.variation_id || 'none'}`}
              className="hover:bg-secundario/5 transition-colors"
              style={{ padding: fluidSizing.space.sm }}
            >
              {/* Vista móvil */}
              <div className="sm:hidden">
                <div className="flex" style={{ gap: fluidSizing.space.sm }}>
                  {/* Imagen */}
                  {productUrl ? (
                    <ScrollToTopLink
                      to={productUrl}
                      className="flex-shrink-0 overflow-hidden rounded-lg border border-secundario/30"
                      style={{ width: '5rem', height: '5rem' }}
                    >
                      <img
                        src={imageUrl}
                        alt={productName}
                        className="h-full w-full object-cover"
                      />
                    </ScrollToTopLink>
                  ) : (
                    <div 
                      className="flex-shrink-0 overflow-hidden rounded-lg border border-secundario/30"
                      style={{ width: '5rem', height: '5rem' }}
                    >
                      <img
                        src={imageUrl}
                        alt={productName}
                        className="h-full w-full object-cover"
                      />
                    </div>
                  )}
                  
                  {/* Info */}
                  <div className="flex-1 min-w-0">
                    {productUrl ? (
                      <ScrollToTopLink to={productUrl}>
                        <h3 
                          className="font-medium text-oscuro line-clamp-2 hover:text-primario transition-colors"
                          style={{ fontSize: fluidSizing.text.sm }}
                        >
                          {productName}
                        </h3>
                      </ScrollToTopLink>
                    ) : (
                      <h3 
                        className="font-medium text-oscuro line-clamp-2"
                        style={{ fontSize: fluidSizing.text.sm }}
                      >
                        {productName}
                      </h3>
                    )}
                    <div className="flex items-center" style={{ gap: fluidSizing.space.xs }}>
                      <span className="text-texto/70" style={{ fontSize: fluidSizing.text.xs }}>{categoryName}</span>
                      {productMembershipLevels[`${item.id}-${item.variation_id || 'none'}`] > 0 && (
                        <MembershipBadge 
                          level={productMembershipLevels[`${item.id}-${item.variation_id || 'none'}`]} 
                          size="xs" 
                        />
                      )}
                    </div>
                    {variationText && (
                      <span 
                        className="inline-block text-texto bg-secundario/20 rounded-full mt-1"
                        style={{ fontSize: fluidSizing.text.xs, padding: `2px ${fluidSizing.space.sm}` }}
                      >
                        {variationText}
                      </span>
                    )}
                    {/* Precio */}
                    <div className="flex items-center mt-1" style={{ gap: fluidSizing.space.xs }}>
                      <VirtualCoinPrice amount={unitPrice} size="sm" showLabel={false} />
                      <span className="text-texto/70" style={{ fontSize: fluidSizing.text.xs }}>x {item.quantity}</span>
                    </div>
                    
                    {/* Contador y botón eliminar */}
                    <div className="flex items-center justify-between" style={{ marginTop: fluidSizing.space.sm, gap: fluidSizing.space.md }}>
                      <QuantityCounter 
                        productId={item.id || 0}
                        variationId={item.variation_id}
                        quantity={item.quantity || 1}
                        productName={productName}
                        size="sm"
                        showRemoveButton={false}
                        maxQuantity={getItemStock(item)}
                      />
                      <button
                        onClick={() => onRemoveItem(item.id, item.variation_id)}
                        className="text-primario hover:text-hover transition-colors !bg-transparent !border-none !p-0"
                        aria-label={t('itemList.removeAria')}
                      >
                        <FiTrash2 style={{ width: fluidSizing.size.iconMd, height: fluidSizing.size.iconMd }} />
                      </button>
                    </div>
                  </div>
                </div>
              </div>

              {/* Vista desktop */}
              <div className="hidden sm:grid sm:grid-cols-12 items-center" style={{ gap: fluidSizing.space.sm }}>
                {/* Producto */}
                <div className="sm:col-span-6 flex items-center" style={{ gap: fluidSizing.space.md }}>
                  {productUrl ? (
                    <ScrollToTopLink
                      to={productUrl}
                      className="flex-shrink-0 overflow-hidden rounded-lg border border-secundario/30"
                      style={{ width: '3.5rem', height: '3.5rem' }}
                    >
                      <img
                        src={imageUrl}
                        alt={productName}
                        className="h-full w-full object-cover"
                      />
                    </ScrollToTopLink>
                  ) : (
                    <div 
                      className="flex-shrink-0 overflow-hidden rounded-lg border border-secundario/30"
                      style={{ width: '3.5rem', height: '3.5rem' }}
                    >
                      <img
                        src={imageUrl}
                        alt={productName}
                        className="h-full w-full object-cover"
                      />
                    </div>
                  )}
                  <div className="flex-1 min-w-0">
                    {productUrl ? (
                      <ScrollToTopLink to={productUrl}>
                        <h3 
                          className="font-medium text-oscuro line-clamp-1 hover:text-primario transition-colors"
                          style={{ fontSize: fluidSizing.text.sm }}
                        >
                          {productName}
                        </h3>
                      </ScrollToTopLink>
                    ) : (
                      <h3 
                        className="font-medium text-oscuro line-clamp-1"
                        style={{ fontSize: fluidSizing.text.sm }}
                      >
                        {productName}
                      </h3>
                    )}
                    <div className="flex items-center" style={{ gap: fluidSizing.space.xs }}>
                      <span className="text-texto/70" style={{ fontSize: fluidSizing.text.xs }}>{categoryName}</span>
                      {productMembershipLevels[`${item.id}-${item.variation_id || 'none'}`] > 0 && (
                        <MembershipBadge 
                          level={productMembershipLevels[`${item.id}-${item.variation_id || 'none'}`]} 
                          size="xs" 
                        />
                      )}
                    </div>
                    {variationText && (
                      <span 
                        className="inline-block text-texto bg-secundario/20 rounded-full"
                        style={{ fontSize: fluidSizing.text.xs, padding: `2px ${fluidSizing.space.sm}` }}
                      >
                        {variationText}
                      </span>
                    )}
                  </div>
                </div>
                
                {/* Precio */}
                <div className="sm:col-span-2 flex flex-col items-center justify-center">
                  <VirtualCoinPrice amount={unitPrice} size="sm" showLabel={false} />
                  {onSale && regularPrice > unitPrice && (
                    <VirtualCoinPrice amount={regularPrice} size="xs" showLabel={false} className="line-through text-texto/50" />
                  )}
                </div>
                
                {/* Cantidad */}
                <div className="sm:col-span-3 flex justify-center">
                  <QuantityCounter 
                    productId={item.id || 0}
                    variationId={item.variation_id}
                    quantity={item.quantity || 1}
                    productName={productName}
                    size="sm"
                    showRemoveButton={false}
                    maxQuantity={getItemStock(item)}
                  />
                </div>
                
                {/* Eliminar */}
                <div className="sm:col-span-1 flex justify-end">
                  <button
                    onClick={() => onRemoveItem(item.id, item.variation_id)}
                    className="text-primario hover:text-hover transition-colors !bg-transparent !border-none !p-0"
                    aria-label={t('itemList.removeAria')}
                  >
                    <FiTrash2 style={{ width: fluidSizing.size.iconMd, height: fluidSizing.size.iconMd }} />
                  </button>
                </div>
              </div>
            </div>
          );
        })}
      </div>

      {/* Footer con acciones */}
      <div 
        className="flex flex-col sm:flex-row sm:justify-between sm:items-center border-t border-secundario/30"
        style={{ 
          marginTop: fluidSizing.space.md,
          paddingTop: fluidSizing.space.md,
          gap: fluidSizing.space.sm
        }}
      >
        <button
          onClick={onClearCart}
          className="bg-primario text-white hover:bg-hover transition-colors rounded-full flex items-center justify-center"
          style={{ 
            padding: `${fluidSizing.space.sm} ${fluidSizing.space.md}`,
            fontSize: fluidSizing.text.sm,
            gap: fluidSizing.space.xs
          }}
        >
          <FiTrash2 style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
          {t('itemList.clearCart')}
        </button>
        
        <ScrollToTopLink
          to={localizedPath('/catalogo')}
          className="bg-secundario/20 text-oscuro hover:bg-secundario/40 rounded-full transition-colors flex items-center justify-center"
          style={{ 
            padding: `${fluidSizing.space.sm} ${fluidSizing.space.md}`,
            fontSize: fluidSizing.text.sm
          }}
        >
          {t('itemList.continueShopping')}
        </ScrollToTopLink>
      </div>
    </CollapsibleSection>
  );
};

export default CartItemList;
