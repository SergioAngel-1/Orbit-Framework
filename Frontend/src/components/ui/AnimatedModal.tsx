import React, { useEffect, useState, useRef, useId } from 'react';
import { useTranslation } from 'react-i18next';
import { createPortal } from 'react-dom';
import './AnimatedModal.css';
import { useModal } from '../../contexts/ModalContext';
import { lockBodyScroll, unlockBodyScroll } from '../../utils/bodyScrollLock';

interface AnimatedModalProps {
  isOpen: boolean;
  onClose: () => void;
  children: React.ReactNode;
  className?: string;
  title?: React.ReactNode;
  hideCloseButton?: boolean;
  maxWidth?: string;
  preventOverlayClose?: boolean;
  noPadding?: boolean;
}

const AnimatedModal: React.FC<AnimatedModalProps> = ({ 
  isOpen, 
  onClose, 
  children, 
  className = "",
  title = "",
  hideCloseButton = false,
  maxWidth = "auto",
  preventOverlayClose = false,
  noPadding = false
}) => {
  const { t } = useTranslation('uiComponents');
  const [isAnimating, setIsAnimating] = useState(false);
  const [isVisible, setIsVisible] = useState(false);
  const animationTimeoutRef = useRef<NodeJS.Timeout | null>(null);
  const modalId = useId();
  const { registerCustomModal, unregisterCustomModal } = useModal();
  
  // Registrar/desregistrar modal en el contexto
  // Nota: Solo registrar cuando se abre, desregistrar cuando se cierra
  useEffect(() => {
    if (isOpen) {
      registerCustomModal(modalId);
      return () => {
        unregisterCustomModal(modalId);
      };
    }
  }, [isOpen, modalId, registerCustomModal, unregisterCustomModal]);
  
  // Bloquear/desbloquear scroll del body
  // Usamos un ref para trackear si bloqueamos el scroll en esta instancia
  const hasLockedScrollRef = useRef(false);
  
  useEffect(() => {
    if (isOpen) {
      lockBodyScroll();
      hasLockedScrollRef.current = true;
    } else if (hasLockedScrollRef.current) {
      // Solo desbloquear si este modal bloqueó el scroll
      unlockBodyScroll();
      hasLockedScrollRef.current = false;
    }
  }, [isOpen]);
  
  // Cleanup al desmontar el componente
  useEffect(() => {
    return () => {
      if (hasLockedScrollRef.current) {
        unlockBodyScroll();
        hasLockedScrollRef.current = false;
      }
    };
  }, []);
  
  // Gestionar animaciones
  useEffect(() => {
    if (isOpen) {
      setIsAnimating(true);
      
      // Forzar una actualización para activar la animación
      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          setIsVisible(true);
        });
      });
    } else if (isAnimating) {
      setIsVisible(false);
      
      // Limpiar cualquier timeout anterior
      if (animationTimeoutRef.current) {
        clearTimeout(animationTimeoutRef.current);
      }
      
      // Esperar a que termine la animación de salida
      animationTimeoutRef.current = setTimeout(() => {
        setIsAnimating(false);
      }, 350); // Un poco más de tiempo que la duración de la transición
    }
    
    return () => {
      if (animationTimeoutRef.current) {
        clearTimeout(animationTimeoutRef.current);
      }
    };
  }, [isOpen, isAnimating]);

  if (!isAnimating) return null;
  
  const modalContent = (
    <div 
      className={`animated-modal-overlay ${isVisible ? 'visible' : ''}`}
      onClick={preventOverlayClose ? undefined : onClose}
    >
      <div 
        className={`animated-modal-content ${isVisible ? 'visible' : ''} ${className} ${maxWidth}`}
        onClick={(e) => e.stopPropagation()}
      >
        {!hideCloseButton && (
          <div className="animated-modal-header">
            {title && <h2 className="animated-modal-title">{title}</h2>}
            <button 
              className="animated-modal-close-btn" 
              onClick={onClose} 
              aria-label={t('animatedModal.close')}
            >
              ×
            </button>
          </div>
        )}
        <div className={`animated-modal-body ${noPadding ? 'no-padding' : ''}`}>
          {children}
        </div>
      </div>
    </div>
  );

  return createPortal(modalContent, document.body);
};

export default AnimatedModal;
