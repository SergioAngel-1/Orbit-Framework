import { FC, useState, useEffect } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useLanguage } from '../../contexts/LanguageContext';
import { MenuCategory } from '../../types/menu';
import useMembershipLevels from '../../hooks/useMembershipLevels';
import { buildCatalogUrl } from '../../utils/membershipRouteUtils';

interface MainMenuProps {
  activeTab: string;
  setActiveTab: (tab: string) => void;
  categories: MenuCategory[];
}

// Función auxiliar para formatear texto a formato título (primera letra mayúscula, resto minúsculas)
const formatTitleCase = (text: string): string => {
  if (!text) return '';
  return text
    .toLowerCase()
    .split(' ')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
};

/**
 * Componente MainMenu optimizado
 * Ahora maneja automáticamente el fallback del menú en caso de errores de API
 */
const MainMenu: FC<MainMenuProps> = ({ activeTab, setActiveTab, categories }) => {
  const { t } = useTranslation('mainMenu');
  const { localizedPath } = useLanguage();
  const { levels } = useMembershipLevels();
  // Estado para controlar qué categoría tiene el submenú abierto
  const [openSubmenu, setOpenSubmenu] = useState<number | null>(null);
  const [openChildSubmenu, setOpenChildSubmenu] = useState<number | null>(null);
  const location = useLocation();
  
  // Efecto para actualizar activeTab basado en la URL actual
  useEffect(() => {
    const currentPath = location.pathname;
    
    if (currentPath === '/') {
      setActiveTab('inicio');
    } else if (currentPath === '/contacto') {
      setActiveTab('contacto');
    } else if (currentPath === '/invitados') {
      setActiveTab('invitados');
    } else if (currentPath.startsWith('/catalogo/')) {
      // Extraer slug de categoría considerando rutas:
      // - /catalogo/{category}
      // - /catalogo/{membership}/{category}
      const parts = currentPath.split('/').filter(Boolean); // ['catalogo', ...]
      const slug = parts.length >= 3 ? parts[2] : parts[1];
      
      // Verificar si corresponde a alguna categoría principal
      const matchingCategory = categories.find(category => category.slug === slug);
      if (matchingCategory) {
        setActiveTab(matchingCategory.slug);
      } else {
        // Verificar recursivamente en subniveles
        const findInTree = (subs: any[] | undefined): boolean => {
          if (!subs) return false;
          for (const sub of subs) {
            if (sub.slug === slug) return true;
            if (sub.children && findInTree(sub.children)) return true;
          }
          return false;
        };
        for (const category of categories) {
          if (findInTree(category.subcategories)) {
            setActiveTab(category.slug);
            break;
          }
        }
      }
    }
  }, [location.pathname, categories, setActiveTab]);
  
  // Manejadores para abrir y cerrar submenús
  const handleMouseEnter = (categoryId: number) => {
    setOpenSubmenu(categoryId);
  };
  
  const handleMouseLeave = () => {
    // Cerrar inmediatamente el submenú cuando el cursor sale
    setOpenSubmenu(null);
  };
  
  // Manejador para cuando el cursor entra en el submenú
  const handleSubmenuMouseEnter = () => {
    // Mantener el submenú abierto mientras el cursor esté sobre él
  };
  
  // Manejador para cuando el cursor sale del submenú
  const handleSubmenuMouseLeave = () => {
    // Cerrar el submenú cuando el cursor sale
    setOpenSubmenu(null);
  };
  return (
    <nav className="flex items-center justify-center w-full">
      <div className="flex flex-wrap items-center gap-2 md:gap-3 xl:gap-5 px-2">
        <Link 
          to={localizedPath('/')} 
          className={`text-xs md:text-sm font-bold px-2.5 md:px-3 py-2 rounded-md transition-all duration-300 text-white hover:text-primario hover:bg-secundario capitalize tab-push-effect shrink-0 ${
            activeTab === 'inicio' 
              ? 'border-b-2 border-white' 
              : ''
          }`} 
          onClick={() => setActiveTab('inicio')}
        >
          {t('home')}
        </Link>
        
        {categories.map(category => (
          <div 
            key={category.id} 
            className="relative shrink-0"
            onMouseEnter={() => handleMouseEnter(category.id)}
            onMouseLeave={handleMouseLeave}
          >
            <Link 
              to={localizedPath(buildCatalogUrl(category.slug, category.min_membership_level ?? 0, levels))}
              className={`text-xs md:text-sm font-bold flex items-center px-2.5 md:px-3 py-2 rounded-md transition-all duration-300 text-white hover:text-primario hover:bg-secundario capitalize tab-push-effect ${
                activeTab === category.slug 
                  ? 'border-b-2 border-white' 
                  : ''
              }`}
              onClick={() => setActiveTab(category.slug)}
            >
              {formatTitleCase(category.name)}
              {category.subcategories && category.subcategories.length > 0 && (
                <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4 ml-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                </svg>
              )}
            </Link>
            
            {category.subcategories && category.subcategories.length > 0 && (
              <div 
                className={`absolute left-0 mt-0 w-56 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 focus:outline-none z-50 transition-opacity duration-300 ${
                  openSubmenu === category.id ? 'opacity-100 visible' : 'opacity-0 invisible'
                }`}
                style={{ marginTop: '0.5rem', paddingTop: '0.5rem' }} // Espacio adicional para facilitar la interacción
                onMouseEnter={handleSubmenuMouseEnter}
                onMouseLeave={handleSubmenuMouseLeave}
              >
                <div className="py-1" role="menu" aria-orientation="vertical" aria-labelledby="options-menu">
                  {category.subcategories.map(subcategory => (
                    <div
                      key={subcategory.id}
                      className="px-2 py-1 relative"
                      onMouseEnter={() => setOpenChildSubmenu(subcategory.id as number)}
                      onMouseLeave={() => setOpenChildSubmenu(null)}
                    >
                      <Link 
                        to={localizedPath(buildCatalogUrl(subcategory.slug, subcategory.min_membership_level ?? 0, levels))}
                        className="block px-3 py-1 text-sm font-bold text-texto hover:bg-secundario hover:text-primario transition-all duration-300 capitalize rounded"
                        role="menuitem"
                      >
                        {formatTitleCase(subcategory.name)}
                        {subcategory.children && subcategory.children.length > 0 && (
                          <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4 inline ml-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                          </svg>
                        )}
                      </Link>
                      {subcategory.children && subcategory.children.length > 0 && (
                        <div 
                          className={`absolute left-full top-0 mt-0 ml-1 w-56 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-50 transition-opacity duration-200 ${
                            openChildSubmenu === (subcategory.id as number) ? 'opacity-100 visible' : 'opacity-0 invisible'
                          }`}
                        >
                          <div className="py-1" role="menu" aria-orientation="vertical">
                            {subcategory.children.map(child => (
                              <Link
                                key={child.id}
                                to={localizedPath(buildCatalogUrl(child.slug, child.min_membership_level ?? 0, levels))}
                                className="block px-3 py-1 text-xs font-medium text-texto hover:bg-secundario hover:text-primario transition-all duration-300 rounded capitalize"
                                role="menuitem"
                              >
                                {formatTitleCase(child.name)}
                              </Link>
                            ))}
                          </div>
                        </div>
                      )}
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        ))}
        
        <Link 
          to={localizedPath('/contacto')} 
          className={`text-xs md:text-sm font-bold px-2.5 md:px-3 py-2 rounded-md transition-all duration-300 text-white hover:text-primario hover:bg-secundario capitalize tab-push-effect shrink-0 ${
            activeTab === 'contacto' 
              ? 'border-b-2 border-white' 
              : ''
          }`} 
          onClick={() => setActiveTab('contacto')}
        >
          {t('contact')}
        </Link>
      </div>
    </nav>
  );
};

export default MainMenu;
