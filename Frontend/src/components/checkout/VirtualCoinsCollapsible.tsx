import React from 'react';
import { useTranslation } from 'react-i18next';
import { FiHelpCircle } from 'react-icons/fi';
import VirtualCoinPrice from '../common/VirtualCoinPrice';
import CollapsibleSection from '../common/CollapsibleSection';
import { fluidSizing } from '../../utils/fluidSizing';

interface VirtualCoinsCollapsibleProps {
  userPoints: { balance: number } | null;
  systemConfig: { configuration: any } | null;
  pointsLoading: boolean;
  pointsToUse: number;
  appliedPointsDiscount: number;
  hasAppliedDiscount: boolean;
  onPointsChange: (points: number) => void;
  onApplyDiscount: () => void;
  onRemoveDiscount: () => void;
  onOpenHelpModal: () => void;
  getMaxPointsToUse: () => number;
  canApplyDiscount: () => boolean;
  getHelpMessage: () => string | null;
}

const VirtualCoinsCollapsible: React.FC<VirtualCoinsCollapsibleProps> = ({
  userPoints,
  systemConfig,
  pointsLoading,
  pointsToUse,
  appliedPointsDiscount,
  hasAppliedDiscount,
  onPointsChange,
  onApplyDiscount,
  onRemoveDiscount,
  onOpenHelpModal,
  getMaxPointsToUse,
  canApplyDiscount,
  getHelpMessage,
}) => {
  const { t } = useTranslation('checkoutPage');

  const handlePointsChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const value = parseInt(e.target.value) || 0;
    onPointsChange(value);
  };

  const handleApplyDiscount = () => {
    onApplyDiscount();
  };

  const handleRemoveDiscount = () => {
    onRemoveDiscount();
  };

  return (
    <CollapsibleSection
      title={t('VirtualCoins.title')}
      variant="soft"
      defaultExpanded={false}
      headerExtra={
        userPoints ? (
          <VirtualCoinPrice 
            amount={userPoints.balance} 
            size="sm" 
            showLabel={false}
            className="text-primario font-medium"
          />
        ) : undefined
      }
    >
      <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.sm }}>
        {pointsLoading ? (
          <div className="text-center" style={{ padding: fluidSizing.space.md }}>
            <div className="animate-pulse h-4 bg-primario/20 rounded w-3/4 mx-auto"></div>
          </div>
        ) : userPoints && systemConfig ? (
            (() => {
              const config = systemConfig.configuration;
              const availablePoints = userPoints.balance;
              const maxPoints = getMaxPointsToUse();

              if (availablePoints < config.min_points_redemption) {
                return (
                  <div className="space-y-2">
                    <div className="flex items-center gap-1 text-xs text-primario">
                      <span>{t('VirtualCoins.needMinimum')}</span>
                      <VirtualCoinPrice amount={config.min_points_redemption} size="xs" showLabel={false} />
                      <span>{t('VirtualCoins.toRedeem')}</span>
                    </div>
                    <button
                      type="button"
                      onClick={onOpenHelpModal}
                      className="flex items-center justify-center w-full px-3 py-2 text-xs bg-white border border-primario text-primario rounded hover:bg-primario hover:text-white transition-colors"
                    >
                      <FiHelpCircle className="mr-2 h-3 w-3" />
                      {t('VirtualCoins.howItWorks')}
                    </button>
                  </div>
                );
              }

              return (
                <div className="space-y-2">
                  {/* Mostrar descuento ya aplicado */}
                  {hasAppliedDiscount ? (
                    <div className="bg-white border border-primario border-opacity-30 rounded p-2">
                      <div className="flex justify-between items-center mb-2">
                        <span className="text-sm font-medium text-gray-800">
                          {t('VirtualCoins.discountApplied')}
                        </span>
                        <div className="flex items-center">
                          <span className="text-sm font-bold text-primario">-</span>
                          <VirtualCoinPrice amount={appliedPointsDiscount} size="sm" showLabel={false} className="text-primario font-bold" />
                        </div>
                      </div>
                      <div className="flex justify-between items-center">
                        <span className="text-xs text-gray-600 text-opacity-80">
                          {t('VirtualCoins.coinsUsed')}
                        </span>
                        <div className="flex items-center space-x-2">
                          <VirtualCoinPrice amount={Math.floor(appliedPointsDiscount / config.points_conversion_rate)} size="xs" showLabel={false} className="text-primario font-medium" />
                          <button
                            type="button"
                            onClick={handleRemoveDiscount}
                            className="px-2 py-1 text-xs bg-red-500 text-white rounded hover:bg-red-600 transition-colors"
                          >
                            {t('VirtualCoins.remove')}
                          </button>
                        </div>
                      </div>
                    </div>
                  ) : (
                    // Mostrar interfaz para aplicar descuento
                    <>
                      <div className="flex items-center space-x-2 mt-2">
                        <input
                          type="number"
                          min="0"
                          max={maxPoints}
                          value={pointsToUse}
                          onChange={handlePointsChange}
                          className="flex-1 px-2 py-2 text-sm border border-primario border-opacity-30 rounded focus:outline-none focus:ring-1 focus:ring-primario"
                          placeholder="0"
                        />
                        <button
                          type="button"
                          onClick={() => onPointsChange(maxPoints)}
                          className="px-3 py-2 text-sm bg-primario text-white rounded hover:bg-primario-dark transition-colors"
                        >
                          {t('VirtualCoins.max')}
                        </button>
                      </div>
                      
                      {pointsToUse > 0 && (
                        <div className="space-y-2">
                          <div className="flex justify-between items-center">
                            <div className="flex items-center gap-1 text-xs text-primario text-opacity-80">
                              <span>{t('VirtualCoins.discountOf')}</span>
                              <VirtualCoinPrice amount={pointsToUse} size="xs" showLabel={false} />
                            </div>
                            <div className="flex items-center">
                              <span className="text-xs font-medium text-primario">-</span>
                              <VirtualCoinPrice amount={pointsToUse * config.points_conversion_rate} size="xs" showLabel={false} className="text-primario font-medium" />
                            </div>
                          </div>
                          
                          {/* Mensaje de ayuda si no se puede aplicar */}
                          {!canApplyDiscount() && getHelpMessage() && (
                            <div className="text-xs text-yellow-600 bg-yellow-50 border border-yellow-200 rounded p-2">
                              ⚠️ {getHelpMessage()}
                            </div>
                          )}
                          
                          <button
                            type="button"
                            onClick={handleApplyDiscount}
                            disabled={!canApplyDiscount()}
                            className={`w-full px-3 py-2 text-sm rounded transition-colors ${
                              canApplyDiscount()
                                ? 'bg-primario text-white hover:bg-primario-dark'
                                : 'bg-gray-300 text-gray-500 cursor-not-allowed'
                            }`}
                          >
                            {canApplyDiscount() ? t('VirtualCoins.applyDiscount') : t('VirtualCoins.cannotApply')}
                          </button>
                        </div>
                      )}
                      
                      <div className="text-xs text-gray-800 text-opacity-70 space-y-1">
                        <div className="flex items-center gap-1">
                          <span>{t('VirtualCoins.maxAvailable')}</span>
                          <VirtualCoinPrice amount={maxPoints} size="xs" showLabel={false} />
                        </div>
                        {config.min_points_redemption > 0 && (
                          <div className="flex items-center gap-1">
                            <span>{t('VirtualCoins.minToRedeem')}</span>
                            <VirtualCoinPrice amount={config.min_points_redemption} size="xs" showLabel={false} />
                          </div>
                        )}
                      </div>
                    </>
                  )}
                </div>
              );
            })()
          ) : null}
      </div>
    </CollapsibleSection>
  );
};

export default VirtualCoinsCollapsible;
