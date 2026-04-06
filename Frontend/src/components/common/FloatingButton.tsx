/**
 * FloatingButton - Botón flotante reutilizable
 * 
 * Base visual compartida para todos los botones flotantes (Instagram, Order Confirm, etc.).
 * Maneja: posición fixed, gradient, border, shadow, icono, tooltip (mobile + desktop),
 * animación de entrada, safe-area iOS y sizing fluido.
 * 
 * Puede renderizarse como <a> (link externo) o <button> (acción).
 */

import { useState, useEffect, useRef, type ReactNode } from 'react';
import { fluidSizing } from '../../utils/fluidSizing';

interface FloatingButtonBaseProps {
  /** Icono React a renderizar dentro del botón */
  icon: ReactNode;
  /** Contenido del tooltip (mobile y desktop) */
  tooltip?: ReactNode;
  /** Badge superpuesto (esquina superior derecha) */
  badge?: ReactNode;
  /** aria-label para accesibilidad */
  ariaLabel: string;
  /** Si true, el botón es visible (controla animación de entrada) */
  visible?: boolean;
  /** Delay en ms antes de mostrar la animación de entrada (default: 500) */
  entryDelay?: number;
  /** Duración en ms del tooltip mobile antes de ocultarse (default: 10000) */
  mobileTooltipDuration?: number;
  /** Estilos CSS adicionales para posición (bottom, right, top, left) */
  position: React.CSSProperties;
  /** Clase extra para el contenedor */
  className?: string;
}

interface FloatingButtonLinkProps extends FloatingButtonBaseProps {
  /** URL para renderizar como <a> */
  href: string;
  onClick?: never;
}

interface FloatingButtonActionProps extends FloatingButtonBaseProps {
  /** Handler para renderizar como <button> */
  onClick: () => void;
  href?: never;
}

type FloatingButtonProps = FloatingButtonLinkProps | FloatingButtonActionProps;

const FloatingButton = ({
  icon,
  tooltip,
  badge,
  ariaLabel,
  visible: externalVisible,
  entryDelay = 500,
  mobileTooltipDuration = 10000,
  position,
  className = '',
  ...rest
}: FloatingButtonProps) => {
  const [isVisible, setIsVisible] = useState(false);
  const [mobileTooltipActive, setMobileTooltipActive] = useState(false);
  const [mobileTooltipDismissed, setMobileTooltipDismissed] = useState(false);

  // Detectar mobile UNA SOLA VEZ al mount
  const isMobileRef = useRef(window.innerWidth < 1024);
  const isMobile = isMobileRef.current;

  // Animación de entrada
  useEffect(() => {
    // Si se controla externamente y no es visible, ocultar
    if (externalVisible === false) {
      setIsVisible(false);
      return;
    }

    const entryTimer = setTimeout(() => {
      setIsVisible(true);
      if (isMobile && tooltip) {
        setMobileTooltipActive(true);
      }
    }, entryDelay);

    return () => clearTimeout(entryTimer);
  }, [externalVisible, entryDelay, isMobile, tooltip]);

  // Timer para ocultar tooltip en mobile
  useEffect(() => {
    if (!mobileTooltipActive || !isMobile) return;

    const hideTimer = setTimeout(() => {
      setMobileTooltipActive(false);
      setMobileTooltipDismissed(true);
    }, mobileTooltipDuration);

    return () => clearTimeout(hideTimer);
  }, [mobileTooltipActive, isMobile, mobileTooltipDuration]);

  const sharedClassName = `fixed z-40 flex items-center justify-center rounded-full bg-gradient-to-br from-primario to-oscuro text-white shadow-lg hover:shadow-xl hover:scale-105 active:scale-95 group border-2 border-white/20 transition-all duration-500 p-0 ${
    isVisible ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'
  } ${className}`;

  const sharedStyle: React.CSSProperties = {
    width: fluidSizing.size.floatingButton,
    height: fluidSizing.size.floatingButton,
    minWidth: 0,
    boxSizing: 'border-box',
    boxShadow: '0 6px 20px rgba(185, 30, 89, 0.35)',
    WebkitTapHighlightColor: 'transparent',
    WebkitTouchCallout: 'none',
    touchAction: 'manipulation',
    transform: 'translateZ(0)',
    willChange: 'transform, opacity',
    ...position,
  };

  const iconElement = (
    <span
      className="text-white transition-all duration-300 flex items-center justify-center"
      style={{
        width: fluidSizing.size.floatingIcon,
        height: fluidSizing.size.floatingIcon,
      }}
    >
      {icon}
    </span>
  );

  const badgeElement = badge ? (
    <span className="absolute top-0 right-0 pointer-events-none">
      {badge}
    </span>
  ) : null;

  const tooltipMobile = tooltip && isMobile && !mobileTooltipDismissed ? (
    <span
      className={`absolute right-full px-3 py-1.5 bg-oscuro text-white font-medium rounded-lg transition-opacity duration-300 pointer-events-none border border-white/20 text-center whitespace-nowrap ${
        mobileTooltipActive ? 'opacity-100' : 'opacity-0'
      }`}
      style={{
        marginRight: fluidSizing.space.md,
        fontSize: fluidSizing.text.sm,
        lineHeight: '1.4',
      }}
    >
      {tooltip}
    </span>
  ) : null;

  const tooltipDesktop = tooltip && !isMobile ? (
    <span
      className="absolute right-full px-3 py-1.5 bg-oscuro text-white font-medium rounded-lg transition-opacity duration-300 pointer-events-none border border-white/20 text-center whitespace-nowrap opacity-0 group-hover:opacity-100"
      style={{
        marginRight: fluidSizing.space.md,
        fontSize: fluidSizing.text.sm,
        lineHeight: '1.4',
      }}
    >
      {tooltip}
    </span>
  ) : null;

  const children = (
    <>
      {iconElement}
      {badgeElement}
      {tooltipMobile}
      {tooltipDesktop}
    </>
  );

  // Renderizar como <a> o <button>
  if ('href' in rest && rest.href) {
    return (
      <a
        href={rest.href}
        target="_blank"
        rel="noopener noreferrer"
        className={sharedClassName}
        aria-label={ariaLabel}
        style={sharedStyle}
      >
        {children}
      </a>
    );
  }

  return (
    <button
      type="button"
      onClick={(rest as FloatingButtonActionProps).onClick}
      className={sharedClassName}
      aria-label={ariaLabel}
      style={sharedStyle}
    >
      {children}
    </button>
  );
};

export default FloatingButton;
