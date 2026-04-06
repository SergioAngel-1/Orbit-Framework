import React from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { FiLogIn } from 'react-icons/fi';
import { useAuth } from '../../contexts/AuthContext';
import { useLanguage } from '../../contexts/LanguageContext';
import { fluidSizing } from '../../utils/fluidSizing';

interface LoginButtonProps {
  className?: string;
}

const LoginButton: React.FC<LoginButtonProps> = ({ className = '' }) => {
  const { t } = useTranslation('loginButton');
  const { localizedPath } = useLanguage();
  const { isAuthenticated } = useAuth();

  if (isAuthenticated) {
    return null;
  }

  return (
    <Link 
      to={localizedPath('/iniciar-sesion')} 
      className={`flex items-center whitespace-nowrap bg-primario hover:bg-white hover:text-primario border border-primario text-white font-medium rounded-lg transition-colors shadow-md flex-shrink-0 ${className}`}
      style={{
        padding: `${fluidSizing.space.xs} ${fluidSizing.space.sm}`,
        fontSize: fluidSizing.text.xs,
        gap: fluidSizing.space.xs,
      }}
    >
      <FiLogIn style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} /> {t('goToLogin')}
    </Link>
  );
};

export default LoginButton;
