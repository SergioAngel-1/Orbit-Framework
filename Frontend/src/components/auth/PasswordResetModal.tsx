import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FiMail } from 'react-icons/fi';
import { authService } from '../../services/api';
import alertService from '../../services/alertService';
import logger from '../../utils/logger';
import errorHandler from '../../utils/errorHandler';
import AnimatedModal from '../ui/AnimatedModal';
import { useModal } from '../../contexts/ModalContext';

interface PasswordResetModalProps {
  isOpen: boolean;
  onClose: () => void;
}

const PasswordResetModal: React.FC<PasswordResetModalProps> = ({ isOpen, onClose }) => {
  const { t } = useTranslation('passwordReset');
  const [email, setEmail] = useState('');
  const [loading, setLoading] = useState(false);
  const [submitted, setSubmitted] = useState(false);
  const { registerCustomModal, unregisterCustomModal } = useModal();

  // Registrar/desregistrar modal sincronizado con isOpen
  useEffect(() => {
    const modalId = 'password-reset';
    if (isOpen) {
      registerCustomModal(modalId);
    }
    return () => {
      if (isOpen) {
        unregisterCustomModal(modalId);
      }
    };
  }, [isOpen, registerCustomModal, unregisterCustomModal]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!email) {
      alertService.error(t('modal.emptyEmail'));
      return;
    }

    try {
      setLoading(true);
      logger.info('PasswordResetModal', 'Solicitando restablecimiento para:', { email });
      
      const response = await authService.requestPasswordReset(email);
      
      setSubmitted(true);
      alertService.success(response.message);
    } catch (error: any) {
      // Usar el manejador de errores centralizado para errores de restablecimiento de contraseña
      errorHandler.handlePasswordResetError(error);
    } finally {
      setLoading(false);
    }
  };

  const handleClose = () => {
    setEmail('');
    setSubmitted(false);
    onClose();
  };

  return (
    <AnimatedModal
      isOpen={isOpen}
      onClose={handleClose}
      title={t('modal.title')}
      className="max-w-lg w-full"
    >
      <div className="text-left">
        {!submitted ? (
          <>
            <p className="text-sm text-gray-500">
              {t('modal.description')}
            </p>

            <form onSubmit={handleSubmit} className="mt-4 space-y-4">
              <div className="relative">
                <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                  <FiMail className="h-5 w-5 text-gray-400" aria-hidden="true" />
                </div>
                <input
                  id="email"
                  name="email"
                  type="email"
                  autoComplete="email"
                  required
                  className="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md leading-5 bg-white placeholder-gray-500 focus:outline-none focus:ring-primario focus:border-primario sm:text-sm"
                  placeholder={t('modal.emailPlaceholder')}
                  value={email}
                  onChange={(e) => setEmail(e.target.value.trim())}
                  disabled={loading}
                />
              </div>

              <div className="flex flex-col sm:flex-row-reverse gap-3">
                <button
                  type="submit"
                  className="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-primario text-base font-medium text-white hover:bg-primario-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primario sm:w-auto sm:text-sm disabled:opacity-70"
                  disabled={loading}
                >
                  {loading ? t('modal.sending') : t('modal.sendButton')}
                </button>
                <button
                  type="button"
                  className="w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primario sm:w-auto sm:text-sm"
                  onClick={handleClose}
                  disabled={loading}
                >
                  {t('modal.cancelButton')}
                </button>
              </div>
            </form>
          </>
        ) : (
          <div className="mt-2 text-center space-y-4">
            <div className="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-green-100">
              <svg className="h-6 w-6 text-green-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
              </svg>
            </div>
            <div>
              <h3 className="text-lg leading-6 font-medium text-gray-900">
                {t('modal.successTitle')}
              </h3>
              <p className="mt-2 text-sm text-gray-500">
                {t('modal.successMessage')}
              </p>
            </div>
            <div>
              <button
                type="button"
                className="inline-flex justify-center w-full rounded-md border border-transparent shadow-sm px-4 py-2 bg-primario text-base font-medium text-white hover:bg-primario-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primario sm:text-sm"
                onClick={handleClose}
              >
                {t('modal.understoodButton')}
              </button>
            </div>
          </div>
        )}
      </div>
    </AnimatedModal>
  );
};

export default PasswordResetModal;
