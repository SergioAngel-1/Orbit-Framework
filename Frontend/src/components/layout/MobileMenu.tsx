import { FC, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import gsap from 'gsap';
import { Link, useNavigate, useLocation } from 'react-router-dom';
import { MenuCategory } from '../../types/menu';
import { useAuth } from '../../contexts/AuthContext';
import { FiUser, FiCreditCard, FiUsers, FiLogOut, FiHelpCircle, FiMail, FiPackage, FiAward } from 'react-icons/fi';
import alertService from '../../services/alertService';
import ConfirmModal from '../ui/ConfirmModal';
import { useLanguage } from '../../contexts/LanguageContext';
import { lockBodyScroll, unlockBodyScroll } from '../../utils/bodyScrollLock';
import LanguageSwitch from '../common/LanguageSwitch';
import useMembershipLevels from '../../hooks/useMembershipLevels';
import { buildCatalogUrl } from '../../utils/membershipRouteUtils';
import { useSiteFeatures } from '../../contexts/SiteConfigContext';

interface MobileMenuProps {
  isOpen: boolean;
  onClose: () => void;
  activeTab: string;
  setActiveTab: (tab: string) => void;
  categories: MenuCategory[];
  openProfileModal?: (section?: 'profile' | 'addresses' | 'orders' | 'referrals' | 'membership' | 'digitalCard') => void;
  openHelpModal?: () => void;
}

const MobileMenu: FC<MobileMenuProps> = ({ 
  isOpen, 
  onClose, 
  activeTab, 
  setActiveTab, 
  categories,
  openProfileModal,
  openHelpModal
}) => {
  const { isAuthenticated, isPending, logout } = useAuth();
  const { t } = useTranslation('mobileMenu');
  const { localizedPath } = useLanguage();
  const { levels } = useMembershipLevels();
  const features = useSiteFeatures();
  const navigate = useNavigate();
  const location = useLocation();
  const [openNodes, setOpenNodes] = useState<Record<number, boolean>>({});
  const [isLogoutConfirmOpen, setIsLogoutConfirmOpen] = useState(false);

  const toggleNode = (id: number) => {
    setOpenNodes(prev => ({ ...prev, [id]: !prev[id] }));
  };
  
  // Normalizar pathname eliminando el prefijo /en para comparaciones consistentes
  const normalizePath = (path: string) => path.startsWith('/en/') ? path.slice(3) : (path === '/en' ? '/' : path);

  // Efecto para actualizar activeTab basado en la URL actual
  useEffect(() => {
    if (!isOpen) return; // Solo actualizar cuando el menú está abierto
    
    const currentPath = normalizePath(location.pathname);
    
    if (currentPath === '/') {
      setActiveTab('inicio');
    } else if (currentPath === '/catalogo') {
      setActiveTab('catalogo');
    } else if (currentPath === '/contacto') {
      setActiveTab('contacto');
    } else if (currentPath === '/invitados') {
      setActiveTab('invitados');
    } else if (currentPath === '/toures') {
      setActiveTab('toures');
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
            if ((sub as any).children && findInTree((sub as any).children)) return true;
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
  }, [isOpen, location.pathname, categories, setActiveTab]);
  // Efecto para bloquear el scroll del body cuando el menú está abierto
  useEffect(() => {
    if (isOpen) {
      lockBodyScroll();
      return () => {
        unlockBodyScroll();
      };
    }
  }, [isOpen]);
  
  // Referencia al panel del menú para la animación
  const menuRef = useRef<HTMLDivElement>(null);
  
  // Efecto para animar la apertura y cierre del menú
  useEffect(() => {
    if (!menuRef.current) return;
    
    if (isOpen) {
      // Preparar para animación GPU-accelerated
      gsap.set(menuRef.current, { willChange: 'transform' });
      
      // Animación de entrada (izquierda a derecha)
      gsap.fromTo(menuRef.current,
        { x: '-100%' },
        { 
          x: '0%', 
          duration: 0.3, 
          ease: 'power2.out',
          onComplete: () => {
            // Limpiar will-change después de la animación
            if (menuRef.current) {
              gsap.set(menuRef.current, { willChange: 'auto' });
            }
          }
        }
      );
    }
  }, [isOpen]);
  
  if (!isOpen) return null;
  
  const handleLogout = () => {
    setIsLogoutConfirmOpen(true);
  };

  const confirmLogout = () => {
    setIsLogoutConfirmOpen(false);
    logout();
    onClose();
    alertService.success(t('logout.success'));
    navigate(localizedPath('/'));
  };

  // Función para manejar el cierre con animación
  const handleClose = () => {
    if (!menuRef.current) {
      onClose();
      return;
    }
    
    // Preparar para animación GPU-accelerated
    gsap.set(menuRef.current, { willChange: 'transform' });
    
    // Animación de salida (derecha a izquierda)
    gsap.to(menuRef.current, {
      x: '-100%',
      duration: 0.25,
      ease: 'power2.in',
      onComplete: onClose
    });
  };

  return (
    <div className="fixed inset-0 bg-black/30 backdrop-blur-sm z-50" onClick={handleClose}>
      <div 
        ref={menuRef} 
        className="bg-white/95 h-full w-4/5 max-w-sm flex flex-col transform-gpu"
        style={{ transform: 'translateX(-100%)' }}
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex justify-between items-center gap-2 p-4 border-b sticky top-0 bg-white z-10">
          <h2 className="text-lg font-semibold text-primario">{t('title')}</h2>
          <span className="md:hidden"><LanguageSwitch variant="mobile" /></span>
          <button onClick={handleClose} className="text-primario hover:text-hover transition-colors !bg-white">
            <svg xmlns="http://www.w3.org/2000/svg" className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
        
        <nav className="p-4 overflow-y-auto flex-1">
          <ul className="space-y-2">
            {/* Opciones de usuario */}
            {isAuthenticated && (
              <>
                <li>
                  <div 
                    className="flex items-center w-full py-2 px-4 rounded-md hover:bg-gray-100 cursor-pointer"
                    onClick={() => {
                      if (openProfileModal) {
                        openProfileModal();
                        onClose();
                      }
                    }}
                  >
                    <FiUser className="h-5 w-5 mr-2" />
                    <span>{t('nav.myAccount')}</span>
                    {isPending && (
                      <span className="ml-2 bg-yellow-500 text-white text-xs rounded-full h-4 w-4 flex items-center justify-center">!</span>
                    )}
                  </div>
                </li>
                {features.referrals_points && (
                  <li>
                    <Link
                      to={localizedPath('/fondo-de-aportes')}
                      className={`flex items-center w-full py-2 px-4 rounded-md ${normalizePath(location.pathname) === '/fondo-de-aportes' ? 'bg-primario text-white hover:bg-primario-dark hover:text-white' : 'hover:bg-gray-100'}`}
                      onClick={onClose}
                    >
                      <FiCreditCard className="h-5 w-5 mr-2" />
                      <span>{t('nav.contributions')}</span>
                    </Link>
                  </li>
                )}
                {features.referrals_points && (
                  <li>
                    <Link
                      to={localizedPath('/invitados')}
                      className={`flex items-center w-full py-2 px-4 rounded-md ${normalizePath(location.pathname) === '/invitados' ? 'bg-primario text-white hover:bg-primario-dark hover:text-white' : 'hover:bg-gray-100'}`}
                      onClick={onClose}
                    >
                      <FiUsers className="h-5 w-5 mr-2" />
                      <span>{t('nav.members')}</span>
                    </Link>
                  </li>
                )}
                {features.memberships && (
                  <li>
                    <Link
                      to={localizedPath('/membresias')}
                      className={`flex items-center w-full py-2 px-4 rounded-md ${normalizePath(location.pathname) === '/membresias' ? 'bg-primario text-white hover:bg-primario-dark hover:text-white' : 'hover:bg-gray-100'}`}
                      onClick={onClose}
                    >
                      <FiAward className="h-5 w-5 mr-2" />
                      <span>{t('nav.memberships')}</span>
                    </Link>
                  </li>
                )}
              </>
            )}
            {!isAuthenticated && (
              <li>
                <Link
                  to={localizedPath('/iniciar-sesion')}
                  className="flex items-center w-full py-2 px-4 rounded-md hover:bg-gray-100"
                  onClick={onClose}
                >
                  <FiUser className="h-5 w-5 mr-2" />
                  <span>{t('nav.login')}</span>
                </Link>
              </li>
            )}
            
            {/* Separador */}
            <li className="border-b border-gray-200 my-2"></li>
            
            {/* Navegación principal */}
            <li>
              <Link 
                to={localizedPath('/')} 
                className={`block py-2 px-4 rounded-md ${activeTab === 'inicio' ? 'bg-primario text-white hover:bg-primario-dark hover:text-white' : 'hover:bg-gray-100'}`}
                onClick={() => {
                  setActiveTab('inicio');
                  onClose();
                }}
              >
                {t('nav.home')}
              </Link>
            </li>
            <li>
              <Link 
                to={localizedPath('/toures')} 
                className={`block py-2 px-4 rounded-md ${activeTab === 'toures' ? 'bg-primario text-white hover:bg-primario-dark hover:text-white' : 'hover:bg-gray-100'}`}
                onClick={() => {
                  setActiveTab('toures');
                  onClose();
                }}
              >
                {t('nav.tours')}
                <span className="ml-2 bg-red-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full leading-none">{t('nav.toursNew')}</span>
              </Link>
            </li>
            <li>
              <Link 
                to={localizedPath('/catalogo')} 
                className={`block py-2 px-4 rounded-md ${activeTab === 'catalogo' ? 'bg-primario text-white hover:bg-primario-dark hover:text-white' : 'hover:bg-gray-100'}`}
                onClick={() => {
                  setActiveTab('catalogo');
                  onClose();
                }}
              >
                {t('nav.catalog')}
              </Link>
            </li>
            
            {categories.length > 0 && (
              categories.map(category => (
                <li key={category.id} className="py-1">
                  <div className="flex flex-col">
                    <Link 
                      to={localizedPath(buildCatalogUrl(category.slug, category.min_membership_level ?? 0, levels))}
                      className={`block py-2 px-4 rounded-md ${activeTab === category.slug ? 'bg-primario text-white hover:bg-primario-dark hover:text-white' : 'hover:bg-gray-100'}`}
                      onClick={() => {
                        setActiveTab(category.slug);
                        onClose();
                      }}
                    >
                      {category.name}
                    </Link>
                    
                    {category.subcategories && category.subcategories.length > 0 && (
                      <div className="ml-4 mt-1 space-y-1">
                        {category.subcategories.map((node: any) => (
                          <div key={node.id}>
                            <div className="flex items-center justify-between">
                              <Link
                                to={localizedPath(buildCatalogUrl(node.slug, node.min_membership_level ?? 0, levels))}
                                className="block py-1 px-4 text-sm font-semibold text-texto hover:bg-secundario hover:text-primario rounded-md transition-colors"
                                onClick={onClose}
                              >
                                {node.name}
                              </Link>
                              {node.children && node.children.length > 0 && (
                                <button
                                  aria-label={t('submenuAria')}
                                  className={`p-2 text-texto hover:text-primario`}
                                  onClick={(e) => { e.preventDefault(); e.stopPropagation(); toggleNode(node.id as number); }}
                                >
                                  <svg xmlns="http://www.w3.org/2000/svg" className={`h-5 w-5 transform transition-transform ${openNodes[node.id] ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                                  </svg>
                                </button>
                              )}
                            </div>
                            {node.children && node.children.length > 0 && openNodes[node.id] && (
                              <div className="ml-3 mt-1 space-y-1 border-l border-secundario/20 pl-3">
                                {node.children.map((child: any) => (
                                  <div key={child.id}>
                                    <div className="flex items-center justify-between">
                                      <Link
                                        to={localizedPath(buildCatalogUrl(child.slug, child.min_membership_level ?? 0, levels))}
                                        className="block py-1 px-4 text-xs font-medium text-texto hover:bg-secundario hover:text-primario rounded-md transition-colors"
                                        onClick={onClose}
                                      >
                                        {child.name}
                                      </Link>
                                      {child.children && child.children.length > 0 && (
                                        <button
                                          aria-label={t('submenuAria')}
                                          className={`p-2 text-texto hover:text-primario`}
                                          onClick={(e) => { e.preventDefault(); e.stopPropagation(); toggleNode(child.id as number); }}
                                        >
                                          <svg xmlns="http://www.w3.org/2000/svg" className={`h-5 w-5 transform transition-transform ${openNodes[child.id] ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                                          </svg>
                                        </button>
                                      )}
                                    </div>
                                    {child.children && child.children.length > 0 && openNodes[child.id] && (
                                      <div className="ml-3 mt-1 space-y-1 border-l border-secundario/20 pl-3">
                                        {child.children.map((grand: any) => (
                                          <Link
                                            key={grand.id}
                                            to={localizedPath(buildCatalogUrl(grand.slug, grand.min_membership_level ?? 0, levels))}
                                            className="block py-1 px-4 text-[11px] text-texto hover:bg-secundario hover:text-primario rounded-md transition-colors"
                                            onClick={onClose}
                                          >
                                            {grand.name}
                                          </Link>
                                        ))}
                                      </div>
                                    )}
                                  </div>
                                ))}
                              </div>
                            )}
                          </div>
                        ))}
                      </div>
                    )}
                  </div>
                </li>
              ))
            )}
            
            {/* Separador antes de las opciones finales */}
            <li className="border-b border-gray-200 my-2"></li>
            
            {isAuthenticated && (
              <>
                <li>
                  <div 
                    className="flex items-center w-full py-2 px-4 rounded-md hover:bg-gray-100 cursor-pointer text-primario"
                    onClick={() => {
                      if (openProfileModal) {
                        openProfileModal('orders');
                        onClose();
                      }
                    }}
                  >
                    <FiPackage className="h-5 w-5 mr-2" />
                    <span>{t('nav.myOrders')}</span>
                  </div>
                </li>
                <li>
                  <div 
                    className="flex items-center w-full py-2 px-4 rounded-md hover:bg-gray-100 cursor-pointer text-primario"
                    onClick={() => {
                      if (openProfileModal) {
                        openProfileModal('digitalCard');
                        onClose();
                      }
                    }}
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                    </svg>
                    <span>{t('nav.digitalCard')}</span>
                  </div>
                </li>
              </>
            )}
            
            <li>
              <div 
                className="flex items-center w-full py-2 px-4 rounded-md hover:bg-gray-100 cursor-pointer"
                onClick={() => {
                  if (openHelpModal) {
                    openHelpModal();
                    onClose();
                  }
                }}
              >
                <FiHelpCircle className="h-5 w-5 mr-2" />
                <span>{t('nav.helpCenter')}</span>
              </div>
            </li>
            
            <li>
              <div 
                className={`flex items-center w-full py-2 px-4 rounded-md cursor-pointer ${normalizePath(location.pathname) === '/contacto' ? 'bg-primario text-white hover:bg-primario-dark hover:text-white' : 'hover:bg-gray-100'}`}
                onClick={() => {
                  setActiveTab('contacto');
                  navigate(localizedPath('/contacto'));
                  onClose();
                }}
              >
                <FiMail className="h-5 w-5 mr-2" />
                <span>{t('nav.contact')}</span>
              </div>
            </li>
            
          </ul>
        </nav>
        
        {/* Footer fijo con botón de cerrar sesión */}
        {isAuthenticated && (
          <div className="sticky bottom-0 bg-white border-t border-gray-200 p-4">
            <button 
              className="flex items-center w-full py-2 px-4 rounded-md text-red-600 hover:bg-red-50"
              onClick={handleLogout}
            >
              <FiLogOut className="h-5 w-5 mr-2" />
              <span>{t('logout.button')}</span>
            </button>
          </div>
        )}
      </div>
      
      {/* Modal de confirmación de cierre de sesión */}
      <ConfirmModal
        isOpen={isLogoutConfirmOpen}
        onClose={() => setIsLogoutConfirmOpen(false)}
        onConfirm={confirmLogout}
        title={t('logout.title')}
        message={t('logout.message')}
        confirmText={t('logout.confirm')}
        cancelText={t('logout.cancel')}
        variant="warning"
      />
    </div>
  );
};

export default MobileMenu;
