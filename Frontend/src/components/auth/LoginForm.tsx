import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FaEye, FaEyeSlash, FaLock, FaUser } from 'react-icons/fa';
import { fluidSizing } from '../../utils/fluidSizing';
import Loader from '../ui/Loader';
import Input, { ValidationStatus } from './form-inputs/Input';

interface LoginFormProps {
  onSubmit: (e: React.FormEvent) => void;
  identifier: string;
  setIdentifier: (value: string) => void;
  password: string;
  setPassword: (value: string) => void;
  loading: boolean;
  onResetPassword: () => void;
}

const LoginForm: React.FC<LoginFormProps> = ({
  onSubmit,
  identifier,
  setIdentifier,
  password,
  setPassword,
  loading,
  onResetPassword
}) => {
  const { t } = useTranslation('loginForm');
  const [showPassword, setShowPassword] = useState(false);
  const [identifierStatus, setIdentifierStatus] = useState<ValidationStatus>('none');
  const [identifierError, setIdentifierError] = useState<string>('');
  const [passwordStatus, setPasswordStatus] = useState<ValidationStatus>('none');
  const [passwordError, setPasswordError] = useState<string>('');
  
  // Validar identifier (usuario o email)
  useEffect(() => {
    if (!identifier) {
      setIdentifierStatus('none');
      setIdentifierError('');
      return;
    }
    
    // Límite de longitud para prevenir DoS
    if (identifier.length > 100) {
      setIdentifierStatus('invalid');
      setIdentifierError(t('validation.identifierTooLong'));
      return;
    }
    
    // Validar formato básico
    const trimmed = identifier.trim();
    if (trimmed.length < 3) {
      setIdentifierStatus('invalid');
      setIdentifierError(t('validation.identifierMinLength'));
      return;
    }
    
    setIdentifierStatus('valid');
    setIdentifierError('');
  }, [identifier]);
  
  // Validar password
  useEffect(() => {
    if (!password) {
      setPasswordStatus('none');
      setPasswordError('');
      return;
    }
    
    // Límite de longitud para prevenir DoS
    if (password.length > 100) {
      setPasswordStatus('invalid');
      setPasswordError(t('validation.passwordTooLong'));
      return;
    }
    
    setPasswordStatus('valid');
    setPasswordError('');
  }, [password]);

  return (
    <form 
      id="login-form" 
      onSubmit={onSubmit} 
      style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.md }}
    >
      {/* Usuario o Email */}
      <Input
        id="identifier"
        name="identifier"
        type="text"
        value={identifier}
        onChange={(e) => setIdentifier(e.target.value.trim())}
        placeholder={t('identifierPlaceholder')}
        autoComplete="username"
        required={true}
        disabled={loading}
        icon={<FaUser />}
        label={t('identifierLabel')}
        labelRequired={true}
        validationStatus={identifierStatus}
        validationMessage={identifierError}
      />

      {/* Contraseña */}
      <Input
        id="password"
        name="password"
        type={showPassword ? "text" : "password"}
        value={password}
        onChange={(e) => setPassword(e.target.value)}
        placeholder={t('passwordPlaceholder')}
        autoComplete="current-password"
        required={true}
        disabled={loading}
        icon={<FaLock />}
        label={t('passwordLabel')}
        labelRequired={true}
        validationStatus={passwordStatus}
        validationMessage={passwordError}
        rightElement={
          password ? (
            <button
              type="button"
              onClick={() => setShowPassword(!showPassword)}
              className="text-gray-400 hover:text-gray-600 focus:outline-none transition-colors"
              style={{ border: 'none', boxShadow: 'none', background: 'transparent' }}
            >
              {showPassword ? <FaEyeSlash /> : <FaEye />}
            </button>
          ) : undefined
        }
      />

      {/* Olvidaste contraseña */}
      <div className="flex items-center justify-center">
        <button
          type="button"
          onClick={onResetPassword}
          className="font-medium text-primario hover:text-primario/80 focus:outline-none transition-colors"
          style={{ fontSize: fluidSizing.text.sm }}
        >
          {t('forgotPassword')}
        </button>
      </div>

      {/* Botón Submit */}
      <button
        type="submit"
        className="w-full flex justify-center items-center rounded-lg font-semibold text-white bg-primario hover:bg-primario/90 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primario disabled:opacity-50 disabled:cursor-not-allowed transition-all"
        style={{ 
          height: fluidSizing.size.buttonMd,
          fontSize: fluidSizing.text.base,
          marginTop: fluidSizing.space.sm
        }}
        disabled={loading || !!identifierError || !!passwordError || !identifier || !password}
        aria-label={t('submitAria')}
      >
        {loading ? (
          <Loader text="" size="small" />
        ) : null}
        {t('submitText')}
      </button>
    </form>
  );
};

export default LoginForm;
