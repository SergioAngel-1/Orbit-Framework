import React from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { useLanguage } from '../../contexts/LanguageContext';
import useMembershipLevels from '../../hooks/useMembershipLevels';
import { buildCatalogUrl } from '../../utils/membershipRouteUtils';

interface SectionFooterButtonProps {
  categorySlug: string;
  text?: string;
  /** Nivel mínimo de membresía requerido por la categoría */
  minMembershipLevel?: number;
}

/**
 * Botón "Ver más" mejorado que se muestra al final de cada sección
 */
const SectionFooterButton: React.FC<SectionFooterButtonProps> = ({ 
  categorySlug, 
  text,
  minMembershipLevel = 0
}) => {
  const { t } = useTranslation('homeProductGrid');
  const { localizedPath } = useLanguage();
  const { levels } = useMembershipLevels();
  const resolvedText = text ?? t('viewMore');
  return (
    <div className="flex justify-center mt-6">
      <Link 
        to={localizedPath(buildCatalogUrl(categorySlug, minMembershipLevel, levels))}
        className="flex items-center justify-center bg-primario hover:bg-oscuro text-white py-2 px-6 rounded-md transition-colors duration-200 shadow-md hover:shadow-lg"
      >
        <span className="mr-2 font-medium">{resolvedText}</span>
        <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M14 5l7 7m0 0l-7 7m7-7H3" />
        </svg>
      </Link>
    </div>
  );
};

export default SectionFooterButton;
