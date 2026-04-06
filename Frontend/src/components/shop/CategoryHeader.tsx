import React, { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { FiArrowLeft } from 'react-icons/fi';
import { useLanguage } from '../../contexts/LanguageContext';
import Loader from '../ui/Loader';
import MembershipBadge from '../common/MembershipBadge';

interface CategoryHeaderProps {
  title: string;
  categorySlug?: string;
  isLoading: boolean;
  isCategoryChanging: boolean;
  minMembershipLevel?: number;
  children?: ReactNode;
}

/**
 * Componente para mostrar el encabezado de la categoría con el nombre,
 * badge de membresía mínima y el botón "Ver todas las categorías" cuando corresponda
 */
const CategoryHeader: React.FC<CategoryHeaderProps> = ({
  title,
  categorySlug,
  isLoading,
  isCategoryChanging,
  minMembershipLevel,
  children
}) => {
  const { t } = useTranslation('shopPage');
  const { localizedPath } = useLanguage();

  return (
    <div className="flex flex-col md:flex-row md:justify-between md:items-center mb-8 gap-3">
      {/* Contenedor del botón "Ver todas las categorías" - Ahora aparece primero */}
      <div className="flex order-first mb-2 md:mb-0 md:order-last">
        {/* Enlace para volver a todas las categorías */}
        {categorySlug && !isCategoryChanging && !isLoading && (
          <Link 
            to={localizedPath('/catalogo')} 
            className="inline-flex items-center text-primario hover:text-primario-dark hover:border-transparent transition-colors duration-200 font-medium"
          >
            <FiArrowLeft className="mr-1" /> {t('categoryHeader.backToAll')}
          </Link>
        )}
        
        {/* Área para componentes adicionales (como botones de filtro) */}
        <div className="ml-auto">
          {children}
        </div>
      </div>

      {/* Título de la categoría con badge de membresía */}
      <div className="flex flex-wrap items-center gap-3 order-last md:order-first">
        {!isLoading && minMembershipLevel !== undefined && minMembershipLevel > 0 && (
          <MembershipBadge level={minMembershipLevel} size="sm" />
        )}
        <h1 className="text-3xl font-bold text-primario">
          {isLoading ? (
            <span className="flex items-center">
              <Loader text="" size="small" />
              <span className="ml-2">{t('categoryHeader.loadingCategory')}</span>
            </span>
          ) : title}
        </h1>
      </div>
    </div>
  );
};

export default CategoryHeader;
