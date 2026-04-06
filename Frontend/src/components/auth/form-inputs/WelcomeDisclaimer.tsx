import React from 'react';
import { useTranslation } from 'react-i18next';
import { FaUsers } from 'react-icons/fa';
import { sanitizeInlineHtml } from '../../../utils/sanitizeHtml';

/**
 * Componente de disclaimer de bienvenida al club
 * Muestra información sobre la membresía básica gratuita
 */
const WelcomeDisclaimer: React.FC = () => {
  const { t } = useTranslation('registerForm');

  return (
    <div className="bg-gradient-to-r from-primario/10 to-primario/5 border border-primario/20 rounded-lg p-3">
      <div className="flex items-start gap-2">
        <FaUsers className="text-primario w-5 h-5 mt-0.5 flex-shrink-0" />
        <div className="text-sm">
          <p className="font-medium text-oscuro mb-1">
            {t('welcome.title')}
          </p>
          <p className="text-gray-600 text-xs leading-relaxed" dangerouslySetInnerHTML={{ __html: sanitizeInlineHtml(t('welcome.description')) }} />
        </div>
      </div>
    </div>
  );
};

export default WelcomeDisclaimer;
