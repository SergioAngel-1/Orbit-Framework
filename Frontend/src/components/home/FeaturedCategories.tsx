import React from 'react';
import { Link } from 'react-router-dom';
import Loader from '../ui/Loader';
import MembershipBadge from '../common/MembershipBadge';
import categoryService from "../../services/categoryService";
import { generateSlug } from "../../utils/formatters";
import { fluidSizing } from "../../utils/fluidSizing";
import { useTranslation } from 'react-i18next';
import { useLanguage } from '../../contexts/LanguageContext';
import useMembershipLevels from '../../hooks/useMembershipLevels';
import { buildCatalogUrl } from '../../utils/membershipRouteUtils';
import "./css/FeaturedCategories.css";

// Interfaces - Estandarizadas con MembershipContext
// La estructura de MembershipInfo es consistente con la usada en MembershipContext
export interface MembershipInfo {
  level: number;
  name: string;
  icon: string;
  color: string;
  mode?: 'cascade' | 'exact';
}

export interface Category {
  id: number;
  name: string;
  description?: string;
  image?: string;
  link?: string;
  slug?: string;
  /** Nivel mínimo de membresía requerido (estandarizado) */
  min_membership_level?: number;
  /** Info detallada del nivel de membresía (estandarizado) */
  min_membership_info?: MembershipInfo | null;
  /** Si el usuario actual tiene acceso a esta categoría */
  user_has_access?: boolean;
  // Legacy - para compatibilidad con endpoints existentes
  /** @deprecated Usar min_membership_level */
  min_membership?: number;
  /** @deprecated Usar min_membership_info */
  membership_info?: MembershipInfo | null;
}

interface FeaturedCategoriesProps {
  categories: Category[];
  loading: boolean;
  error: string | null;
}

