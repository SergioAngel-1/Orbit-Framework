/**
 * HeaderMobile - Header para dispositivos móviles
 * Fixed siempre, con buscador y carousel que se ocultan al bajar
 */
import { FC, useState } from 'react';
import { FiShoppingCart, FiSearch, FiX } from 'react-icons/fi';
import { useTranslation } from 'react-i18next';
import { useLanguage } from '../../../contexts/LanguageContext';
import floresLogo from '../../../assets/images/flores-logo.png';
import ScrollToTopLink from '../../common/ScrollToTopLink';
import SearchBar from '../SearchBar';
import CategoryCarousel from '../CategoryCarousel';
import VirtualCoin from './VirtualCoin';

interface HeaderMobileProps {
  isScrolled: boolean;
  scrollDirection: 'up' | 'down';
  cartItemCount: number;
  searchTerm: string;
  setSearchTerm: (term: string) => void;
  showResults: boolean;
  setShowResults: (show: boolean) => void;
  handleCartClick: () => void;
  toggleMobileMenu: () => void;
  openVirtualCoinsModal: () => void;
  searchRef: React.RefObject<HTMLDivElement | null>;
}

const HeaderMobile: FC<HeaderMobileProps> = ({
  isScrolled,
  scrollDirection,
  cartItemCount,
  searchTerm,
  setSearchTerm,
  showResults,
  setShowResults,
  handleCartClick,
  toggleMobileMenu,
  openVirtualCoinsModal,
  searchRef
}) => {
  const { t } = useTranslation('header');
  const { localizedPath } = useLanguage();
  const [isSearchOpen, setIsSearchOpen] = useState(false);

  return (
    <>
      {/* Espaciador para el header fijo (sin carousel ni buscador) */}
      <div className="w-full h-[60px] md:hidden"></div>
      
      {/* Header fijo */}
      <header className={`md:hidden w-full transition-all duration-300 font-poppins py-3 bg-white fixed top-0 left-0 right-0 z-50 ${
        isScrolled ? 'shadow-sm' : ''
      } ${scrollDirection === 'down' && isScrolled ? 'pb-2' : 'pb-2'}`}>
        <div className="w-full max-w-[1920px] mx-auto px-2">
          <div className="flex items-center justify-between">
            {/* Izquierda: Menú + Moneda */}
            <div className="flex items-center gap-3 flex-1">
              <button
                className="text-primario hover:text-primario-dark active:text-oscuro p-1.5 bg-claro hover:bg-secundario border-0 rounded-lg"
                onClick={toggleMobileMenu}
                aria-label={t('menuAriaLabel')}
              >
                <svg xmlns="http://www.w3.org/2000/svg" className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
                </svg>
              </button>
              <VirtualCoin onClick={openVirtualCoinsModal} size="sm" />
            </div>

            {/* Centro: Logo */}
            <ScrollToTopLink to={localizedPath('/')} className="flex items-center logo flex-shrink-0">
              <img src={floresLogo} alt={t('logoAlt')} className="h-8" />
            </ScrollToTopLink>

            {/* Derecha: Buscar + Carrito */}
            <div className="flex items-center gap-3 flex-1 justify-end">
              <button
                className="text-primario hover:text-primario-dark active:text-oscuro p-1.5 bg-claro hover:bg-secundario border-0 rounded-lg"
                onClick={() => setIsSearchOpen(!isSearchOpen)}
                aria-label={isSearchOpen ? t('closeSearch') : t('searchAriaLabel')}
              >
                {isSearchOpen ? <FiX className="h-6 w-6" /> : <FiSearch className="h-6 w-6" />}
              </button>
              <button
                className="text-primario hover:text-primario-dark active:text-oscuro relative p-1.5 bg-claro hover:bg-secundario border-0 rounded-lg"
                onClick={handleCartClick}
                aria-label={t('cartAriaLabel', { ns: 'headerIcons' })}
              >
                <FiShoppingCart className="h-6 w-6" />
                {cartItemCount > 0 && (
                  <span className="absolute -top-1 -right-1 bg-primario text-white text-xs rounded-full h-4 w-4 flex items-center justify-center">
                    {cartItemCount}
                  </span>
                )}
              </button>
            </div>
          </div>

          {/* Barra de búsqueda - se abre/cierra con el botón de lupa */}
          <div
            className={`relative transition-all duration-300 z-20 ${
              isSearchOpen ? 'opacity-100 mt-3' : 'opacity-0 h-0 overflow-hidden'
            }`}
            ref={searchRef}
          >
            <SearchBar
              searchTerm={searchTerm}
              setSearchTerm={setSearchTerm}
              showResults={showResults}
              setShowResults={setShowResults}
            />
          </div>
        </div>

      </header>

      {/* Carousel de categorías - fuera del sticky, se queda en el flujo normal */}
      <div className="md:hidden bg-white pt-0 pb-1 relative z-30">
        <div className="w-full max-w-[1920px] mx-auto px-2">
          <CategoryCarousel onMenuToggle={toggleMobileMenu} />
        </div>
      </div>
    </>
  );
};

export default HeaderMobile;
