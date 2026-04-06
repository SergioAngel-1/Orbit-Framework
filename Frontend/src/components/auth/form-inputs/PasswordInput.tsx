import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FaEye, FaEyeSlash, FaLock } from 'react-icons/fa';
import '../../../styles/passwordTooltip.css';
import Input, { ValidationStatus } from './Input';
import { validatePasswordStrength } from './validationUtils';

interface PasswordInputProps {
  password: string;
  setPassword: (value: string) => void;
  setPasswordStrength?: (value: { strength: number; message: string }) => void;
  disabled?: boolean;
}

const PasswordInput: React.FC<PasswordInputProps> = ({ 
  password, 
  setPassword, 
  setPasswordStrength: setParentPasswordStrength,
  disabled = false 
}) => {
  const { t } = useTranslation('registerForm');
  const [showPassword, setShowPassword] = useState(false);
  const [showPasswordTooltip, setShowPasswordTooltip] = useState(false);
  const [validationStatus, setValidationStatus] = useState<ValidationStatus>('none');
  const [passwordStrength, setPasswordStrength] = useState({
    strength: 0,
    message: ''
  });

  // Manejar cambio en el campo de contraseña
  const handlePasswordChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const newPassword = e.target.value;
    setPassword(newPassword);
  };

  // Toggle para mostrar/ocultar contraseña
  const toggleShowPassword = () => {
    setShowPassword(!showPassword);
  };

  // Botón para mostrar/ocultar contraseña - solo se muestra cuando hay contenido
  const showPasswordButton = password ? (
    <button
      type="button"
      onClick={toggleShowPassword}
      className="text-gray-400 hover:text-gray-600 focus:outline-none transition-colors"
      style={{ border: 'none', boxShadow: 'none', background: 'transparent' }}
    >
      {showPassword ? <FaEyeSlash /> : <FaEye />}
    </button>
  ) : undefined;

  // Tooltip de ayuda para requisitos de contraseña
  const passwordTooltip = (
    <div className="ml-1 inline-block relative cursor-help">
      <svg 
        xmlns="http://www.w3.org/2000/svg" 
        className="password-tooltip-icon h-4 w-4 text-gray-400 inline" 
        fill="none" 
        viewBox="0 0 24 24" 
        stroke="currentColor"
        onClick={() => setShowPasswordTooltip(true)}
      >
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      
      {/* Modal para dispositivos móviles */}
      {showPasswordTooltip && (
        <div className="fixed inset-0 bg-black bg-opacity-50 z-40 flex items-center justify-center md:hidden">
          <div className="bg-white m-4 p-4 rounded-lg max-w-xs w-full z-50">
            <p className="font-semibold mb-2 text-gray-800">{t('password.tooltip.title')}</p>
            <ul className="list-disc pl-5 space-y-1 text-sm text-gray-700">
              <li>{t('password.tooltip.minLength')}</li>
              <li>{t('password.tooltip.number')}</li>
              <li>{t('password.tooltip.case')}</li>
              <li>{t('password.tooltip.special')}</li>
            </ul>
            <button 
              className="mt-4 w-full py-2 bg-primario text-white rounded-md"
              onClick={(e) => {
                e.stopPropagation();
                setShowPasswordTooltip(false);
              }}
            >
              {t('password.tooltip.understood')}
            </button>
          </div>
        </div>
      )}
      
      {/* Tooltip para escritorio con trigger manual */}
      <div 
        className="password-tooltip hidden md:block absolute z-10 bottom-full left-1/2 transform -translate-x-1/2 -translate-y-2 w-64 px-4 py-3 bg-gray-800 text-white text-xs rounded-md"
        onClick={(e) => e.stopPropagation()}
        style={{ display: 'none' }}
      >
        <p className="font-semibold mb-1">{t('password.tooltip.title')}</p>
        <ul className="list-disc pl-4 space-y-1">
          <li>{t('password.tooltip.minLength')}</li>
          <li>{t('password.tooltip.number')}</li>
          <li>{t('password.tooltip.case')}</li>
          <li>{t('password.tooltip.special')}</li>
        </ul>
        <div className="absolute bottom-0 left-1/2 transform -translate-x-1/2 translate-y-1/2 rotate-45 w-2 h-2 bg-gray-800"></div>
      </div>
    </div>
  );

  // Verificar fortaleza cuando cambia la contraseña
  useEffect(() => {
    if (!password) {
      const emptyResult = { strength: 0, message: '' };
      setPasswordStrength(emptyResult);
      // Comunicar al componente padre
      if (setParentPasswordStrength) setParentPasswordStrength(emptyResult);
      setValidationStatus('none');
      return;
    }

    // Usar la función centralizada para validar la contraseña
    const result = validatePasswordStrength(password);
    setPasswordStrength(result);
    
    // Comunicar el resultado al componente padre
    if (setParentPasswordStrength) setParentPasswordStrength(result);
    
    // Determinar el estado de validación basado en la fortaleza
    if (result.strength >= 4) {
      setValidationStatus('valid');
    } else if (result.strength > 0) {
      setValidationStatus('invalid'); // Condicional - tiene algunos requisitos pero no todos
    } else {
      setValidationStatus('invalid'); // Muy débil
    }
  }, [password]);

  return (
    <div>
      <Input
        id="reg-password"
        name="password"
        type={showPassword ? "text" : "password"}
        value={password}
        onChange={handlePasswordChange}
        placeholder={t('password.placeholder')}
        autoComplete="new-password"
        required={true}
        disabled={disabled}
        icon={<FaLock />}
        label={t('password.label')}
        labelRequired={true}
        validationStatus={validationStatus}
        validationMessage={passwordStrength.message}
        helpTooltip={passwordTooltip}
        rightElement={showPasswordButton}
      />
      
      {/* Indicador de fortaleza de contraseña */}
      {password && (
        <div className="mt-1 h-2 w-full bg-gray-200 rounded-full overflow-hidden">
          <div 
            className={`h-full ${passwordStrength.strength === 0 ? 'bg-[#FF3B30]' : 
              passwordStrength.strength === 1 ? 'bg-[#FF3B30]/80' : 
              passwordStrength.strength === 2 ? 'bg-yellow-500' : 
              passwordStrength.strength === 3 ? 'bg-[#34C759]/80' : 'bg-[#34C759]'}`}
            style={{ width: `${Math.min(100, passwordStrength.strength * 25)}%` }}
          ></div>
        </div>
      )}
    </div>
  );
};

export default PasswordInput;
