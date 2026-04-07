/**
 * VirtualCoin - Moneda giratoria animada (dinámica desde Site Settings)
 * Si no hay imágenes configuradas, muestra un icono circular con el símbolo de la moneda virtual.
 */
import { FC, memo } from 'react';
import { useTranslation } from 'react-i18next';
import { useVirtualCurrency } from '../../../contexts/SiteConfigContext';
import { FiDollarSign } from 'react-icons/fi';
import './VirtualCoin.css';

interface VirtualCoinProps {
  onClick: () => void;
  size?: 'sm' | 'md' | 'lg';
  className?: string;
}

const sizeClasses = {
  sm: 'h-10 w-10',
  md: 'h-12 w-12',
  lg: 'h-14 w-14'
};

const VirtualCoin: FC<VirtualCoinProps> = memo(({ onClick, size = 'md', className = '' }) => {
  const { t } = useTranslation('virtualCoin');
  const vc = useVirtualCurrency();
  const coinFrontSrc = vc.virtual_currency_image_front || '';
  const coinBackSrc = vc.virtual_currency_image_back || '';
  const vcName = vc.virtual_currency_name || 'Virtual Coins';
  const vcIcon = vc.virtual_currency_icon || '⭐';
  const hasImages = !!coinFrontSrc;

  if (!hasImages) {
    return (
      <div
        className={`${sizeClasses[size]} rounded-full bg-primario/10 border-2 border-primario/30 flex items-center justify-center cursor-pointer hover:scale-110 transition-transform duration-300 ${className}`}
        onClick={onClick}
        title={t('title', { defaultValue: vcName })}
      >
        <span className="text-lg">{vcIcon !== '⭐' ? vcIcon : ''}</span>
        {vcIcon === '⭐' && <FiDollarSign className="w-1/2 h-1/2 text-primario" />}
      </div>
    );
  }

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
      {coinBackSrc && (
        <img 
          src={coinBackSrc} 
          alt={t('altBack', { defaultValue: `${vcName} - Back` })} 
          className={`coin-back-logo ${sizeClasses[size]} object-contain`}
        />
      )}
    </div>
  );
});

export default VirtualCoin;
