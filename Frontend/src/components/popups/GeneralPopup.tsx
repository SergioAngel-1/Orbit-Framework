import React from 'react';
import { useTranslation } from 'react-i18next';
import PopupBase from './PopupBase';
import type { Popup } from '../../services/popups/popupTypes';
import { popupApiService } from '../../services/popups';

interface GeneralPopupProps {
  popup: Popup;
  isOpen: boolean;
  onClose: () => void;
}

const GeneralPopup: React.FC<GeneralPopupProps> = ({ popup, isOpen, onClose }) => {
  const { t } = useTranslation('popups');

  const handleDismiss = () => {
    popupApiService.registerInteraction(popup.id, { action: 'dismissed' }).catch(() => {});
    onClose();
  };

  return (
    <PopupBase popup={popup} isOpen={isOpen} onClose={handleDismiss}>
      <div className="flex justify-center mt-4">
        <button
          onClick={handleDismiss}
          className="px-6 py-3 bg-primario hover:bg-primario-dark text-white rounded-lg font-semibold transition-all"
        >
          {t('general.closeButton')}
        </button>
      </div>
    </PopupBase>
  );
};

export default GeneralPopup;
