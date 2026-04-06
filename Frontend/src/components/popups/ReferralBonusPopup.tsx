import React from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useLanguage } from '../../contexts/LanguageContext';
import PopupBase from './PopupBase';
import type { Popup, ReferralBonusData } from '../../services/popups/popupTypes';
import { popupApiService } from '../../services/popups';
import { sanitizeInlineHtml } from '../../utils/sanitizeHtml';

interface ReferralBonusPopupProps {
  popup: Popup;
  isOpen: boolean;
  onClose: () => void;
}

/**
 * Popup de bonificación por referido
 * Se muestra UNA SOLA VEZ en el primer inicio de sesión cuando el usuario
 * fue registrado con código de referido.
 */
const ReferralBonusPopup: React.FC<ReferralBonusPopupProps> = ({ popup, isOpen, onClose }) => {
  const { t } = useTranslation('popups');
  const navigate = useNavigate();
  const { localizedPath } = useLanguage();

  // Obtener datos del bonus de eligibilityData
  const eligibilityData = popup.eligibilityData as ReferralBonusData | undefined;
  const referrerName = eligibilityData?.referrer_name || t('referralBonus.referrerFallback');
  const membershipLevelName = eligibilityData?.membership_level_name || t('referralBonus.membershipFallback');
  const membershipLevelIcon = eligibilityData?.membership_level_icon || '🥈';
  const bonusDuration = eligibilityData?.bonus_duration || 30;
  const months = Math.round(bonusDuration / 30);
  const bonusDurationText = bonusDuration >= 30 
    ? t('referralBonus.durationMonths', { count: months }) 
    : t('referralBonus.durationDays', { count: bonusDuration });

  const handleViewMembership = async () => {
    // Marcar como notificado antes de navegar
    await popupApiService.registerInteraction(popup.id, { action: 'dismissed' });
    onClose();
    navigate(localizedPath('/membresias'));
  };

  const handleDismiss = async () => {
    // Marcar como notificado al cerrar
    await popupApiService.registerInteraction(popup.id, { action: 'dismissed' });
    onClose();
  };

  // Ocultar título del popup, permitir cerrar con botón X
  const popupConfig = {
    ...popup,
    title: '',
    content: '',
    dismissible: true, // Permitir cerrar para marcar como notificado
  };

  return (
    <PopupBase popup={popupConfig} isOpen={isOpen} onClose={handleDismiss} noPadding>
      <div className="p-4">
        {/* Mensaje de felicitación */}
        <div className="text-center mb-4">
          <p className="text-gray-700" dangerouslySetInnerHTML={{ __html: sanitizeInlineHtml(t('referralBonus.message', { referrer: referrerName, icon: membershipLevelIcon, duration: bonusDurationText, level: membershipLevelName })) }} />
        </div>

        {/* Botón */}
        <div className="flex justify-center">
          <button
            onClick={handleViewMembership}
            className="w-full px-6 py-3 bg-primario hover:bg-primario-dark text-white rounded-lg font-semibold transition-all"
          >
            {t('referralBonus.viewButton')}
          </button>
        </div>
      </div>
    </PopupBase>
  );
};

export default ReferralBonusPopup;
