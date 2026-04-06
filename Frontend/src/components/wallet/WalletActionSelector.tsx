/**
 * WalletActionSelector - Componente para seleccionar acción de Virtual Coins
 * Permite elegir entre Enviar (activo) y Comprar (próximamente)
 */

import { FC } from 'react';
import { useTranslation } from 'react-i18next';
import { FiSend, FiShoppingCart } from 'react-icons/fi';
import CollapsibleSection from '../common/CollapsibleSection';
import { fluidSizing } from '../../utils/fluidSizing';

interface WalletActionSelectorProps {
  onSelectSend: () => void;
  onSelectBuy: () => void;
  canBuyPackages?: boolean;
  className?: string;
}

const WalletActionSelector: FC<WalletActionSelectorProps> = ({
  onSelectSend,
  onSelectBuy,
  canBuyPackages = false,
  className = ''
}) => {
  const { t } = useTranslation('walletComponents');
  return (
    <CollapsibleSection
      title={t('actions.title')}
      variant="soft"
      collapsible={false}
      showCollapseButton={false}
      className={className}
    >
      <div 
        className="grid grid-cols-1 sm:grid-cols-2"
        style={{ gap: fluidSizing.space.md }}
      >
        {/* Opción Enviar - Activa */}
        <button
          onClick={onSelectSend}
          className="flex flex-col items-center text-center bg-primario/5 hover:bg-primario/10 border-2 border-primario rounded-lg transition-all hover:shadow-md group"
          style={{ padding: fluidSizing.space.lg }}
        >
          <div 
            className="rounded-full bg-primario/20 group-hover:bg-primario/30 flex items-center justify-center transition-colors"
            style={{ 
              width: fluidSizing.size.floatingButton, 
              height: fluidSizing.size.floatingButton,
              marginBottom: fluidSizing.space.md
            }}
          >
            <FiSend 
              className="text-primario" 
              style={{ width: fluidSizing.size.iconLg, height: fluidSizing.size.iconLg }} 
            />
          </div>
          <h3 
            className="font-semibold text-oscuro"
            style={{ fontSize: fluidSizing.text.base, marginBottom: fluidSizing.space.xs }}
          >
            {t('actions.sendTitle')}
          </h3>
          <p 
            className="text-texto"
            style={{ fontSize: fluidSizing.text.xs }}
          >
            {t('actions.sendDesc')}
          </p>
        </button>

        {/* Opción Comprar - Requiere Membresía Plata */}
        <button
          onClick={canBuyPackages ? onSelectBuy : undefined}
          disabled={!canBuyPackages}
          className={`flex flex-col items-center text-center border-2 rounded-lg transition-all group ${
            canBuyPackages 
              ? 'bg-acento/5 hover:bg-acento/10 border-acento hover:shadow-md cursor-pointer' 
              : 'bg-gray-50 border-gray-200 cursor-not-allowed opacity-70'
          }`}
          style={{ padding: fluidSizing.space.lg }}
          title={!canBuyPackages ? t('actions.buyTooltipLocked') : t('actions.buyTooltipAvailable')}
        >
          <div 
            className={`rounded-full flex items-center justify-center transition-colors ${
              canBuyPackages 
                ? 'bg-acento/20 group-hover:bg-acento/30' 
                : 'bg-gray-200'
            }`}
            style={{ 
              width: fluidSizing.size.floatingButton, 
              height: fluidSizing.size.floatingButton,
              marginBottom: fluidSizing.space.md
            }}
          >
            <FiShoppingCart 
              className={canBuyPackages ? 'text-acento' : 'text-gray-400'}
              style={{ width: fluidSizing.size.iconLg, height: fluidSizing.size.iconLg }} 
            />
          </div>
          <h3 
            className={`font-semibold ${canBuyPackages ? 'text-oscuro' : 'text-gray-500'}`}
            style={{ fontSize: fluidSizing.text.base, marginBottom: fluidSizing.space.xs }}
          >
            {t('actions.buyTitle')}
          </h3>
          <p 
            className={canBuyPackages ? 'text-texto' : 'text-gray-400'}
            style={{ fontSize: fluidSizing.text.xs }}
          >
            {canBuyPackages 
              ? t('actions.buyDescAvailable') 
              : t('actions.buyDescLocked')}
          </p>
        </button>
      </div>
    </CollapsibleSection>
  );
};

export default WalletActionSelector;
