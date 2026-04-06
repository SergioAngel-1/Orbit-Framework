import React from 'react';
import { useTranslation } from 'react-i18next';
import logger from '../../utils/logger';
import { fluidSizing } from '../../utils/fluidSizing';

interface AuthFormContainerProps {
  title: string;
  subtitle: string;
  children: React.ReactNode;
  toggleForm: () => void;
  toggleButtonText: string;
}

const AuthFormContainer: React.FC<AuthFormContainerProps> = ({
  title,
  subtitle,
  children,
  toggleForm,
  toggleButtonText
}) => {
  const { t } = useTranslation('landingPage');
  const isLoginView = toggleButtonText.includes(t('desktop.loginToggle'));
  
  return (
    <div className="w-full max-w-md mx-auto">
      <div className="w-full">
        <div 
          className="text-center"
          style={{ marginBottom: fluidSizing.space['2xl'] }}
        >
          <div style={{ marginBottom: fluidSizing.space.xl }}>
            <img
              src="/assets/images/logo-flores.png"
              alt="Logo"
              className="mx-auto object-contain"
              style={{ 
                height: 'clamp(5rem, 5rem + 4 * ((100vw - 20rem) / 100), 9rem)',
                maxWidth: '100%'
              }}
              onError={(e) => {
                const target = e.target as HTMLImageElement;
                target.style.display = 'none';
                logger.error('AuthFormContainer', 'Error al cargar el logo del sitio.');
              }}
            />
          </div>
          
          <h2 
            className="font-bold text-gray-800 leading-tight"
            style={{ 
              fontSize: fluidSizing.text['3xl'],
              marginBottom: fluidSizing.space.sm
            }}
          >
            {title}
          </h2>
          <p 
            className="text-gray-600 leading-relaxed"
            style={{ 
              fontSize: fluidSizing.text.base
            }}
          >
            {subtitle}
          </p>
        </div>

        {children}

        <div 
          className="text-center"
          style={{ marginTop: fluidSizing.space['2xl'] }}
        >
          <div className="relative" style={{ marginBottom: fluidSizing.space.lg }}>
            <div className="absolute inset-0 flex items-center">
              <div className="w-full border-t border-gray-300"></div>
            </div>
            <div className="relative flex justify-center">
              <span 
                className="bg-white text-gray-600"
                style={{ 
                  padding: `0 ${fluidSizing.space.md}`,
                  fontSize: fluidSizing.text.sm
                }}
              >
                {isLoginView ? t('authContainer.noAccount') : t('authContainer.hasAccount')}
              </span>
            </div>
          </div>
          
          <button
            type="button"
            onClick={toggleForm}
            className="w-full flex items-center justify-center border border-transparent rounded-md shadow-sm font-medium text-white bg-primario hover:bg-primario-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primario transition-colors"
            style={{
              height: fluidSizing.size.buttonMd,
              fontSize: fluidSizing.text.base,
              borderRadius: fluidSizing.modal.borderRadius
            }}
          >
            {isLoginView ? t('authContainer.requestAccount') : t('authContainer.loginButton')}
          </button>
        </div>
      </div>
    </div>
  );
};

export default AuthFormContainer;
