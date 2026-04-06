import React from 'react';
import VerMasButton from '../ui/VerMasButton';
import MembershipBadge from '../common/MembershipBadge';
import { fluidSizing } from '../../utils/fluidSizing';
import useMembershipLevels from '../../hooks/useMembershipLevels';
import { buildCatalogUrl } from '../../utils/membershipRouteUtils';

interface SectionHeaderProps {
  title: string;
  subtitle?: string;
  categorySlug?: string;
  isCompact?: boolean;
  /** Nivel mínimo de membresía requerido para esta sección */
  minMembershipLevel?: number;
}

// Estilos fluidos precalculados (fuera del render para evitar recreación)
const styles = {
  container: {
    gap: fluidSizing.space.md,
    marginBottom: fluidSizing.space.lg,
  },
  titleRow: {
    gap: fluidSizing.space.sm,
  },
  title: {
    fontSize: fluidSizing.text['2xl'],
    lineHeight: 1.2,
  },
  titleCompact: {
    fontSize: fluidSizing.text.xl,
    lineHeight: 1.25,
  },
  subtitle: {
    fontSize: fluidSizing.text.sm,
    marginTop: fluidSizing.space.xs,
    lineHeight: 1.5,
  },
} as const;

/**
 * Componente que muestra el encabezado de una sección con título, subtítulo opcional,
 * badge de membresía mínima y un botón "Ver más" si se proporciona un categorySlug
 */
const SectionHeader: React.FC<SectionHeaderProps> = ({ 
  title, 
  subtitle, 
  categorySlug,
  isCompact = false,
  minMembershipLevel
}) => {
  const { levels } = useMembershipLevels();
  const titleStyle = isCompact ? styles.titleCompact : styles.title;

  return (
    <div 
      className="flex flex-row items-center justify-between w-full"
      style={styles.container}
    >
      <div className="flex-1 min-w-0">
        <div 
          className="flex flex-wrap items-center"
          style={styles.titleRow}
        >
          {minMembershipLevel !== undefined && minMembershipLevel > 0 && (
            <MembershipBadge level={minMembershipLevel} size="sm" />
          )}
          <h2 
            className="font-bold text-primario break-words"
            style={titleStyle}
          >
            {title}
          </h2>
        </div>
        {subtitle && (
          <p 
            className="text-gray-600 break-words line-clamp-2"
            style={styles.subtitle}
          >
            {subtitle}
          </p>
        )}
      </div>
      
      {/* Botón "Ver más" para todas las secciones (visible en móvil y desktop) */}
      {categorySlug && (
        <VerMasButton to={buildCatalogUrl(categorySlug!, minMembershipLevel ?? 0, levels)} />
      )}
    </div>
  );
};

export default SectionHeader;
