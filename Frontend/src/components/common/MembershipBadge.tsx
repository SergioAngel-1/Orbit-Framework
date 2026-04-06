/**
 * MembershipBadge - Componente reutilizable para mostrar membresía mínima requerida
 * Muestra icono de la membresía correspondiente al nivel
 * Al hacer clic abre un modal con el resumen del sistema de membresías
 * 
 * Los datos de niveles se obtienen dinámicamente desde la API
 * para evitar valores hardcoded y mantener consistencia con el backend.
 * 
 * OPTIMIZADO: Usa un modal singleton global para evitar múltiples instancias en el DOM
 */

import { FC, memo } from 'react';
import { fluidSizing } from '../../utils/fluidSizing';
import useMembershipLevels from '../../hooks/useMembershipLevels';

// Configuración de tamaños (constante fuera del componente para evitar recreación)
const BADGE_SIZES = {
  xs: {
    icon: fluidSizing.size.iconSm,
    container: fluidSizing.size.iconSm
  },
  sm: {
    icon: fluidSizing.size.iconMd,
    container: fluidSizing.size.buttonSm
  },
  md: {
    icon: fluidSizing.size.iconLg,
    container: fluidSizing.size.floatingButton
  },
  lg: {
    icon: fluidSizing.size.iconXl,
    container: fluidSizing.size.floatingButton
  }
} as const;

// Singleton para el modal - evita múltiples instancias en el DOM
let openMembershipModal: (() => void) | null = null;

/**
 * Registrar la función para abrir el modal (llamado desde MembershipLevelsModal)
 */
export const registerMembershipModalOpener = (opener: () => void) => {
  openMembershipModal = opener;
};

/**
 * Desregistrar la función (llamado cuando el modal se desmonta)
 */
export const unregisterMembershipModalOpener = () => {
  openMembershipModal = null;
};

/**
 * Abrir el modal de membresías desde cualquier componente
 * Útil para componentes que no son MembershipBadge pero necesitan abrir el modal
 */
export const openMembershipLevelsModal = () => {
  if (openMembershipModal) {
    openMembershipModal();
  }
};

interface MembershipBadgeProps {
  /** Nivel mínimo de membresía requerido (1-5) */
  level: number;
  /** Texto personalizado para el tooltip (opcional) */
  text?: string;
  /** Tamaño del badge */
  size?: 'xs' | 'sm' | 'md' | 'lg';
  /** Clase CSS adicional */
  className?: string;
  /** Deshabilitar el modal al hacer clic */
  disableModal?: boolean;
}

const MembershipBadge: FC<MembershipBadgeProps> = ({
  level,
  text,
  size = 'sm',
  className = '',
  disableModal = false
}) => {
  const { getLevelById, loading } = useMembershipLevels();

  // No mostrar nada si es nivel 0 (público) o nivel inválido
  if (level <= 0) return null;

  // Obtener datos del nivel desde la API
  const membership = getLevelById(level);
  
  // Mientras carga o si no hay datos, no mostrar nada
  if (loading || !membership || !membership.icon_url) return null;

  const currentSize = BADGE_SIZES[size] ?? BADGE_SIZES.sm;
  const displayText = text || `Desde ${membership.name}`;

  const handleClick = (e: React.MouseEvent) => {
    if (disableModal) return;
    e.preventDefault();
    e.stopPropagation();
    // Usar el modal singleton global
    if (openMembershipModal) {
      openMembershipModal();
    }
  };

  return (
    <span 
      className={`inline-flex items-center justify-center rounded-full overflow-hidden cursor-pointer hover:ring-2 hover:ring-primario/30 transition-all ${className}`}
      style={{ 
        width: currentSize.container, 
        height: currentSize.container,
        minWidth: currentSize.container,
        minHeight: currentSize.container
      }}
      title={displayText}
      onClick={handleClick}
      role="button"
      tabIndex={0}
      onKeyDown={(e) => e.key === 'Enter' && handleClick(e as any)}
    >
      <img 
        src={membership.icon_url} 
        alt={membership.name}
        className="object-cover rounded-full"
        style={{
          width: currentSize.container,
          height: currentSize.container
        }}
        loading="lazy"
      />
    </span>
  );
};

// Comparador personalizado para memo - solo re-renderizar si cambian props relevantes
export default memo(MembershipBadge, (prevProps, nextProps) => {
  return (
    prevProps.level === nextProps.level &&
    prevProps.size === nextProps.size &&
    prevProps.className === nextProps.className &&
    prevProps.disableModal === nextProps.disableModal
  );
});
