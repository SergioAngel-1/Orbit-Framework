import React, { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import ReactDOM from 'react-dom';
import { FiAlertCircle, FiX } from 'react-icons/fi';
import { User } from '../../contexts/types/auth.types';
import logger from '../../utils/logger';

interface RejectedAccountModalProps {
  isOpen: boolean;
  onClose: () => void;
  user: User | null;
}

const RejectedAccountModal: React.FC<RejectedAccountModalProps> = ({ isOpen, onClose, user }) => {
  const { t } = useTranslation('authModals');
  // Log cuando cambia el estado de isOpen
  useEffect(() => {
    logger.info('RejectedAccountModal', 'Estado del modal cambiado', { isOpen, userEmail: user?.email });
  }, [isOpen, user]);
  
  // Si el modal no está abierto, no renderizar nada
  if (!isOpen) {
    logger.info('RejectedAccountModal', 'Modal no mostrado porque isOpen es false');
    return null;
  }
  
  logger.info('RejectedAccountModal', 'Renderizando modal', { isOpen, userEmail: user?.email });

  // Crear el contenido del modal
  const modalContent = (
    <div className="fixed inset-0 z-[9999] overflow-y-auto" style={{ zIndex: 9999 }}>
      <div className="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        {/* Overlay de fondo con efecto blur más marcado */}
        <div 
          className="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-40 backdrop-filter backdrop-blur-md" 
          style={{ WebkitBackdropFilter: 'blur(8px)' }}
          aria-hidden="true"
          onClick={onClose}
        ></div>

        {/* Centrado del modal */}
        <span className="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

        {/* Modal */}
        <div 
          className="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full"
          role="dialog" 
          aria-modal="true" 
          aria-labelledby="modal-headline"
        >
          {/* Botón de cerrar */}
          <button 
            onClick={onClose}
            aria-label={t('rejected.closeAriaLabel')}
            className="absolute top-0 right-0 mt-3 mr-3 p-1.5 rounded-full bg-gray-100 text-gray-500 hover:bg-gray-200 hover:text-gray-700 transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primario"
          >
            <FiX className="h-5 w-5" />
          </button>

          <div className="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
            <div className="sm:flex sm:items-center">
              <div className="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-red-100 sm:mx-0 sm:h-10 sm:w-10">
                <FiAlertCircle className="h-6 w-6 text-red-600" />
              </div>
              <div className="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                <h3 className="text-lg leading-6 font-medium text-gray-900" id="modal-headline">
                  {t('rejected.title')}
                </h3>
              </div>
            </div>
            
            <div className="mt-3 px-2 sm:px-4">
              <p className="text-sm text-center sm:text-left text-gray-500">
                {t('rejected.message')}
              </p>
              <p className="mt-3 text-sm text-center sm:text-left text-gray-500">
                {t('rejected.errorNote')}
              </p>
            </div>
          </div>
          <div className="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
            <button
              type="button"
              className="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-primario text-base font-medium text-white hover:bg-primario-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primario sm:ml-3 sm:w-auto sm:text-sm"
              onClick={onClose}
            >
              {t('rejected.understood')}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
  
  // Usar ReactDOM.createPortal para renderizar el modal fuera de la jerarquía normal del DOM
  // Esto ayuda a evitar problemas con z-index y otros estilos que podrían afectar la visibilidad del modal
  return ReactDOM.createPortal(
    modalContent,
    document.body
  );
};

export default RejectedAccountModal;
