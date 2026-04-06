/**
 * CollapsibleSection - Componente reutilizable para secciones colapsables
 * Header con gradiente, contenido colapsado con resumen, y contenido expandido
 * Usa fluidSizing y paleta de colores del tema
 */

import { FC, ReactNode, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FiChevronDown } from 'react-icons/fi';
import { IconType } from 'react-icons';
import { fluidSizing } from '../../utils/fluidSizing';

interface CollapsibleSectionProps {
  /** Título de la sección */
  title: string;
  /** Subtítulo opcional */
  subtitle?: ReactNode;
  /** Icono del header (opcional en variante soft) */
  icon?: IconType;
  /** Contenido principal (cuando está expandido) */
  children: ReactNode;
  /** Texto del resumen cuando está colapsado */
  collapsedSummary?: string;
  /** Texto del botón para expandir */
  expandButtonText?: string;
  /** Estado inicial (expandido o colapsado) - solo se usa si expanded no está definido */
  defaultExpanded?: boolean;
  /** Estado controlado externamente */
  expanded?: boolean;
  /** Callback cuando cambia el estado */
  onExpandedChange?: (expanded: boolean) => void;
  /** Si se puede colapsar */
  collapsible?: boolean;
  /** Si se muestra el botón de colapsar (por defecto true si collapsible es true) */
  showCollapseButton?: boolean;
  /** ID para anclas */
  id?: string;
  /** Clase CSS adicional */
  className?: string;
  /** Contenido extra en el header (en stacked: fila inferior; en inline: lado derecho) */
  headerExtra?: ReactNode;
  /** Contenido esquina superior derecha del header (ej: badge de estado). Solo visible en stacked. */
  headerRight?: ReactNode;
  /** Variante de estilo: 'default' (gradiente fucsia), 'soft' (blanco), 'events' (gradiente violeta), 'banner' (glassmorphism oscuro) */
  variant?: 'default' | 'soft' | 'events' | 'banner';
  /** Layout del header: 'inline' (todo en una fila), 'stacked' (título arriba, headerExtra+chevron abajo) */
  headerLayout?: 'inline' | 'stacked';
}