const FeaturedCategories: React.FC<FeaturedCategoriesProps> = ({
  categories,
  loading,
  error,
}) => {
  const { t } = useTranslation('homeFeaturedCategories');
  const { localizedPath } = useLanguage();
  const { levels } = useMembershipLevels();
  // Función memoizada para generar la URL correcta para la categoría
  const getCategoryUrl = React.useCallback((category: Category): string => {
    const slug = category.slug || generateSlug(category.name);
    const normalizedSlug = categoryService.normalizeSlug(slug);
    const membershipLevel = category.min_membership_level ?? category.min_membership ?? 0;
    return buildCatalogUrl(normalizedSlug, membershipLevel, levels);
  }, [levels]);

  return (
    <div className="featured-categories-container">
      <h2 className="featured-categories-title text-2xl font-bold mb-4 text-oscuro">
        {t('title')}
      </h2>
      {loading ? (
        <div className="featured-categories-loading">
          <Loader size="large" text={t('loading')} />
        </div>
      ) : error ? (
        <div className="featured-categories-error text-center text-red-500">
          {t('error')}
        </div>
      ) : (
        <div className="featured-categories-grid">
          {/* Mapear solo las categorías con productos */}
          {categories.slice(0, 11).map((category) => {
            // Usar propiedad estandarizada, con fallback a legacy
            const membershipLevel = category.min_membership_level ?? category.min_membership;
            const hasMembershipBadge = membershipLevel != null && membershipLevel > 0;
            const categoryUrl = localizedPath(getCategoryUrl(category));

            return (
            <div
              key={category.id}
              className="featured-category-item category-animate group relative overflow-hidden rounded-lg shadow-md transition-all duration-300 hover:shadow-xl"
              title={category.name}
            >
              {/* Badge de membresía si la categoría requiere nivel > 0 */}
              {hasMembershipBadge && (
                <div 
                  className="absolute z-20 rounded-br-lg"
                  style={{ 
                    top: 0, 
                    left: 0, 
                    padding: `${fluidSizing.space.xs}`,
                    backgroundColor: 'transparent'
                  }}
                >
                  <MembershipBadge level={membershipLevel!} size="xs" />
                </div>
              )}
              
              <Link
                to={categoryUrl}
                className="featured-category-link focus:outline-none focus:ring-2 focus:ring-primario focus:ring-offset-2 rounded-t-lg"
                title={t('card.viewBenefits', { name: category.name })}
              >
                {/* Imagen de producto aleatorio de la categoría */}
                <div className="featured-category-content">
                  {category.image ? (
                    <img
                      src={category.image}
                      alt={category.name}
                      className="w-full h-full object-cover transition-all duration-300"
                      title={category.name}
                    />
                  ) : (
                    <div className="w-full h-full bg-gradient-to-br from-gray-100 to-gray-200 flex items-center justify-center group-hover:from-primario/5 group-hover:to-primario/10 transition-all duration-300">
                      <div className="text-center transform transition-all duration-300 group-hover:scale-110">
                        <span className="text-lg sm:text-xl md:text-2xl lg:text-3xl filter drop-shadow-sm">
                          🛍️
                        </span>
                        <div className="mt-0.5 sm:mt-1 w-4 sm:w-6 h-0.5 bg-primario/20 rounded-full mx-auto transition-all duration-300 group-hover:w-6 sm:group-hover:w-8 group-hover:bg-primario/40"></div>
                      </div>
                    </div>
                  )}
                </div>

                {/* Degradado mejorado sobre la imagen */}
                <div className="absolute inset-0 bg-gradient-to-t from-oscuro/30 via-transparent to-transparent opacity-60 group-hover:opacity-80 transition-opacity duration-300"></div>

                {/* Overlay adicional para mejor contraste */}
                <div className="absolute inset-0 bg-black/0 group-hover:bg-black/5 transition-all duration-300"></div>
              </Link>

              {/* Footer mejorado con nombre de categoría */}
              <Link
                to={categoryUrl}
                className="featured-category-footer block group focus:outline-none focus:ring-2 focus:ring-primario focus:ring-offset-2 rounded-b-lg"
                title={t('card.exploreCategory', { name: category.name })}
              >
                <div className="bg-primario py-1 sm:py-1.5 md:py-2 px-0.5 sm:px-1 text-center relative overflow-hidden transition-all duration-300 rounded-b-lg group-hover:bg-primario/90">
                  {/* Efecto shimmer en hover */}
                  <div className="absolute inset-0 bg-gradient-to-r from-transparent via-white/10 to-transparent transform -skew-x-12 -translate-x-full group-hover:translate-x-full transition-transform duration-700 ease-out"></div>

                  {/* Línea decorativa superior */}
                  <div className="absolute inset-x-0 top-0 h-[1px] bg-white/20 transform scale-x-0 group-hover:scale-x-100 transition-transform duration-300 origin-center"></div>

                  {/* Bordes laterales sutiles */}
                  <div className="absolute inset-y-0 left-0 w-[1px] bg-white/10 opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                  <div className="absolute inset-y-0 right-0 w-[1px] bg-white/10 opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>

                  <span
                    className="text-white text-center text-[8px] sm:text-[9px] md:text-[10px] lg:text-xs font-medium w-full leading-tight relative z-10 group-hover:font-semibold transition-all duration-300 line-clamp-2"
                    title={category.name}
                  >
                    {category.name}
                  </span>

                  {/* Indicador de hover en la parte inferior */}
                  <div className="absolute inset-x-0 bottom-0 h-[2px] bg-white/0 group-hover:bg-white/30 transition-all duration-300 rounded-b-lg"></div>
                </div>
              </Link>
            </div>
          );})}

          {/* Tarjeta "Ver todas las categorías" - Siempre en la posición 12 */}
          <div
            className="featured-category-item category-animate group relative overflow-hidden rounded-lg shadow-md transition-all duration-300 hover:shadow-xl"
            title={t('card.viewAllTooltip')}
          >
            <Link
              to={localizedPath('/catalogo')}
              className="featured-category-link focus:outline-none focus:ring-2 focus:ring-primario focus:ring-offset-2 rounded-t-lg"
              title={t('card.exploreAllTooltip')}
            >
              <div className="featured-category-content bg-gradient-to-br from-gray-50 to-white border border-gray-200 group-hover:border-primario group-hover:shadow-inner transition-all duration-300 group-hover:from-primario/5 group-hover:to-white rounded-t-lg">
                {/* Contenido centrado verticalmente */}
                <div className="flex flex-col items-center justify-center h-full px-1 py-2">
                  {/* Contenedor del ícono optimizado */}
                  <div className="rounded-full bg-primario/10 group-hover:bg-primario/20 transition-all duration-300 group-hover:scale-105 p-1 sm:p-1.5 md:p-2">
                    <svg
                      xmlns="http://www.w3.org/2000/svg"
                      className="h-3 w-3 sm:h-4 sm:w-4 md:h-5 md:w-5 lg:h-6 lg:w-6 text-primario transition-transform duration-300 group-hover:rotate-90"
                      fill="none"
                      viewBox="0 0 24 24"
                      stroke="currentColor"
                      strokeWidth={2}
                    >
                      <path
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        d="M12 6v6m0 0v6m0-6h6m-6 0H6"
                      />
                    </svg>
                  </div>

                  {/* Texto optimizado */}
                  <p className="mt-1 font-medium text-primario text-[8px] sm:text-[9px] md:text-[10px] lg:text-xs transition-all duration-300 group-hover:font-semibold text-center leading-tight">
                    {t('card.viewAll')}
                  </p>

                  {/* Indicador visual reducido */}
                  <div className="mt-0.5 w-2 sm:w-3 h-px bg-primario/30 rounded-full transition-all duration-300 group-hover:w-3 sm:group-hover:w-4 group-hover:bg-primario/60"></div>
                </div>
              </div>
            </Link>

            {/* Footer mejorado con el mismo estilo que las otras categorías */}
            <Link
              to={localizedPath('/catalogo')}
              className="featured-category-footer block group focus:outline-none focus:ring-2 focus:ring-primario focus:ring-offset-2 rounded-b-lg"
              title={t('card.fullCatalogTooltip')}
            >
              <div className="bg-primario py-1 sm:py-1.5 md:py-2 px-0.5 sm:px-1 text-center relative overflow-hidden transition-all duration-300 rounded-b-lg group-hover:bg-primario/90">
                {/* Efecto de overlay en hover */}
                <div className="absolute inset-0 bg-gradient-to-r from-transparent via-white/10 to-transparent transform -skew-x-12 -translate-x-full group-hover:translate-x-full transition-transform duration-700 ease-out"></div>

                {/* Línea decorativa en hover */}
                <div className="absolute inset-x-0 top-0 h-[1px] bg-white/20 transform scale-x-0 group-hover:scale-x-100 transition-transform duration-300 origin-center"></div>

                <span
                  className="text-white text-center text-[8px] sm:text-[9px] md:text-[10px] lg:text-xs font-medium w-full relative z-10 group-hover:font-bold transition-all duration-300 leading-tight line-clamp-2"
                  title={t('card.fullCatalogTitle')}
                >
                  {t('card.fullCatalog')}
                </span>
              </div>
            </Link>
          </div>
        </div>
      )}
    </div>
  );
};

export default FeaturedCategories;
