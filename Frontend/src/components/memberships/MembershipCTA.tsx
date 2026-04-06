/**
 * MembershipCTA - Call to Action para membresías
 * Sección de llamada a la acción para usuarios no autenticados
 * Usa el componente FallbackBanner para consistencia
 */

import { FiAward } from 'react-icons/fi';
import { useTranslation } from 'react-i18next';
import { useAuth } from '../../contexts/AuthContext';
import FallbackBanner from '../common/FallbackBanner';

const MembershipCTA = () => {
  const { t } = useTranslation('membershipsPage');
  const { isAuthenticated } = useAuth();

  // No mostrar si el usuario ya está autenticado
  if (isAuthenticated) {
    return null;
  }

  return (
    <section className="mb-6">
      <FallbackBanner
        title={t('cta.title')}
        description={t('cta.description')}
        footer={{
          icon: <FiAward className="w-full h-full" />,
          text: <>{t('cta.footerPrefix')} <strong>{t('cta.footerFree')}</strong> {t('cta.footerSuffix')}</>
        }}
      />
    </section>
  );
};

export default MembershipCTA;
