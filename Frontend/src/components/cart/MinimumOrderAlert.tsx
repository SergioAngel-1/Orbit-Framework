import React from 'react';
import { useTranslation } from 'react-i18next';
import { FiAlertCircle, FiCheckCircle } from 'react-icons/fi';
import VirtualCoinPrice from '../common/VirtualCoinPrice';

interface MinimumOrderAlertProps {
  minimumAmount: number;
  currentTotal: number;
  meetsMinimum: boolean;
  missingAmount: number;
  progress: number;
}

/**
 * Componente de alerta para mostrar el estado del aporte mínimo de mantenimiento
 */
const MinimumOrderAlert: React.FC<MinimumOrderAlertProps> = ({
  minimumAmount,
  currentTotal,
  meetsMinimum,
  missingAmount,
  progress
}) => {
  const { t } = useTranslation('cartPage');

  // Si el mínimo es 0, no mostrar nada
  if (minimumAmount === 0) return null;

  return (
    <div className={`rounded-lg p-4 mb-6 ${meetsMinimum ? 'bg-green-50 border border-green-200' : 'bg-yellow-50 border border-yellow-200'}`}>
      <div className="flex items-start">
        <div className="flex-shrink-0">
          {meetsMinimum ? (
            <FiCheckCircle className="h-5 w-5 text-green-600" />
          ) : (
            <FiAlertCircle className="h-5 w-5 text-yellow-600" />
          )}
        </div>
        <div className="ml-3 flex-1">
          <h3 className={`text-sm font-medium ${meetsMinimum ? 'text-green-800' : 'text-yellow-800'}`}>
            {meetsMinimum ? t('minimumOrder.reached') : t('minimumOrder.title')}
          </h3>
          <div className="mt-2 text-sm">
            {meetsMinimum ? (
              <div className="text-green-700 space-y-1">
                <div className="flex items-center gap-1 flex-wrap">
                  <span>{t('minimumOrder.minimumLabel')}</span>
                  <VirtualCoinPrice amount={minimumAmount} size="sm" showLabel={false} className="text-green-700 font-semibold" />
                </div>
              </div>
            ) : (
              <>
                <div className="text-yellow-700 mb-2 space-y-1">
                  <div className="flex items-center gap-1 flex-wrap">
                    <span>{t('minimumOrder.minimumIs')}</span>
                    <VirtualCoinPrice amount={minimumAmount} size="sm" showLabel={false} className="text-yellow-700 font-bold" />
                  </div>
                  <div className="flex items-center gap-1 flex-wrap">
                    <span>{t('minimumOrder.missing')}</span>
                    <VirtualCoinPrice amount={missingAmount} size="sm" showLabel={false} className="text-yellow-700 font-bold" />
                    <span>{t('minimumOrder.missingEnd')}</span>
                  </div>
                </div>
                
                {/* Barra de progreso */}
                <div className="mt-3">
                  <div className="flex justify-between text-xs text-yellow-700 mb-1">
                    <VirtualCoinPrice amount={currentTotal} size="xs" showLabel={false} className="text-yellow-700" />
                    <VirtualCoinPrice amount={minimumAmount} size="xs" showLabel={false} className="text-yellow-700" />
                  </div>
                  <div className="w-full bg-yellow-200 rounded-full h-2">
                    <div
                      className="bg-yellow-600 h-2 rounded-full transition-all duration-300"
                      style={{ width: `${Math.min(progress, 100)}%` }}
                    />
                  </div>
                  <p className="text-xs text-yellow-600 mt-1">
                    {t('minimumOrder.progress', { percent: progress.toFixed(0) })}
                  </p>
                </div>
              </>
            )}
          </div>
        </div>
      </div>
    </div>
  );
};

export default MinimumOrderAlert;
