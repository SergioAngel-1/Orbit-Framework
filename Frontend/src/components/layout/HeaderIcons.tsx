import { FC, memo } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useLanguage } from '../../contexts/LanguageContext';
import alertService from '../../services/alertService';
import { FiUser, FiShoppingCart, FiCreditCard } from 'react-icons/fi';
import { useSiteFeatures } from '../../contexts/SiteConfigContext';

interface HeaderIconsProps {
  cartItemCount: number;
  openProfileModal: () => void;
  openCartModal: () => void;
  isAuthenticated: boolean;
  isPending?: boolean;
}

const HeaderIcons: FC<HeaderIconsProps> = memo(({ 
  cartItemCount, 
  openProfileModal, 
  openCartModal, 
  isAuthenticated,
  isPending = false
}) => {
  const navigate = useNavigate();
  const { t } = useTranslation('headerIcons');
  const { localizedPath } = useLanguage();
  const features = useSiteFeatures();

  const handleCartClick = (e: React.MouseEvent) => {
    e.preventDefault(); // Prevenir la navegación a /carrito
    
    if (cartItemCount === 0) {
      alertService.info(t('cartEmptyAlert'));
    } else {
      openCartModal();
    }
  };

  const handleAccountClick = () => {
    if (isAuthenticated) {
      openProfileModal();
    } else {
      // Redirigir a /login en lugar de abrir modal
      navigate(localizedPath('/iniciar-sesion'));
    }
  };

  const handleWalletClick = () => {
    // Navegar a la página de wallet (funciona para autenticados y no autenticados)
    navigate(localizedPath('/fondo-de-aportes'));
  };

  return (
    <div className="flex items-center space-x-2 sm:space-x-6">
      {/* Icono de billetera - visible solo si referrals_points está activo */}
      {features.referrals_points && (
        <button 
          className="flex flex-col items-center text-primario hover:text-primario-dark active:text-oscuro transition-colors duration-300 relative py-0.5 sm:py-2 px-1 sm:px-3 rounded-md icon-push-effect"
          onClick={handleWalletClick}
          aria-label={t('walletAriaLabel')}
        >
          <FiCreditCard className="h-5 w-5 sm:h-6 sm:w-6" />
          <span className="text-[10px] sm:text-xs font-bold mt-1">
            {t('wallet')}
          </span>
        </button>
      )}
      
      {/* Icono de cuenta */}
      <button 
        className="flex flex-col items-center text-primario hover:text-primario-dark active:text-oscuro transition-colors duration-300 relative py-0.5 sm:py-2 px-1 sm:px-3 rounded-md icon-push-effect"
        onClick={handleAccountClick}
        aria-label={isAuthenticated ? t('myAccount') : t('login')}
      >
        <FiUser className="h-5 w-5 sm:h-6 sm:w-6" />
        <span className="text-[10px] sm:text-xs font-bold mt-1">
          {isAuthenticated ? t('myAccount') : t('login')}
        </span>
        {isPending && (
          <span className="absolute -top-1 right-0 bg-yellow-500 text-white text-xs rounded-full h-4 w-4 flex items-center justify-center">
            !
          </span>
        )}
      </button>
      
      {/* Icono de carrito */}
      <button 
        className="flex flex-col items-center text-primario hover:text-primario-dark active:text-oscuro transition-colors duration-300 relative py-0.5 sm:py-2 px-1 sm:px-3 rounded-md icon-push-effect"
        onClick={handleCartClick}
        aria-label={t('cartAriaLabel')}
      >
        <FiShoppingCart className="h-5 w-5 sm:h-6 sm:w-6" />
        <span className="text-[10px] sm:text-xs font-bold mt-1">
          {t('cart')}
        </span>
        {cartItemCount > 0 && (
          <span className="absolute -top-1 -right-1 bg-primario text-white text-xs rounded-full h-4 w-4 sm:h-5 sm:w-5 flex items-center justify-center">
            {cartItemCount}
          </span>
        )}
      </button>
    </div>
  );
});

export default HeaderIcons;
