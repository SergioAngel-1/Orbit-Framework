/**
 * MembershipDiscountSection - Sección de descuento por membresía en checkout
 * 
 * Muestra el descuento aplicado por el beneficio de membresía del usuario.
 * Soporta dos tipos de descuento: categorías (fucsia) y eventos (violeta).
 * Usa CollapsibleSection con variantes para consistencia visual.
 */

import { useState, useRef, useEffect, ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { createPortal } from 'react-dom';
import { FiTag, FiHelpCircle, FiCalendar } from 'react-icons/fi';
import { MembershipDiscountSummary, ItemDiscount } from '../../hooks/useMembershipDiscount';
import { fluidSizing } from '../../utils/fluidSizing';
import MembershipBadge from '../common/MembershipBadge';
import CollapsibleSection from '../common/CollapsibleSection';
import VirtualCoinPrice from '../common/VirtualCoinPrice';
import { useMembership } from '../../contexts/MembershipContext';

interface MembershipDiscountSectionProps {
  discount: MembershipDiscountSummary;
  loading?: boolean;
  membershipName?: string;
  membershipColor?: string;
  /** Nombres de las categorías con descuento */
  categoryNames?: string[];
}

// Colores por tipo de descuento
const COLORS = {
  category: '#C72C6C', // Fucsia oscuro
  events: '#C72C6C'    // Fucsia oscuro
};

const MembershipDiscountSection = ({
  discount,
  loading = false,
  membershipName = 'Membresía',
  categoryNames = [],
}: MembershipDiscountSectionProps) => {
  const { t } = useTranslation('checkoutComponents');
  const { currentLevel } = useMembership();
  const [showTooltip, setShowTooltip] = useState(false);
  const buttonRef = useRef<HTMLButtonElement>(null);
  const [tooltipPosition, setTooltipPosition] = useState({ top: 0, left: 0 });

  // Calcular posición del tooltip cuando se abre
  useEffect(() => {
    if (showTooltip && buttonRef.current) {
      const rect = buttonRef.current.getBoundingClientRect();
      const tooltipWidth = Math.min(280, window.innerWidth - 32); // Max 280px o ancho de ventana - padding
      
      // Calcular left para que no se salga de la pantalla
      let left = rect.left;
      if (left + tooltipWidth > window.innerWidth - 16) {
        left = window.innerWidth - tooltipWidth - 16;
      }
      if (left < 16) {
        left = 16;
      }
      
      setTooltipPosition({
        top: rect.bottom + 8,
        left: left
      });
    }
  }, [showTooltip]);

  // Cerrar tooltip al hacer click fuera
  useEffect(() => {
    if (!showTooltip) return;
    
    const handleClickOutside = (e: MouseEvent) => {
      if (buttonRef.current && !buttonRef.current.contains(e.target as Node)) {
        setShowTooltip(false);
      }
    };
    
    document.addEventListener('click', handleClickOutside);
    return () => document.removeEventListener('click', handleClickOutside);
  }, [showTooltip]);

  // No mostrar si no hay descuento (verificar primero para evitar skeleton innecesario)
  if (!discount.hasDiscount && !loading) {
    return null;
  }

  // Mostrar skeleton solo si está cargando Y hay posibilidad de descuento
  // (el usuario tiene membresía con nivel > 0)
  if (loading && currentLevel > 0) {
    return (
      <div 
        className="bg-secundario/20 rounded-lg animate-pulse"
        style={{ padding: fluidSizing.space.md }}
      >
        <div className="flex items-center" style={{ gap: fluidSizing.space.sm }}>
          <div className="w-8 h-8 bg-secundario/40 rounded-full" />
          <div className="flex-1">
            <div className="h-4 bg-secundario/40 rounded w-32 mb-2" />
            <div className="h-3 bg-secundario/30 rounded w-24" />
          </div>
        </div>
      </div>
    );
  }

  // No mostrar si no hay descuento después de cargar
  if (!discount.hasDiscount) {
    return null;
  }

  // Separar items por tipo de descuento
  const categoryItems = discount.discountedItems.filter(item => item.discountType === 'category');
  const eventsItems = discount.discountedItems.filter(item => item.discountType === 'events');
  
  // Calcular totales por tipo
  const categoryTotal = categoryItems.reduce((sum, item) => sum + item.totalDiscount, 0);
  const eventsTotal = eventsItems.reduce((sum, item) => sum + item.totalDiscount, 0);

  // Componente para renderizar una sección de descuento
  const renderDiscountSection = (
    items: ItemDiscount[],
    type: 'category' | 'events',
    title: string,
    subtitle: ReactNode,
    totalAmount: number
  ) => {
    if (items.length === 0) return null;
    
    const color = COLORS[type];
    const variant = type === 'events' ? 'events' : 'default';
    const Icon = type === 'events' ? FiCalendar : FiTag;
    const maxPercentage = Math.max(...items.map(i => i.discountPercentage));

    const headerExtra = (
      <span 
        className="font-bold rounded-full"
        style={{ 
          fontSize: fluidSizing.text.xs,
          backgroundColor: 'white',
          color: color,
          paddingLeft: fluidSizing.space.sm,
          paddingRight: fluidSizing.space.sm,
          paddingTop: '2px',
          paddingBottom: '2px'
        }}
      >
        -{maxPercentage}%
      </span>
    );

    return (
      <CollapsibleSection
        title={title}
        subtitle={subtitle}
        variant={variant}
        collapsible={true}
        defaultExpanded={false}
        headerExtra={headerExtra}
      >
        {/* Badge de membresía del usuario */}
        <div className="flex items-center mb-4" style={{ gap: fluidSizing.space.sm }}>
          <MembershipBadge 
            level={currentLevel} 
            size="sm" 
            disableModal={true}
          />
          <div className="flex items-center relative" style={{ gap: fluidSizing.space.xs }}>
            <p 
              className="text-texto flex items-center"
              style={{ fontSize: fluidSizing.text.xs, gap: fluidSizing.space.xs }}
            >
              <Icon style={{ width: fluidSizing.size.iconXs, height: fluidSizing.size.iconXs }} />
              {type === 'events' 
                ? t('membershipDiscount.appliesToEvents') 
                : discount.appliesToAllCategories 
                  ? t('membershipDiscount.appliesToAll') 
                  : t('membershipDiscount.appliesToSelected')}
            </p>
            
            {/* Icono de ayuda con tooltip para mostrar categorías */}
            {type === 'category' && !discount.appliesToAllCategories && categoryNames.length > 0 && (
              <>
                <button
                  ref={buttonRef}
                  type="button"
                  className="text-primario/70 hover:text-primario transition-colors p-0 bg-transparent border-none cursor-pointer"
                  onClick={(e) => {
                    e.stopPropagation();
                    setShowTooltip(!showTooltip);
                  }}
                  aria-label={t('membershipDiscount.viewCategories')}
                >
                  <FiHelpCircle style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                </button>
                
                {/* Tooltip con Portal */}
                {showTooltip && createPortal(
                  <div 
                    className="fixed bg-white border border-secundario/30 rounded-lg shadow-xl"
                    style={{ 
                      top: tooltipPosition.top,
                      left: tooltipPosition.left,
                      padding: fluidSizing.space.md,
                      width: 'calc(100vw - 32px)',
                      maxWidth: '280px',
                      zIndex: 99999
                    }}
                  >
                    <p className="text-oscuro font-medium mb-2" style={{ fontSize: fluidSizing.text.xs }}>
                      {t('membershipDiscount.categoriesWithDiscount')}
                    </p>
                    <div className="flex flex-wrap" style={{ gap: fluidSizing.space.xs }}>
                      {categoryNames.map((name, index) => (
                        <span
                          key={index}
                          className="bg-primario/10 text-primario rounded-full"
                          style={{ 
                            fontSize: fluidSizing.text['2xs'],
                            paddingLeft: fluidSizing.space.sm,
                            paddingRight: fluidSizing.space.sm,
                            paddingTop: '2px',
                            paddingBottom: '2px'
                          }}
                        >
                          {name}
                        </span>
                      ))}
                    </div>
                  </div>,
                  document.body
                )}
              </>
            )}
          </div>
        </div>

        {/* Lista de productos con descuento */}
        <div className="space-y-2">
          {items.map((item: ItemDiscount) => (
            <div 
              key={`${item.productId}-${item.variationId || 0}`}
              className="bg-secundario/10 rounded-md"
              style={{ padding: fluidSizing.space.sm }}
            >
              {/* Nombre del producto */}
              <p 
                className="font-medium text-oscuro truncate mb-1"
                style={{ fontSize: fluidSizing.text.xs }}
              >
                {item.productName}
              </p>
              
              {/* Precios y descuento */}
              <div className="flex items-center justify-between">
                <div className="flex items-center flex-wrap" style={{ gap: fluidSizing.space.xs }}>
                  <span className="text-texto" style={{ fontSize: fluidSizing.text['2xs'] }}>
                    {item.quantity} x
                  </span>
                  <span className="text-texto/60 line-through" style={{ fontSize: fluidSizing.text['2xs'] }}>
                    <VirtualCoinPrice amount={item.originalPrice} size="xs" showLabel={false} />
                  </span>
                  <span className="text-texto" style={{ fontSize: fluidSizing.text['2xs'] }}>→</span>
                  <span style={{ color }}>
                    <VirtualCoinPrice amount={item.finalPrice} size="xs" showLabel={false} />
                  </span>
                </div>
                <div 
                  className="font-semibold flex-shrink-0 flex items-center"
                  style={{ fontSize: fluidSizing.text.xs, color, marginLeft: fluidSizing.space.sm }}
                >
                  <span style={{ marginRight: fluidSizing.space.xs }}>-</span>
                  <VirtualCoinPrice amount={item.totalDiscount} size="xs" showLabel={false} />
                </div>
              </div>
            </div>
          ))}
        </div>

        {/* Resumen total */}
        <div 
          className="flex items-center justify-between border-t mt-4 pt-3"
          style={{ borderColor: 'rgba(0,0,0,0.1)' }}
        >
          <span className="text-texto font-medium" style={{ fontSize: fluidSizing.text.sm }}>
            {type === 'events' ? t('membershipDiscount.totalDiscountEvents') : t('membershipDiscount.totalDiscountMembership')}
          </span>
          <div 
            className="font-bold flex items-center"
            style={{ fontSize: fluidSizing.text.base, color }}
          >
            <span style={{ marginRight: fluidSizing.space.xs }}>-</span>
            <VirtualCoinPrice amount={totalAmount} size="sm" showLabel={false} />
          </div>
        </div>
      </CollapsibleSection>
    );
  };

  return (
    <div className="space-y-3">
      {/* Sección de descuento por categorías */}
      {renderDiscountSection(
        categoryItems,
        'category',
        t('membershipDiscount.discountTitle', { name: membershipName }),
        <span className="flex items-center gap-1">{categoryItems.length} {categoryItems.length === 1 ? t('membershipDiscount.product') : t('membershipDiscount.products')} · <span className="inline-flex items-center">-<VirtualCoinPrice amount={categoryTotal} size="xs" showLabel={false} /></span></span>,
        categoryTotal
      )}
      
      {/* Sección de descuento por eventos */}
      {renderDiscountSection(
        eventsItems,
        'events',
        t('membershipDiscount.eventsTitle'),
        <span className="flex items-center gap-1">{eventsItems.length} {eventsItems.length === 1 ? t('membershipDiscount.product') : t('membershipDiscount.products')} · <span className="inline-flex items-center">-<VirtualCoinPrice amount={eventsTotal} size="xs" showLabel={false} /></span></span>,
        eventsTotal
      )}
    </div>
  );
};

export default MembershipDiscountSection;
