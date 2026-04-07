/**
 * HeaderDesktop - Header para desktop
 * Se vuelve fijo al hacer scroll, con top bar y carousel que se ocultan al bajar
 */
import { FC } from 'react';
import { useLanguage } from '../../../contexts/LanguageContext';
import ScrollToTopLink from '../../common/ScrollToTopLink';
import SiteLogo from '../../common/SiteLogo';
import SearchBar from '../SearchBar';
import HeaderIcons from '../HeaderIcons';
import CategoryCarousel from '../CategoryCarousel';
import TopBar from './TopBar';
import VirtualCoin from './VirtualCoin';
import { ProfileSection, HelpTab } from './types';

interface HeaderDesktopProps {
  isScrolled: boolean;
  scrollDirection: 'up' | 'down';
  isAuthenticated: boolean;
  isPending?: boolean;
  cartItemCount: number;
  searchTerm: string;
  setSearchTerm: (term: string) => void;
  showResults: boolean;
  setShowResults: (show: boolean) => void;
  openProfileModal: (section?: ProfileSection) => void;
  openHelpModal: (tab?: HelpTab) => void;
  openCartModal: () => void;
  openVirtualCoinsModal: () => void;
  toggleMobileMenu: () => void;
  searchRef: React.RefObject<HTMLDivElement | null>;
}

const HeaderDesktop: FC<HeaderDesktopProps> = ({
  isScrolled,
  scrollDirection,
  isAuthenticated,
  isPending,
  cartItemCount,
  searchTerm,
  setSearchTerm,
  showResults,
  setShowResults,
  openProfileModal,
  openHelpModal,
  openCartModal,
  openVirtualCoinsModal,
  toggleMobileMenu,
  searchRef
}) => {
  const { localizedPath } = useLanguage();
  const openAddressSection = () => openProfileModal('addresses');

  return (
    <>
      {/* Wrapper - siempre fijo en desktop cuando hay scroll */}
      <div className={`hidden md:block bg-white transition-all duration-300 ${
        isScrolled ? 'fixed top-0 left-0 right-0 z-50 shadow-md' : 'relative z-40'
      }`}>
        {/* Top Bar - se oculta al bajar */}
        <TopBar
          isScrolled={isScrolled}
          scrollDirection={scrollDirection}
          isAuthenticated={isAuthenticated}
          openProfileModal={openProfileModal}
          openHelpModal={openHelpModal}
          openAddressSection={openAddressSection}
          onMenuToggle={toggleMobileMenu}
        />

        {/* Header principal */}
        <header className="w-full font-poppins bg-white transition-all duration-300 py-3 pb-4 overflow-visible">
          <div className="w-full max-w-[1920px] mx-auto px-4 overflow-visible" ref={searchRef}>
            <div className="flex justify-between items-center relative overflow-visible">
              {/* Logo + Moneda */}
              <div className="flex items-center gap-4">
                <ScrollToTopLink to={localizedPath('/')} className="flex items-center logo">
                  <SiteLogo maxHeight={48} maxWidth={160} />
                </ScrollToTopLink>
                
                <VirtualCoin onClick={openVirtualCoinsModal} size="lg" />
              </div>

              {/* Barra de búsqueda centrada - solo visible en lg+ (inline) */}
              <div
                className="hidden lg:block absolute left-1/2 transform -translate-x-1/2 w-full max-w-md xl:max-w-xl 2xl:max-w-2xl px-4"
              >
                <SearchBar
                  searchTerm={searchTerm}
                  setSearchTerm={setSearchTerm}
                  showResults={showResults}
                  setShowResults={setShowResults}
                />
              </div>

              {/* Iconos de cuenta y carrito */}
              <HeaderIcons
                cartItemCount={cartItemCount}
                isAuthenticated={isAuthenticated}
                isPending={isPending}
                openProfileModal={openProfileModal}
                openCartModal={openCartModal}
              />
            </div>

            {/* Barra de búsqueda en fila separada - solo visible en tablet (md a lg) */}
            <div className="lg:hidden mt-2 px-2">
              <SearchBar
                searchTerm={searchTerm}
                setSearchTerm={setSearchTerm}
                showResults={showResults}
                setShowResults={setShowResults}
              />
            </div>
          </div>
        </header>

      </div>
      
      {/* Espaciador que aparece cuando el header está fijo para evitar salto de contenido */}
      {isScrolled && <div className="hidden md:block h-[100px]"></div>}

      {/* Carousel de categorías - fuera del sticky, se queda en el flujo normal */}
      <div className="hidden md:block bg-white pt-4 pb-2 relative z-30">
        <div className="w-full max-w-[1920px] mx-auto px-6">
          <CategoryCarousel onMenuToggle={toggleMobileMenu} />
        </div>
      </div>
    </>
  );
};

export default HeaderDesktop;
