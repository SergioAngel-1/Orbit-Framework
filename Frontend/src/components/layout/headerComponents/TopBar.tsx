/**
 * TopBar - Barra superior con enlaces de navegación (solo desktop)
 */
import { FC } from 'react';
import { useTranslation } from 'react-i18next';
import { useLanguage } from '../../../contexts/LanguageContext';
import ScrollToTopLink from '../../common/ScrollToTopLink';
import LanguageSwitch from '../../common/LanguageSwitch';
import AddressBar from '../AddressBar';
import { ProfileSection, HelpTab } from './types';
import { useSiteFeatures } from '../../../contexts/SiteConfigContext';

interface TopBarProps {
  isScrolled: boolean;
  scrollDirection: 'up' | 'down';
  isAuthenticated: boolean;
  openProfileModal: (section?: ProfileSection) => void;
  openHelpModal: (tab?: HelpTab) => void;
  openAddressSection: () => void;
  onMenuToggle?: () => void;
}

const TopBar: FC<TopBarProps> = ({
  isScrolled,
  scrollDirection,
  isAuthenticated,
  openProfileModal,
  openHelpModal,
  openAddressSection,
  onMenuToggle
}) => {
  const { t } = useTranslation('topbar');
  const { localizedPath } = useLanguage();
  const features = useSiteFeatures();

  return (
    <div className={`bg-white text-primario pb-2 hidden sm:block transition-all duration-300 ${
      scrollDirection === 'down' && isScrolled ? 'h-0 overflow-hidden pb-0 opacity-0' : 'opacity-100'
    }`}>
      <div className="w-full max-w-[1920px] mx-auto px-2 sm:px-4">
        <div className="flex justify-between items-center">
          <div className="flex items-center space-x-2">
            {isScrolled && onMenuToggle && (
              <span
                onClick={onMenuToggle}
                className="text-primario text-sm font-bold hidden md:inline-block relative group px-2 pt-0 pb-1 shadow-sm rounded-b-lg overflow-hidden border-b-2 border-l-2 border-r-2 border-primario cursor-pointer tab-push-effect"
              >
                <span className="absolute inset-0 bg-white group-hover:bg-gray-50 transition-colors duration-300 -z-10 rounded-b-lg"></span>
                <svg className="h-4 w-4 inline mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
                </svg>
                {t('menu')}
              </span>
            )}
            <AddressBar
              openProfileModal={openProfileModal}
              openAddressSection={openAddressSection}
            />
            <LanguageSwitch />
            <ScrollToTopLink
              to={localizedPath('/toures')}
              className="tab-push hover:text-white"
            >
              {t('tours')}
            </ScrollToTopLink>
          </div>

          <div className="flex items-center space-x-2">
            {isAuthenticated && (
              <ScrollToTopLink
                to={localizedPath('/mis-pedidos')}
                className="tab-push hover:text-white"
                onClick={(e) => {
                  e.preventDefault();
                  openProfileModal('orders');
                }}
              >
                {t('myOrders')}
              </ScrollToTopLink>
            )}
            {features.memberships && (
              <ScrollToTopLink
                to={localizedPath('/membresias')}
                className="tab-push hover:text-white"
              >
                {t('myMembership')}
              </ScrollToTopLink>
            )}
            {features.referrals_points && (
              <ScrollToTopLink
                to={localizedPath('/invitados')}
                className="tab-push hover:text-white"
              >
                {t('myReferrals')}
              </ScrollToTopLink>
            )}
            <ScrollToTopLink
              to="#"
              className="tab-push hover:text-white"
              onClick={(e) => {
                e.preventDefault();
                openHelpModal('howToRequest');
              }}
            >
              {t('howToOrder')}
            </ScrollToTopLink>
            <ScrollToTopLink
              to="#"
              className="tab-push hidden lg:inline-block hover:text-white"
              onClick={(e) => {
                e.preventDefault();
                openHelpModal('help');
              }}
            >
              {t('help')}
            </ScrollToTopLink>
            <ScrollToTopLink
              to={localizedPath('/contacto')}
              className="tab-push hidden lg:inline-block hover:text-white"
            >
              {t('contact')}
            </ScrollToTopLink>
          </div>
        </div>
      </div>
    </div>
  );
};

export default TopBar;
