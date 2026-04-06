import { useTranslation } from 'react-i18next';
import logger from '../../utils/logger';
import VirtualCoinPrice from '../common/VirtualCoinPrice';
import CollapsibleSection from '../common/CollapsibleSection';
import { fluidSizing } from '../../utils/fluidSizing';

interface ReferralStats {
  total_referrals: number;
  direct_referrals: number;
  indirect_referrals: number;
  total_earnings: number;
  pending_referrals?: number;
}

interface ReferralStatsProps {
  stats: ReferralStats | null;
  canUseReferrals: boolean;
}

/**
 * Componente para mostrar las estadísticas de referidos del usuario
 * Usa CollapsibleSection con header gradiente
 */
const ReferralStats: React.FC<ReferralStatsProps> = ({ stats, canUseReferrals }) => {
  const { t } = useTranslation('referralComponents');
  logger.info('ReferralStats', `Stats recibidas: ${JSON.stringify(stats)}`);
  logger.info('ReferralStats', `Can use referrals: ${canUseReferrals}`);
  
  if (!canUseReferrals) {
    return (
      <CollapsibleSection
        title={t('stats.title')}
        variant="soft"
        collapsible={false}
        showCollapseButton={false}
        className="flex-grow"
      >
        <p className="text-texto text-center" style={{ paddingTop: fluidSizing.space.lg, paddingBottom: fluidSizing.space.lg }}>
          {t('stats.unavailable')}
        </p>
      </CollapsibleSection>
    );
  }

  if (!stats) {
    return null;
  }

  return (
    <CollapsibleSection
      title={t('stats.title')}
      variant="soft"
      collapsible={false}
      showCollapseButton={false}
      className="flex-grow"
    >
      <div 
        className="grid grid-cols-2 md:grid-cols-5" 
        style={{ gap: fluidSizing.space.md }}
      >
        <div 
          className="bg-secundario/20 rounded-lg border border-secundario/30 text-center flex flex-col justify-center"
          style={{ padding: fluidSizing.space.md }}
        >
          <p className="text-texto" style={{ fontSize: fluidSizing.text.xs, marginBottom: fluidSizing.space.xs }}>{t('stats.totalReferrals')}</p>
          <p className="font-bold text-primario" style={{ fontSize: fluidSizing.text.xl }}>{stats.total_referrals}</p>
          <p className="text-texto/70" style={{ fontSize: fluidSizing.text.xs, marginTop: fluidSizing.space.xs }}>
            {t('stats.allLevels')}
          </p>
        </div>

        <div 
          className="bg-secundario/20 rounded-lg border border-secundario/30 text-center flex flex-col justify-center"
          style={{ padding: fluidSizing.space.md }}
        >
          <p className="text-texto" style={{ fontSize: fluidSizing.text.xs, marginBottom: fluidSizing.space.xs }}>{t('stats.direct')}</p>
          <p className="font-bold text-primario" style={{ fontSize: fluidSizing.text.xl }}>{stats.direct_referrals}</p>
          <p className="text-texto/70" style={{ fontSize: fluidSizing.text.xs, marginTop: fluidSizing.space.xs }}>
            {t('stats.firstLevel')}
          </p>
        </div>

        <div 
          className="bg-secundario/20 rounded-lg border border-secundario/30 text-center flex flex-col justify-center"
          style={{ padding: fluidSizing.space.md }}
        >
          <p className="text-texto" style={{ fontSize: fluidSizing.text.xs, marginBottom: fluidSizing.space.xs }}>{t('stats.indirect')}</p>
          <p className="font-bold text-primario" style={{ fontSize: fluidSizing.text.xl }}>{stats.indirect_referrals}</p>
          <p className="text-texto/70" style={{ fontSize: fluidSizing.text.xs, marginTop: fluidSizing.space.xs }}>
            {t('stats.otherLevels')}
          </p>
        </div>

        <div 
          className="bg-secundario/20 rounded-lg border border-secundario/30 text-center flex flex-col justify-center"
          style={{ padding: fluidSizing.space.md }}
        >
          <p className="text-texto" style={{ fontSize: fluidSizing.text.xs, marginBottom: fluidSizing.space.xs }}>{t('stats.pending')}</p>
          <p className="font-bold text-primario" style={{ fontSize: fluidSizing.text.xl }}>{stats.pending_referrals || 0}</p>
          <p className="text-texto/70" style={{ fontSize: fluidSizing.text.xs, marginTop: fluidSizing.space.xs }}>
            {t('stats.inApproval')}
          </p>
        </div>

        <div 
          className="bg-secundario/20 rounded-lg border border-secundario/30 text-center flex flex-col justify-center col-span-2 md:col-span-1"
          style={{ padding: fluidSizing.space.md }}
        >
          <p className="text-texto" style={{ fontSize: fluidSizing.text.xs, marginBottom: fluidSizing.space.xs }}>{t('stats.coinsGenerated')}</p>
          <div className="flex justify-center" style={{ marginTop: fluidSizing.space.xs, marginBottom: fluidSizing.space.xs }}>
            <VirtualCoinPrice amount={stats.total_earnings} size="md" showLabel={false} />
          </div>
          <p className="text-texto/70" style={{ fontSize: fluidSizing.text.xs }}>
            {t('stats.byReferrals')}
          </p>
        </div>
      </div>
    </CollapsibleSection>
  );
};

export default ReferralStats;
