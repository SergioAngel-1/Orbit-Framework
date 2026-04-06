import React, { useEffect, useMemo, memo } from 'react';
import { fluidSizing } from '../../utils/fluidSizing';
import { roundCurrency } from '../../utils/formatters';
import { useVirtualCurrency, useSiteCurrency } from '../../contexts/SiteConfigContext';

// Fallback a imágenes locales si no hay configuración
const DEFAULT_COIN_FRONT = '/assets/images/moneda/coin-front.webp';
const DEFAULT_COIN_BACK = '/assets/images/moneda/coin-back.webp';

// Inyectar CSS (solo una vez)
let initialized = false;
const initializeOnce = () => {
  if (initialized) return;
  initialized = true;
  
  // Inyectar CSS una sola vez
  const style = document.createElement('style');
  style.id = 'virtual-coin-styles';
  style.textContent = `
    .coin-flip-container {
      perspective: 1000px;
    }
    .coin-flip-container .coin-front,
    .coin-flip-container .coin-back {
      position: absolute;
      top: 0;
      left: 0;
      backface-visibility: hidden;
      transition: transform 0.6s ease-in-out;
    }
    .coin-flip-container .coin-back {
      transform: rotateY(180deg);
    }
    @media (min-width: 768px) {
      .coin-flip-container:hover .coin-front {
        transform: rotateY(180deg);
      }
      .coin-flip-container:hover .coin-back {
        transform: rotateY(360deg);
      }
    }
  `;
  document.head.appendChild(style);
};

interface VirtualCoinPriceProps {
  amount: number;
  size?: 'xs' | 'sm' | 'md' | 'lg' | 'xl';
  showLabel?: boolean;
  className?: string;
  inheritColor?: boolean;
}

/**
 * Componente para mostrar precios en moneda virtual
 * Muestra la imagen de la moneda + abreviación + el precio formateado
 * Usa fluidSizing para escalado responsive automático
 */
const VirtualCoinPrice: React.FC<VirtualCoinPriceProps> = ({
  amount,
  size = 'md',
  showLabel = true,
  className = '',
  inheritColor = false
}) => {
  const vc = useVirtualCurrency();
  const { currency_locale } = useSiteCurrency();
  const vcShort = vc.virtual_currency_short || 'VC';
  const vcName = vc.virtual_currency_name || 'Virtual Coins';
  const coinFrontSrc = vc.virtual_currency_image_front || DEFAULT_COIN_FRONT;
  const coinBackSrc = vc.virtual_currency_image_back || DEFAULT_COIN_BACK;

  // Inicializar (precargar imágenes + CSS) en el primer render
  useEffect(() => {
    initializeOnce();
  }, []);
  // Mapeo de tamaños a valores de fluidSizing
  const sizeConfig = {
    xs: {
      coin: fluidSizing.size.iconSm,      // 16px → 20px (más grande que el texto)
      text: fluidSizing.text.xs,          // 12px → 13px
      marginLabel: fluidSizing.space.xs,  // 4px → 6px
      marginPrice: fluidSizing.space.xs   // 4px → 6px (reducido)
    },
    sm: {
      coin: fluidSizing.size.iconMd,      // 20px → 24px
      text: fluidSizing.text.sm,          // 14px → 15px
      marginLabel: fluidSizing.space.xs,  // 4px → 6px
      marginPrice: fluidSizing.space.xs   // 4px → 6px (reducido)
    },
    md: {
      coin: fluidSizing.size.iconLg,      // 24px → 32px
      text: fluidSizing.text.base,        // 14px → 16px
      marginLabel: fluidSizing.space.xs,  // 4px → 6px (reducido)
      marginPrice: fluidSizing.space.sm   // 8px → 12px (reducido)
    },
    lg: {
      coin: fluidSizing.size.iconXl,      // 32px → 48px
      text: fluidSizing.text.lg,          // 16px → 18px
      marginLabel: fluidSizing.space.xs,  // 4px → 6px (reducido)
      marginPrice: fluidSizing.space.sm   // 8px → 12px (reducido)
    },
    xl: {
      coin: 'clamp(2.5rem, 2.5rem + 1.5 * ((100vw - 20rem) / 100), 4rem)', // 40px → 64px (custom)
      text: fluidSizing.text.xl,          // 18px → 21px
      marginLabel: fluidSizing.space.xs,  // 4px → 6px (reducido)
      marginPrice: fluidSizing.space.sm   // 8px → 12px (reducido)
    }
  };

  const config = sizeConfig[size];

  // Redondear al múltiplo configurado y formatear
  const formattedAmount = useMemo(() => {
    const locale = currency_locale || 'en-US';
    return new Intl.NumberFormat(locale, { minimumFractionDigits: 0, maximumFractionDigits: 2 }).format(roundCurrency(amount));
  }, [amount, currency_locale]);

  return (
      <div className={`flex items-center ${className}`}>
        {/* Contenedor de la moneda con efecto flip */}
        <div 
          className="relative flex-shrink-0 coin-flip-container"
          style={{
            width: config.coin,
            height: config.coin
          }}
          title={vcName}
        >
          <img 
            src={coinFrontSrc} 
            alt={vcName} 
            className="w-full h-full object-contain coin-front"
          />
          <img 
            src={coinBackSrc} 
            alt={vcName} 
            className="w-full h-full object-contain coin-back hidden md:block"
          />
        </div>
        {showLabel && (
          <span 
            className={`font-medium ${inheritColor ? '' : 'text-gray-700'}`}
            style={{
              fontSize: config.text,
              marginLeft: config.marginLabel
            }}
          >
            {vcShort}
          </span>
        )}
        <span 
          className="font-semibold"
          style={{
            fontSize: config.text,
            marginLeft: showLabel ? config.marginPrice : config.marginLabel
          }}
        >
          {formattedAmount}
        </span>
      </div>
  );
};

export default memo(VirtualCoinPrice);
