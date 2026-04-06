/**
 * VerifiedBadge - Badge de "Compra verificada" para reseñas
 */

import { FiCheckCircle } from 'react-icons/fi';
import { useTranslation } from 'react-i18next';
import { fluidSizing } from '../../../utils/fluidSizing';

const VerifiedBadge = () => {
  const { t } = useTranslation('reviews');

  return (
    <span
      className="inline-flex items-center text-green-600 font-medium"
      style={{ fontSize: fluidSizing.text['2xs'], gap: '3px' }}
    >
      <FiCheckCircle style={{ width: fluidSizing.size.iconXs, height: fluidSizing.size.iconXs }} />
      {t('verifiedBuyer', 'Compra verificada')}
    </span>
  );
};

export default VerifiedBadge;
