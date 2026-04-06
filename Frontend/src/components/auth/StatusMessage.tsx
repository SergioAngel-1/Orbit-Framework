import React from 'react';
import { useNavigate } from 'react-router-dom';
import { useLanguage } from '../../contexts/LanguageContext';

interface StatusMessageProps {
  type: 'success' | 'error';
  title: string;
  message: string;
  redirectText: string;
  buttonText: string;
}

/**
 * Componente para mostrar mensajes de estado (éxito o error)
 */
const StatusMessage: React.FC<StatusMessageProps> = ({
  type,
  title,
  message,
  redirectText,
  buttonText
}) => {
  const navigate = useNavigate();
  const { localizedPath } = useLanguage();
  
  return (
    <div className="text-center">
      {/* Icono */}
      <div className={`mx-auto flex items-center justify-center h-16 w-16 rounded-full ${
        type === 'success' ? 'bg-green-100' : 'bg-red-100'
      }`}>
        {type === 'success' ? (
          <svg
            className="h-8 w-8 text-green-600"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
          >
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              strokeWidth={2}
              d="M5 13l4 4L19 7"
            />
          </svg>
        ) : (
          <svg
            className="h-8 w-8 text-red-600"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
          >
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              strokeWidth={2}
              d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
            />
          </svg>
        )}
      </div>
      
      {/* Título */}
      <h3 className="mt-4 text-2xl font-bold text-gray-800">
        {title}
      </h3>
      
      {/* Mensaje */}
      <p className="mt-2 text-gray-600">
        {message}
      </p>
      
      {/* Texto de redirección */}
      <p className="mt-2 text-sm text-gray-500">
        {redirectText}
      </p>
      
      {/* Botón */}
      <div className="mt-6">
        <button
          type="button"
          onClick={() => navigate(localizedPath('/iniciar-sesion'))}
          className="w-full px-4 py-3 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primario hover:bg-primario-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primario"
        >
          {buttonText}
        </button>
      </div>
    </div>
  );
};

export default StatusMessage;
