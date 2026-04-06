import React, { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import logger from '../../utils/logger';
import usePromotionalProducts from '../../hooks/usePromotionalProducts';
import usePromotionalCart from '../../hooks/usePromotionalCart';
import PromotionalProductCard from './PromotionalProductCard';
import CollapsibleSection from '../common/CollapsibleSection';
import MembershipBadge from '../common/MembershipBadge';
import { fluidSizing } from '../../utils/fluidSizing';
import Loader from '../ui/Loader';

interface PromotionalGridProps {
  categoryId?: number;
  displayAsRow?: boolean;
  hideHeader?: boolean;
  customTitle?: string;
}

/**
 * Componente de grid promocional que muestra productos destacados
 * Usa CollapsibleSection con variante soft para un diseño consistente
 * @param categoryId - ID opcional de la categoría para filtrar productos
 */
const PromotionalGrid: React.FC<PromotionalGridProps> = ({ categoryId, displayAsRow = false, customTitle }) => {
  const { t } = useTranslation('productDetailPage');
  // Validar explícitamente que categoryId sea un número válido
  const validCategoryId = categoryId !== undefined && categoryId !== null && !isNaN(Number(categoryId)) 
    ? Number(categoryId) 
    : undefined;
    
  // Log de depuración
  if (validCategoryId !== undefined) {
    logger.info('PromotionalGrid', `Renderizando grilla con categoryId validado: ${validCategoryId}`);
  } else if (categoryId !== undefined) {
    logger.warn('PromotionalGrid', `categoryId inválido proporcionado: ${categoryId}, usando grilla por defecto`);
  } else {
    logger.info('PromotionalGrid', 'Renderizando grilla sin categoryId (grilla por defecto)');
  }
  
  const { products, loading, error, gridTitle, gridMetadata } = usePromotionalProducts(validCategoryId);
  
  // Log adicional para mostrar detalles del tipo de grilla
  React.useEffect(() => {
    if (!loading) {
      logger.info('PromotionalGrid', 'Detalles de la grilla cargada:', {
        ...gridMetadata,
        productCount: products.length,
        categoryId: validCategoryId
      });
    }
  }, [loading, gridMetadata, products, validCategoryId]);
  
  // Usar el hook para manejar operaciones del carrito
  const { quantities, handleAddToCart, handleUpdateQuantity } = usePromotionalCart(products);

  // Animaciones con CSS
  useEffect(() => {
    if (!loading && products.length > 0) {
      const gridItems = document.querySelectorAll('.promo-grid-item');
      
      gridItems.forEach((item, index) => {
        const element = item as HTMLElement;
        element.style.opacity = '0';
        element.style.transform = 'translateX(5px)';
        
        setTimeout(() => {
          element.style.opacity = '1';
          element.style.transform = 'translateX(0)';
          element.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
        }, index * 80);
      });
    }
  }, [loading, products]);

  // Obtener nivel de membresía de la grilla
  const membershipLevel = gridMetadata?.membershipInfo?.minLevel;
  const title = customTitle || gridTitle || t('promotional.defaultTitle');

  // Badge de membresía para el header
  const headerBadge = membershipLevel !== undefined && membershipLevel > 0 ? (
    <MembershipBadge level={membershipLevel} size="xs" />
  ) : undefined;

  // Contenido de carga
  if (loading) {
    return (
      <CollapsibleSection
        title={title}
        variant="soft"
        collapsible={false}
        showCollapseButton={false}
        defaultExpanded={true}
        className="border-0 shadow-none"
        headerExtra={headerBadge}
      >
        <div className="flex justify-center items-center" style={{ padding: fluidSizing.space.lg }}>
          <Loader size="medium" text="" />
        </div>
      </CollapsibleSection>
    );
  }

  // Contenido de error
  if (error) {
    return (
      <CollapsibleSection
        title={title}
        variant="soft"
        collapsible={false}
        showCollapseButton={false}
        defaultExpanded={true}
        className="border-0 shadow-none"
        headerExtra={headerBadge}
      >
        <p 
          className="text-texto text-center"
          style={{ fontSize: fluidSizing.text.sm, padding: fluidSizing.space.md }}
        >
          {t('promotional.error')}
        </p>
      </CollapsibleSection>
    );
  }

  // Contenido vacío
  if (!products.length) {
    logger.warn('PromotionalGrid', `No hay productos para mostrar. Metadatos:`, gridMetadata);
    return (
      <CollapsibleSection
        title={title}
        variant="soft"
        collapsible={false}
        showCollapseButton={false}
        defaultExpanded={true}
        className="border-0 shadow-none"
        headerExtra={headerBadge}
      >
        <div className="flex flex-col items-center justify-center text-center" style={{ padding: fluidSizing.space.md }}>
          <svg xmlns="http://www.w3.org/2000/svg" className="h-10 w-10 text-gray-300 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M4 6h16M4 10h16M4 14h16M4 18h16" />
          </svg>
          <p className="text-texto" style={{ fontSize: fluidSizing.text.sm }}>
            {t('promotional.empty')}
          </p>
        </div>
      </CollapsibleSection>
    );
  }

  // Contenido con productos
  return (
    <CollapsibleSection
      title={title}
      variant="soft"
      collapsible={false}
      showCollapseButton={false}
      defaultExpanded={true}
      className="border-0 shadow-none"
      headerExtra={headerBadge}
    >
      <div 
        className={displayAsRow 
          ? "flex flex-col divide-y divide-gray-100" 
          : "grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3"
        }
        style={{ gap: displayAsRow ? 0 : fluidSizing.space.sm }}
      >
        {(displayAsRow ? products.slice(0, 3) : products).map(product => (
          <div 
            key={product.id} 
            className="promo-grid-item"
          >
            <PromotionalProductCard
              product={product}
              quantity={quantities[product.id] || 0}
              onAddToCart={handleAddToCart}
              onUpdateQuantity={handleUpdateQuantity}
              compact={displayAsRow}
            />
          </div>
        ))}
      </div>
    </CollapsibleSection>
  );
};

export default PromotionalGrid;