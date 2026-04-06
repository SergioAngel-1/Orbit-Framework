/**
 * WalletEmptyState - Componente para mostrar cuando el usuario no tiene FC
 * Call to action para ganar Virtual Coins
 * Usa fluidSizing y paleta de colores del tema
 */

import { FC } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { FiGift, FiUsers, FiAward, FiShoppingCart } from 'react-icons/fi';
import { fluidSizing } from '../../utils/fluidSizing';
import { useLanguage } from '../../contexts/LanguageContext';

interface WalletEmptyStateProps {
  className?: string;
  onBuyCoins?: () => void;
  canBuyPackages?: boolean;
}

const WalletEmptyState: FC<WalletEmptyStateProps> = ({ className = '', onBuyCoins, canBuyPackages = false }) => {
  const { t } = useTranslation('walletComponents');
  const { localizedPath } = useLanguage();
  return (
    <div 
      className={`bg-white rounded-lg shadow-sm border border-gray-100 text-center ${className}`}
      style={{ padding: fluidSizing.space.xl }}
    >
      <div 
        className="flex justify-center" 
        style={{ marginBottom: fluidSizing.space.md }}
      >
        <div 
          className="bg-secundario rounded-full flex items-center justify-center"
          style={{ 
            width: fluidSizing.size.floatingButton, 
            height: fluidSizing.size.floatingButton 
          }}
        >
          <FiGift 
            className="text-primario" 
            style={{ 
              width: fluidSizing.size.iconXl, 
              height: fluidSizing.size.iconXl 
            }} 
          />
        </div>
      </div>
      
      <h3 
        className="font-semibold text-oscuro" 
        style={{ 
          fontSize: fluidSizing.text.xl,
          marginBottom: fluidSizing.space.xs
        }}
      >
        {t('emptyState.title')}
      </h3>
      <p 
        className="text-texto max-w-md mx-auto" 
        style={{ 
          fontSize: fluidSizing.text.sm,
          marginBottom: fluidSizing.space.lg
        }}
      >
        {t('emptyState.description')}
      </p>

      <div 
        className="grid grid-cols-1 sm:grid-cols-3 max-w-2xl mx-auto" 
        style={{ 
          gap: fluidSizing.space.md,
          marginBottom: fluidSizing.space.lg
        }}
      >
        <div 
          className="bg-secundario/50 rounded-lg"
          style={{ padding: fluidSizing.space.md }}
        >
          <FiUsers 
            className="text-primario mx-auto" 
            style={{ 
              width: fluidSizing.size.iconLg, 
              height: fluidSizing.size.iconLg,
              marginBottom: fluidSizing.space.xs
            }} 
          />
          <p className="font-medium text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>{t('emptyState.inviteFriends')}</p>
          <p className="text-texto" style={{ fontSize: fluidSizing.text.xs, marginTop: fluidSizing.space.xs }}>{t('emptyState.inviteDesc')}</p>
        </div>
        
        <div 
          className="bg-secundario/50 rounded-lg"
          style={{ padding: fluidSizing.space.md }}
        >
          <FiAward 
            className="text-primario mx-auto" 
            style={{ 
              width: fluidSizing.size.iconLg, 
              height: fluidSizing.size.iconLg,
              marginBottom: fluidSizing.space.xs
            }} 
          />
          <p className="font-medium text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>{t('emptyState.membership')}</p>
          <p className="text-texto" style={{ fontSize: fluidSizing.text.xs, marginTop: fluidSizing.space.xs }}>{t('emptyState.membershipDesc')}</p>
        </div>
        
        <div 
          className="bg-secundario/50 rounded-lg"
          style={{ padding: fluidSizing.space.md }}
        >
          <FiGift 
            className="text-primario mx-auto" 
            style={{ 
              width: fluidSizing.size.iconLg, 
              height: fluidSizing.size.iconLg,
              marginBottom: fluidSizing.space.xs
            }} 
          />
          <p className="font-medium text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>{t('emptyState.fcPackages')}</p>
          <p className="text-texto" style={{ fontSize: fluidSizing.text.xs, marginTop: fluidSizing.space.xs }}>{t('emptyState.fcPackagesDesc')}</p>
        </div>
      </div>

      <div 
        className="flex flex-col sm:flex-row justify-center" 
        style={{ gap: fluidSizing.space.sm }}
      >
        <Link
          to={localizedPath('/invitados')}
          className="inline-flex items-center justify-center bg-primario text-white font-medium rounded-lg hover:bg-hover hover:text-white transition-colors"
          style={{
            paddingLeft: fluidSizing.space.lg,
            paddingRight: fluidSizing.space.lg,
            paddingTop: fluidSizing.space.sm,
            paddingBottom: fluidSizing.space.sm,
            fontSize: fluidSizing.text.sm
          }}
        >
          <FiUsers style={{ marginRight: fluidSizing.space.xs, width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
          {t('emptyState.goToReferrals')}
        </Link>
        <Link
          to={localizedPath('/membresias')}
          className="inline-flex items-center justify-center bg-white text-primario font-medium rounded-lg border border-primario hover:bg-primario/5 transition-colors"
          style={{
            paddingLeft: fluidSizing.space.lg,
            paddingRight: fluidSizing.space.lg,
            paddingTop: fluidSizing.space.sm,
            paddingBottom: fluidSizing.space.sm,
            fontSize: fluidSizing.text.sm
          }}
        >
          <FiAward style={{ marginRight: fluidSizing.space.xs, width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
          {t('emptyState.viewMemberships')}
        </Link>
        <button
          onClick={onBuyCoins}
          disabled={!onBuyCoins || !canBuyPackages}
          className={`inline-flex items-center justify-center font-medium rounded-lg border transition-colors ${
            onBuyCoins && canBuyPackages
              ? 'bg-acento text-white border-acento hover:bg-acento/90' 
              : 'bg-gray-100 text-gray-400 border-gray-200 cursor-not-allowed'
          }`}
          style={{
            paddingLeft: fluidSizing.space.lg,
            paddingRight: fluidSizing.space.lg,
            paddingTop: fluidSizing.space.sm,
            paddingBottom: fluidSizing.space.sm,
            fontSize: fluidSizing.text.sm
          }}
          title={!canBuyPackages ? t('actions.buyTooltipLocked') : t('actions.buyTooltipAvailable')}
        >
          <FiShoppingCart style={{ marginRight: fluidSizing.space.xs, width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
          {t('emptyState.buyPackages')}
        </button>
      </div>
    </div>
  );
};

export default WalletEmptyState;
