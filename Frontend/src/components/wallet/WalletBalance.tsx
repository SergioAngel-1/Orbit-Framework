/**
 * WalletBalance - Componente para mostrar el saldo de Virtual Coins
 * Muestra balance, equivalencia en COP y estadísticas
 * Usa CollapsibleSection para el diseño colapsable
 */

import { FC, useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { FiDollarSign } from 'react-icons/fi';
import VirtualCoinPrice from '../common/VirtualCoinPrice';
import CollapsibleSection from '../common/CollapsibleSection';
import { formatCurrency } from '../../utils/formatters';
import { fluidSizing } from '../../utils/fluidSizing';
import type { PointsInfo } from '../../hooks/useWallet';

interface WalletBalanceProps {
  points: PointsInfo | null;
  conversionRate?: number;
  className?: string;
}

const WalletBalance: FC<WalletBalanceProps> = ({ 
  points, 
  conversionRate,
  className = '' 
}) => {
  const { t } = useTranslation('walletComponents');
  const rate = conversionRate || points?.conversion_rate || 1000;
  
  // Detectar si es mobile para estado inicial del colapsable
  const [isDesktop, setIsDesktop] = useState(window.innerWidth >= 768);
  
  useEffect(() => {
    const handleResize = () => {
      setIsDesktop(window.innerWidth >= 768);
    };
    
    window.addEventListener('resize', handleResize);
    return () => window.removeEventListener('resize', handleResize);
  }, []);

  return (
    <CollapsibleSection
      title={t('balance.title')}
      icon={FiDollarSign}
      expandButtonText={t('balance.expandButton')}
      defaultExpanded={isDesktop}
      className={className}
      headerLayout={isDesktop ? 'inline' : 'stacked'}
      headerExtra={
        <VirtualCoinPrice 
          amount={points?.balance || 0} 
          size="lg" 
          showLabel={true} 
          className="text-white font-bold"
          inheritColor={true}
        />
      }
    >
      <div 
        className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4" 
        style={{ gap: fluidSizing.space.md }}
      >
        {/* Total Virtual Coins */}
        <div 
          className="bg-secundario/20 rounded-lg border border-secundario/30"
          style={{ padding: fluidSizing.space.md }}
        >
          <div className="text-texto" style={{ fontSize: fluidSizing.text.xs, marginBottom: fluidSizing.space.xs }}>{t('balance.available')}</div>
          <VirtualCoinPrice 
            amount={points?.balance || 0} 
            size="xl" 
            showLabel={true} 
            className="text-primario font-bold"
          />
        </div>

        {/* Equivalencia en COP */}
        <div 
          className="bg-secundario/20 rounded-lg border border-secundario/30"
          style={{ padding: fluidSizing.space.md }}
        >
          <div className="text-texto" style={{ fontSize: fluidSizing.text.xs, marginBottom: fluidSizing.space.xs }}>{t('balance.copEquivalent')}</div>
          <div className="flex items-baseline">
            <span className="font-bold text-primario" style={{ fontSize: fluidSizing.text.xl }}>
              {points ? formatCurrency((points.balance || 0) * rate) : '$0'}
            </span>
          </div>
          <div className="text-texto/70" style={{ fontSize: fluidSizing.text.xs, marginTop: fluidSizing.space.xs }}>
            {t('balance.conversionRate', { rate: rate.toLocaleString() })}
          </div>
        </div>

        {/* Total ganados */}
        <div 
          className="bg-secundario/20 rounded-lg border border-secundario/30"
          style={{ padding: fluidSizing.space.md }}
        >
          <div className="text-texto" style={{ fontSize: fluidSizing.text.xs, marginBottom: fluidSizing.space.xs }}>{t('balance.totalEarned')}</div>
          <VirtualCoinPrice 
            amount={points?.total_earned || 0} 
            size="lg" 
            showLabel={true} 
            className="text-primario font-bold"
          />
          <div className="text-texto/70" style={{ fontSize: fluidSizing.text.xs, marginTop: fluidSizing.space.xs }}>{t('balance.historic')}</div>
        </div>

        {/* Utilizados */}
        <div 
          className="bg-secundario/20 rounded-lg border border-secundario/30"
          style={{ padding: fluidSizing.space.md }}
        >
          <div className="text-texto" style={{ fontSize: fluidSizing.text.xs, marginBottom: fluidSizing.space.xs }}>{t('balance.used')}</div>
          <VirtualCoinPrice 
            amount={points?.used || 0} 
            size="lg" 
            showLabel={true} 
            className="text-primario font-bold"
          />
          <div className="text-texto/70" style={{ fontSize: fluidSizing.text.xs, marginTop: fluidSizing.space.xs }}>{t('balance.inOrders')}</div>
        </div>
      </div>
    </CollapsibleSection>
  );
};

export default WalletBalance;
