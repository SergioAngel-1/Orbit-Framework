import React from 'react';
import { useTranslation } from 'react-i18next';
import AnimatedModal from '../ui/AnimatedModal';
import type { Popup } from '../../services/popups/popupTypes';
import { sanitizeRichContent } from '../../utils/sanitizeHtml';

interface PopupBaseProps {
  popup: Popup;
  isOpen: boolean;
  onClose: () => void;
  children?: React.ReactNode;
  noPadding?: boolean;
  onImageClick?: () => void;
}

/**
 * Componente base para popups usando AnimatedModal
 * La imagen es el contenido principal (diseño de marca)
 * Los botones y acciones se manejan en cada componente específico (children)
 */
const PopupBase: React.FC<PopupBaseProps> = ({
  popup,
  isOpen,
  onClose,
  children,
  noPadding = false,
  onImageClick: onImageClickProp,
}) => {
  const { t } = useTranslation('popups');

  const handleImageClick = () => {
    if (onImageClickProp) {
      onImageClickProp();
    } else if (popup.imageUrl) {
      window.open(popup.imageUrl, '_blank', 'noopener,noreferrer');
    }
  };

  return (
    <AnimatedModal
      isOpen={isOpen}
      onClose={onClose}
      hideCloseButton={true}
      preventOverlayClose={!popup.dismissible}
      maxWidth="max-w-lg"
      noPadding={noPadding}
    >
      <div className="popup-content relative overflow-y-auto flex-1 min-h-0 overscroll-contain" style={{ WebkitOverflowScrolling: 'touch' }}>
        {/* Botón de cerrar dentro de la imagen */}
        {popup.dismissible && (
          <button
            onClick={onClose}
            className="absolute top-2 right-2 z-20 w-8 h-8 flex items-center justify-center bg-white/80 hover:bg-white rounded-full shadow-md transition-all text-gray-600 hover:text-gray-900"
            aria-label={t('base.closeAria')}
          >
            <span className="text-xl leading-none">×</span>
          </button>
        )}

        {/* Imagen principal (diseño de marca) */}
        {(popup.image || popup.imageMobile) && (
          <div 
            className={`relative w-full ${onImageClickProp || popup.imageUrl ? 'cursor-pointer' : ''}`}
            onClick={onImageClickProp || popup.imageUrl ? handleImageClick : undefined}
          >
            <picture>
              {popup.imageMobile && (
                <source media="(max-width: 640px)" srcSet={popup.imageMobile} />
              )}
              <img
                src={popup.image || popup.imageMobile}
                alt={popup.title || t('base.imageAltFallback')}
                className={`w-full h-auto object-contain ${noPadding ? '' : 'rounded-lg'}`}
              />
            </picture>
          </div>
        )}

        {/* Título opcional */}
        {popup.title && (
          <h2 className="text-xl font-bold text-gray-900 mt-4 mb-2">
            {popup.title}
          </h2>
        )}

        {/* Contenido HTML opcional */}
        {popup.content && (
          <div 
            className="text-gray-600 prose prose-sm max-w-none"
            dangerouslySetInnerHTML={{ __html: sanitizeRichContent(popup.content) }}
          />
        )}

        {/* Contenido adicional (botones, checkboxes, etc.) */}
        {children}
      </div>
    </AnimatedModal>
  );
};

export default PopupBase;
