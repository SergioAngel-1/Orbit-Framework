import React, { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import DOMPurify from 'dompurify';
import { ProductDescription } from './';
import CollapsibleSection from '../common/CollapsibleSection';
import { fluidSizing } from '../../utils/fluidSizing';
import { IoIosArrowDown, IoIosArrowUp } from 'react-icons/io';

interface ProductDescriptionSectionProps {
  description: string;
  shortDescription?: string;
  /** Altura máxima en píxeles para el contenedor cuando está colapsado (default: 180) */
  maxCollapsedHeight?: number;
  /** Altura máxima en píxeles para el contenedor cuando está expandido (default: 280) */
  maxExpandedHeight?: number;
}

/**
 * Componente que muestra la sección completa de descripción del producto
 * Usa CollapsibleSection con variante soft para un diseño limpio y moderno
 * 
 * Comportamiento:
 * - Si hay shortDescription: muestra primero la corta con botón "Ver más"
 * - Al expandir: muestra descripción corta + descripción larga completa
 * - Altura máxima configurable con scroll cuando está expandido
 */
const ProductDescriptionSection: React.FC<ProductDescriptionSectionProps> = ({
  description,
  shortDescription,
  maxCollapsedHeight = 255,
  maxExpandedHeight = 255
}) => {
  const { t } = useTranslation('productDetailPage');
  // Estado para controlar si se muestra la descripción completa
  const [isExpanded, setIsExpanded] = useState<boolean>(!shortDescription);

  // Sanitizar shortDescription antes de renderizar para prevenir XSS
  const sanitizedShortDescription = useMemo(() => {
    if (!shortDescription) return '';
    return DOMPurify.sanitize(shortDescription, {
      ALLOWED_TAGS: ['p', 'strong', 'em', 'br', 'a', 'ul', 'ol', 'li'],
      ALLOWED_ATTR: ['href', 'target', 'rel']
    });
  }, [shortDescription]);

  // Si no hay ninguna descripción, mostrar mensaje
  // Verificar que no sean strings vacíos o solo espacios
  const hasContent = description?.trim() || shortDescription?.trim();
  
  // Determinar si hay contenido adicional para mostrar
  const hasMoreContent = shortDescription && description;

  // Botón para el header (headerExtra de CollapsibleSection)
  const headerButton = hasMoreContent ? (
    <button 
      onClick={(e) => {
        e.stopPropagation();
        setIsExpanded(!isExpanded);
      }}
      className="flex items-center gap-1 text-primario hover:text-hover transition-colors font-medium whitespace-nowrap"
      style={{ fontSize: fluidSizing.text.xs }}
    >
      {isExpanded ? (
        <>
          <span>{t('description.showLess')}</span>
          <IoIosArrowUp className="w-3.5 h-3.5" />
        </>
      ) : (
        <>
          <span>{t('description.showMore')}</span>
          <IoIosArrowDown className="w-3.5 h-3.5" />
        </>
      )}
    </button>
  ) : undefined;

  return (
    <CollapsibleSection
      title={t('description.title')}
      variant="soft"
      collapsible={false}
      showCollapseButton={false}
      defaultExpanded={true}
      className="border-0 shadow-none relative z-0"
      headerExtra={headerButton}
    >
      <div className="flex flex-col">
        {!hasContent ? (
          <p 
            className="text-texto italic"
            style={{ fontSize: fluidSizing.text.sm }}
          >
            {t('description.empty')}
          </p>
        ) : (
          <div 
            className="overflow-y-auto pr-2 scrollbar-thin scrollbar-thumb-primario/20 scrollbar-track-transparent"
            style={{ 
              maxHeight: isExpanded ? `${maxExpandedHeight}px` : `${maxCollapsedHeight}px`,
              transition: 'max-height 0.3s ease-in-out'
            }}
          >
            {/* Descripción corta - solo visible cuando NO está expandido */}
            {shortDescription?.trim() && !isExpanded && (
              <div 
                className="text-texto leading-relaxed"
                style={{ fontSize: fluidSizing.text.base }}
                dangerouslySetInnerHTML={{ __html: sanitizedShortDescription }}
              />
            )}
            
            {/* Descripción larga - visible cuando está expandido o no hay shortDescription */}
            {description?.trim() && (isExpanded || !shortDescription?.trim()) && (
              <div 
                className="prose prose-sm md:prose-base max-w-none text-texto"
                style={{ fontSize: fluidSizing.text.sm }}
              >
                <ProductDescription description={description} />
              </div>
            )}
          </div>
        )}
      </div>
    </CollapsibleSection>
  );
};

export default ProductDescriptionSection;
