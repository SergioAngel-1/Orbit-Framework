/**
 * VirtualCoinsRewardBadge - Badge que muestra la recompensa en Virtual Coins por reseñar
 */

import { useTranslation } from 'react-i18next';
import { fluidSizing } from '../../../utils/fluidSizing';
import VirtualCoinPrice from '../../common/VirtualCoinPrice';

interface VirtualCoinsRewardBadgeProps {
  /** Cantidad de Virtual Coins que se otorgan */
  points: number;
}

const VirtualCoinsRewardBadge = ({ points }: VirtualCoinsRewardBadgeProps) => {
  const { t } = useTranslation('reviews');

  if (!points || points <= 0) return null;

  return (
    <span
      className="inline-flex items-center bg-yellow-50 text-yellow-700 border border-yellow-200 rounded-full font-medium"
      style={{
        fontSize: fluidSizing.text['2xs'],
        padding: `${fluidSizing.space.xs} ${fluidSizing.space.sm}`,
        gap: '4px',
      }}
    >
      {t('rewardBadgePrefix', 'Gana')}
      <VirtualCoinPrice amount={points} size="xs" showLabel={true} inheritColor />
      {t('rewardBadgeSuffix', 'por tu reseña')}
    </span>
  );
};

export default VirtualCoinsRewardBadge;
