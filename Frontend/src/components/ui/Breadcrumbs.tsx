import React from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useLanguage } from '../../contexts/LanguageContext';
import { Category } from '../../types/woocommerce';
import { generateSlug } from '../../utils/formatters';
import { useMediaQuery } from '../../hooks/useMediaQuery';
import categoryService from '../../services/categoryService';
import useMembershipLevels from '../../hooks/useMembershipLevels';
import { buildCatalogUrl } from '../../utils/membershipRouteUtils';

interface BreadcrumbsProps {
  categories?: Category[];
  currentProduct?: string;
  currentCategory?: string;
  /** Nivel mínimo de membresía requerido por la categoría actual (para construir URL correcta) */
  currentCategoryMinMembership?: number;
  hideCurrentProduct?: boolean;
}

const Breadcrumbs: React.FC<BreadcrumbsProps> = ({ categories, currentProduct, currentCategory, currentCategoryMinMembership = 0, hideCurrentProduct = false }) => {
  const { t } = useTranslation(['productDetailPage', 'uiComponents']);
  const { localizedPath } = useLanguage();
  const { levels } = useMembershipLevels();
  // Detectar diferentes tamaños de pantalla para un responsive más granular
  const isSmallMobile = useMediaQuery('(max-width: 380px)');
  const isMobile = useMediaQuery('(max-width: 640px)');
  const isTablet = useMediaQuery('(max-width: 768px)');
  
  // Filtrar categorías y construir una ruta jerárquica única
  // Queremos mostrar solo una ruta desde la categoría más general a la más específica
  const filteredCategories = React.useMemo(() => {
    if (!categories || categories.length === 0) return [];
    
    // Filtrar categorías duplicadas
    const uniqueCategories = categories.filter((category, index, self) => 
      index === self.findIndex((c) => c.id === category.id)
    );
    
    // Si hay una categoría actual, no mostrarla en la lista de categorías
    const withoutCurrent = currentCategory 
      ? uniqueCategories.filter(category => category.name.toLowerCase() !== currentCategory.toLowerCase())
      : uniqueCategories;
    
    // Identificar categorías con relaciones padre-hijo
    // Primero, crear un mapa de categorías por ID para búsqueda rápida
    const categoryMap = withoutCurrent.reduce((map, category) => {
      map[category.id] = category;
      return map;
    }, {} as Record<number, Category>);
    
    // Encontrar la categoría más específica (que no es padre de ninguna otra)
    const childCategories = new Set();
    withoutCurrent.forEach(category => {
      if (category.parent && categoryMap[category.parent]) {
        childCategories.add(category.id);
      }
    });
    
    // Construir la ruta jerárquica desde la categoría más específica hacia arriba
    let hierarchicalPath: Category[] = [];
    
    // Si tenemos una categoría actual, buscar su jerarquía
    if (currentCategory) {
      // Encontrar la categoría actual en el mapa
      const currentCat = withoutCurrent.find(c => 
        c.name.toLowerCase() === currentCategory.toLowerCase()
      );
      
      if (currentCat) {
        // Construir la ruta desde esta categoría hacia arriba
        let currentId = currentCat.parent;
        while (currentId && categoryMap[currentId]) {
          hierarchicalPath.push(categoryMap[currentId]);
          currentId = categoryMap[currentId].parent;
        }
      }
    } else {
      // Si no hay categoría actual, usar la primera categoría que no es padre de ninguna otra
      const leafCategories = withoutCurrent.filter(cat => !childCategories.has(cat.id));
      
      if (leafCategories.length > 0) {
        // Tomar la primera categoría hoja y construir su ruta
        const currentCat = leafCategories[0];
        let currentId = currentCat.parent;
        
        // Agregar la categoría hoja
        hierarchicalPath = [currentCat];
        
        // Seguir la cadena de padres
        while (currentId && categoryMap[currentId]) {
          hierarchicalPath.push(categoryMap[currentId]);
          currentId = categoryMap[currentId].parent;
        }
      }
    }
    
    // Invertir para que vaya de más general a más específica
    hierarchicalPath.reverse();
    
    // Adaptar la cantidad de categorías mostradas según el tamaño de pantalla
    if (isSmallMobile && hierarchicalPath.length > 0) {
      // En pantallas muy pequeñas, mostrar solo la categoría más específica
      hierarchicalPath = [hierarchicalPath[hierarchicalPath.length - 1]];
    } else if (isMobile && hierarchicalPath.length > 2) {
      // En móviles normales, mostrar máximo 2 niveles
      hierarchicalPath = hierarchicalPath.slice(-2);
    } else if (isTablet && hierarchicalPath.length > 3) {
      // En tablets, mostrar máximo 3 niveles
      hierarchicalPath = hierarchicalPath.slice(-3);
    }
    
    return hierarchicalPath;
  }, [categories, currentCategory, isSmallMobile, isMobile, isTablet]);

  return (
    <nav className="flex mb-2 md:mb-4" aria-label={t('uiComponents:breadcrumbs.ariaLabel')}>
      <ol className="inline-flex items-center space-x-1 md:space-x-2 flex-wrap w-full overflow-x-auto pb-1 scrollbar-hide">
        <li className="inline-flex items-center">
          <Link to={localizedPath('/')} className="inline-flex items-center text-[10px] sm:text-xs md:text-sm text-gray-500 hover:text-primario whitespace-nowrap">
            <svg className="w-3 h-3 sm:w-4 sm:h-4 mr-1 sm:mr-2" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
              <path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"></path>
            </svg>
            <span className={isSmallMobile ? 'hidden' : ''}>{t('breadcrumbs.home')}</span>
          </Link>
        </li>
        
        <li>
          <div className="flex items-center">
            <svg className="w-4 h-4 sm:w-5 sm:h-5 md:w-6 md:h-6 text-gray-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
              <path fillRule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clipRule="evenodd"></path>
            </svg>
            <Link 
              to={localizedPath('/catalogo')} 
              className="ml-1 text-[10px] sm:text-xs md:text-sm text-gray-500 hover:text-primario md:ml-2 whitespace-nowrap"
            >
              {t('breadcrumbs.catalog')}
            </Link>
          </div>
        </li>
        
        {filteredCategories && filteredCategories.length > 0 && filteredCategories.map((category) => (
          <li key={category.id}>
            <div className="flex items-center">
              <svg className="w-4 h-4 sm:w-5 sm:h-5 md:w-6 md:h-6 text-gray-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                <path fillRule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clipRule="evenodd"></path>
              </svg>
              <Link 
                to={localizedPath(buildCatalogUrl(category.slug ? categoryService.normalizeSlug(category.slug) : categoryService.normalizeSlug(generateSlug(category.name)), category.min_membership_level ?? 0, levels))} 
                className="ml-1 text-[10px] sm:text-xs md:text-sm text-gray-500 hover:text-primario md:ml-2 truncate max-w-[80px] sm:max-w-[100px] md:max-w-[150px] lg:max-w-[200px]"
                title={category.name}
              >
                {isSmallMobile && category.name.length > 15 ? `${category.name.substring(0, 15)}...` : 
                 isMobile && category.name.length > 20 ? `${category.name.substring(0, 20)}...` : 
                 isTablet && category.name.length > 25 ? `${category.name.substring(0, 25)}...` : 
                 category.name.length > 35 ? `${category.name.substring(0, 35)}...` : 
                 category.name}
              </Link>
            </div>
          </li>
        ))}
        
        {currentCategory && (
          <li>
            <div className="flex items-center">
              <svg className="w-4 h-4 sm:w-5 sm:h-5 md:w-6 md:h-6 text-gray-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                <path fillRule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clipRule="evenodd"></path>
              </svg>
              <Link 
                to={localizedPath(buildCatalogUrl(categoryService.normalizeSlug(generateSlug(currentCategory)), currentCategoryMinMembership, levels))} 
                className="ml-1 text-[10px] sm:text-xs md:text-sm text-gray-500 hover:text-primario md:ml-2 truncate max-w-[80px] sm:max-w-[100px] md:max-w-[150px] lg:max-w-[200px]"
                title={currentCategory}
              >
                {isSmallMobile && currentCategory.length > 15 ? `${currentCategory.substring(0, 15)}...` : 
                 isMobile && currentCategory.length > 20 ? `${currentCategory.substring(0, 20)}...` : 
                 isTablet && currentCategory.length > 25 ? `${currentCategory.substring(0, 25)}...` : 
                 currentCategory.length > 35 ? `${currentCategory.substring(0, 35)}...` : 
                 currentCategory}
              </Link>
            </div>
          </li>
        )}
        
        {currentProduct && !hideCurrentProduct && (
          <li aria-current="page">
            <div className="flex items-center">
              <svg className="w-4 h-4 sm:w-5 sm:h-5 md:w-6 md:h-6 text-gray-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                <path fillRule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clipRule="evenodd"></path>
              </svg>
              <span className="ml-1 text-[10px] sm:text-xs md:text-sm font-medium text-primario md:ml-2 truncate max-w-[100px] sm:max-w-[120px] md:max-w-[180px] lg:max-w-[250px]" title={currentProduct}>
                {isSmallMobile && currentProduct.length > 18 ? `${currentProduct.substring(0, 18)}...` : 
                 isMobile && currentProduct.length > 25 ? `${currentProduct.substring(0, 25)}...` : 
                 isTablet && currentProduct.length > 35 ? `${currentProduct.substring(0, 35)}...` : 
                 currentProduct.length > 50 ? `${currentProduct.substring(0, 50)}...` : 
                 currentProduct}
              </span>
            </div>
          </li>
        )}
      </ol>
    </nav>
  );
};

export default Breadcrumbs;
