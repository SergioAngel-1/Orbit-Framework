/**
 * WalletDisclaimer - Componente para mostrar información legal sobre Virtual Coins
 * Disclaimer sobre el uso de FC como método de pago
 * Usa CollapsibleSection con variante soft
 */

import { FC } from 'react';
import { useTranslation } from 'react-i18next';
import { FiInfo, FiAlertCircle, FiDollarSign, FiGift, FiRefreshCw } from 'react-icons/fi';
import { fluidSizing } from '../../utils/fluidSizing';
import CollapsibleSection from '../common/CollapsibleSection';

interface DisclaimerItem {
  icon: FC<{ className?: string; style?: React.CSSProperties }>;
  title: string;
  description: string;
}

interface WalletDisclaimerProps {
  className?: string;
  variant?: 'full' | 'compact';
}

const WalletDisclaimer: FC<WalletDisclaimerProps> = ({
  className = '',
  variant = 'full'
}) => {
  const { t } = useTranslation('walletPage');

  const disclaimerItems: DisclaimerItem[] = [
    {
      icon: FiDollarSign,
      title: t('disclaimer.item1Title'),
      description: t('disclaimer.item1Desc')
    },
    {
      icon: FiGift,
      title: t('disclaimer.item2Title'),
      description: t('disclaimer.item2Desc')
    },
    {
      icon: FiAlertCircle,
      title: t('disclaimer.item3Title'),
      description: t('disclaimer.item3Desc')
    },
    {
      icon: FiRefreshCw,
      title: t('disclaimer.item4Title'),
      description: t('disclaimer.item4Desc')
    }
  ];

  if (variant === 'compact') {
    return (
      <div 
        className={`bg-white rounded-lg shadow-sm border border-gray-100 ${className}`}
        style={{ 
          padding: fluidSizing.space.md,
          borderRadius: fluidSizing.modal.borderRadius
        }}
      >
        <div 
          className="flex items-start" 
          style={{ gap: fluidSizing.space.sm }}
        >
          <FiInfo 
            className="text-primario flex-shrink-0" 
            style={{ 
              width: fluidSizing.size.iconSm, 
              height: fluidSizing.size.iconSm,
              marginTop: '2px'
            }} 
          />
          <p style={{ fontSize: fluidSizing.text.sm }} className="text-texto">
            {t('disclaimer.compactText')}
          </p>
        </div>
      </div>
    );
  }

  return (
    <CollapsibleSection
      title={t('disclaimer.title')}
      variant="soft"
      collapsible={false}
      showCollapseButton={false}
      className={className}
    >
      <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.md }}>
        {disclaimerItems.map((item, index) => {
          const IconComponent = item.icon;
          return (
            <div 
              key={index}
              className="flex items-start" 
              style={{ gap: fluidSizing.space.sm }}
            >
              <div 
                className="rounded-full bg-secundario flex items-center justify-center flex-shrink-0"
                style={{ 
                  width: fluidSizing.size.iconXl, 
                  height: fluidSizing.size.iconXl 
                }}
              >
                <IconComponent 
                  className="text-primario" 
                  style={{ 
                    width: fluidSizing.size.iconMd, 
                    height: fluidSizing.size.iconMd 
                  }} 
                />
              </div>
              <div>
                <p 
                  className="font-medium text-oscuro" 
                  style={{ fontSize: fluidSizing.text.sm }}
                >
                  {item.title}
                </p>
                <p 
                  className="text-texto" 
                  style={{ 
                    fontSize: fluidSizing.text.xs,
                    marginTop: fluidSizing.space.xs
                  }}
                >
                  {item.description}
                </p>
              </div>
            </div>
          );
        })}
      </div>

      <div 
        className="border-t border-gray-100" 
        style={{ 
          marginTop: fluidSizing.space.md,
          paddingTop: fluidSizing.space.sm
        }}
      >
        <p 
          className="text-texto/70 italic" 
          style={{ fontSize: fluidSizing.text.xs }}
        >
          {t('disclaimer.footer')}
        </p>
      </div>
    </CollapsibleSection>
  );
};

export default WalletDisclaimer;
