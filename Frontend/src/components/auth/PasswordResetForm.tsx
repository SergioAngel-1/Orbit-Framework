import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FaEye, FaEyeSlash, FaLock } from 'react-icons/fa';
import Loader from '../ui/Loader';
import PasswordStrengthIndicator from './PasswordStrengthIndicator';

interface PasswordResetFormProps {
  password: string;
  confirmPassword: string;
  passwordStrength: {
    strength: number;
    message: string;
  };
  isLoading: boolean;
  validToken: boolean;
  onPasswordChange: (e: React.ChangeEvent<HTMLInputElement>) => void;
  onConfirmPasswordChange: (e: React.ChangeEvent<HTMLInputElement>) => void;
  onSubmit: (e: React.FormEvent) => void;
}

/**
 * Componente para el formulario de restablecimiento de contraseña
 */
const PasswordResetForm: React.FC<PasswordResetFormProps> = ({
  password,
  confirmPassword,
  passwordStrength,
  isLoading,
  validToken,
  onPasswordChange,
  onConfirmPasswordChange,
  onSubmit
}) => {
  const { t } = useTranslation('passwordReset');
  const [showPassword, setShowPassword] = useState(false);

  return (
    <form onSubmit={onSubmit} className="space-y-6">
      {/* Campo username oculto para accesibilidad */}
      <input
        type="text"
        name="username"
        autoComplete="username"
        value=""
        readOnly
        style={{ display: 'none' }}
        aria-hidden="true"
        tabIndex={-1}
      />
      
      <div>
        <label htmlFor="password" className="block text-sm font-medium text-gray-700 mb-1">
          {t('form.newPasswordLabel')}
        </label>
        <div className="relative">
          <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
            <FaLock className="text-gray-400" />
          </div>
          <input
            id="password"
            name="password"
            type={showPassword ? 'text' : 'password'}
            autoComplete="new-password"
            required
            value={password}
            onChange={onPasswordChange}
            className="appearance-none block w-full pl-10 pr-10 py-3 border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-primario focus:border-primario"
            placeholder={t('form.newPasswordPlaceholder')}
          />
          <button
            type="button"
            className="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-0 outline-none"
            onClick={() => setShowPassword(!showPassword)}
          >
            {showPassword ? <FaEyeSlash /> : <FaEye />}
          </button>
        </div>
        <PasswordStrengthIndicator 
          strength={passwordStrength.strength} 
          message={passwordStrength.message} 
          password={password} 
        />
      </div>

      <div>
        <label htmlFor="confirmPassword" className="block text-sm font-medium text-gray-700 mb-1">
          {t('form.confirmPasswordLabel')}
        </label>
        <div className="relative">
          <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
            <FaLock className="text-gray-400" />
          </div>
          <input
            id="confirmPassword"
            name="confirmPassword"
            type={showPassword ? 'text' : 'password'}
            autoComplete="new-password"
            required
            value={confirmPassword}
            onChange={onConfirmPasswordChange}
            className="appearance-none block w-full pl-10 pr-10 py-3 border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-primario focus:border-primario"
            placeholder={t('form.confirmPasswordPlaceholder')}
          />
        </div>
        {confirmPassword && password !== confirmPassword && (
          <p className="text-xs text-red-600 mt-1">
            {t('form.passwordMismatch')}
          </p>
        )}
      </div>

      <div>
        <button
          type="submit"
          disabled={isLoading || !validToken || password !== confirmPassword || passwordStrength.strength < 3}
          className="w-full flex justify-center py-3 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primario hover:bg-primario-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primario disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {isLoading ? (
            <div className="flex items-center justify-center text-white">
              <Loader text="" size="small" />
              <span className="ml-2">{t('form.processing')}</span>
            </div>
          ) : (
            t('form.submitButton')
          )}
        </button>
      </div>
    </form>
  );
};

export default PasswordResetForm;
