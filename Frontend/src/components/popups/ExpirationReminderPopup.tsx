import React from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useLanguage } from '../../contexts/LanguageContext';
import PopupBase from './PopupBase';
import type { Popup, MembershipExpirationData } from '../../services/popups/popupTypes';
import { popupApiService } from '../../services/popups';
import { sanitizeInlineHtml } from '../../utils/sanitizeHtml';

interface ExpirationReminderPopupProps {
  popup: Popup;
  isOpen: boolean;
  onClose: () => void;
}

/**
 * Popup de recordatorio de expiración de membresía
 * Se muestra cuando la membresía está próxima a expirar (2 días o menos)
 */
const ExpirationReminderPopup: React.FC<ExpirationReminderPopupProps> = ({ popup, isOpen, onClose }) => {
  const { t } = useTranslation('popups');
  const navigate = useNavigate();
  const { localizedPath } = useLanguage();

  // Obtener datos de expiración
  const eligibilityData = popup.eligibilityData as MembershipExpirationData | undefined;
  const daysRemaining = eligibilityData?.days_remaining ?? 0;
  const levelName = eligibilityData?.level_name || t('expirationReminder.membershipFallback');
  const levelIcon = eligibilityData?.level_icon || '🥕';
  const renewalPeriodLabel = eligibilityData?.renewal_period_label || t('expirationReminder.renewalFallback');

  // Formatear texto de días restantes
  const daysText = t('expirationReminder.days', { count: daysRemaining });

  const handleRenew = async () => {
    await popupApiService.registerInteraction(popup.id, { action: 'clicked' });
    onClose();
    navigate(localizedPath('/membresias'));
  };

  const handleDismiss = async () => {
    await popupApiService.registerInteraction(popup.id, { action: 'dismissed' });
    onClose();
  };

  // Ocultar título del popup - solo mostrar imagen
  const popupWithoutTitle = {
    ...popup,
    title: '',
    content: '',
  };

  return (
    <PopupBase popup={popupWithoutTitle} isOpen={isOpen} onClose={handleDismiss} noPadding>
      <div className="p-4">
        {/* Información de expiración */}
        <div className="text-center mb-4">
          <p className="text-gray-700" dangerouslySetInnerHTML={{ __html: sanitizeInlineHtml(t('expirationReminder.message', { icon: levelIcon, name: levelName, period: renewalPeriodLabel, days: daysText })) }} />
          <p className="text-sm text-gray-500 mt-1">
            {t('expirationReminder.renewPrompt')}
          </p>
        </div>

        {/* Botones */}
        <div className="flex flex-col sm:flex-row gap-3">
          <button
            onClick={handleRenew}
            className="flex-1 px-6 py-3 bg-primario hover:bg-primario-dark text-white rounded-lg font-semibold transition-all"
          >
            {t('expirationReminder.renewButton')}
          </button>
          <button
            onClick={handleDismiss}
            className="flex-1 px-6 py-3 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg font-semibold transition-all"
          >
            {t('expirationReminder.laterButton')}
          </button>
        </div>
      </div>
    </PopupBase>
  );
};

export default ExpirationReminderPopup;
