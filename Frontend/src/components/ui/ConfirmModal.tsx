import React from 'react';
import { useTranslation } from 'react-i18next';
import AnimatedModal from './AnimatedModal';
import { fluidSizing } from '../../utils/fluidSizing';
import { FiAlertTriangle, FiX, FiCheck } from 'react-icons/fi';

interface ConfirmModalProps {
  isOpen: boolean;
  onClose: () => void;
  onConfirm: () => void;
  title?: string;
  message: string;
  confirmText?: string;
  cancelText?: string;
  variant?: 'danger' | 'warning' | 'info';
}

/**
 * Modal de confirmación reutilizable que usa AnimatedModal
 * Reemplaza alertService.confirm() para mantener consistencia visual
 */
const ConfirmModal: React.FC<ConfirmModalProps> = ({
  isOpen,
  onClose,
  onConfirm,
  title,
  message,
  confirmText,
  cancelText,
  variant = 'warning'
}) => {
  const { t } = useTranslation('uiComponents');
  const resolvedTitle = title ?? t('confirmModal.title');
  const resolvedConfirmText = confirmText ?? t('confirmModal.confirm');
  const resolvedCancelText = cancelText ?? t('confirmModal.cancel');
  const handleConfirm = () => {
    onConfirm();
    onClose();
  };

  const getVariantStyles = () => {
    switch (variant) {
      case 'danger':
        return {
          iconBg: 'bg-red-100',
          iconColor: 'text-red-600',
          confirmBg: 'bg-red-600 hover:bg-red-700',
        };
      case 'info':
        return {
          iconBg: 'bg-blue-100',
          iconColor: 'text-blue-600',
          confirmBg: 'bg-primario hover:bg-hover',
        };
      case 'warning':
      default:
        return {
          iconBg: 'bg-amber-100',
          iconColor: 'text-amber-600',
          confirmBg: 'bg-primario hover:bg-hover',
        };
    }
  };

  const styles = getVariantStyles();

  return (
    <AnimatedModal
      isOpen={isOpen}
      onClose={onClose}
      hideCloseButton={true}
      maxWidth="max-w-md"
    >
      <div 
        className="text-center"
        style={{ padding: fluidSizing.space.lg }}
      >
        {/* Icono */}
        <div 
          className={`mx-auto rounded-full flex items-center justify-center ${styles.iconBg}`}
          style={{ 
            width: fluidSizing.size.avatar, 
            height: fluidSizing.size.avatar,
            marginBottom: fluidSizing.space.md
          }}
        >
          <FiAlertTriangle 
            className={styles.iconColor}
            style={{ width: fluidSizing.size.iconLg, height: fluidSizing.size.iconLg }}
          />
        </div>

        {/* Título */}
        <h3 
          className="font-semibold text-oscuro"
          style={{ fontSize: fluidSizing.text.lg, marginBottom: fluidSizing.space.sm }}
        >
          {resolvedTitle}
        </h3>

        {/* Mensaje */}
        <p 
          className="text-texto"
          style={{ fontSize: fluidSizing.text.sm, marginBottom: fluidSizing.space.lg }}
        >
          {message}
        </p>

        {/* Botones */}
        <div 
          className="flex flex-col sm:flex-row w-full"
          style={{ gap: fluidSizing.space.sm }}
        >
          <button
            onClick={onClose}
            className="flex-1 flex items-center justify-center border border-gray-300 rounded-lg text-texto bg-white hover:bg-gray-50 transition-colors font-medium"
            style={{ 
              padding: `${fluidSizing.space.sm} ${fluidSizing.space.md}`, 
              fontSize: fluidSizing.text.sm,
              gap: fluidSizing.space.xs,
              minHeight: '44px'
            }}
          >
            <FiX style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
            <span>{resolvedCancelText}</span>
          </button>
          <button
            onClick={handleConfirm}
            className={`flex-1 flex items-center justify-center rounded-lg text-white transition-colors font-medium ${styles.confirmBg}`}
            style={{ 
              padding: `${fluidSizing.space.sm} ${fluidSizing.space.md}`, 
              fontSize: fluidSizing.text.sm,
              gap: fluidSizing.space.xs,
              minHeight: '44px'
            }}
          >
            <FiCheck style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
            <span>{resolvedConfirmText}</span>
          </button>
        </div>
      </div>
    </AnimatedModal>
  );
};

export default ConfirmModal;
