import React from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useLanguage } from '../../contexts/LanguageContext';
import PopupBase from './PopupBase';
import type { Popup, MembershipExpiredData } from '../../services/popups/popupTypes';
import { popupApiService } from '../../services/popups';
import { sanitizeInlineHtml } from '../../utils/sanitizeHtml';

interface MembershipExpiredPopupProps {
  popup: Popup;
  isOpen: boolean;
  onClose: () => void;
}

/**
 * Popup de membresía expirada
 * Se muestra cuando la membresía ha expirado recientemente (menos de 30 días)
 */
const MembershipExpiredPopup: React.FC<MembershipExpiredPopupProps> = ({ popup, isOpen, onClose }) => {
  const { t } = useTranslation('popups');
  const navigate = useNavigate();
  const { localizedPath } = useLanguage();

  // Obtener datos de la membresía expirada
  const eligibilityData = popup.eligibilityData as MembershipExpiredData | undefined;
  const levelName = eligibilityData?.expired_level_name || t('membershipExpired.membershipFallback');
  const levelIcon = eligibilityData?.expired_level_icon || '🥕';
  const daysSinceExpiry = eligibilityData?.days_since_expiry ?? 0;

  // Formatear texto de días desde expiración
  const expiryText = daysSinceExpiry === 0 
    ? t('membershipExpired.expiryToday') 
    : t('membershipExpired.expiryDays', { count: daysSinceExpiry });

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
          <p className="text-gray-700" dangerouslySetInnerHTML={{ __html: sanitizeInlineHtml(t('membershipExpired.message', { icon: levelIcon, name: levelName, expiry: expiryText })) }} />
          <p className="text-sm text-gray-500 mt-1">
            {t('membershipExpired.renewPrompt')}
          </p>
        </div>

        {/* Botones */}
        <div className="flex flex-col sm:flex-row gap-3">
          <button
            onClick={handleRenew}
            className="flex-1 px-6 py-3 bg-primario hover:bg-primario-dark text-white rounded-lg font-semibold transition-all"
          >
            {t('membershipExpired.renewButton')}
          </button>
          <button
            onClick={handleDismiss}
            className="flex-1 px-6 py-3 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg font-semibold transition-all"
          >
            {t('membershipExpired.laterButton')}
          </button>
        </div>
      </div>
    </PopupBase>
  );
};

export default MembershipExpiredPopup;
