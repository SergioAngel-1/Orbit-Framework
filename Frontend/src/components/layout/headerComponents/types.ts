/**
 * Tipos compartidos para los componentes del Header
 */

export interface HeaderProps {
  isScrolled: boolean;
  scrollDirection: 'up' | 'down';
  isAuthenticated: boolean;
  cartItemCount: number;
  searchTerm: string;
  setSearchTerm: (term: string) => void;
  showResults: boolean;
  setShowResults: (show: boolean) => void;
  openProfileModal: (section?: 'profile' | 'addresses' | 'orders' | 'referrals' | 'membership') => void;
  openHelpModal: (tab?: 'help' | 'howToRequest' | 'coinsSystem') => void;
  openCartModal: () => void;
  openVirtualCoinsModal: () => void;
  handleCartClick: () => void;
  toggleMobileMenu: () => void;
}

export type ProfileSection = 'profile' | 'addresses' | 'orders' | 'referrals' | 'membership' | 'digitalCard';
export type HelpTab = 'help' | 'howToRequest' | 'coinsSystem';
