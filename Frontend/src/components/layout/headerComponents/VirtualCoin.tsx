/**
 * VirtualCoin - Moneda giratoria animada (dinámica desde Site Settings)
 */
import { FC, memo } from 'react';
import { useTranslation } from 'react-i18next';
import { useVirtualCurrency } from '../../../contexts/SiteConfigContext';
import './VirtualCoin.css';

const DEFAULT_COIN_FRONT = '/assets/images/moneda/coin-front.webp';
const DEFAULT_COIN_BACK = '/assets/images/moneda/coin-back.webp';

interface VirtualCoinProps {
  onClick: () => void;
  size?: 'sm' | 'md' | 'lg';
  className?: string;
}

const VirtualCoin: FC<VirtualCoinProps> = memo(({ onClick, size = 'md', className = '' }) => {
  const { t } = useTranslation('virtualCoin');
  const vc = useVirtualCurrency();
  const coinFrontSrc = vc.virtual_currency_image_front || DEFAULT_COIN_FRONT;
  const coinBackSrc = vc.virtual_currency_image_back || DEFAULT_COIN_BACK;
  const vcName = vc.virtual_currency_name || 'Virtual Coins';

  const sizeClasses = {
    sm: 'h-10 w-10',
    md: 'h-12 w-12',
    lg: 'h-14 w-14'
  };

  return (
    <div 
      className={`coin-container-logo ${sizeClasses[size]} cursor-pointer hover:scale-110 transition-transform duration-300 ${className}`}
      onClick={onClick}
      title={t('title', { defaultValue: vcName })}
    >
      <img 
        src={coinFrontSrc} 
        alt={t('altFront', { defaultValue: `${vcName} - Front` })} 
        className={`coin-front-logo ${sizeClasses[size]} object-contain`}
      />
      <img 
        src={coinBackSrc} 
        alt={t('altBack', { defaultValue: `${vcName} - Back` })} 
        className={`coin-back-logo ${sizeClasses[size]} object-contain`}
      />
    </div>
  );
});

export default VirtualCoin;
