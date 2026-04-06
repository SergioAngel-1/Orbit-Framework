/**
 * Header - Componente principal que orquesta HeaderMobile y HeaderDesktop
 */
import { useState, useEffect, useRef } from 'react';
import { gsap } from 'gsap';
import { useTranslation } from 'react-i18next';
import { useAuth } from '../../contexts/AuthContext';
import { useCart } from '../../contexts/CartContext';
import ProfileModal from '../profile/ProfileModal';
import HelpModal from '../help/HelpModal';
import CartModal from '../cart/CartModal';
import VirtualCoinsModal from '../modals/PointsModal';
import MobileMenu from './MobileMenu';
import useWordPressMenu from '../../hooks/useWordPressMenu';
import alertService from '../../services/alertService';
import { HeaderMobile, HeaderDesktop, ProfileSection, HelpTab } from './headerComponents';

const Header = () => {
  const { t } = useTranslation('header');
  const [isProfileModalOpen, setIsProfileModalOpen] = useState(false);
  const [isHelpModalOpen, setIsHelpModalOpen] = useState(false);
  const [isCartModalOpen, setIsCartModalOpen] = useState(false);
  const [isVirtualCoinsModalOpen, setIsVirtualCoinsModalOpen] = useState(false);
  const [helpModalInitialTab, setHelpModalInitialTab] = useState<HelpTab>('help');
  const [activeTab, setActiveTab] = useState('inicio');
  const [searchTerm, setSearchTerm] = useState('');
  const [showResults, setShowResults] = useState(false);
  const [isScrolled, setIsScrolled] = useState(false);
  const [scrollDirection, setScrollDirection] = useState<'up' | 'down'>('up');
  const lastScrollY = useRef(0);
  const [isMenuOpen, setIsMenuOpen] = useState(false);
  const [activeProfileSection, setActiveProfileSection] = useState<ProfileSection>('profile');
  const desktopSearchRef = useRef<HTMLDivElement>(null);
  const mobileSearchRef = useRef<HTMLDivElement>(null);
  const { isAuthenticated, isPending } = useAuth();
  const { items } = useCart();

  // Obtener categorías del menú desde WordPress (para MobileMenu)
  const { menuCategories } = useWordPressMenu();

  // Funciones
  const toggleMobileMenu = () => setIsMenuOpen(!isMenuOpen);

  // Manejar clic en el carrito
  const handleCartClick = () => {
    if (items.length === 0) {
      alertService.warning(t('cartEmptyAlert'));
    } else {
      openCartModal();
    }
  };

  // Abrir modal de perfil
  const openProfileModal = (section: ProfileSection = 'profile') => {
    setActiveProfileSection(section);
    setIsProfileModalOpen(true);
  };

  // Cerrar modal de perfil
  const closeProfileModal = () => {
    setIsProfileModalOpen(false);
  };

  // Abrir/cerrar modal de ayuda
  const openHelpModal = (tab: HelpTab = 'help') => {
    setHelpModalInitialTab(tab);
    setIsHelpModalOpen(true);
  };

  const closeHelpModal = () => {
    setIsHelpModalOpen(false);
  };

  // Abrir/cerrar modal de carrito
  const openCartModal = () => {
    setIsCartModalOpen(true);
  };

  const closeCartModal = () => {
    setIsCartModalOpen(false);
  };

  // Abrir/cerrar modal de Virtual Coins
  const openVirtualCoinsModal = () => {
    setIsVirtualCoinsModalOpen(true);
  };

  const closeVirtualCoinsModal = () => {
    setIsVirtualCoinsModalOpen(false);
  };

  // Calcular el número de elementos en el carrito
  const cartItemCount = items.reduce((total, item) => total + item.quantity, 0);

  // Efecto para manejar el scroll y su dirección
  useEffect(() => {
    const handleScroll = () => {
      const currentScrollY = window.scrollY;
      setIsScrolled(currentScrollY > 50);
      
      // Detectar dirección del scroll
      if (currentScrollY > lastScrollY.current && currentScrollY > 50) {
        setScrollDirection('down'); // Bajando
      } else if (currentScrollY < lastScrollY.current) {
        setScrollDirection('up'); // Subiendo
      }
      
      lastScrollY.current = currentScrollY;
    };

    window.addEventListener('scroll', handleScroll, { passive: true });
    return () => window.removeEventListener('scroll', handleScroll);
  }, []);

  // Efecto para cerrar resultados de búsqueda al hacer clic fuera
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      const target = event.target as Node;
      const isOutsideDesktopSearch = desktopSearchRef.current && !desktopSearchRef.current.contains(target);
      const isOutsideMobileSearch = mobileSearchRef.current && !mobileSearchRef.current.contains(target);

      // Ignorar clics dentro del dropdown de resultados (renderizado via portal en document.body)
      const targetEl = event.target as HTMLElement;
      if (targetEl.closest?.('.search-results-portal')) {
        return;
      }

      if (showResults && isOutsideDesktopSearch && isOutsideMobileSearch) {
        setTimeout(() => {
          setShowResults(false);
        }, 100);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
    };
  }, [showResults]);

  // Escuchar evento personalizado para abrir el modal de perfil
  useEffect(() => {
    const handleOpenProfileSection = (event: Event) => {
      const customEvent = event as CustomEvent;
      const { section } = customEvent.detail;
      if (section) {
        openProfileModal(section as ProfileSection);
      }
    };

    document.addEventListener('openProfileSection', handleOpenProfileSection);
    window.addEventListener('openProfileModal', handleOpenProfileSection);

    return () => {
      document.removeEventListener('openProfileSection', handleOpenProfileSection);
      window.removeEventListener('openProfileModal', handleOpenProfileSection);
    };
  }, []);

  // Animaciones con GSAP - usando contexto para cleanup automático
  useEffect(() => {
    const ctx = gsap.context(() => {
      const logoElement = document.querySelector('.logo');
      const navItems = document.querySelectorAll('.nav-item');

      if (logoElement) {
        gsap.fromTo(
          logoElement,
          { opacity: 0, y: -20 },
          { opacity: 1, y: 0, duration: 0.8, ease: 'power2.out' }
        );
      }

      if (navItems.length) {
        gsap.fromTo(
          navItems,
          { opacity: 0, y: -10 },
          {
            opacity: 1,
            y: 0,
            duration: 0.5,
            stagger: 0.1,
            ease: 'power2.out',
            delay: 0.3
          }
        );
      }
    });

    // Cleanup: mata todas las animaciones del contexto al desmontar
    return () => ctx.revert();
  }, []);

  return (
    <>
      {/* Header Mobile */}
      <HeaderMobile
        isScrolled={isScrolled}
        scrollDirection={scrollDirection}
        cartItemCount={cartItemCount}
        searchTerm={searchTerm}
        setSearchTerm={setSearchTerm}
        showResults={showResults}
        setShowResults={setShowResults}
        handleCartClick={handleCartClick}
        toggleMobileMenu={toggleMobileMenu}
        openVirtualCoinsModal={openVirtualCoinsModal}
        searchRef={mobileSearchRef}
      />

      {/* Header Desktop */}
      <HeaderDesktop
        isScrolled={isScrolled}
        scrollDirection={scrollDirection}
        isAuthenticated={isAuthenticated}
        isPending={isPending}
        cartItemCount={cartItemCount}
        searchTerm={searchTerm}
        setSearchTerm={setSearchTerm}
        showResults={showResults}
        setShowResults={setShowResults}
        openProfileModal={openProfileModal}
        openHelpModal={openHelpModal}
        openCartModal={openCartModal}
        openVirtualCoinsModal={openVirtualCoinsModal}
        toggleMobileMenu={toggleMobileMenu}
        searchRef={desktopSearchRef}
      />

      {/* Menú móvil */}
      <div className={`${isMenuOpen ? 'fixed top-0 left-0 right-0 z-50' : ''}`}>
        <MobileMenu
          isOpen={isMenuOpen}
          onClose={toggleMobileMenu}
          activeTab={activeTab}
          setActiveTab={setActiveTab}
          categories={menuCategories}
          openProfileModal={openProfileModal}
          openHelpModal={() => openHelpModal('help')}
        />
      </div>

      {/* Modales */}
      {isProfileModalOpen && (
        <ProfileModal 
          isOpen={isProfileModalOpen}
          onClose={closeProfileModal} 
          activeSection={activeProfileSection}
        />
      )}
      
      {isHelpModalOpen && (
        <HelpModal 
          isOpen={isHelpModalOpen}
          onClose={closeHelpModal}
          initialTab={helpModalInitialTab}
        />
      )}

      {isCartModalOpen && (
        <CartModal
          isOpen={isCartModalOpen}
          onClose={closeCartModal}
        />
      )}

      {isVirtualCoinsModalOpen && (
        <VirtualCoinsModal
          isOpen={isVirtualCoinsModalOpen}
          onClose={closeVirtualCoinsModal}
          onOpenHelpModal={() => openHelpModal('coinsSystem')}
        />
      )}
    </>
  );
};

export default Header;
