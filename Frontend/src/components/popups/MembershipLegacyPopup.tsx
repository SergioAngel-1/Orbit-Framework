import React, { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FaCheck } from 'react-icons/fa';
import PopupBase from './PopupBase';
import type { Popup } from '../../services/popups/popupTypes';
import { popupApiService } from '../../services/popups';
import { useMembership } from '../../contexts/MembershipContext';
import alertService from '../../services/alertService';
import logger from '../../utils/logger';
import { sanitizeInlineHtml } from '../../utils/sanitizeHtml';

interface MembershipLegacyPopupProps {
  popup: Popup;
  isOpen: boolean;
  onClose: () => void;
}

const MembershipLegacyPopup: React.FC<MembershipLegacyPopupProps> = ({ popup, isOpen, onClose }) => {
  const { t } = useTranslation('popups');
  const [isAccepting, setIsAccepting] = useState(false);
  const [acceptTerms, setAcceptTerms] = useState(false);
  const { refreshMembership } = useMembership();
  const termsRef = useRef<HTMLLabelElement>(null);

  const handleImageClick = () => {
    termsRef.current?.scrollIntoView({ behavior: 'smooth', block: 'center' });
  };

  const handleAccept = async () => {
    if (!acceptTerms) return;
    
    setIsAccepting(true);
    
    try {
      const response = await popupApiService.respondToLegacyMembership('accepted');
      
      if (response.success) {
        alertService.success(t('membershipLegacy.successAlert'));
        
        // CRÍTICO: Esperar a que se refresque la membresía ANTES de cerrar el popup
        // Esto asegura que MembershipContext tenga el nivel 5 (Antigüedad) actualizado
        // y todos los componentes dependientes se re-rendericen con los datos correctos
        try {
          logger.info('MembershipLegacyPopup', 'Refrescando membresía después de aceptar...');
          await refreshMembership();
          logger.info('MembershipLegacyPopup', 'Membresía refrescada exitosamente');
        } catch (err) {
          // Si falla el refresh, no es crítico - el usuario puede recargar la página
          // Pero sí logueamos el error para debugging
          logger.warn('MembershipLegacyPopup', 'Error al refrescar membresía (continuando):', err);
        }
        
        // Cerrar popup después de refrescar la membresía
        logger.info('MembershipLegacyPopup', 'Membresía aceptada exitosamente, cerrando popup');
        onClose();
      } else {
        alertService.error(response.message || t('membershipLegacy.errorDefault'));
      }
    } catch (error) {
      logger.error('MembershipLegacyPopup', 'Error al aceptar membresía:', error);
      alertService.error(t('membershipLegacy.errorGeneric'));
    } finally {
      setIsAccepting(false);
    }
  };

  // Este popup NO se puede cerrar sin aceptar - es OBLIGATORIO
  const handleClose = () => {
    // No hacer nada - el usuario DEBE aceptar los términos
  };

  // Ocultar título - solo mostrar imagen
  const popupWithoutTitle = {
    ...popup,
    title: '',
    content: '',
    dismissible: false,
  };

  return (
    <PopupBase
      popup={popupWithoutTitle}
      isOpen={isOpen}
      onClose={handleClose}
      noPadding
      onImageClick={handleImageClick}
    >
      {/* Contenido debajo de la imagen */}
      <div className="p-4">
        {/* Checkbox de aceptación de términos - estilo personalizado */}
        <label 
          ref={termsRef}
          className={`flex items-start gap-3 p-3 rounded-lg cursor-pointer transition-all border-2 ${
            acceptTerms 
              ? 'bg-primario/5 border-primario' 
              : 'bg-gray-50 border-gray-200 hover:bg-gray-100'
          }`}
          onClick={() => setAcceptTerms(!acceptTerms)}
        >
          {/* Checkbox visual personalizado */}
          <div className="flex items-center pt-0.5">
            <div className={`w-5 h-5 rounded border-2 flex items-center justify-center transition-all ${
              acceptTerms ? 'border-primario bg-primario' : 'border-gray-300 bg-white'
            }`}>
              {acceptTerms && (
                <FaCheck className="w-3 h-3 text-white" />
              )}
            </div>
          </div>
          <span className="text-sm text-gray-700 select-none" dangerouslySetInnerHTML={{ __html: sanitizeInlineHtml(t('membershipLegacy.termsLabel')) }} />
        </label>

        {/* Botón de aceptar - único botón, obligatorio */}
        <div className="mt-4">
          <button
            onClick={handleAccept}
            disabled={!acceptTerms || isAccepting}
            className={`w-full px-6 py-3 rounded-lg font-semibold transition-all ${
              acceptTerms 
                ? 'bg-primario hover:bg-primario-dark text-white' 
                : 'bg-gray-300 text-gray-500 cursor-not-allowed'
            } disabled:opacity-50`}
          >
            {isAccepting ? t('membershipLegacy.processing') : t('membershipLegacy.acceptButton')}
          </button>
          <p className="text-xs text-gray-500 text-center mt-2">
            {t('membershipLegacy.termsRequired')}
          </p>
        </div>
      </div>
    </PopupBase>
  );
};

export default MembershipLegacyPopup;
