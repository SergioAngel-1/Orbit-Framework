import React from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useLanguage } from '../../contexts/LanguageContext';
import PopupBase from './PopupBase';
import type { Popup } from '../../services/popups/popupTypes';
import { popupApiService } from '../../services/popups';

interface LoginPromptPopupProps {
  popup: Popup;
  isOpen: boolean;
  onClose: () => void;
}

/**
 * Popup de invitación a iniciar sesión
 * Solo muestra la imagen, al hacer click redirige a /login
 * Sin botones adicionales
 */
const LoginPromptPopup: React.FC<LoginPromptPopupProps> = ({ popup, isOpen, onClose }) => {
  const { t } = useTranslation('popups');
  const navigate = useNavigate();
  const { localizedPath } = useLanguage();

  const handleImageClick = () => {
    popupApiService.registerInteraction(popup.id, { action: 'clicked' }).catch(() => {});
    onClose();
    navigate(localizedPath('/iniciar-sesion'));
  };

  const handleDismiss = () => {
    popupApiService.registerInteraction(popup.id, { action: 'dismissed' }).catch(() => {});
    onClose();
  };

  // Forzar que la imagen siempre redirija a /login (ignorar imageUrl del backend)
  // También ocultar título y contenido - solo mostrar imagen
  const popupWithLoginRedirect = {
    ...popup,
    imageUrl: undefined, // Ignorar URL del backend, manejamos el click manualmente
    title: '', // No mostrar título
    content: '', // No mostrar contenido
  };

  return (
    <PopupBase popup={popupWithLoginRedirect} isOpen={isOpen} onClose={handleDismiss} noPadding>
      {/* Área clickeable sobre la imagen para redirigir a login */}
      <div 
        className="absolute inset-0 cursor-pointer z-10"
        onClick={handleImageClick}
        role="button"
        aria-label={t('loginPrompt.loginAria')}
      />
    </PopupBase>
  );
};

export default LoginPromptPopup;