const CollapsibleSection: FC<CollapsibleSectionProps> = ({
  title,
  subtitle,
  icon: Icon,
  children,
  collapsedSummary,
  expandButtonText,
  defaultExpanded = true,
  expanded,
  onExpandedChange,
  collapsible = true,
  showCollapseButton,
  id,
  className = '',
  headerExtra,
  headerRight,
  variant = 'default',
  headerLayout = 'inline'
}) => {
  const { t } = useTranslation('uiComponents');
  // Por defecto, mostrar botón si es colapsable
  const shouldShowCollapseButton = showCollapseButton !== undefined ? showCollapseButton : collapsible;
  const resolvedExpandButtonText = expandButtonText ?? t('collapsibleSection.expandButton');
  const isSoft = variant === 'soft';
  const isEvents = variant === 'events';
  const isBanner = variant === 'banner';
  // Solo mostrar icono en variantes con fondo de color (default, events, banner), no en soft
  const shouldShowIcon = Icon && !isSoft;
  const [internalExpanded, setInternalExpanded] = useState(defaultExpanded);
  
  // Usar estado controlado si se proporciona, sino usar estado interno
  const isExpanded = expanded !== undefined ? expanded : internalExpanded;
  
  const setIsExpanded = (value: boolean) => {
    if (expanded !== undefined && onExpandedChange) {
      onExpandedChange(value);
    } else {
      setInternalExpanded(value);
    }
  };

  return (
    <section 
      id={id}
      className={`rounded-lg overflow-hidden ${
        isBanner
          ? 'border border-white/15 shadow-2xl'
          : 'bg-white shadow-sm border border-gray-100'
      } ${className}`}
      style={isBanner ? {
        background: 'rgba(15, 10, 25, 0.6)',
        backdropFilter: 'blur(20px)',
        WebkitBackdropFilter: 'blur(20px)',
      } : undefined}
    >
      {/* Header */}
      <div
        className={`flex flex-wrap items-center justify-between ${
          isBanner
            ? 'bg-white/10 border-b border-white/10'
            : isSoft 
              ? 'border-b border-gray-100' 
              : isEvents
                ? 'bg-gradient-to-r from-primario to-hover'
                : 'bg-gradient-to-r from-primario to-hover'
        } ${collapsible ? 'cursor-pointer' : ''}`}
        style={{ padding: fluidSizing.space.md, gap: headerLayout === 'stacked' ? fluidSizing.space.xs : fluidSizing.space.sm }}
        onClick={collapsible ? () => setIsExpanded(!isExpanded) : undefined}
        aria-expanded={isExpanded}
        role={collapsible ? 'button' : undefined}
        tabIndex={collapsible ? 0 : undefined}
        onKeyDown={collapsible ? (e) => e.key === 'Enter' && setIsExpanded(!isExpanded) : undefined}
      >
        {headerLayout === 'inline' ? (
          /* INLINE: todo en una sola fila */
          <>
            <div className="flex items-center min-w-0 flex-1" style={{ gap: fluidSizing.space.sm }}>
              {shouldShowIcon && (
                <div 
                  className={`rounded-full flex items-center justify-center flex-shrink-0 ${isBanner ? 'bg-white/15' : 'bg-white/20'}`}
                  style={{ width: fluidSizing.size.buttonSm, height: fluidSizing.size.buttonSm }}
                >
                  <Icon className="text-white" style={{ width: fluidSizing.size.iconMd, height: fluidSizing.size.iconMd }} />
                </div>
              )}
              <div className="min-w-0">
                <h2 
                  className={`font-semibold ${isSoft ? 'text-oscuro' : 'text-white'} ${isExpanded ? '' : 'truncate'} ${isBanner ? 'drop-shadow-sm' : ''}`} 
                  style={{ fontSize: fluidSizing.text.lg }}
                >
                  {title}
                </h2>
                {subtitle && (
                  <div className={`${isSoft ? 'text-texto' : 'text-white/70'}`} style={{ fontSize: fluidSizing.text.xs }}>{subtitle}</div>
                )}
              </div>
            </div>
            
            <div className="flex items-center flex-shrink-0" style={{ gap: fluidSizing.space.sm }}>
              {headerExtra}
              {shouldShowCollapseButton && (
                <button
                  className={`transition-colors focus:outline-none rounded-full flex-shrink-0 ${
                    isSoft 
                      ? 'text-primario hover:text-hover bg-secundario/30 hover:bg-secundario/50' 
                      : 'text-white hover:text-white/80 bg-white/20 hover:bg-white/30'
                  }`}
                  style={{ padding: fluidSizing.space.xs }}
                  aria-label={isExpanded ? t('collapsibleSection.collapse') : t('collapsibleSection.expand')}
                  onClick={(e) => {
                    e.stopPropagation();
                    setIsExpanded(!isExpanded);
                  }}
                >
                  <FiChevronDown
                    className={`transition-transform duration-300 ${isExpanded ? 'rotate-180' : ''}`}
                    style={{ width: fluidSizing.size.iconMd, height: fluidSizing.size.iconMd }}
                  />
                </button>
              )}
            </div>
          </>
        ) : (
          /* STACKED: Row 1 = título, Row 2 = subtitle + extras + chevron */
          <>
            {/* Row 1: Icon + Title + headerRight (top-right) */}
            <div className="flex items-center justify-between w-full" style={{ gap: fluidSizing.space.sm }}>
              <div className="flex items-center min-w-0 flex-1" style={{ gap: fluidSizing.space.sm }}>
                {shouldShowIcon && (
                  <div 
                    className={`rounded-full flex items-center justify-center flex-shrink-0 ${isBanner ? 'bg-white/15' : 'bg-white/20'}`}
                    style={{ width: fluidSizing.size.buttonSm, height: fluidSizing.size.buttonSm }}
                  >
                    <Icon className="text-white" style={{ width: fluidSizing.size.iconMd, height: fluidSizing.size.iconMd }} />
                  </div>
                )}
                <h2 
                  className={`font-semibold min-w-0 ${isSoft ? 'text-oscuro' : 'text-white'} ${isBanner ? 'drop-shadow-sm' : ''}`} 
                  style={{ fontSize: fluidSizing.text.lg }}
                >
                  {title}
                </h2>
              </div>
              {headerRight && (
                <div className="flex-shrink-0">{headerRight}</div>
              )}
            </div>

            {/* Row 2: subtitle + headerExtra + chevron */}
            <div className="flex items-center justify-between w-full" style={{ gap: fluidSizing.space.xs }}>
              <div className="flex items-center flex-wrap flex-1 min-w-0" style={{ gap: fluidSizing.space.xs }}>
                {subtitle && (
                  <div className={`${isSoft ? 'text-texto' : 'text-white/70'}`} style={{ fontSize: fluidSizing.text.xs }}>{subtitle}</div>
                )}
                {headerExtra}
              </div>
              {shouldShowCollapseButton && (
                <button
                  className={`transition-colors focus:outline-none rounded-full flex-shrink-0 ${
                    isSoft 
                      ? 'text-primario hover:text-hover bg-secundario/30 hover:bg-secundario/50' 
                      : 'text-white hover:text-white/80 bg-white/20 hover:bg-white/30'
                  }`}
                  style={{ padding: fluidSizing.space.xs }}
                  aria-label={isExpanded ? t('collapsibleSection.collapse') : t('collapsibleSection.expand')}
                  onClick={(e) => {
                    e.stopPropagation();
                    setIsExpanded(!isExpanded);
                  }}
                >
                  <FiChevronDown
                    className={`transition-transform duration-300 ${isExpanded ? 'rotate-180' : ''}`}
                    style={{ width: fluidSizing.size.iconMd, height: fluidSizing.size.iconMd }}
                  />
                </button>
              )}
            </div>
          </>
        )}
      </div>

      {/* Contenido colapsado - resumen */}
      {collapsible && !isExpanded && collapsedSummary && (
        <div 
          className="flex flex-col sm:flex-row sm:items-center sm:justify-between bg-secundario/20"
          style={{ padding: fluidSizing.space.md, gap: fluidSizing.space.sm }}
        >
          <p className="text-texto" style={{ fontSize: fluidSizing.text.sm }}>
            {collapsedSummary}
          </p>
          <button
            onClick={() => setIsExpanded(true)}
            className="w-full sm:w-auto text-center text-primario hover:text-hover font-medium transition-colors flex-shrink-0"
            style={{ fontSize: fluidSizing.text.sm }}
          >
            {resolvedExpandButtonText}
          </button>
        </div>
      )}

      {/* Contenido expandido */}
      <div
        className={`transition-all duration-300 ease-in-out overflow-hidden ${
          isExpanded ? 'max-h-[5000px] opacity-100' : 'max-h-0 opacity-0'
        } ${isBanner ? 'text-white/90' : ''}`}
        style={{ padding: isExpanded ? fluidSizing.space.lg : 0 }}
      >
        {children}
      </div>
    </section>
  );
};

export default CollapsibleSection;
